<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Services;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;
use Pentacore\Typefinder\Cache\CacheKeyFactory;
use Pentacore\Typefinder\Cache\ExtractionCache;
use Pentacore\Typefinder\Extractors\BroadcastExtractor;
use Pentacore\Typefinder\Extractors\ControllerExtractor;
use Pentacore\Typefinder\Extractors\EnumExtractor;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\Resolvers\MorphToResolver;
use Pentacore\Typefinder\TypefinderRegistry;
use ReflectionAttribute;
use ReflectionClass;

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
     * Incremental regeneration: given a list of absolute PHP file paths that
     * changed, re-extract and re-render only the affected .d.ts outputs.
     * Empty $paths falls back to full regen.
     *
     * @param  list<string>  $paths  Absolute paths. Empty = full regen.
     */
    public function generatePaths(array $paths): RegenResult
    {
        if ($paths === []) {
            return $this->generateFull();
        }

        $startedAt = microtime(true);
        $outputPath = (string) config('typefinder.output_path');
        $config = (array) config('typefinder');
        $changed = [];
        $warnings = [];
        $failed = [];

        $extractionCache = ExtractionCache::load(
            $this->cachePath,
            $this->cacheKeyFactory->composerLockHash(),
            $this->cacheKeyFactory->configHash($config),
        );

        $categoryMap = $this->buildCategoryMap($config);
        $affectedCategories = [];

        foreach ($paths as $path) {
            $category = $this->resolveCategory($path, $categoryMap);
            if ($category === null) {
                $warnings[] = $path.': outside any configured typefinder category — ignored';

                continue;
            }

            if (! is_file($path)) {
                $this->handleDeletion($path, $category, $extractionCache, $outputPath, $changed);
                $affectedCategories[$category] = true;

                continue;
            }

            try {
                $extraction = $this->extractSingleFile($path, $category, $config, $warnings);
                if ($extraction !== null) {
                    $extractionCache->put($path, $category, $extraction);
                    $this->writeSingleExtraction($category, $extraction, $config, $outputPath, $changed);
                    $affectedCategories[$category] = true;
                }
            } catch (\Throwable $e) {
                $failed[] = ['path' => $path, 'message' => $e->getMessage()];
            }
        }

        foreach (array_keys($affectedCategories) as $cat) {
            $this->rewriteCategoryBarrel($cat, $config, $outputPath, $changed);
        }

        if ($changed !== []) {
            $enabledCats = $this->detectEnabledCategories($outputPath);
            $barrel = $this->typeScriptRenderer->renderTopLevelBarrel($enabledCats);
            File::ensureDirectoryExists($outputPath);
            if ($this->writeIfChanged($outputPath.'/index.d.ts', $barrel)) {
                $changed[] = 'index.d.ts';
            }
        }

        $extractionCache->persist();

        return new RegenResult(
            changed: array_values(array_unique($changed)),
            warnings: $warnings,
            failed: $failed,
            durationMs: (int) round((microtime(true) - $startedAt) * 1000),
        );
    }

    /**
     * Run the full generation pipeline using values from config('typefinder').
     * Returns a RegenResult with relative paths of files that changed,
     * any warnings collected, and duration.
     */
    public function generateFull(): RegenResult
    {
        $startedAt = microtime(true);
        $outputPath = (string) config('typefinder.output_path');

        $config = (array) config('typefinder');
        $extractionCache = ExtractionCache::load(
            $this->cachePath,
            $this->cacheKeyFactory->composerLockHash(),
            $this->cacheKeyFactory->configHash($config),
        );

        $warnings = [];
        $changed = [];

        $allEnums = [];
        $allModels = [];
        $allRequests = [];
        $allPivots = [];
        $categories = [];

        $warn = function (string $message) use (&$warnings): void {
            $warnings[] = $message;
            if ($this->onWarn !== null) {
                ($this->onWarn)($message);
            }
        };

        if (config('typefinder.enums.enabled', true)) {
            $enumExtractor = new EnumExtractor;
            $paths = config('typefinder.enums.paths', []);

            foreach ($paths as $path) {
                $allEnums = array_merge($allEnums, $enumExtractor->extractFromDirectory($path));
            }

            foreach ($allEnums as $allEnum) {
                $absolutePath = (new ReflectionClass($allEnum['fqcn']))->getFileName();
                if ($absolutePath !== false) {
                    $extractionCache->put($absolutePath, 'enums', $allEnum);
                }
            }

            if ($allEnums !== []) {
                $this->writeEnums($allEnums, $outputPath, $changed);
                $categories[] = 'enums';
            }
        }

        if (config('typefinder.models.enabled', true)) {
            $castOverrides = config('typefinder.casts.type_map', []);
            $modelExtractor = new ModelExtractor(
                new ColumnTypeResolver,
                new CastTypeResolver($castOverrides, $this->typefinderRegistry),
                $warn,
            );

            $paths = config('typefinder.models.paths', []);

            foreach ($paths as $path) {
                $allModels = array_merge($allModels, $modelExtractor->extractFromDirectory($path));
            }

            $morphToResolver = new MorphToResolver;
            $allModels = $morphToResolver->resolve($allModels);

            foreach ($allModels as $allModel) {
                $absolutePath = (new ReflectionClass($allModel['fqcn']))->getFileName();
                if ($absolutePath !== false) {
                    $extractionCache->put($absolutePath, 'models', $allModel);
                }
            }

            $allPivots = $this->extractPivots($allModels);

            if ($allModels !== [] || $allPivots !== []) {
                $this->writeModels($allModels, $allPivots, $allEnums, $outputPath, $changed);
                $categories[] = 'models';
            }
        }

        if (config('typefinder.requests.enabled', true)) {
            $requestExtractor = new RequestExtractor;
            $paths = config('typefinder.requests.paths', []);

            $onRequestWarn = function (string $cls, \Throwable $throwable) use ($warn): void {
                $warn(sprintf('skipped %s: ', $cls).$throwable->getMessage());
            };
            foreach ($paths as $path) {
                $allRequests = array_merge($allRequests, $requestExtractor->extractFromDirectory($path, null, $onRequestWarn));
            }

            foreach ($allRequests as $allRequest) {
                $absolutePath = (new ReflectionClass($allRequest['fqcn']))->getFileName();
                if ($absolutePath !== false) {
                    $extractionCache->put($absolutePath, 'requests', $allRequest);
                }
            }

            if ($allRequests !== []) {
                $extractNested = config('typefinder.requests.extract_nested', false);
                $this->writeRequests($allRequests, $allEnums, $outputPath, $extractNested, $changed);
                $categories[] = 'requests';
            }
        }

        $allResources = [];
        if (config('typefinder.resources.enabled', true)) {
            $resourceExtractor = new ResourceExtractor;
            $paths = config('typefinder.resources.paths', []);

            $onResourceWarn = function (string $cls, \Throwable $throwable) use ($warn): void {
                $warn(sprintf('skipped %s: ', $cls).$throwable->getMessage());
            };

            foreach ($paths as $path) {
                $allResources = array_merge($allResources, $resourceExtractor->extractFromDirectory($path, null, $onResourceWarn));
            }

            foreach ($allResources as $allResource) {
                $absolutePath = (new ReflectionClass($allResource['fqcn']))->getFileName();
                if ($absolutePath !== false) {
                    $extractionCache->put($absolutePath, 'resources', $allResource);
                }
            }

            if ($allResources !== []) {
                $this->writeResources($allResources, $allModels, $allEnums, $outputPath, $changed);
                $categories[] = 'resources';
            }
        }

        if (config('typefinder.inertia.enabled', false)) {
            $controllerExtractor = new ControllerExtractor;
            $paths = config('typefinder.inertia.paths', []);

            $allPages = [];
            foreach ($paths as $path) {
                $allPages = array_merge($allPages, $controllerExtractor->extractFromDirectory($path));
            }

            $this->assertNoPageCollisions($allPages);

            if ($allPages !== []) {
                $this->writePages($allPages, $allModels, $allEnums, $outputPath, $changed);
                $categories[] = 'pages';
            }
        }

        if (config('typefinder.broadcasting.enabled', false)) {
            $broadcastExtractor = new BroadcastExtractor;
            $paths = config('typefinder.broadcasting.paths', []);

            $onBroadcastWarn = function (string $cls, \Throwable $throwable) use ($warn): void {
                $warn(sprintf('skipped %s: ', $cls).$throwable->getMessage());
            };

            $allBroadcasts = [];
            foreach ($paths as $path) {
                $allBroadcasts = array_merge($allBroadcasts, $broadcastExtractor->extractFromDirectory($path, null, $onBroadcastWarn));
            }

            $this->assertNoBroadcastCollisions($allBroadcasts);

            if ($allBroadcasts !== []) {
                $this->writeBroadcasting($allBroadcasts, $allModels, $allEnums, $outputPath, $changed);
                $categories[] = 'broadcasting';
            }
        }

        $this->writeHelpers($outputPath, $changed);
        $categories[] = 'helpers';

        $barrel = $this->typeScriptRenderer->renderTopLevelBarrel($categories);
        File::ensureDirectoryExists($outputPath);
        $wrote = $this->writeIfChanged($outputPath.'/index.d.ts', $barrel);
        if ($wrote) {
            $changed[] = 'index.d.ts';
        }

        $extractionCache->persist();

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return new RegenResult(
            changed: $changed,
            warnings: $warnings,
            failed: [],
            durationMs: $durationMs,
        );
    }

    private function writeEnums(array $enums, string $outputPath, array &$changed): void
    {
        $dir = $outputPath.'/enums';
        File::ensureDirectoryExists($dir);

        $emitValues = (bool) config('typefinder.enums.emit_values', false);
        $ext = $emitValues ? 'ts' : 'd.ts';

        $names = [];
        foreach ($enums as $enum) {
            $content = $this->typeScriptRenderer->renderEnum($enum, $emitValues);
            $relativePath = 'enums/'.$enum['name'].'.'.$ext;
            $wrote = $this->writeIfChanged(sprintf('%s/%s.%s', $dir, $enum['name'], $ext), $content);
            if ($wrote) {
                $changed[] = $relativePath;
            }

            $names[] = $enum['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n): string => $n.'.'.$ext, [...$names, 'index']));

        $barrelContent = $emitValues
            ? $this->renderValueBarrel($names)
            : $this->typeScriptRenderer->renderBarrelIndex($names);

        $indexPath = 'enums/index.'.$ext;
        $wrote = $this->writeIfChanged($dir.'/index.'.$ext, $barrelContent);
        if ($wrote) {
            $changed[] = $indexPath;
        }
    }

    /**
     * @param  list<string>  $names
     */
    private function renderValueBarrel(array $names): string
    {
        $lines = array_map(
            fn (string $n): string => sprintf("export * from './%s';", $n),
            $names,
        );

        return TypeScriptRenderer::FILE_HEADER."\n".implode("\n", $lines)."\n";
    }

    private function writeModels(array $models, array $pivots, array $allEnums, string $outputPath, array &$changed): void
    {
        $dir = $outputPath.'/models';
        File::ensureDirectoryExists($dir);

        $emitWriteShapes = (bool) config('typefinder.models.emit_write_shapes', true);
        $globalImmutable = (array) config('typefinder.models.immutable_on_update', ['id', 'created_at', 'updated_at', 'deleted_at']);

        $names = [];

        foreach ($models as $model) {
            $immutable = array_values(array_unique([...$globalImmutable, ...$this->getContractImmutable($model['fqcn'])]));
            $content = $this->typeScriptRenderer->renderModelFile($model, $allEnums, $models, $emitWriteShapes, $immutable);
            $this->writeModelFile($dir, $model['name'], $content, $changed);
            $names[] = $model['name'];
        }

        foreach ($pivots as $pivot) {
            $content = $this->typeScriptRenderer->renderPivot($pivot);
            $this->writeModelFile($dir, $pivot['name'], $content, $changed);
            $names[] = $pivot['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n): string => $n.'.d.ts', [...$names, 'index']));

        $wrote = $this->writeIfChanged($dir.'/index.d.ts', $this->typeScriptRenderer->renderBarrelIndex($names));
        if ($wrote) {
            $changed[] = 'models/index.d.ts';
        }
    }

    private function writeModelFile(string $dir, string $name, string $content, array &$changed): void
    {
        $relativePath = 'models/'.$name.'.d.ts';
        $wrote = $this->writeIfChanged(sprintf('%s/%s.d.ts', $dir, $name), $content);
        if ($wrote) {
            $changed[] = $relativePath;
        }
    }

    /**
     * @param  class-string  $fqcn
     * @return list<string>
     */
    private function getContractImmutable(string $fqcn): array
    {
        $attrs = (new ReflectionClass($fqcn))
            ->getAttributes(TypefinderWriteShape::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs === []) {
            return [];
        }

        return $attrs[0]->newInstance()->immutableOnUpdate;
    }

    private function writeRequests(array $requests, array $allEnums, string $outputPath, bool $extractNested, array &$changed): void
    {
        $dir = $outputPath.'/requests';
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach ($requests as $request) {
            $content = $this->typeScriptRenderer->renderRequest($request, $allEnums, $extractNested);
            $relativePath = 'requests/'.$request['name'].'.d.ts';
            $wrote = $this->writeIfChanged(sprintf('%s/%s.d.ts', $dir, $request['name']), $content);
            if ($wrote) {
                $changed[] = $relativePath;
            }

            $names[] = $request['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n): string => $n.'.d.ts', [...$names, 'index']));

        $wrote = $this->writeIfChanged($dir.'/index.d.ts', $this->typeScriptRenderer->renderBarrelIndex($names));
        if ($wrote) {
            $changed[] = 'requests/index.d.ts';
        }
    }

    private function writeResources(array $resources, array $allModels, array $allEnums, string $outputPath, array &$changed): void
    {
        $dir = $outputPath.'/resources';
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach ($resources as $resource) {
            $content = $this->typeScriptRenderer->renderResource($resource, $allModels, $allEnums, $resources);
            $relativePath = 'resources/'.$resource['name'].'.d.ts';
            $wrote = $this->writeIfChanged($dir.'/'.$resource['name'].'.d.ts', $content);
            if ($wrote) {
                $changed[] = $relativePath;
            }

            $names[] = $resource['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn (string $n): string => $n.'.d.ts', [...$names, 'index']));

        $wrote = $this->writeIfChanged($dir.'/index.d.ts', $this->typeScriptRenderer->renderBarrelIndex($names));
        if ($wrote) {
            $changed[] = 'resources/index.d.ts';
        }
    }

    private function writeHelpers(string $outputPath, array &$changed): void
    {
        File::ensureDirectoryExists($outputPath);

        $content = $this->typeScriptRenderer->renderHelpers();
        $wrote = $this->writeIfChanged($outputPath.'/helpers.d.ts', $content);
        if ($wrote) {
            $changed[] = 'helpers.d.ts';
        }
    }

    private function writePages(array $pages, array $allModels, array $allEnums, string $outputPath, array &$changed): void
    {
        File::ensureDirectoryExists($outputPath);

        $content = $this->typeScriptRenderer->renderPages($pages, $allModels, $allEnums);
        $wrote = $this->writeIfChanged($outputPath.'/pages.d.ts', $content);
        if ($wrote) {
            $changed[] = 'pages.d.ts';
        }
    }

    /**
     * @param  list<array{component: string, source: string}>  $pages
     */
    private function assertNoPageCollisions(array $pages): void
    {
        $byComponent = [];
        foreach ($pages as $page) {
            $byComponent[$page['component']][] = $page['source'];
        }

        $conflicts = array_filter($byComponent, fn ($sources): bool => count($sources) > 1);
        if ($conflicts === []) {
            return;
        }

        $lines = [];
        foreach ($conflicts as $component => $sources) {
            $lines[] = $component.': '.implode(', ', $sources);
        }

        throw new \RuntimeException("Duplicate TypefinderPage components:\n".implode("\n", $lines));
    }

    private function writeBroadcasting(array $events, array $allModels, array $allEnums, string $outputPath, array &$changed): void
    {
        File::ensureDirectoryExists($outputPath);

        $content = $this->typeScriptRenderer->renderBroadcasting($events, $allModels, $allEnums);
        $wrote = $this->writeIfChanged($outputPath.'/broadcasting.d.ts', $content);
        if ($wrote) {
            $changed[] = 'broadcasting.d.ts';
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

        $conflicts = array_filter($byKey, fn ($classes): bool => count($classes) > 1);
        if ($conflicts === []) {
            return;
        }

        $lines = [];
        foreach ($conflicts as $key => $classes) {
            $lines[] = $key.': '.implode(', ', $classes);
        }

        throw new \RuntimeException("Duplicate broadcast event on channel:\n".implode("\n", $lines));
    }

    private function writeIfChanged(string $path, string $content): bool
    {
        if (File::exists($path) && File::get($path) === $content) {
            return false;
        }

        File::put($path, $content);

        return true;
    }

    /**
     * @param  list<string>  $expectedFiles
     */
    private function pruneStaleFiles(string $dir, array $expectedFiles): void
    {
        $expected = array_fill_keys($expectedFiles, true);

        foreach (File::files($dir) as $file) {
            if (! isset($expected[$file->getFilename()])) {
                File::delete($file->getRealPath());
            }
        }
    }

    /**
     * Extract pivot type definitions from model relationships.
     *
     * @return list<array>
     */
    private function extractPivots(array $allModels): array
    {
        $pivots = [];
        $seen = [];

        foreach ($allModels as $allModel) {
            foreach ($allModel['relationships'] as $rel) {
                if ($rel['type'] !== 'manyWithPivot') {
                    continue;
                }

                if (! isset($rel['pivot'])) {
                    continue;
                }

                $tableName = $rel['pivot']['table'];
                if (isset($seen[$tableName])) {
                    continue;
                }

                $seen[$tableName] = true;

                $pivotName = str_replace(' ', '', ucwords(str_replace('_', ' ', $tableName))).'Pivot';

                $columns = [
                    ['name' => $rel['pivot']['foreignKey'], 'type' => 'number'],
                    ['name' => $rel['pivot']['relatedKey'], 'type' => 'number'],
                ];

                if (isset($rel['pivot']['morphType'])) {
                    $columns[] = ['name' => $rel['pivot']['morphType'], 'type' => 'string'];
                }

                foreach ($rel['pivot']['withPivot'] as $col) {
                    $columns[] = ['name' => $col, 'type' => 'string', 'nullable' => true];
                }

                $pivots[] = [
                    'name' => $pivotName,
                    'columns' => $columns,
                    'withTimestamps' => $rel['pivot']['withTimestamps'],
                ];
            }
        }

        return $pivots;
    }

    // ── Incremental helpers ─────────────────────────────────────────────

    /**
     * Map each enabled category to its list of absolute config paths.
     *
     * @return array<string, list<string>>
     */
    private function buildCategoryMap(array $config): array
    {
        $map = [];
        foreach (['enums', 'models', 'requests', 'resources', 'inertia', 'broadcasting'] as $cat) {
            if (! ($config[$cat]['enabled'] ?? false)) {
                continue;
            }

            $configPaths = $config[$cat]['paths'] ?? [];
            $resolved = [];
            foreach ((array) $configPaths as $p) {
                $real = realpath((string) $p);
                $resolved[] = $real !== false ? $real : (string) $p;
            }

            $map[$cat] = $resolved;
        }

        return $map;
    }

    /**
     * Longest-prefix match to find which category a file belongs to.
     */
    private function resolveCategory(string $absolutePath, array $categoryMap): ?string
    {
        $best = null;
        $bestLen = 0;
        $resolved = realpath($absolutePath) ?: $absolutePath;
        foreach ($categoryMap as $category => $roots) {
            foreach ($roots as $root) {
                if (str_starts_with($resolved, $root.'/') && strlen((string) $root) > $bestLen) {
                    $best = $category;
                    $bestLen = strlen((string) $root);
                }
            }
        }

        return $best;
    }

    /**
     * Resolve the fully-qualified class name from a PHP file.
     */
    private function resolveClassName(string $filePath): ?string
    {
        $contents = @file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        if (! preg_match('/namespace\s+(.+?);/', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/(class|enum)\s+(\w+)/', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1].'\\'.$classMatch[2];
    }

    /**
     * Extract metadata from a single PHP file using the appropriate extractor.
     */
    private function extractSingleFile(string $path, string $category, array $config, array &$warnings): ?array
    {
        $className = $this->resolveClassName($path);
        if ($className === null || ! class_exists($className)) {
            $warnings[] = $path.': could not resolve class name — skipped';

            return null;
        }

        $warn = function (string $msg) use (&$warnings): void {
            $warnings[] = $msg;
            if ($this->onWarn !== null) {
                ($this->onWarn)($msg);
            }
        };

        return match ($category) {
            'enums' => (new EnumExtractor)->extract($className),
            'models' => $this->extractSingleModel($className, $config, $warn),
            'requests' => (new RequestExtractor)->extract($className),
            'resources' => (new ResourceExtractor)->extract($className),
            'inertia' => $this->extractSingleController($className),
            'broadcasting' => (new BroadcastExtractor)->extract($className),
            default => null,
        };
    }

    private function extractSingleModel(string $className, array $config, callable $warn): array
    {
        $castOverrides = $config['casts']['type_map'] ?? [];
        $modelExtractor = new ModelExtractor(
            new ColumnTypeResolver,
            new CastTypeResolver($castOverrides, $this->typefinderRegistry),
            $warn,
        );

        $extraction = $modelExtractor->extract($className);
        $morphToResolver = new MorphToResolver;
        $resolved = $morphToResolver->resolve([$extraction]);

        return $resolved[0];
    }

    /**
     * @return list<array>|null
     */
    private function extractSingleController(string $className): ?array
    {
        $pages = (new ControllerExtractor)->extract($className);

        return $pages !== [] ? $pages : null;
    }

    /**
     * Render and write a single .d.ts file for a non-aggregated category.
     */
    private function writeSingleExtraction(string $category, array $extraction, array $config, string $outputPath, array &$changed): void
    {
        if (in_array($category, ['inertia', 'broadcasting'], true)) {
            return;
        }

        match ($category) {
            'enums' => $this->writeSingleEnum($extraction, $config, $outputPath, $changed),
            'models' => $this->writeSingleModel($extraction, $config, $outputPath, $changed),
            'requests' => $this->writeSingleRequest($extraction, $config, $outputPath, $changed),
            'resources' => $this->writeSingleResource($extraction, $outputPath, $changed),
            default => null,
        };
    }

    private function writeSingleEnum(array $enum, array $config, string $outputPath, array &$changed): void
    {
        $dir = $outputPath.'/enums';
        File::ensureDirectoryExists($dir);
        $emitValues = (bool) ($config['enums']['emit_values'] ?? false);
        $ext = $emitValues ? 'ts' : 'd.ts';
        $content = $this->typeScriptRenderer->renderEnum($enum, $emitValues);
        $relativePath = 'enums/'.$enum['name'].'.'.$ext;
        if ($this->writeIfChanged($dir.'/'.$enum['name'].'.'.$ext, $content)) {
            $changed[] = $relativePath;
        }
    }

    private function writeSingleModel(array $model, array $config, string $outputPath, array &$changed): void
    {
        $dir = $outputPath.'/models';
        File::ensureDirectoryExists($dir);

        $emitWriteShapes = (bool) ($config['models']['emit_write_shapes'] ?? true);
        $globalImmutable = (array) ($config['models']['immutable_on_update'] ?? ['id', 'created_at', 'updated_at', 'deleted_at']);
        $immutable = array_values(array_unique([...$globalImmutable, ...$this->getContractImmutable($model['fqcn'])]));

        $content = $this->typeScriptRenderer->renderModelFile($model, [], [], $emitWriteShapes, $immutable);
        $relativePath = 'models/'.$model['name'].'.d.ts';
        if ($this->writeIfChanged($dir.'/'.$model['name'].'.d.ts', $content)) {
            $changed[] = $relativePath;
        }
    }

    private function writeSingleRequest(array $request, array $config, string $outputPath, array &$changed): void
    {
        $dir = $outputPath.'/requests';
        File::ensureDirectoryExists($dir);
        $extractNested = (bool) ($config['requests']['extract_nested'] ?? false);
        $content = $this->typeScriptRenderer->renderRequest($request, [], $extractNested);
        $relativePath = 'requests/'.$request['name'].'.d.ts';
        if ($this->writeIfChanged($dir.'/'.$request['name'].'.d.ts', $content)) {
            $changed[] = $relativePath;
        }
    }

    private function writeSingleResource(array $resource, string $outputPath, array &$changed): void
    {
        $dir = $outputPath.'/resources';
        File::ensureDirectoryExists($dir);
        $content = $this->typeScriptRenderer->renderResource($resource, [], [], []);
        $relativePath = 'resources/'.$resource['name'].'.d.ts';
        if ($this->writeIfChanged($dir.'/'.$resource['name'].'.d.ts', $content)) {
            $changed[] = $relativePath;
        }
    }

    /**
     * Remove the .d.ts output for a deleted source file.
     */
    private function handleDeletion(string $path, string $category, ExtractionCache $extractionCache, string $outputPath, array &$changed): void
    {
        $cachedExtraction = $extractionCache->get($path);
        $extractionCache->forget($path);

        if ($cachedExtraction === null) {
            return;
        }

        if (in_array($category, ['inertia', 'broadcasting'], true)) {
            return;
        }

        $name = $cachedExtraction['name'] ?? null;
        if (! is_string($name)) {
            return;
        }

        $ext = ($category === 'enums' && config('typefinder.enums.emit_values', false)) ? 'ts' : 'd.ts';
        $relative = $category.'/'.$name.'.'.$ext;
        $abs = $outputPath.'/'.$relative;
        if (is_file($abs)) {
            @unlink($abs);
            $changed[] = $relative;
        }

        if ($category === 'models') {
            $pivotRel = 'models/'.$name.'Pivot.d.ts';
            $pivotAbs = $outputPath.'/'.$pivotRel;
            if (is_file($pivotAbs)) {
                @unlink($pivotAbs);
                $changed[] = $pivotRel;
            }
        }
    }

    /**
     * Re-render the barrel index for an affected category from what's on disk.
     */
    private function rewriteCategoryBarrel(string $category, array $config, string $outputPath, array &$changed): void
    {
        if (in_array($category, ['inertia', 'broadcasting'], true)) {
            return;
        }

        $dir = $outputPath.'/'.$category;
        if (! is_dir($dir)) {
            return;
        }

        $names = [];
        $ext = ($category === 'enums' && ($config['enums']['emit_values'] ?? false)) ? 'ts' : 'd.ts';
        foreach (File::files($dir) as $file) {
            $filename = $file->getFilename();
            if ($filename === 'index.'.$ext) {
                continue;
            }

            if ($filename === 'index.d.ts') {
                continue;
            }

            if (str_ends_with($filename, '.d.ts')) {
                $names[] = substr($filename, 0, -5);
            } elseif (str_ends_with($filename, '.ts')) {
                $names[] = substr($filename, 0, -3);
            }
        }

        sort($names);

        $barrelContent = ($category === 'enums' && ($config['enums']['emit_values'] ?? false))
            ? $this->renderValueBarrel($names)
            : $this->typeScriptRenderer->renderBarrelIndex($names);

        $barrelRelative = $category.'/index.'.$ext;
        if ($this->writeIfChanged($dir.'/index.'.$ext, $barrelContent)) {
            $changed[] = $barrelRelative;
        }
    }

    /**
     * Return the list of category names that have output on disk (for the top-level barrel).
     *
     * @return list<string>
     */
    private function detectEnabledCategories(string $outputPath): array
    {
        $categories = [];
        foreach (['enums', 'models', 'requests', 'resources'] as $cat) {
            if (is_dir($outputPath.'/'.$cat)) {
                $categories[] = $cat;
            }
        }

        if (is_file($outputPath.'/pages.d.ts')) {
            $categories[] = 'pages';
        }

        if (is_file($outputPath.'/broadcasting.d.ts')) {
            $categories[] = 'broadcasting';
        }

        $categories[] = 'helpers';

        return $categories;
    }
}
