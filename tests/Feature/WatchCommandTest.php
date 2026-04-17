<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Protocol\ProtocolCodec;
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
}
