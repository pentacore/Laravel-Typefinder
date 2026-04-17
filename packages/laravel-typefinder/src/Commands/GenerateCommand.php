<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;

class GenerateCommand extends Command
{
    protected $signature = 'typefinder:generate {--debug} {--json} {--check : Dry-run: fail if regeneration would change on-disk files.}';

    protected $aliases = ['typefinder:gen', 'typefinder'];

    protected $description = 'Generate TypeScript type definitions from Laravel Models, Enums, JsonResources, Typefinder Attributes, and Requests';

    /** @var list<array{path: string, written: bool}> */
    private array $files = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $warnings = [];

    public function handle(): int
    {
        $startedAt = microtime(true);
        $realOutputPath = (string) config('typefinder.output_path');
        $useJson = (bool) $this->option('json');
        $useDebug = (bool) $this->option('debug');
        $useCheck = (bool) $this->option('check');

        $outputPath = $useCheck
            ? sys_get_temp_dir().'/typefinder-check-'.uniqid('', true)
            : $realOutputPath;

        if ($useCheck) {
            config(['typefinder.output_path' => $outputPath]);
        }

        $this->files = [];
        $this->counts = [];
        $this->warnings = [];

        try {
            $this->debugLine('starting', $useJson, $useDebug);

            /** @var Generator $generator */
            $generator = app(Generator::class);
            $result = $generator->generateFull();

            $this->warnings = $result->warnings;
            $this->files = array_map(fn (string $p): array => ['path' => $p, 'written' => true], $result->changed);

            // Emit per-category info lines for non-JSON mode
            $this->emitCategoryCounts($result->changed, $useJson);

            if ($useCheck) {
                $drift = $this->collectDrift($outputPath, $realOutputPath);
                File::deleteDirectory($outputPath);

                $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

                if ($drift !== []) {
                    if ($useJson) {
                        $this->emitJson(false, $durationMs, $realOutputPath, $drift, []);
                    } else {
                        $this->warn('[typefinder] drift detected — the following files would change:');
                        foreach ($drift as $path) {
                            $this->warn('  '.$path);
                        }
                    }

                    return self::FAILURE;
                }

                $this->debugLine('check passed — no drift', $useJson, $useDebug);
                if ($useJson) {
                    $this->emitJson(true, $durationMs, $realOutputPath, [], []);
                } else {
                    $this->info('TypeScript types are up to date.');
                }

                return self::SUCCESS;
            }

            if (config('typefinder.gitignore_generated', true)) {
                $this->ensureGitignored($realOutputPath, $useJson, $useDebug);
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
        } finally {
            // In --check mode, always clean up the temp directory — even if
            // an exception escaped the pipeline. Without this, failed runs
            // leak `/tmp/typefinder-check-*` directories.
            if ($useCheck && $outputPath !== $realOutputPath && File::isDirectory($outputPath)) {
                File::deleteDirectory($outputPath);
            }

            if ($useCheck) {
                config(['typefinder.output_path' => $realOutputPath]);
            }
        }
    }

    /**
     * Reconstruct and print per-category info lines from the changed paths list.
     *
     * @param  list<string>  $changed
     */
    private function emitCategoryCounts(array $changed, bool $useJson): void
    {
        $categoryCounts = [];

        foreach ($changed as $path) {
            // Skip barrel indexes — they aren't user-facing "generated types"
            if (str_ends_with($path, '/index.d.ts')) {
                continue;
            }

            if ($path === 'index.d.ts') {
                continue;
            }

            if ($path === 'helpers.d.ts') {
                continue;
            }

            $category = str_contains($path, '/') ? explode('/', $path, 2)[0] : 'other';
            $categoryCounts[$category] = ($categoryCounts[$category] ?? 0) + 1;
        }

        $labelMap = [
            'enums' => 'enum',
            'models' => 'model',
            'requests' => 'request',
            'resources' => 'resource',
            'pages' => 'page prop',
            'broadcasting' => 'broadcast event',
        ];

        foreach ($categoryCounts as $category => $count) {
            $label = $labelMap[$category] ?? $category;
            $this->counts[$category] = $count;
            $this->printInfo('Generated '.$count.' '.$label.' type(s)', $useJson);
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

    /**
     * Compare a freshly-generated tree against the on-disk tree.
     * Returns a sorted list of relative paths that differ (either missing
     * from disk or with different content). Extra files in $realPath that
     * the generator did not produce are also reported.
     *
     * @return list<string>
     */
    protected function collectDrift(string $generatedPath, string $realPath): array
    {
        $generated = $this->listRelativeFiles($generatedPath);
        $real = $this->listRelativeFiles($realPath);

        $drift = [];

        foreach ($generated as $rel) {
            $generatedContents = File::get($generatedPath.'/'.$rel);
            $realFile = $realPath.'/'.$rel;

            if (! File::exists($realFile)) {
                $drift[] = $rel.' (missing on disk)';

                continue;
            }

            if (File::get($realFile) !== $generatedContents) {
                $drift[] = $rel;
            }
        }

        foreach ($real as $rel) {
            if (! in_array($rel, $generated, true)) {
                $drift[] = $rel.' (stale on disk)';
            }
        }

        sort($drift);

        return $drift;
    }

    /**
     * @return list<string>
     */
    protected function listRelativeFiles(string $dir): array
    {
        if (! File::isDirectory($dir)) {
            return [];
        }

        $results = [];
        foreach (File::allFiles($dir) as $file) {
            $results[] = ltrim(str_replace($dir, '', $file->getPathname()), '/');
        }

        return $results;
    }
}
