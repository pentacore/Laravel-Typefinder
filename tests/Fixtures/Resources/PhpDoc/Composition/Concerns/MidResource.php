<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Concerns;

/**
 * No toArray() of its own: `parent::toArray()` below it reaches the grandparent's.
 */
abstract class MidResource extends ComposingGrandResource {}
