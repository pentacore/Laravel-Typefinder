<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\Support\ThrowableMessage;

class GenerateCommand extends Command
{
    protected $signature = 'typefinder:generate {--debug} {--json} {--check : Dry-run: fail if regeneration would change on-disk files.} {--only=* : Absolute paths to re-extract incrementally.} {--strict : Fail (exit 1) if generation produced any warning.}';

    protected $aliases = ['typefinder:gen', 'typefinder'];

    protected $description = 'Generate TypeScript type definitions from Laravel Models, Enums, JsonResources, Typefinder Attributes, and Requests';

    /** @var list<array{path: string, written: bool, removed: bool}> */
    private array $files = [];

    /** @var array<string, int> */
    private array $counts = [];

    /** @var list<string> */
    private array $warnings = [];

    /** @var list<array{path: string, message: string}> */
    private array $failed = [];

    public function handle(): int
    {
        $startedAt = microtime(true);
        $realOutputPath = (string) config('typefinder.output_path');
        $useJson = (bool) $this->option('json');
        $useDebug = (bool) $this->option('debug');
        $useCheck = (bool) $this->option('check');
        $useStrict = (bool) $this->option('strict');

        $outputPath = $useCheck
            ? sys_get_temp_dir().'/typefinder-check-'.uniqid('', true)
            : $realOutputPath;

        if ($useCheck) {
            config(['typefinder.output_path' => $outputPath]);
        }

        $this->files = [];
        $this->counts = [];
        $this->warnings = [];
        $this->failed = [];

        try {
            $this->debugLine('starting', $useJson, $useDebug);

            /** @var Generator $generator */
            $generator = app(Generator::class);
            $onlyPaths = $this->onlyPaths();
            $result = $onlyPaths === []
                ? $generator->generateFull()
                : $generator->generatePaths($onlyPaths);

            $this->warnings = $result->warnings;
            $this->failed = $result->failed;
            $this->printWarnings($useJson);
            $this->files = array_map(fn (string $p): array => [
                'path' => $p,
                'written' => ! in_array($p, $result->removed, true),
                'removed' => in_array($p, $result->removed, true),
            ], $result->changed);

            $this->emitCategoryCounts($result->counts, $useJson);

            $drift = [];
            if ($useCheck) {
                $drift = $this->collectDrift($outputPath, $realOutputPath);
            } elseif (config('typefinder.gitignore_generated', true)) {
                $this->ensureGitignored($realOutputPath, $useJson, $useDebug);
            }

            $outcome = $this->outcome($useCheck, $useStrict, $drift, $realOutputPath);
        } catch (\Throwable $throwable) {
            $message = ThrowableMessage::describe($throwable);
            $outcome = ['success' => false, 'debug' => null, 'lines' => [['error', $message]], 'warnings' => [], 'errors' => [$message]];
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

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($outcome['debug'] !== null) {
            $this->debugLine(sprintf($outcome['debug'], $durationMs), $useJson, $useDebug);
        }

        if ($useJson) {
            $this->emitJson($outcome['success'], $durationMs, $realOutputPath, $outcome['warnings'], $outcome['errors']);
        } else {
            foreach ($outcome['lines'] as [$level, $line]) {
                $this->{$level}($line);
            }
        }

        return $outcome['success'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The --only paths, comma-separated or repeated.
     *
     * @return list<string>
     */
    private function onlyPaths(): array
    {
        $paths = [];
        foreach ((array) $this->option('only') as $entry) {
            foreach (explode(',', (string) $entry) as $path) {
                $path = trim($path);
                if ($path !== '') {
                    $paths[] = $path;
                }
            }
        }

        return $paths;
    }

    /**
     * How a run that finished ended, for either output mode: whether it
     * succeeded, the lines to print as text, what to add to the JSON, and a
     * debug line (`%d` is the duration).
     *
     * Drift (--check) is reported first, then files that could not be
     * extracted, then --strict warnings.
     *
     * @param  list<string>  $drift
     * @return array{success: bool, debug: ?string, lines: list<array{0: 'info'|'warn'|'error', 1: string}>, warnings: list<string>, errors: list<string>}
     */
    private function outcome(bool $useCheck, bool $useStrict, array $drift, string $outputPath): array
    {
        if ($drift !== []) {
            $hint = File::isDirectory($outputPath) ? null : $this->missingOutputHint($outputPath);
            $lines = $hint !== null
                ? [['warn', '[typefinder] '.$hint]]
                : [['warn', '[typefinder] drift detected — the following files would change:'], ...array_map(fn (string $path): array => ['warn', '  '.$path], $drift)];

            return ['success' => false, 'debug' => null, 'lines' => $lines, 'warnings' => [...$drift, ...($hint === null ? [] : [$hint])], 'errors' => []];
        }

        if ($this->failed !== []) {
            // An incremental run (--only) that could not extract some files.
            // The rest of the output was still written.
            $errors = array_map(fn (array $failure): string => $failure['path'].': '.$failure['message'], $this->failed);

            return [
                'success' => false,
                'debug' => sprintf('done success=false failed_files=%d duration_ms=%%d', count($this->failed)),
                'lines' => array_map(fn (string $error): array => ['error', '[typefinder] '.$error], $errors),
                'warnings' => [],
                'errors' => $errors,
            ];
        }

        if ($useStrict && $this->warnings !== []) {
            $count = count($this->warnings);

            return [
                'success' => false,
                'debug' => sprintf('done success=false strict_warnings=%d duration_ms=%%d', $count),
                'lines' => [['error', sprintf('[typefinder] --strict: %d warning(s) — failing.', $count)]],
                'warnings' => [],
                'errors' => [sprintf('--strict: %d warning(s) produced', $count)],
            ];
        }

        return $useCheck
            ? ['success' => true, 'debug' => 'check passed — no drift', 'lines' => [['info', 'TypeScript types are up to date.']], 'warnings' => [], 'errors' => []]
            : ['success' => true, 'debug' => 'done success=true duration_ms=%d', 'lines' => [['info', 'TypeScript types generated successfully.']], 'warnings' => [], 'errors' => []];
    }

    /**
     * Print a line per category with the number of types it generated.
     *
     * @param  array<string, int>  $categoryCounts
     */
    private function emitCategoryCounts(array $categoryCounts, bool $useJson): void
    {
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
     * Print collected warnings unless JSON mode is active (JSON carries them in its payload).
     */
    private function printWarnings(bool $useJson): void
    {
        if ($useJson) {
            return;
        }

        foreach ($this->warnings as $message) {
            $this->warn('[typefinder] warning: '.$message);
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
            // An object even when empty, as the keys are category names.
            'counts' => (object) $this->counts,
            'files' => $this->files,
            'warnings' => array_merge($this->warnings, $warnings),
            'errors' => $errors,
        ];

        $this->getOutput()->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Explain a --check run against an output directory that does not exist.
     * This is the usual result of running --check in a fresh CI checkout while
     * the output is git-ignored, where listing every file as missing hides the
     * actual cause.
     */
    private function missingOutputHint(string $outputPath): string
    {
        return sprintf(
            'output path %s does not exist, so there is nothing to compare against. '
            .'--check needs the generated output committed (`gitignore_generated` => false). '
            .'If the output is git-ignored, run typefinder:generate in CI before the TypeScript build instead of --check.',
            $outputPath,
        );
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
        $base = rtrim($this->normalizePath(base_path()), '/');
        $normalized = $this->normalizePath($path);

        if (! str_starts_with($normalized, $base.'/')) {
            return null;
        }

        return substr($normalized, strlen($base) + 1);
    }

    /**
     * Forward slashes, with `.` and `..` segments resolved, so a path that
     * steps out of the project is not taken for one inside it (#188).
     */
    private function normalizePath(string $path): string
    {
        $real = realpath($path);
        $path = str_replace('\\', '/', $real === false ? $path : $real);

        $segments = [];
        foreach (explode('/', $path) as $index => $segment) {
            if ($segment === '..' && $segments !== [] && end($segments) !== '' && end($segments) !== '..') {
                array_pop($segments);
            } elseif ($segment !== '.' && ($segment !== '' || $index === 0)) {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
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
