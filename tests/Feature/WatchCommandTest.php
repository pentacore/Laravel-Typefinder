<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Pentacore\Typefinder\Protocol\ProtocolCodec;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class WatchCommandTest extends TestCase
{
    private function spawnWatch(?string $stdin = null): Process
    {
        // base_path() resolves to the testbench laravel skeleton, not the repo root.
        // Walk up from this file to find the real project root.
        $artisan = dirname(__DIR__, 2).'/vendor/bin/testbench';
        $process = new Process([PHP_BINARY, $artisan, 'typefinder:watch']);
        $process->setTimeout(15.0);
        if ($stdin !== null) {
            $process->setInput($stdin);
        }

        $process->start();

        return $process;
    }

    public function test_emits_ready_handshake_on_stdout(): void
    {
        // Send empty stdin so the watcher exits after emitting handshake.
        $process = $this->spawnWatch('');
        try {
            $process->wait();
            $output = $process->getOutput();
            $lines = array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== '');

            $this->assertNotEmpty($lines, 'watcher emitted no stdout');

            $firstLine = $lines[0];
            $decoded = json_decode($firstLine, true);
            $this->assertIsArray($decoded, 'handshake was not JSON: '.$firstLine);
            $this->assertSame('ready', $decoded['type']);
            $this->assertSame(1, $decoded['protocol']);
            $this->assertArrayHasKey('categories', $decoded);
            $this->assertArrayHasKey('output_path', $decoded);
            $this->assertArrayHasKey('models', $decoded['categories']);
            $this->assertArrayHasKey('enums', $decoded['categories']);
        } finally {
            if ($process->isRunning()) {
                $process->stop(1.0);
            }
        }
    }

    public function test_regen_request_returns_done_with_matching_id(): void
    {
        $stdin = ProtocolCodec::encode([
            'type' => 'regen',
            'id' => 'abc',
            'paths' => [],
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();

        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        // Line 0 = handshake, Line 1 = regen response
        $this->assertGreaterThanOrEqual(2, count($lines), 'expected at least 2 lines: handshake + response');

        $response = json_decode($lines[1], true);
        $this->assertIsArray($response);
        $this->assertSame('regen.done', $response['type']);
        $this->assertSame('abc', $response['id']);
        $this->assertIsArray($response['changed']);
        $this->assertIsArray($response['warnings']);
        $this->assertIsArray($response['failed']);
        $this->assertArrayHasKey('duration_ms', $response);
    }

    public function test_malformed_request_returns_error_without_killing_loop(): void
    {
        // Two lines: a bad one, then a valid one. Loop must survive the bad line.
        $stdin = "{not json\n".ProtocolCodec::encode([
            'type' => 'regen',
            'id' => 'ok',
            'paths' => [],
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();

        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        // Line 0 = handshake, Line 1 = regen.error for bad JSON, Line 2 = regen.done for valid request
        $this->assertGreaterThanOrEqual(3, count($lines), 'expected 3+ lines');

        $errorResponse = json_decode($lines[1], true);
        $this->assertSame('regen.error', $errorResponse['type']);
        $this->assertStringContainsString('malformed JSON', $errorResponse['message']);

        $okResponse = json_decode($lines[2], true);
        $this->assertSame('regen.done', $okResponse['type']);
        $this->assertSame('ok', $okResponse['id']);
    }

    public function test_empty_paths_triggers_full_regen(): void
    {
        $stdin = ProtocolCodec::encode([
            'type' => 'regen',
            'id' => 'full',
            'paths' => [],
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();

        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        $this->assertGreaterThanOrEqual(2, count($lines));
        $response = json_decode($lines[1], true);
        $this->assertSame('regen.done', $response['type']);
        $this->assertSame('full', $response['id']);
        $this->assertIsArray($response['changed']);
        $this->assertIsArray($response['warnings']);
        $this->assertArrayHasKey('duration_ms', $response);
    }

    public function test_unknown_message_type_returns_error(): void
    {
        $stdin = ProtocolCodec::encode([
            'type' => 'unknown_cmd',
            'id' => 'u1',
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();
        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        $this->assertGreaterThanOrEqual(2, count($lines));
        $response = json_decode($lines[1], true);
        $this->assertSame('regen.error', $response['type']);
        $this->assertSame('u1', $response['id']);
        $this->assertStringContainsString('unknown message type', $response['message']);
    }

    public function test_blank_lines_are_silently_skipped(): void
    {
        // Send blank lines before a valid regen — they should be ignored.
        $stdin = "\n\n".ProtocolCodec::encode([
            'type' => 'regen',
            'id' => 'after-blanks',
            'paths' => [],
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();
        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        // Handshake + regen.done — no error lines for blank input
        $this->assertGreaterThanOrEqual(2, count($lines));

        $response = json_decode($lines[1], true);
        $this->assertSame('regen.done', $response['type']);
        $this->assertSame('after-blanks', $response['id']);
    }

    public function test_missing_id_falls_back_to_null(): void
    {
        $stdin = ProtocolCodec::encode([
            'type' => 'regen',
            'paths' => [],
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();
        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        $this->assertGreaterThanOrEqual(2, count($lines));
        $response = json_decode($lines[1], true);
        $this->assertSame('regen.done', $response['type']);
        $this->assertNull($response['id']);
    }

    public function test_non_string_paths_are_filtered_out(): void
    {
        // paths contains a mix of strings and non-strings
        $stdin = ProtocolCodec::encode([
            'type' => 'regen',
            'id' => 'filter-test',
            'paths' => ['/tmp/valid.php', 42, null, true],
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();
        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        $this->assertGreaterThanOrEqual(2, count($lines));
        $response = json_decode($lines[1], true);
        $this->assertSame('regen.done', $response['type']);
        $this->assertSame('filter-test', $response['id']);
    }

    public function test_regen_with_specific_paths_returns_done(): void
    {
        $userPath = (new \ReflectionClass(User::class))->getFileName();
        $stdin = ProtocolCodec::encode([
            'type' => 'regen',
            'id' => 'inc1',
            'paths' => [$userPath],
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();
        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        $this->assertGreaterThanOrEqual(2, count($lines));
        $response = json_decode($lines[1], true);
        $this->assertSame('regen.done', $response['type']);
        $this->assertSame('inc1', $response['id']);
        $this->assertIsArray($response['changed']);
    }

    public function test_multiple_regen_requests_in_sequence(): void
    {
        $stdin = ProtocolCodec::encode([
            'type' => 'regen',
            'id' => 'first',
            'paths' => [],
        ]).ProtocolCodec::encode([
            'type' => 'regen',
            'id' => 'second',
            'paths' => [],
        ]);

        $process = $this->spawnWatch($stdin);
        $process->wait();

        $output = $process->getOutput();
        $lines = array_values(array_filter(explode("\n", $output), fn (string $l): bool => trim($l) !== ''));

        // Handshake + 2 regen.done responses
        $this->assertGreaterThanOrEqual(3, count($lines));

        $first = json_decode($lines[1], true);
        $this->assertSame('regen.done', $first['type']);
        $this->assertSame('first', $first['id']);

        $second = json_decode($lines[2], true);
        $this->assertSame('regen.done', $second['type']);
        $this->assertSame('second', $second['id']);
    }

    public function test_sigterm_causes_clean_exit(): void
    {
        if (! extension_loaded('pcntl')) {
            $this->markTestSkipped('pcntl not available');
        }

        // Use an InputStream that stays open so fgets(STDIN) blocks
        // rather than seeing EOF and exiting the loop.
        $inputStream = new InputStream;
        $artisan = dirname(__DIR__, 2).'/vendor/bin/testbench';
        $process = new Process([PHP_BINARY, $artisan, 'typefinder:watch']);
        $process->setTimeout(15.0);
        $process->setInput($inputStream);
        $process->start();

        try {
            $deadline = microtime(true) + 10.0;
            while ($process->isRunning() && microtime(true) < $deadline) {
                if (str_contains($process->getOutput(), '"type":"ready"')) {
                    break;
                }

                usleep(50_000);
            }

            $this->assertStringContainsString('"type":"ready"', $process->getOutput(), 'watcher did not emit ready handshake');
            $this->assertTrue($process->isRunning(), 'process exited before SIGTERM could be sent');

            $process->signal(SIGTERM);

            $exitDeadline = microtime(true) + 5.0;
            while ($process->isRunning() && microtime(true) < $exitDeadline) {
                usleep(50_000);
            }

            $this->assertFalse($process->isRunning(), 'watcher did not exit within 5s of SIGTERM');
            $this->assertContains($process->getExitCode(), [0, 143]);
        } finally {
            $inputStream->close();
            if ($process->isRunning()) {
                $process->stop(1.0);
            }
        }
    }
}
