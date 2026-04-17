<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Commands;

use Illuminate\Console\Command;
use Pentacore\Typefinder\Protocol\ProtocolCodec;
use Pentacore\Typefinder\Protocol\ProtocolException;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\Version;

class WatchCommand extends Command
{
    protected $signature = 'typefinder:watch';

    protected $description = 'Start the Typefinder watch loop — a long-lived generator used by the Vite plugin.';

    public function handle(Generator $generator): int
    {
        $this->emitHandshake();

        while (! feof(STDIN)) {
            $line = fgets(STDIN);
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

            try {
                $result = $generator->generatePaths($paths);
                $this->writeLine([
                    'type' => 'regen.done',
                    'id' => $id,
                    'duration_ms' => $result->durationMs,
                    'changed' => $result->changed,
                    'warnings' => $result->warnings,
                    'failed' => $result->failed,
                ]);
            } catch (\Throwable $throwable) {
                fwrite(STDERR, '[typefinder] '.$throwable->getMessage()."\n");
                $this->writeLine([
                    'type' => 'regen.error',
                    'id' => $id,
                    'message' => $throwable->getMessage(),
                ]);
            }
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
        $config = (array) config('typefinder');

        $payload = [
            'type' => 'ready',
            'version' => Version::VERSION,
            'protocol' => 1,
            'output_path' => (string) ($config['output_path'] ?? ''),
            'categories' => $this->resolveCategories($config),
        ];

        fwrite(STDOUT, ProtocolCodec::encode($payload));
        fflush(STDOUT);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, array{enabled: bool, paths: list<string>}>
     */
    private function resolveCategories(array $config): array
    {
        $categories = [];
        foreach (['models', 'enums', 'requests', 'resources', 'inertia', 'broadcasting'] as $name) {
            $node = $config[$name] ?? [];
            $paths = $node['paths'] ?? [];
            $resolved = [];
            foreach ((array) $paths as $p) {
                $real = realpath((string) $p);
                $resolved[] = $real !== false ? $real : (string) $p;
            }

            $categories[$name] = [
                'enabled' => (bool) ($node['enabled'] ?? false),
                'paths' => array_values($resolved),
            ];
        }

        return $categories;
    }
}
