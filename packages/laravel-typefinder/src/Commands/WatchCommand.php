<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Commands;

use Illuminate\Console\Command;
use Pentacore\Typefinder\Protocol\ProtocolCodec;
use Pentacore\Typefinder\Protocol\ProtocolException;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\Services\RegenWorker;
use Pentacore\Typefinder\Support\Categories;
use Pentacore\Typefinder\Version;

class WatchCommand extends Command
{
    protected $signature = 'typefinder:watch {--in-process : Regenerate inside this process instead of a worker. It keeps the classes it has loaded, so this is only right for one request. Used by the watcher itself.}';

    protected $description = 'Start the Typefinder watch loop — a long-lived generator used by the Vite plugin.';

    public function handle(Generator $generator): int
    {
        $this->emitHandshake();

        $regenWorker = new RegenWorker($generator, static function (string $message): void {
            fwrite(STDERR, '[typefinder] '.$message."\n");
        });
        $inProcess = (bool) $this->option('in-process');

        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            $shutdown = function (int $signal): void {
                fwrite(STDERR, '[typefinder] received signal '.$signal.', exiting'."\n");
                exit(0);
            };
            pcntl_signal(SIGTERM, $shutdown);
            pcntl_signal(SIGINT, $shutdown);
        }

        // Use stream_select with a 1-second timeout instead of blocking
        // fgets so that pcntl signal handlers can fire between iterations.
        // A bare fgets(STDIN) is a libc call subject to SA_RESTART which
        // silently resumes after signal delivery, defeating pcntl_signal.
        $stdin = STDIN;
        while (! feof($stdin)) {
            $read = [$stdin];
            $write = [];
            $except = [];
            $ready = @stream_select($read, $write, $except, 1);
            if ($ready === false) {
                continue;
            }

            if ($ready === 0) {
                continue;
            }

            $line = fgets($stdin);
            if ($line === false) {
                break;
            }

            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            try {
                $message = ProtocolCodec::decode($trimmed);
            } catch (ProtocolException $protocolException) {
                $this->writeLine([
                    'type' => 'regen.error',
                    'id' => null,
                    'message' => $protocolException->getMessage(),
                ]);

                continue;
            }

            if ($message['type'] !== 'regen') {
                $this->writeLine([
                    'type' => 'regen.error',
                    'id' => $message['id'] ?? null,
                    'message' => sprintf('unknown message type "%s"', $message['type']),
                ]);

                continue;
            }

            $id = is_string($message['id'] ?? null) ? $message['id'] : null;
            $paths = [];
            foreach ((array) ($message['paths'] ?? []) as $p) {
                if (is_string($p)) {
                    $paths[] = $p;
                }
            }

            // The watcher never extracts itself: PHP cannot reload a class
            // it has loaded, so an edited file would not be read again.
            $this->writeLine($inProcess ? $regenWorker->inProcess($id, $paths) : $regenWorker->isolated($id, $paths));
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $message */
    private function writeLine(array $message): void
    {
        fwrite(STDOUT, ProtocolCodec::encode($message));
        fflush(STDOUT);
    }

    private function emitHandshake(): void
    {
        $payload = [
            'type' => 'ready',
            'version' => Version::VERSION,
            'protocol' => 1,
            'output_path' => (string) config('typefinder.output_path', ''),
            'categories' => Categories::all(),
        ];

        fwrite(STDOUT, ProtocolCodec::encode($payload));
        fflush(STDOUT);
    }
}
