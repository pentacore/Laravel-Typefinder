<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Services;

use Illuminate\Support\Facades\File;

/**
 * The generated files under the output path. Writes only what differs from
 * disk and records which paths a run changed: written and removed alike.
 */
final class OutputTree
{
    /** @var list<string> */
    private array $changed = [];

    /** @var list<string> */
    private array $removed = [];

    public function __construct(private readonly string $root) {}

    public function write(string $relativePath, string $content): void
    {
        $path = $this->root.'/'.$relativePath;
        if (File::exists($path) && File::get($path) === $content) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, $content);
        $this->changed[] = $relativePath;
    }

    /**
     * Remove a generated file, if it is there.
     */
    public function remove(string $relativePath): void
    {
        $path = $this->root.'/'.$relativePath;
        if (! is_file($path)) {
            return;
        }

        File::delete($path);
        $this->changed[] = $relativePath;
        $this->removed[] = $relativePath;
    }

    /**
     * Delete every file in a directory of the tree except the named ones.
     *
     * @param  list<string>  $keep  file names
     */
    public function prune(string $directory, array $keep): void
    {
        $dir = $this->root.'/'.$directory;
        if (! is_dir($dir)) {
            return;
        }

        $kept = array_fill_keys($keep, true);

        foreach (File::files($dir) as $file) {
            if (! isset($kept[$file->getFilename()])) {
                $this->remove($directory.'/'.$file->getFilename());
            }
        }
    }

    /**
     * Remove a directory of the tree and the files in it.
     */
    public function removeDirectory(string $directory): void
    {
        $this->prune($directory, []);
        @rmdir($this->root.'/'.$directory);
    }

    /**
     * @return list<string> paths relative to the output path, written or removed
     */
    public function changed(): array
    {
        return array_values(array_unique($this->changed));
    }

    /**
     * @return list<string> paths relative to the output path that were removed
     */
    public function removed(): array
    {
        return array_values(array_unique($this->removed));
    }
}
