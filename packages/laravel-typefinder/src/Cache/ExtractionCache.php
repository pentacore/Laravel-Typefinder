<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Cache;

/**
 * What each source file last extracted to, per category and absolute file
 * path. The generator emits from these entries, so after a run they describe
 * every type in the output.
 *
 * A file that yields no type (an ignored class, a helper) is recorded with a
 * null extraction: it is known and need not be read again until it changes.
 * Entries track the file's mtime and size at extraction time, and those of
 * the files it builds on (parent classes, traits, cast classes);
 * {@see self::isFresh()} compares those against the files on disk.
 *
 * Top-level invalidation (composer.lock change, config change, database
 * schema change) is enforced by the static {@see self::load()} factory, which
 * returns an empty cache if the persisted hashes don't match the expected ones.
 *
 * @phpstan-type Entry array{mtime: int, size: int, extraction: array<string, mixed>|null, deps?: array<string, array{0: int, 1: int}>}
 */
final class ExtractionCache
{
    public const int SCHEMA_VERSION = 3;

    /** @var array<string, array<string, Entry>> entries by category, then by path */
    private array $entries = [];

    private bool $restored = false;

    /** @var array<string, array<string, Entry>> the entries as they were persisted, deleted files included */
    private array $persisted = [];

    public function __construct(
        private readonly string $path,
        private readonly string $composerLockHash,
        private readonly string $configHash,
        private readonly string $schemaHash = '',
    ) {}

    public static function load(string $path, string $composerLockHash, string $configHash, string $schemaHash = ''): self
    {
        $cache = new self($path, $composerLockHash, $configHash, $schemaHash);

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

        if (($decoded['schema_hash'] ?? null) !== $schemaHash) {
            return $cache;
        }

        if (($decoded['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            return $cache;
        }

        $entries = $decoded['entries'] ?? [];
        if (! is_array($entries)) {
            return $cache;
        }

        /** @var array<string, array<string, Entry>> $entries */
        $cache->entries = $entries;
        $cache->persisted = $entries;
        $cache->dropMissingFiles();
        $cache->restored = true;

        return $cache;
    }

    /**
     * Whether the entries come from a previous run. When false the cache
     * says nothing about the output on disk.
     */
    public function restored(): bool
    {
        return $this->restored;
    }

    /**
     * @param  array<string, mixed>|null  $extraction  null when the file yields no type
     * @param  list<string>  $dependencies  other files the extraction depends on
     */
    public function put(string $absolutePath, string $category, ?array $extraction, array $dependencies = []): void
    {
        clearstatcache(true, $absolutePath);
        $mtime = @filemtime($absolutePath);
        $size = @filesize($absolutePath);

        if ($mtime === false || $size === false) {
            return;
        }

        $deps = [];
        foreach ($dependencies as $dependency) {
            clearstatcache(true, $dependency);
            $deps[$dependency] = [(int) @filemtime($dependency), (int) @filesize($dependency)];
        }

        $this->entries[$category][$absolutePath] = [
            'mtime' => $mtime,
            'size' => $size,
            'extraction' => $extraction,
        ] + ($deps === [] ? [] : ['deps' => $deps]);
    }

    /**
     * Whether the file, and every file it was recorded as depending on, is
     * unchanged since it was last recorded for the category.
     */
    public function isFresh(string $absolutePath, string $category): bool
    {
        $entry = $this->entries[$category][$absolutePath] ?? null;
        if ($entry === null) {
            return false;
        }

        clearstatcache(true, $absolutePath);
        if (@filemtime($absolutePath) !== $entry['mtime'] || @filesize($absolutePath) !== $entry['size']) {
            return false;
        }

        foreach ($entry['deps'] ?? [] as $dependency => [$mtime, $size]) {
            clearstatcache(true, $dependency);
            if ((int) @filemtime($dependency) !== $mtime || (int) @filesize($dependency) !== $size) {
                return false;
            }
        }

        return true;
    }

    public function forget(string $absolutePath, string $category): void
    {
        unset($this->entries[$category][$absolutePath]);
    }

    /**
     * The last extraction of every file that yields a type, fresh or not.
     *
     * @return array<string, array<string, mixed>>
     */
    public function entriesByCategory(string $category): array
    {
        $out = [];
        foreach ($this->entries[$category] ?? [] as $path => $entry) {
            if ($entry['extraction'] !== null) {
                $out[$path] = $entry['extraction'];
            }
        }

        return $out;
    }

    /**
     * The extractions of the previous run, of files that have since been
     * deleted too: what the output was generated from.
     *
     * @return array<string, array<string, mixed>>
     */
    public function persistedEntriesByCategory(string $category): array
    {
        return array_filter(array_map(
            static fn (array $entry): ?array => $entry['extraction'],
            $this->persisted[$category] ?? [],
        ));
    }

    public function persist(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $this->dropMissingFiles();

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'composer_lock_hash' => $this->composerLockHash,
            'config_hash' => $this->configHash,
            'schema_hash' => $this->schemaHash,
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

    private function dropMissingFiles(): void
    {
        foreach ($this->entries as $category => $entries) {
            $this->entries[$category] = array_filter(
                $entries,
                is_file(...),
                ARRAY_FILTER_USE_KEY,
            );
        }
    }
}
