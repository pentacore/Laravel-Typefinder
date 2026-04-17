<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Cache;

/**
 * Extractor result cache keyed by absolute file path. Entries track the
 * file's mtime and size at extraction time; lookups compare those against
 * the current on-disk values to detect stale entries. Top-level
 * invalidation (composer.lock change, config change) is enforced by the
 * static {@see self::load()} factory, which returns an empty cache if the
 * persisted hashes don't match the expected ones.
 *
 * @phpstan-type Entry array{category: string, mtime: int, size: int, extraction: array<string, mixed>}
 */
final class ExtractionCache
{
    public const int SCHEMA_VERSION = 1;

    /** @var array<string, Entry> */
    private array $entries = [];

    public function __construct(
        private readonly string $path,
        private readonly string $composerLockHash,
        private readonly string $configHash,
    ) {}

    public static function load(string $path, string $composerLockHash, string $configHash): self
    {
        $cache = new self($path, $composerLockHash, $configHash);

        if (! is_file($path)) {
            return $cache;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            return $cache;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $cache;
        }

        if (! is_array($decoded)) {
            return $cache;
        }

        if (($decoded['composer_lock_hash'] ?? null) !== $composerLockHash) {
            return $cache;
        }

        if (($decoded['config_hash'] ?? null) !== $configHash) {
            return $cache;
        }

        if (($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return $cache;
        }

        $entries = $decoded['entries'] ?? [];
        if (is_array($entries)) {
            /** @var array<string, Entry> $entries */
            $cache->entries = $entries;
        }

        $cache->entries = array_filter(
            $cache->entries,
            fn (array $entry, string $path): bool => is_file($path),
            ARRAY_FILTER_USE_BOTH,
        );

        return $cache;
    }

    /**
     * @param  array<string, mixed>  $extraction
     */
    public function put(string $absolutePath, string $category, array $extraction): void
    {
        clearstatcache(true, $absolutePath);
        $mtime = @filemtime($absolutePath);
        $size = @filesize($absolutePath);

        if ($mtime === false || $size === false) {
            return;
        }

        $this->entries[$absolutePath] = [
            'category' => $category,
            'mtime' => $mtime,
            'size' => $size,
            'extraction' => $extraction,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(string $absolutePath): ?array
    {
        $entry = $this->entries[$absolutePath] ?? null;
        if ($entry === null) {
            return null;
        }

        clearstatcache(true, $absolutePath);
        $mtime = @filemtime($absolutePath);
        $size = @filesize($absolutePath);

        if ($mtime !== $entry['mtime'] || $size !== $entry['size']) {
            return null;
        }

        return $entry['extraction'];
    }

    public function forget(string $absolutePath): void
    {
        unset($this->entries[$absolutePath]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function entriesByCategory(string $category): array
    {
        $out = [];
        foreach ($this->entries as $path => $entry) {
            if ($entry['category'] === $category) {
                $out[$path] = $entry['extraction'];
            }
        }

        return $out;
    }

    public function persist(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->entries = array_filter(
            $this->entries,
            fn (array $entry, string $path): bool => is_file($path),
            ARRAY_FILTER_USE_BOTH,
        );

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'composer_lock_hash' => $this->composerLockHash,
            'config_hash' => $this->configHash,
            'entries' => $this->entries,
        ];

        $tmp = $this->path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $bytes = @file_put_contents($tmp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($bytes === false) {
            @unlink($tmp);

            return;
        }

        if (! @rename($tmp, $this->path)) {
            @unlink($tmp);
        }
    }
}
