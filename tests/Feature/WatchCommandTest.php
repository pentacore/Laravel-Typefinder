<?php

declare(strict_types=1);

namespace Tests\Feature;

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
}
