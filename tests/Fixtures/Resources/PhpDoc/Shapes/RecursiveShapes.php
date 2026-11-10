<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Shapes;

use Tests\Fixtures\Resources\PhpDoc\RecursiveImportResource;

/**
 * @phpstan-import-type Leaf from RecursiveImportResource
 *
 * @phpstan-type Branch array{leaf: Leaf}
 */
final class RecursiveShapes {}
