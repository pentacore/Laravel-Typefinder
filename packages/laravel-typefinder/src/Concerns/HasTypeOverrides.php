<?php

namespace Pentacore\Typefinder\Concerns;

trait HasTypeOverrides
{
    /**
     * Return an array of field name => TypeScript type string overrides.
     *
     * @return array<string, string>
     */
    abstract public function typeOverrides(): array;
}
