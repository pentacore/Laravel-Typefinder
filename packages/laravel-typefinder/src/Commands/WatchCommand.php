<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Commands;

use Illuminate\Console\Command;
use Pentacore\Typefinder\Protocol\ProtocolCodec;
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

            // Regen loop is Task 10. For now just consume and discard.
        }

        return self::SUCCESS;
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
