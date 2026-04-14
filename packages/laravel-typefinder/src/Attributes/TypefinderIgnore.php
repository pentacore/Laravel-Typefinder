<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Mark a class (model, enum, form request, or controller) as skipped by
 * the generator. Useful for abstract base classes, testing scaffolding,
 * or legacy classes that don't have clean types.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class TypefinderIgnore {}
