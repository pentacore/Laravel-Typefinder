<?php

namespace Pentacore\Typefinder\Concerns;

/**
 * Opt-in contract for per-model write-shape overrides. Using this trait
 * is documentation-only — the extractor detects the methods via
 * method_exists(), so a model can also declare any subset of them directly.
 */
trait HasWriteShapeContract
{
    /**
     * Extra column names to treat as server-filled (omitted from Create).
     * Primary key and timestamps are handled automatically.
     *
     * @return list<string>
     */
    public static function typefinderServerFilled(): array
    {
        return [];
    }

    /**
     * Per-model override for "respect $fillable/$guarded". Returning null
     * inherits the global config value.
     */
    public static function typefinderRespectMassAssignment(): ?bool
    {
        return null;
    }

    /**
     * Extra column names excluded from the Update shape. Merged with
     * config('typefinder.models.immutable_on_update').
     *
     * @return list<string>
     */
    public static function typefinderImmutableOnUpdate(): array
    {
        return [];
    }
}
