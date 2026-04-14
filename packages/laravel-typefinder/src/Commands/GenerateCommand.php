<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;
use Pentacore\Typefinder\Extractors\BroadcastExtractor;
use Pentacore\Typefinder\Extractors\ControllerExtractor;
use Pentacore\Typefinder\Extractors\EnumExtractor;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\Resolvers\MorphToResolver;
use ReflectionAttribute;
use ReflectionClass;

class GenerateCommand extends Command
{
    protected $signature = 'typefinder:generate {--debug} {--json}';

    protected $description = 'Generate TypeScript type definitions from Laravel Models, Enums, and Requests';

    /** @var list<array{path: string, written: bool}> */
    private array $files = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $startedAt = microtime(true);
        $outputPath = config('typefinder.output_path');
        $typeScriptRenderer = new TypeScriptRenderer;
        $useJson = (bool) $this->option('json');
        $useDebug = (bool) $this->option('debug');

        $this->files = [];
        $this->counts = [];
        $this->warnings = [];

        try {
            $allEnums = [];
            $allModels = [];
            $allRequests = [];
            $allPivots = [];
            $categories = [];

            $this->debugLine('starting', $useJson, $useDebug);

            if (config('typefinder.enums.enabled', true)) {
                $enumExtractor = new EnumExtractor;
                $paths = config('typefinder.enums.paths', []);

                $this->debugLine('extracting category=enums paths=['.implode(',', array_map(fn ($p): string => '"'.$p.'"', $paths)).']', $useJson, $useDebug);

                $onEnum = fn (string $cls) => $this->debugLine('parsing category=enums class='.$cls, $useJson, $useDebug);
                foreach ($paths as $path) {
                    $allEnums = array_merge($allEnums, $enumExtractor->extractFromDirectory($path, $onEnum));
                }

                $this->debugLine('extracted category=enums count='.count($allEnums), $useJson, $useDebug);

                if ($allEnums !== []) {
                    $this->writeEnums($allEnums, $typeScriptRenderer, $outputPath, $useJson, $useDebug);
                    $categories[] = 'enums';
                    $this->counts['enums'] = count($allEnums);
                    $this->printInfo('Generated '.count($allEnums).' enum type(s)', $useJson);
                }
            }

            if (config('typefinder.models.enabled', true)) {
                $castOverrides = config('typefinder.casts.type_map', []);
                $modelExtractor = new ModelExtractor(
                    new ColumnTypeResolver,
                    new CastTypeResolver($castOverrides),
                );

                $paths = config('typefinder.models.paths', []);

                $this->debugLine('extracting category=models paths=['.implode(',', array_map(fn ($p): string => '"'.$p.'"', $paths)).']', $useJson, $useDebug);

                $onModel = fn (string $cls) => $this->debugLine('parsing category=models class='.$cls, $useJson, $useDebug);
                foreach ($paths as $path) {
                    $allModels = array_merge($allModels, $modelExtractor->extractFromDirectory($path, $onModel));
                }

                $morphToResolver = new MorphToResolver;
                $allModels = $morphToResolver->resolve($allModels);

                $allPivots = $this->extractPivots($allModels);

                $this->debugLine('extracted category=models count='.count($allModels), $useJson, $useDebug);

                if ($allModels !== []) {
                    $this->writeModels($allModels, $allEnums, $typeScriptRenderer, $outputPath, $useJson, $useDebug);
                    $categories[] = 'models';
                    $this->counts['models'] = count($allModels);
                    $this->printInfo('Generated '.count($allModels).' model type(s)', $useJson);
                    $this->debugLine('generated category=models count='.count($allModels), $useJson, $useDebug);
                }

                if ($allPivots !== []) {
                    $this->writePivots($allPivots, $typeScriptRenderer, $outputPath, $useJson, $useDebug);
                    $categories[] = 'pivots';
                    $this->counts['pivots'] = count($allPivots);
                    $this->printInfo('Generated '.count($allPivots).' pivot type(s)', $useJson);
                }
            }

            if (config('typefinder.requests.enabled', true)) {
                $requestExtractor = new RequestExtractor;
                $paths = config('typefinder.requests.paths', []);

                $this->debugLine('extracting category=requests paths=['.implode(',', array_map(fn ($p): string => '"'.$p.'"', $paths)).']', $useJson, $useDebug);

                $onRequest = fn (string $cls) => $this->debugLine('parsing category=requests class='.$cls, $useJson, $useDebug);
                $onRequestWarn = function (string $cls, \Throwable $throwable) use ($useJson): void {
                    $message = sprintf('skipped %s: ', $cls).$throwable->getMessage();
                    $this->warnings[] = $message;
                    if (! $useJson) {
                        $this->warn('[typefinder] '.$message);
                    }
                };
                foreach ($paths as $path) {
                    $allRequests = array_merge($allRequests, $requestExtractor->extractFromDirectory($path, $onRequest, $onRequestWarn));
                }

                $this->debugLine('extracted category=requests count='.count($allRequests), $useJson, $useDebug);

                if ($allRequests !== []) {
                    $extractNested = config('typefinder.requests.extract_nested', false);
                    $this->writeRequests($allRequests, $allEnums, $typeScriptRenderer, $outputPath, $extractNested, $useJson, $useDebug);
                    $categories[] = 'requests';
                    $this->counts['requests'] = count($allRequests);
                    $this->printInfo('Generated '.count($allRequests).' request type(s)', $useJson);
                }
            }

            if (config('typefinder.inertia.enabled', false)) {
                $controllerExtractor = new ControllerExtractor;
                $paths = config('typefinder.inertia.paths', []);

                $this->debugLine('extracting category=inertia paths=['.implode(',', array_map(fn ($p): string => '"'.$p.'"', $paths)).']', $useJson, $useDebug);

                $onPage = fn (string $cls) => $this->debugLine('parsing category=inertia class='.$cls, $useJson, $useDebug);
                $allPages = [];
                foreach ($paths as $path) {
                    $allPages = array_merge($allPages, $controllerExtractor->extractFromDirectory($path, $onPage));
                }

                $this->assertNoPageCollisions($allPages);

                $this->debugLine('extracted category=inertia count='.count($allPages), $useJson, $useDebug);

                if ($allPages !== []) {
                    $this->writePages($allPages, $allModels, $allEnums, $typeScriptRenderer, $outputPath, $useJson, $useDebug);
                    $categories[] = 'pages';
                    $this->counts['pages'] = count($allPages);
                    $this->printInfo('Generated '.count($allPages).' page prop type(s)', $useJson);
                }
            }

            if (config('typefinder.broadcasting.enabled', false)) {
                $broadcastExtractor = new BroadcastExtractor;
                $paths = config('typefinder.broadcasting.paths', []);

                $this->debugLine('extracting category=broadcasting paths=['.implode(',', array_map(fn ($p): string => '"'.$p.'"', $paths)).']', $useJson, $useDebug);

                $onBroadcast = fn (string $cls) => $this->debugLine('parsing category=broadcasting class='.$cls, $useJson, $useDebug);
                $onBroadcastWarn = function (string $cls, \Throwable $throwable) use ($useJson): void {
                    $message = "skipped {$cls}: ".$throwable->getMessage();
                    $this->warnings[] = $message;
                    if (! $useJson) {
                        $this->warn('[typefinder] '.$message);
                    }
                };

                $allBroadcasts = [];
                foreach ($paths as $path) {
                    $allBroadcasts = array_merge($allBroadcasts, $broadcastExtractor->extractFromDirectory($path, $onBroadcast, $onBroadcastWarn));
                }

                $this->assertNoBroadcastCollisions($allBroadcasts);

                $this->debugLine('extracted category=broadcasting count='.count($allBroadcasts), $useJson, $useDebug);

                if ($allBroadcasts !== []) {
                    $this->writeBroadcasting($allBroadcasts, $allModels, $allEnums, $typeScriptRenderer, $outputPath, $useJson, $useDebug);
                    $categories[] = 'broadcasting';
                    $this->counts['broadcasting'] = count($allBroadcasts);
                    $this->printInfo('Generated '.count($allBroadcasts).' broadcast event type(s)', $useJson);
                }
            }

            if ($categories !== []) {
                $barrel = $typeScriptRenderer->renderTopLevelBarrel($categories);
                File::ensureDirectoryExists($outputPath);
                $wrote = $this->writeIfChanged($outputPath.'/index.d.ts', $barrel);
                $this->files[] = ['path' => 'index.d.ts', 'written' => $wrote];
            }

            if (config('typefinder.gitignore_generated', true)) {
                $this->ensureGitignored($outputPath, $useJson, $useDebug);
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->debugLine('done success=true duration_ms='.$durationMs, $useJson, $useDebug);

            if ($useJson) {
                $this->emitJson(true, $durationMs, $outputPath, [], []);
            } else {
                $this->info('TypeScript types generated successfully.');
            }

            return self::SUCCESS;

        } catch (\Throwable $throwable) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($useJson) {
                $this->emitJson(false, $durationMs, $outputPath ?? '', [], [$throwable->getMessage()]);
            } else {
                $this->error($throwable->getMessage());
            }

            return self::FAILURE;
        }
    }

    /**
     * Print an info line unless JSON mode is active.
     */
    private function printInfo(string $message, bool $useJson): void
    {
        if (! $useJson) {
            $this->info($message);
        }
    }

    /**
     * Emit a [typefinder] prefixed debug line — suppressed when JSON mode is active.
     */
    private function debugLine(string $message, bool $useJson, bool $useDebug): void
    {
        if ($useJson || ! $useDebug) {
            return;
        }

        $this->getOutput()->writeln('[typefinder] '.$message);
    }

    /**
     * Emit the final JSON blob to stdout.
     */
    private function emitJson(bool $success, int $durationMs, string $outputPath, array $warnings, array $errors): void
    {
        $payload = [
            'success' => $success,
            'duration_ms' => $durationMs,
            'output_path' => $outputPath,
            'counts' => $this->counts,
            'files' => $this->files,
            'warnings' => array_merge($this->warnings, $warnings),
            'errors' => $errors,
        ];

        $this->getOutput()->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function writeEnums(array $enums, TypeScriptRenderer $typeScriptRenderer, string $outputPath, bool $useJson, bool $useDebug): void
    {
        $dir = $outputPath.'/enums';
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach ($enums as $enum) {
            $content = $typeScriptRenderer->renderEnum($enum);
            $relativePath = 'enums/'.$enum['name'].'.d.ts';
            $wrote = $this->writeIfChanged(sprintf('%s/%s.d.ts', $dir, $enum['name']), $content);
            $this->files[] = ['path' => $relativePath, 'written' => $wrote];
            $this->debugLine('writing category=enums path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
            $names[] = $enum['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n): string => $n.'.d.ts', [...$names, 'index']));

        $indexPath = 'enums/index.d.ts';
        $wrote = $this->writeIfChanged($dir.'/index.d.ts', $typeScriptRenderer->renderBarrelIndex($names));
        $this->files[] = ['path' => $indexPath, 'written' => $wrote];
        $this->debugLine('writing category=enums path='.$indexPath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    protected function writeModels(array $models, array $allEnums, TypeScriptRenderer $typeScriptRenderer, string $outputPath, bool $useJson, bool $useDebug): void
    {
        $dir = $outputPath.'/models';
        File::ensureDirectoryExists($dir);

        $emitWriteShapes = (bool) config('typefinder.models.emit_write_shapes', true);
        $globalImmutable = (array) config('typefinder.models.immutable_on_update', ['id', 'created_at', 'updated_at', 'deleted_at']);

        $names = [];

        foreach ($models as $model) {
            $immutable = array_values(array_unique([...$globalImmutable, ...$this->getContractImmutable($model['fqcn'])]));
            $content = $typeScriptRenderer->renderModelFile($model, $allEnums, $models, $emitWriteShapes, $immutable);
            $this->writeModelFile($dir, $model['name'], $content, $useJson, $useDebug);
            $names[] = $model['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n): string => $n.'.d.ts', [...$names, 'index']));

        $indexPath = 'models/index.d.ts';
        $wrote = $this->writeIfChanged($dir.'/index.d.ts', $typeScriptRenderer->renderBarrelIndex($names));
        $this->files[] = ['path' => $indexPath, 'written' => $wrote];
        $this->debugLine('writing category=models path='.$indexPath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    private function writeModelFile(string $dir, string $name, string $content, bool $useJson, bool $useDebug): void
    {
        $relativePath = 'models/'.$name.'.d.ts';
        $wrote = $this->writeIfChanged(sprintf('%s/%s.d.ts', $dir, $name), $content);
        $this->files[] = ['path' => $relativePath, 'written' => $wrote];
        $this->debugLine('writing category=models path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
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

    protected function writeRequests(array $requests, array $allEnums, TypeScriptRenderer $typeScriptRenderer, string $outputPath, bool $extractNested, bool $useJson, bool $useDebug): void
    {
        $dir = $outputPath.'/requests';
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach ($requests as $request) {
            $content = $typeScriptRenderer->renderRequest($request, $allEnums, $extractNested);
            $relativePath = 'requests/'.$request['name'].'.d.ts';
            $wrote = $this->writeIfChanged(sprintf('%s/%s.d.ts', $dir, $request['name']), $content);
            $this->files[] = ['path' => $relativePath, 'written' => $wrote];
            $this->debugLine('writing category=requests path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
            $names[] = $request['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n): string => $n.'.d.ts', [...$names, 'index']));

        $indexPath = 'requests/index.d.ts';
        $wrote = $this->writeIfChanged($dir.'/index.d.ts', $typeScriptRenderer->renderBarrelIndex($names));
        $this->files[] = ['path' => $indexPath, 'written' => $wrote];
        $this->debugLine('writing category=requests path='.$indexPath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    protected function writePivots(array $pivots, TypeScriptRenderer $typeScriptRenderer, string $outputPath, bool $useJson, bool $useDebug): void
    {
        $dir = $outputPath.'/pivots';
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach ($pivots as $pivot) {
            $content = $typeScriptRenderer->renderPivot($pivot);
            $relativePath = 'pivots/'.$pivot['name'].'.d.ts';
            $wrote = $this->writeIfChanged(sprintf('%s/%s.d.ts', $dir, $pivot['name']), $content);
            $this->files[] = ['path' => $relativePath, 'written' => $wrote];
            $this->debugLine('writing category=pivots path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
            $names[] = $pivot['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n): string => $n.'.d.ts', [...$names, 'index']));

        $indexPath = 'pivots/index.d.ts';
        $wrote = $this->writeIfChanged($dir.'/index.d.ts', $typeScriptRenderer->renderBarrelIndex($names));
        $this->files[] = ['path' => $indexPath, 'written' => $wrote];
        $this->debugLine('writing category=pivots path='.$indexPath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    protected function writePages(array $pages, array $allModels, array $allEnums, TypeScriptRenderer $typeScriptRenderer, string $outputPath, bool $useJson, bool $useDebug): void
    {
        File::ensureDirectoryExists($outputPath);

        $content = $typeScriptRenderer->renderPages($pages, $allModels, $allEnums);
        $relativePath = 'pages.d.ts';
        $wrote = $this->writeIfChanged($outputPath.'/pages.d.ts', $content);
        $this->files[] = ['path' => $relativePath, 'written' => $wrote];
        $this->debugLine('writing category=pages path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    /**
     * @param  list<array{component: string, source: string}>  $pages
     */
    protected function assertNoPageCollisions(array $pages): void
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

    protected function writeBroadcasting(array $events, array $allModels, array $allEnums, TypeScriptRenderer $typeScriptRenderer, string $outputPath, bool $useJson, bool $useDebug): void
    {
        File::ensureDirectoryExists($outputPath);

        $content = $typeScriptRenderer->renderBroadcasting($events, $allModels, $allEnums);
        $relativePath = 'broadcasting.d.ts';
        $wrote = $this->writeIfChanged($outputPath.'/broadcasting.d.ts', $content);
        $this->files[] = ['path' => $relativePath, 'written' => $wrote];
        $this->debugLine('writing category=broadcasting path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    /**
     * @param  list<array{event_class: string, broadcast_name: string, channels: list<array{type: string, name: string}>}>  $events
     */
    protected function assertNoBroadcastCollisions(array $events): void
    {
        $byKey = [];
        foreach ($events as $event) {
            foreach ($event['channels'] as $channel) {
                $key = "{$channel['type']}:{$channel['name']}:{$event['broadcast_name']}";
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

    /**
     * Append the generated output path to the project root .gitignore (if present).
     * Idempotent — does nothing if the line is already there or the gitignore
     * is missing or the output path sits outside the project root.
     */
    protected function ensureGitignored(string $outputPath, bool $useJson, bool $useDebug): void
    {
        $gitignore = base_path('.gitignore');

        if (! File::exists($gitignore)) {
            $this->debugLine('gitignore skipped reason=missing', $useJson, $useDebug);

            return;
        }

        $relative = $this->relativeToBase($outputPath);

        if ($relative === null) {
            $this->debugLine('gitignore skipped reason=outside-base-path', $useJson, $useDebug);

            return;
        }

        $line = '/'.ltrim($relative, '/');
        $existing = File::get($gitignore);
        $existingLines = preg_split('/\R/', $existing) ?: [];

        foreach ($existingLines as $existingLine) {
            if (trim($existingLine) === $line) {
                $this->debugLine('gitignore already-present path='.$line, $useJson, $useDebug);

                return;
            }
        }

        $append = (str_ends_with($existing, "\n") ? '' : "\n")
            ."# Generated by typefinder\n{$line}\n";

        File::put($gitignore, $existing.$append);
        $this->debugLine('gitignore appended path='.$line, $useJson, $useDebug);
    }

    protected function relativeToBase(string $path): ?string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/');
        $normalized = str_replace('\\', '/', $path);

        if (! str_starts_with($normalized, $base.'/')) {
            return null;
        }

        return substr($normalized, strlen($base) + 1);
    }

    protected function writeIfChanged(string $path, string $content): bool
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
    protected function pruneStaleFiles(string $dir, array $expectedFiles): void
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
    protected function extractPivots(array $allModels): array
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
}
