<?php

namespace Pentacore\Typefinder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Extractors\EnumExtractor;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\Resolvers\MorphToResolver;

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
        $renderer = new TypeScriptRenderer;
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

                $this->debugLine('extracting category=enums paths=['.implode(',', array_map(fn ($p) => '"'.$p.'"', $paths)).']', $useJson, $useDebug);

                foreach ($paths as $path) {
                    $allEnums = array_merge($allEnums, $enumExtractor->extractFromDirectory($path));
                }

                $this->debugLine('extracted category=enums count='.count($allEnums), $useJson, $useDebug);

                if (! empty($allEnums)) {
                    $this->writeEnums($allEnums, $renderer, $outputPath, $useJson, $useDebug);
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

                $this->debugLine('extracting category=models paths=['.implode(',', array_map(fn ($p) => '"'.$p.'"', $paths)).']', $useJson, $useDebug);

                foreach ($paths as $path) {
                    $allModels = array_merge($allModels, $modelExtractor->extractFromDirectory($path));
                }

                $morphResolver = new MorphToResolver;
                $allModels = $morphResolver->resolve($allModels);

                $allPivots = $this->extractPivots($allModels);

                $this->debugLine('extracted category=models count='.count($allModels), $useJson, $useDebug);

                if (! empty($allModels)) {
                    $this->writeModels($allModels, $allEnums, $renderer, $outputPath, $useJson, $useDebug);
                    $categories[] = 'models';
                    $this->counts['models'] = count($allModels);
                    $this->printInfo('Generated '.count($allModels).' model type(s)', $useJson);
                    $this->debugLine('generated category=models count='.count($allModels), $useJson, $useDebug);
                }

                if (! empty($allPivots)) {
                    $this->writePivots($allPivots, $renderer, $outputPath, $useJson, $useDebug);
                    $categories[] = 'pivots';
                    $this->counts['pivots'] = count($allPivots);
                    $this->printInfo('Generated '.count($allPivots).' pivot type(s)', $useJson);
                }
            }

            if (config('typefinder.requests.enabled', true)) {
                $requestExtractor = new RequestExtractor;
                $paths = config('typefinder.requests.paths', []);

                $this->debugLine('extracting category=requests paths=['.implode(',', array_map(fn ($p) => '"'.$p.'"', $paths)).']', $useJson, $useDebug);

                foreach ($paths as $path) {
                    $allRequests = array_merge($allRequests, $requestExtractor->extractFromDirectory($path));
                }

                $this->debugLine('extracted category=requests count='.count($allRequests), $useJson, $useDebug);

                if (! empty($allRequests)) {
                    $extractNested = config('typefinder.requests.extract_nested', false);
                    $this->writeRequests($allRequests, $allEnums, $renderer, $outputPath, $extractNested, $useJson, $useDebug);
                    $categories[] = 'requests';
                    $this->counts['requests'] = count($allRequests);
                    $this->printInfo('Generated '.count($allRequests).' request type(s)', $useJson);
                }
            }

            if (! empty($categories)) {
                $barrel = $renderer->renderTopLevelBarrel($categories);
                File::ensureDirectoryExists($outputPath);
                $wrote = $this->writeIfChanged($outputPath.'/index.d.ts', $barrel);
                $this->files[] = ['path' => 'index.d.ts', 'written' => $wrote];
            }

            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);
            $this->debugLine('done success=true duration_ms='.$durationMs, $useJson, $useDebug);

            if ($useJson) {
                $this->emitJson(true, $durationMs, $outputPath, [], []);
            } else {
                $this->info('TypeScript types generated successfully.');
            }

            return self::SUCCESS;

        } catch (\Throwable $e) {
            $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

            if ($useJson) {
                $this->emitJson(false, $durationMs, $outputPath ?? '', [], [$e->getMessage()]);
            } else {
                $this->error($e->getMessage());
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

    protected function writeEnums(array $enums, TypeScriptRenderer $renderer, string $outputPath, bool $useJson, bool $useDebug): void
    {
        $dir = $outputPath.'/enums';
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach ($enums as $enum) {
            $content = $renderer->renderEnum($enum);
            $relativePath = 'enums/'.$enum['name'].'.d.ts';
            $wrote = $this->writeIfChanged("{$dir}/{$enum['name']}.d.ts", $content);
            $this->files[] = ['path' => $relativePath, 'written' => $wrote];
            $this->debugLine('writing category=enums path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
            $names[] = $enum['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n) => "{$n}.d.ts", [...$names, 'index']));

        $indexPath = 'enums/index.d.ts';
        $wrote = $this->writeIfChanged("{$dir}/index.d.ts", $renderer->renderBarrelIndex($names));
        $this->files[] = ['path' => $indexPath, 'written' => $wrote];
        $this->debugLine('writing category=enums path='.$indexPath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    protected function writeModels(array $models, array $allEnums, TypeScriptRenderer $renderer, string $outputPath, bool $useJson, bool $useDebug): void
    {
        $dir = $outputPath.'/models';
        File::ensureDirectoryExists($dir);

        $emitWriteShapes = (bool) config('typefinder.models.emit_write_shapes', true);
        $globalImmutable = (array) config('typefinder.models.immutable_on_update', ['id', 'created_at', 'updated_at', 'deleted_at']);

        $names = [];

        foreach ($models as $model) {
            $this->writeModelFile($dir, $model['name'], $renderer->renderModel($model, $allEnums, $models), $useJson, $useDebug);
            $names[] = $model['name'];

            if (! $emitWriteShapes) {
                continue;
            }

            $this->writeModelFile($dir, $model['name'].'Create', $renderer->renderModelCreate($model, $allEnums, $models), $useJson, $useDebug);
            $names[] = $model['name'].'Create';

            $immutable = array_values(array_unique([...$globalImmutable, ...$this->getContractImmutable($model['fqcn'])]));
            $this->writeModelFile($dir, $model['name'].'Update', $renderer->renderModelUpdate($model, $allEnums, $models, $immutable), $useJson, $useDebug);
            $names[] = $model['name'].'Update';
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n) => "{$n}.d.ts", [...$names, 'index']));

        $indexPath = 'models/index.d.ts';
        $wrote = $this->writeIfChanged("{$dir}/index.d.ts", $renderer->renderBarrelIndex($names));
        $this->files[] = ['path' => $indexPath, 'written' => $wrote];
        $this->debugLine('writing category=models path='.$indexPath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    private function writeModelFile(string $dir, string $name, string $content, bool $useJson, bool $useDebug): void
    {
        $relativePath = 'models/'.$name.'.d.ts';
        $wrote = $this->writeIfChanged("{$dir}/{$name}.d.ts", $content);
        $this->files[] = ['path' => $relativePath, 'written' => $wrote];
        $this->debugLine('writing category=models path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    /**
     * @param  class-string  $fqcn
     * @return list<string>
     */
    private function getContractImmutable(string $fqcn): array
    {
        if (! method_exists($fqcn, 'typefinderImmutableOnUpdate')) {
            return [];
        }

        return (array) $fqcn::typefinderImmutableOnUpdate();
    }

    protected function writeRequests(array $requests, array $allEnums, TypeScriptRenderer $renderer, string $outputPath, bool $extractNested, bool $useJson, bool $useDebug): void
    {
        $dir = $outputPath.'/requests';
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach ($requests as $request) {
            $content = $renderer->renderRequest($request, $allEnums, $extractNested);
            $relativePath = 'requests/'.$request['name'].'.d.ts';
            $wrote = $this->writeIfChanged("{$dir}/{$request['name']}.d.ts", $content);
            $this->files[] = ['path' => $relativePath, 'written' => $wrote];
            $this->debugLine('writing category=requests path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
            $names[] = $request['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n) => "{$n}.d.ts", [...$names, 'index']));

        $indexPath = 'requests/index.d.ts';
        $wrote = $this->writeIfChanged("{$dir}/index.d.ts", $renderer->renderBarrelIndex($names));
        $this->files[] = ['path' => $indexPath, 'written' => $wrote];
        $this->debugLine('writing category=requests path='.$indexPath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
    }

    protected function writePivots(array $pivots, TypeScriptRenderer $renderer, string $outputPath, bool $useJson, bool $useDebug): void
    {
        $dir = $outputPath.'/pivots';
        File::ensureDirectoryExists($dir);

        $names = [];
        foreach ($pivots as $pivot) {
            $content = $renderer->renderPivot($pivot);
            $relativePath = 'pivots/'.$pivot['name'].'.d.ts';
            $wrote = $this->writeIfChanged("{$dir}/{$pivot['name']}.d.ts", $content);
            $this->files[] = ['path' => $relativePath, 'written' => $wrote];
            $this->debugLine('writing category=pivots path='.$relativePath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
            $names[] = $pivot['name'];
        }

        $this->pruneStaleFiles($dir, array_map(fn ($n) => "{$n}.d.ts", [...$names, 'index']));

        $indexPath = 'pivots/index.d.ts';
        $wrote = $this->writeIfChanged("{$dir}/index.d.ts", $renderer->renderBarrelIndex($names));
        $this->files[] = ['path' => $indexPath, 'written' => $wrote];
        $this->debugLine('writing category=pivots path='.$indexPath.' changed='.($wrote ? 'true' : 'false'), $useJson, $useDebug);
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

        foreach ($allModels as $model) {
            foreach ($model['relationships'] as $rel) {
                if ($rel['type'] !== 'manyWithPivot' || ! isset($rel['pivot'])) {
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
