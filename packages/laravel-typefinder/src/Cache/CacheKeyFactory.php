<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Cache;

/**
 * Produces the two top-level cache invalidation keys: a hash of the host
 * app's composer.lock (catches dependency upgrades) and a hash of the
 * resolved typefinder config (catches config edits after app_path() etc.
 * resolve to absolute paths).
 *
 * Key order is normalised recursively before hashing so semantically
 * identical configs produce identical hashes regardless of array order.
 */
final readonly class CacheKeyFactory
{
    /** Top-level config keys that do not change what a source file extracts to. */
    private const array OUTPUT_ONLY = ['output_path', 'gitignore_generated'];

    public function __construct(
        private string $basePath,
    ) {}

    public function composerLockHash(): string
    {
        $path = $this->basePath.'/composer.lock';
        if (! is_file($path)) {
            return 'sha256:missing';
        }

        return 'sha256:'.hash_file('sha256', $path);
    }

    /**
     * Hash of the `typefinder` config, less the keys that only say where the
     * output goes. Extractions do not depend on those, so a run that writes
     * to another directory (`--check`) keeps the cache usable.
     *
     * @param  array<string, mixed>  $config
     */
    public function configHash(array $config): string
    {
        $normalised = $this->normalise(array_diff_key($config, array_flip(self::OUTPUT_ONLY)));

        return 'sha256:'.hash('sha256', json_encode($normalised, JSON_THROW_ON_ERROR));
    }

    private function normalise(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $out = [];
        $keys = array_keys($value);
        sort($keys);
        foreach ($keys as $key) {
            $out[$key] = $this->normalise($value[$key]);
        }

        return $out;
    }
}
