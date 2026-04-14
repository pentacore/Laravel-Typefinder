<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Attributes;

use Attribute;

/**
 * Skip a class entirely during generation. Applies to models, enums, form
 * requests, JSON resources, Inertia controllers, and broadcast events —
 * any category the extractors walk.
 *
 * Useful for abstract base classes, legacy code you can't clean up yet,
 * test scaffolding, or classes whose generated output would be wrong and
 * can't be salvaged with the other attributes.
 *
 * Example:
 * ```php
 * use Pentacore\Typefinder\Attributes\TypefinderIgnore;
 *
 * #[TypefinderIgnore]
 * class LegacyModel extends \Illuminate\Database\Eloquent\Model {}
 * ```
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class TypefinderIgnore {}
