<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Shapes;

use Tests\Fixtures\Resources\PhpDoc\MutualImportResource;

/**
 * @phpstan-import-type Local from MutualImportResource
 *
 * @phpstan-type Remote array{local: Local}
 */
final class MutualShapes {}
