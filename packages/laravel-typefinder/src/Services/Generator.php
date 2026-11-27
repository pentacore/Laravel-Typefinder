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
use Pentacore\Typefinder\Support\ThrowableMessage;
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
    /**
     * Categories whose extractions depend on which models and enums get a
     * type (a PHPDoc type names them: page props, broadcast payloads).
     */
    private const array READ_GENERATED_CLASSES = ['resources', 'inertia', 'broadcasting'];

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
        $schemaHash = $this->cacheKeyFactory->schemaHash();

        $extractionCache = $paths === [] ? null : ExtractionCache::load($this->cachePath, $lockHash, $configHash, $schemaHash);
        // Without the entries of a previous run nothing is known about the
        // other types, so every file is extracted.
        $incremental = $extractionCache instanceof ExtractionCache && $extractionCache->restored();
        if (! $incremental) {
            $extractionCache = new ExtractionCache($this->cachePath, $lockHash, $configHash, $schemaHash);
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
        $generatedBefore = $this->generatedClasses($extractionCache->persistedEntriesByCategory(...));

        foreach (array_keys($roots) as $category) {
            // Resources infer their model from the models that get a type, and
            // drift checks read them too. When that set changed, the cached
            // entries of these categories may be wrong (#176).
            if (in_array($category, self::READ_GENERATED_CLASSES, true) && $this->generatedClasses($extractionCache->entriesByCategory(...)) !== $generatedBefore) {
                foreach ($this->sourceFiles($roots[$category]) as $file) {
                    $pending[$category][$file] = true;
                }
            }

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

                    $extractionCache->put($file, $category, $extraction, $fileExtractor->dependencies($file, $category));
                } catch (\Throwable $throwable) {
                    if (! $incremental) {
                        throw $throwable;
                    }

                    $failed[] = ['path' => $file, 'message' => ThrowableMessage::describe($throwable)];
                }
            }

            if (! $incremental) {
                // An incremental run checked each file as it was extracted.
                $this->assertValid($category, $this->inSourceOrder($extractionCache->entriesByCategory($category), $roots[$category]));
            }
        }

        $outputTree = new OutputTree((string) config('typefinder.output_path'));
        $counts = $this->emit($extractionCache, $roots, $outputTree, $warn);
        $extractionCache->persist();

        return new RegenResult(
            changed: $outputTree->changed(),
            warnings: $warnings,
            failed: $failed,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
            removed: $outputTree->removed(),
            counts: $counts,
        );
    }

    /**
     * Write the output tree for the types in the cache, in one pass over
     * {@see Categories::ALL}: every file of every enabled category with the
     * barrels, and nothing else in their directories. A category that is
     * disabled or has no types leaves no output.
     *
     * @param  array<string, list<string>>  $roots
     * @param  callable(string): void  $warn
     * @return array<string, int> the number of types each output directory or file holds
     */
    private function emit(ExtractionCache $extractionCache, array $roots, OutputTree $outputTree, callable $warn): array
    {
        $this->typeScriptRenderer->onWarn($warn);

        $sets = array_fill_keys(Categories::ALL, []);
        foreach ($roots as $category => $dirs) {
            $sets[$category] = $this->inSourceOrder($extractionCache->entriesByCategory($category), $dirs);
        }

        $enums = $sets['enums'];
        $models = new MorphToResolver()->resolve($sets['models']);
        $resources = $sets['resources'];

        $emitted = [];
        $counts = [];
        // What each category barrel exports, for the cross-category check.
        $barrelNames = [];

        foreach (Categories::ALL as $category) {
            [$kind, $name] = Categories::OUTPUT[$category];
            [$files, $count] = isset($roots[$category])
                ? $this->render($category, $sets[$category], $enums, $models, $resources)
                : [[], 0];

            if ($files === []) {
                $kind === 'directory' ? $outputTree->removeDirectory($name) : $outputTree->remove($name.'.d.ts');

                continue;
            }

            if ($kind === 'directory') {
                $barrelNames[$category] = $this->writeDirectory($category, $files, $outputTree, $warn);
            } else {
                $outputTree->write($name.'.d.ts', $files[$name]);
            }

            $emitted[] = $name;
            $counts[$name] = $count;
        }

        $this->warnOnCrossCategoryNames($barrelNames, $warn);

        $outputTree->write('helpers.d.ts', $this->typeScriptRenderer->renderHelpers());
        $emitted[] = 'helpers';

        $outputTree->write('index.d.ts', $this->typeScriptRenderer->renderTopLevelBarrel($emitted));

        return $counts;
    }

    /**
     * The files of one category and the number of types they hold.
     *
     * @param  list<array<string, mixed>>  $entries  the category's extractions, in source order
     * @param  list<array<string, mixed>>  $enums
     * @param  list<array<string, mixed>>  $models  with their morph targets resolved
     * @param  list<array<string, mixed>>  $resources
     * @return array{0: array<string, string>, 1: int} file contents by name (a type name, or the single file's name), and the count
     */
    private function render(string $category, array $entries, array $enums, array $models, array $resources): array
    {
        $files = [];

        switch ($category) {
            case 'enums':
                $emitValues = (bool) config('typefinder.enums.emit_values', false);
                foreach ($enums as $enum) {
                    $files[$enum['name']] = $this->typeScriptRenderer->renderEnum($enum, $emitValues);
                }

                return [$files, count($files)];

            case 'models':
                $emitWriteShapes = (bool) config('typefinder.models.emit_write_shapes', true);
                $globalImmutable = (array) config('typefinder.models.immutable_on_update', ['id', 'created_at', 'updated_at', 'deleted_at']);
                foreach ($models as $model) {
                    $immutable = array_values(array_unique([...$globalImmutable, ...$this->getContractImmutable($model['fqcn'])]));
                    $files[$model['name']] = $this->typeScriptRenderer->renderModelFile($model, $enums, $models, $emitWriteShapes, $immutable, $resources);
                }

                foreach (new PivotResolver()->resolve($models) as $pivot) {
                    $files[$pivot['name']] = $this->typeScriptRenderer->renderPivot($pivot);
                }

                return [$files, count($files)];

            case 'requests':
                $extractNested = (bool) config('typefinder.requests.extract_nested', false);
                foreach ($entries as $request) {
                    $files[$request['name']] = $this->typeScriptRenderer->renderRequest($request, $enums, $extractNested);
                }

                return [$files, count($files)];

            case 'resources':
                foreach ($resources as $resource) {
                    $files[$resource['name']] = $this->typeScriptRenderer->renderResource($resource, $models, $enums, $resources);
                }

                return [$files, count($files)];

            case 'inertia':
                $pages = PageMerger::merge($this->pagesOf($entries));

                // Written with no tagged page too: `SharedPageProps` is useful on its own (#231).
                return [['pages' => $this->typeScriptRenderer->renderPages($pages, $models, $enums, $resources)], count($pages)];

            case 'broadcasting':
                return $entries === []
                    ? [[], 0]
                    : [['broadcasting' => $this->typeScriptRenderer->renderBroadcasting($entries, $models, $enums, $resources)], count($entries)];
        }

        return [[], 0];
    }

    /**
     * Write the files of a category directory and its barrel, and remove
     * every other file in it.
     *
     * @param  array<string, string>  $files  file contents by type name
     * @param  callable(string): void  $warn
     * @return list<string> the type names the category barrel exports
     */
    private function writeDirectory(string $category, array $files, OutputTree $outputTree, callable $warn): array
    {
        $emitValues = $category === 'enums' && (bool) config('typefinder.enums.emit_values', false);
        $ext = $emitValues ? 'ts' : 'd.ts';

        $exports = [];
        foreach ($files as $name => $content) {
            $outputTree->write(sprintf('%s/%s.%s', $category, $name, $ext), $content);
            // An enum file is named after its one type.
            $exports[$name] = $category === 'enums' ? [(string) $name] : TypeScriptRenderer::exportedTypeNames($content, (string) $name);
        }

        $names = array_map(strval(...), array_keys($exports));
        $outputTree->prune($category, array_map(fn (string $name): string => $name.'.'.$ext, [...$names, 'index']));

        if ($category === 'enums') {
            $outputTree->write('enums/index.'.$ext, $emitValues
                ? $this->renderValueBarrel($names)
                : $this->typeScriptRenderer->renderBarrelIndex($names));

            return $names;
        }

        // Every type of every file is re-exported.
        $exports = $this->barrelExports($category, $exports, $warn);
        $outputTree->write($category.'/index.d.ts', $this->typeScriptRenderer->renderBarrelIndex($exports));

        return array_merge([], ...array_values($exports));
    }

    /**
     * The classes of the categories other extractions refer to that get a type.
     *
     * @param  callable(string): array<string, array<string, mixed>>  $entriesByCategory
     * @return array<string, list<string>>
     */
    private function generatedClasses(callable $entriesByCategory): array
    {
        $classes = [];
        foreach (['models', 'enums'] as $category) {
            $classes[$category] = array_values(array_column($entriesByCategory($category), 'fqcn'));
            sort($classes[$category]);
        }

        return $classes;
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
            if (Categories::contains($dir, $file)) {
                return $index;
            }
        }

        return null;
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
            .'Rename one, mark it #[TypefinderIgnore], or narrow typefinder.%s.paths.%s',
            $category,
            implode("\n", $lines),
            $category,
            $category === 'requests'
                ? " Or key the paths to prefix the type names: ['Api' => app_path('Http/Requests/Api'), 'Web' => app_path('Http/Requests/Web')]."
                : '',
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
     * Fail when two events share a broadcast name: `BroadcastEvents` is keyed
     * by name alone, whatever their channels (#166).
     *
     * @param  list<array{event_class: string, broadcast_name: string}>  $events
     */
    private function assertNoBroadcastCollisions(array $events): void
    {
        $byName = [];
        foreach ($events as $event) {
            $byName[$event['broadcast_name']][] = $event['event_class'];
        }

        $conflicts = array_filter($byName, fn (array $classes): bool => count($classes) > 1);
        if ($conflicts === []) {
            return;
        }

        ksort($conflicts);
        $lines = [];
        foreach ($conflicts as $name => $classes) {
            $lines[] = sprintf('  %s: %s', $name, implode(', ', $classes));
        }

        throw new \RuntimeException(
            "Several broadcast events would be emitted under the same name:\n".implode("\n", $lines)."\n"
            .'Give one a distinct broadcastAs() or #[TypefinderBroadcast(as: …)], or mark it #[TypefinderIgnore].',
        );
    }
}
