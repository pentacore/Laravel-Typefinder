<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Services;

/**
 * Outcome of one generation run (full or incremental).
 *
 * @phpstan-type FailedEntry array{path: string, message: string}
 */
final readonly class RegenResult
{
    /**
     * @param  list<string>  $changed  Relative `.d.ts` paths that were written or removed (or would have been, in check mode).
     * @param  list<string>  $warnings  Human-readable warnings (unknown column types, skipped classes, etc.).
     * @param  list<FailedEntry>  $failed  Per-path extractor failures that did not abort the batch.
     * @param  list<string>  $removed  The paths of $changed that were removed.
     * @param  array<string, int>  $counts  Generated types per output directory (`models`, `pages`, …), whether they changed or not.
     */
    public function __construct(
        public array $changed,
        public array $warnings,
        public array $failed,
        public int $durationMs,
        public array $removed = [],
        public array $counts = [],
    ) {}
}
