<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Services;

use Pentacore\Typefinder\Analysis\PageMerger;
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;
use Pentacore\Typefinder\Cache\CacheKeyFactory;
use Pentacore\Typefinder\Cache\ExtractionCache;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\MorphToResolver;
use Pentacore\Typefinder\Resolvers\PivotResolver;
use Pentacore\Typefinder\Support\Categories;
use Pentacore\Typefinder\Support\ClassDiscovery;
use Pentacore\Typefinder\TypefinderRegistry;
use ReflectionAttribute;
use ReflectionClass;

/**
 * Runs generation. A run has two steps: bring the {@see ExtractionCache} up
 * to date with the source files, then emit the whole output tree from it.
 *
 * A full run extracts every file. An incremental run extracts the files it
 * was given plus any that changed since they were last extracted. The emit
 * step is the same for both, so they leave the same output.
 */
final class Generator
{
    public function __construct(
        private readonly TypefinderRegistry $typefinderRegistry,
        private readonly TypeScriptRenderer $typeScriptRenderer,
        private readonly CacheKeyFactory $cacheKeyFactory,
        private readonly string $cachePath,
        /** @var null|callable(string): void */
        private $onWarn = null,
    ) {}

    /**
     * Incremental regeneration: extract the given PHP files again, then
     * rewrite whatever in the output that changes. Files that changed without
     * being listed are picked up too. Falls back to a full run when $paths is
     * empty or no cache of a previous run is usable.
     *
     * A file that cannot be extracted is reported in the result's `failed`
     * and keeps the type it had.
     *
     * @param  list<string>  $paths  Absolute paths. Empty = full regen.
     */
    public function generatePaths(array $paths): RegenResult
    {
        return $this->run($paths);
    }

    /**
     * Run the full generation pipeline using values from config('typefinder').
     * Returns a RegenResult with relative paths of files that changed,
     * any warnings collected, and duration.
     */
    public function generateFull(): RegenResult
    {
        return $this->run([]);
    }

    /**
     * @param  list<string>  $paths  files to extract again; empty for all of them
     */
    private function run(array $paths): RegenResult
    {
        $startedAt = microtime(true);
        $config = (array) config('typefinder');
        $warnings = [];
        $failed = [];

        $warn = function (string $message) use (&$warnings): void {
            $warnings[] = $message;
            if ($this->onWarn !== null) {
                ($this->onWarn)($message);
            }
        };

        $lockHash = $this->cacheKeyFactory->composerLockHash();
        $configHash = $this->cacheKeyFactory->configHash($config);

        $extractionCache = $paths === [] ? null : ExtractionCache::load($this->cachePath, $lockHash, $configHash);
        // Without the entries of a previous run nothing is known about the
        // other types, so every file is extracted.
        $incremental = $extractionCache instanceof ExtractionCache && $extractionCache->restored();
        if (! $incremental) {
            $extractionCache = new ExtractionCache($this->cachePath, $lockHash, $configHash);
        }

        $roots = Categories::roots();
        $pending = [];

        foreach ($paths as $path) {
            $file = Categories::realPath($path);
            $categories = array_keys(array_filter($roots, fn (array $dirs): bool => $this->rootIndex($file, $dirs) !== null));

            if ($categories === []) {
                $warnings[] = $path.': outside any configured typefinder category — ignored';
            }

            foreach ($categories as $category) {
                $pending[$category][$file] = true;
            }
        }

        // Every file the cache has no fresh entry for: all of them in a full
        // run, otherwise those that changed without being listed (a git pull,
        // an edit while nothing was watching).
        foreach ($roots as $category => $dirs) {
            foreach ($this->sourceFiles($dirs) as $file) {
                if (! $extractionCache->isFresh($file, $category)) {
                    $pending[$category][$file] = true;
                }
            }
        }

        $fileExtractor = new FileExtractor($this->typefinderRegistry, $extractionCache, $warn);

        foreach (array_keys($roots) as $category) {
            foreach (array_keys($pending[$category] ?? []) as $file) {
                if (! is_file($file)) {
                    $extractionCache->forget($file, $category);

                    continue;
                }

                try {
                    $extraction = $fileExtractor->extract($file, $category);

                    if ($incremental && $extraction !== null) {
                        // Reject the one file that breaks the set, so the rest can still be emitted.
                        $this->assertValid($category, array_values([...$extractionCache->entriesByCategory($category), $file => $extraction]));
                    }

                    $extractionCache->put($file, $category, $extraction);
                } catch (\Throwable $throwable) {
                    if (! $incremental) {
                        throw $throwable;
                    }

                    $failed[] = ['path' => $file, 'message' => $throwable->getMessage()];
                }
            }
        }

        $changed = $this->emit($extractionCache, $roots, $warn);
        $extractionCache->persist();

        return new RegenResult(
            changed: $changed,
            warnings: $warnings,
            failed: $failed,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * Write the output tree for the types in the cache: every file of every
     * enabled category, the barrels, and nothing else in their directories.
     *
     * @param  array<string, list<string>>  $roots
     * @param  callable(string): void  $warn
     * @return list<string> relative paths of the files that changed
     */
    private function emit(ExtractionCache $extractionCache, array $roots, callable $warn): array
    {
        $sets = array_fill_keys(Categories::ALL, []);
        foreach ($roots as $category => $dirs) {
            $sets[$category] = $this->inSourceOrder($extractionCache->entriesByCategory($category), $dirs);
            $this->assertValid($category, $sets[$category]);
        }

        $enums = $sets['enums'];
        $models = new MorphToResolver()->resolve($sets['models']);
        $pivots = new PivotResolver()->resolve($models);
        $requests = $sets['requests'];
        $resources = $sets['resources'];
        $pages = PageMerger::merge($this->pagesOf($sets['inertia']));
        $broadcasts = $sets['broadcasting'];

        $outputTree = new OutputTree((string) config('typefinder.output_path'));
        $files = [];

        $emitWriteShapes = (bool) config('typefinder.models.emit_write_shapes', true);
        $globalImmutable = (array) config('typefinder.models.immutable_on_update', ['id', 'created_at', 'updated_at', 'deleted_at']);
        foreach ($models as $model) {
            $immutable = array_values(array_unique([...$globalImmutable, ...$this->getContractImmutable($model['fqcn'])]));
            $files['models'][$model['name']] = $this->typeScriptRenderer->renderModelFile($model, $enums, $models, $emitWriteShapes, $immutable);
        }

        foreach ($pivots as $pivot) {
            $files['models'][$pivot['name']] = $this->typeScriptRenderer->renderPivot($pivot);
        }

        $extractNested = (bool) config('typefinder.requests.extract_nested', false);
        foreach ($requests as $request) {
            $files['requests'][$request['name']] = $this->typeScriptRenderer->renderRequest($request, $enums, $extractNested);
        }

        foreach ($resources as $resource) {
            $files['resources'][$resource['name']] = $this->typeScriptRenderer->renderResource($resource, $models, $enums, $resources);
        }

        $categories = [];
        // What each category barrel exports, for the cross-category check.
        $barrelNames = [];

        foreach (['enums', 'models', 'requests', 'resources'] as $category) {
            if (! isset($roots[$category])) {
                continue;
            }

            if ($sets[$category] === []) {
                $outputTree->removeDirectory($category);

                continue;
            }

            $barrelNames[$category] = $category === 'enums'
                ? $this->writeEnums($enums, $outputTree)
                : $this->writeCategory($category, $files[$category], $outputTree, $warn);
            $categories[] = $category;
        }

        $singleFiles = [
            'inertia' => ['pages', $pages, fn (): string => $this->typeScriptRenderer->renderPages($pages, $models, $enums, $resources)],
            'broadcasting' => ['broadcasting', $broadcasts, fn (): string => $this->typeScriptRenderer->renderBroadcasting($broadcasts, $models, $enums, $resources)],
        ];

        foreach ($singleFiles as $category => [$name, $entries, $render]) {
            if (! isset($roots[$category])) {
                continue;
            }

            if ($entries === []) {
                $outputTree->remove($name.'.d.ts');

                continue;
            }

            $outputTree->write($name.'.d.ts', $render());
            $categories[] = $name;
        }

        $this->warnOnCrossCategoryNames($barrelNames, $warn);

        $outputTree->write('helpers.d.ts', $this->typeScriptRenderer->renderHelpers());
        $categories[] = 'helpers';

        $outputTree->write('index.d.ts', $this->typeScriptRenderer->renderTopLevelBarrel($categories));

        return $outputTree->changed();
    }

    /**
     * Fail when the extractions of a category cannot be emitted together.
     *
     * @param  list<array<string, mixed>>  $extractions
     */
    private function assertValid(string $category, array $extractions): void
    {
        match ($category) {
            'models' => $this->assertUniqueTypeNames('models', [
                ...$extractions,
                ...array_map(fn (array $pivot): array => ['name' => $pivot['name'], 'fqcn' => 'pivot table of '.$pivot['name']], new PivotResolver()->resolve($extractions)),
            ]),
            'inertia' => PageMerger::merge($this->pagesOf($extractions)),
            'broadcasting' => $this->assertNoBroadcastCollisions($extractions),
            default => $this->assertUniqueTypeNames($category, $extractions),
        };
    }

    /**
     * The `#[TypefinderPage]` entries of every controller, in one list.
     *
     * @param  list<array{pages: list<array>}>  $controllers
     * @return list<array>
     */
    private function pagesOf(array $controllers): array
    {
        return array_merge([], ...array_column($controllers, 'pages'));
    }

    /**
     * The PHP files under the directories: directory by directory, by name
     * within each. Extraction and emission both follow this order, so a run
     * does not depend on the filesystem or on which files it was given.
     *
     * @param  list<string>  $dirs
     * @return list<string> real paths
     */
    private function sourceFiles(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            foreach (ClassDiscovery::files($dir) as $file) {
                $files[$file] = true;
            }
        }

        return array_keys($files);
    }

    /**
     * Cache entries in the order {@see self::sourceFiles()} lists their files.
     *
     * @param  array<string, array<string, mixed>>  $entries  extractions by file path
     * @param  list<string>  $dirs
     * @return list<array<string, mixed>>
     */
    private function inSourceOrder(array $entries, array $dirs): array
    {
        $rank = [];
        foreach (array_keys($entries) as $file) {
            $rank[$file] = $this->rootIndex($file, $dirs) ?? PHP_INT_MAX;
        }

        uksort($entries, fn (string $a, string $b): int => $rank[$a] <=> $rank[$b] ?: strcmp($a, $b));

        return array_values($entries);
    }

    /**
     * Index of the first directory the file is in, or null when it is in none.
     *
     * @param  list<string>  $dirs
     */
    private function rootIndex(string $file, array $dirs): ?int
    {
        foreach ($dirs as $index => $dir) {
            if (str_starts_with($file, $dir.'/')) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @return list<string> the type names the category barrel exports
     */
    private function writeEnums(array $enums, OutputTree $outputTree): array
    {
        $emitValues = (bool) config('typefinder.enums.emit_values', false);
        $ext = $emitValues ? 'ts' : 'd.ts';

        $names = [];
        foreach ($enums as $enum) {
            $outputTree->write(sprintf('enums/%s.%s', $enum['name'], $ext), $this->typeScriptRenderer->renderEnum($enum, $emitValues));
            $names[] = $enum['name'];
        }

        $outputTree->prune('enums', array_map(fn (string $name): string => $name.'.'.$ext, [...$names, 'index']));

        $outputTree->write('enums/index.'.$ext, $emitValues
            ? $this->renderValueBarrel($names)
            : $this->typeScriptRenderer->renderBarrelIndex($names));

        return $names;
    }

    /**
     * @param  list<string>  $names
     */
    private function renderValueBarrel(array $names): string
    {
        sort($names, SORT_STRING);

        $lines = array_map(
            fn (string $n): string => sprintf("export * from './%s';", $n),
            $names,
        );

        return TypeScriptRenderer::FILE_HEADER."\n".implode("\n", $lines)."\n";
    }

    /**
     * @param  class-string  $fqcn
     * @return list<string>
     */
    private function getContractImmutable(string $fqcn): array
    {
        $attrs = new ReflectionClass($fqcn)
            ->getAttributes(TypefinderWriteShape::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs === []) {
            return [];
        }

        return $attrs[0]->newInstance()->immutableOnUpdate;
    }

    /**
     * Write the files of a category directory and its barrel, and remove
     * every other file in it.
     *
     * @param  array<string, string>  $files  file contents by type name
     * @param  callable(string): void  $warn
     * @return list<string> the type names the category barrel exports
     */
    private function writeCategory(string $category, array $files, OutputTree $outputTree, callable $warn): array
    {
        $exports = [];
        foreach ($files as $name => $content) {
            $outputTree->write(sprintf('%s/%s.d.ts', $category, $name), $content);
            $exports[$name] = TypeScriptRenderer::exportedTypeNames($content, (string) $name);
        }

        $outputTree->prune($category, array_map(fn (string $name): string => $name.'.d.ts', [...array_map(strval(...), array_keys($exports)), 'index']));

        // Every type of every file is re-exported.
        $exports = $this->barrelExports($category, $exports, $warn);
        $outputTree->write($category.'/index.d.ts', $this->typeScriptRenderer->renderBarrelIndex($exports));

        return array_merge([], ...array_values($exports));
    }

    /**
     * What a category barrel re-exports from each file. A name that several
     * files export can only be re-exported once: the file named after it
     * keeps it, and when there is none the barrel leaves the name out. The
     * other types stay importable from their own file.
     *
     * @param  array<string, list<string>>  $exportsByFile
     * @return array<string, list<string>>
     */
    private function barrelExports(string $category, array $exportsByFile, callable $warn): array
    {
        $filesByName = [];
        foreach ($exportsByFile as $file => $names) {
            foreach ($names as $name) {
                $filesByName[$name][] = (string) $file;
            }
        }

        ksort($filesByName, SORT_STRING);

        foreach ($filesByName as $name => $files) {
            if (count($files) < 2) {
                continue;
            }

            sort($files, SORT_STRING);
            $owner = in_array((string) $name, $files, true) ? (string) $name : null;

            foreach ($files as $file) {
                if ($file !== $owner) {
                    $exportsByFile[$file] = array_values(array_diff($exportsByFile[$file], [$name]));
                }
            }

            $warn(sprintf(
                '%s: %s is exported by %s. The barrel re-exports %s.',
                $category,
                $name,
                implode(' and ', array_map(fn (string $file): string => $file.'.d.ts', $files)),
                $owner === null
                    ? (count($files) === 2 ? 'neither' : 'none of them').'; import it from its file'
                    : sprintf('the one from %s.d.ts; import any other from its file', $owner),
            ));
        }

        return $exportsByFile;
    }

    /**
     * Fail when two classes in one category would be emitted under the same
     * type name. One file would silently overwrite the other and the barrel
     * would export the name twice.
     *
     * @param  list<array{name: string, fqcn: string}>  $extractions
     */
    private function assertUniqueTypeNames(string $category, array $extractions): void
    {
        $byName = [];
        foreach ($extractions as $extraction) {
            $byName[$extraction['name']][] = $extraction['fqcn'];
        }

        $conflicts = array_filter($byName, fn (array $fqcns): bool => count($fqcns) > 1);
        if ($conflicts === []) {
            return;
        }

        ksort($conflicts);
        $lines = [];
        foreach ($conflicts as $name => $fqcns) {
            $lines[] = sprintf('  %s: %s', $name, implode(', ', $fqcns));
        }

        throw new \RuntimeException(sprintf(
            "Several %s would be emitted under the same type name:\n%s\n"
            .'Rename one, mark it #[TypefinderIgnore], or narrow typefinder.%s.paths.',
            $category,
            implode("\n", $lines),
            $category,
        ));
    }

    /**
     * Warn when two categories emit the same type name. Each category barrel
     * stays valid, but the top-level index.d.ts re-exports both, which
     * TypeScript reports as ambiguous (TS2308).
     *
     * @param  array<string, list<string>>  $byCategory  the type names each category barrel exports
     */
    private function warnOnCrossCategoryNames(array $byCategory, callable $warn): void
    {
        $categoriesByName = [];
        foreach ($byCategory as $category => $names) {
            foreach ($names as $name) {
                $categoriesByName[$name][$category] = true;
            }
        }

        ksort($categoriesByName);
        foreach ($categoriesByName as $name => $categories) {
            if (count($categories) < 2) {
                continue;
            }

            $warn(sprintf(
                '%s is emitted by both %s. The top-level index.d.ts re-exports both, so import it from its category barrel instead.',
                $name,
                implode(' and ', array_keys($categories)),
            ));
        }
    }

    /**
     * @param  list<array{event_class: string, broadcast_name: string, channels: list<array{type: string, name: string}>}>  $events
     */
    private function assertNoBroadcastCollisions(array $events): void
    {
        $byKey = [];
        foreach ($events as $event) {
            foreach ($event['channels'] as $channel) {
                $key = sprintf('%s:%s:%s', $channel['type'], $channel['name'], $event['broadcast_name']);
                $byKey[$key][] = $event['event_class'];
            }
        }

        $conflicts = array_filter($byKey, fn (array $classes): bool => count($classes) > 1);
        if ($conflicts === []) {
            return;
        }

        $lines = [];
        foreach ($conflicts as $key => $classes) {
            $lines[] = $key.': '.implode(', ', $classes);
        }

        throw new \RuntimeException("Duplicate broadcast event on channel:\n".implode("\n", $lines));
    }
}
