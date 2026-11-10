<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Shapes;

use Tests\Fixtures\Resources\PhpDoc\ManyImportsResource;

/**
 * @phpstan-import-type AT1 from ManyImportsResource
 * @phpstan-import-type AT2 from ManyImportsResource
 * @phpstan-import-type AT3 from ManyImportsResource
 * @phpstan-import-type AT4 from ManyImportsResource
 * @phpstan-import-type AT5 from ManyImportsResource
 * @phpstan-import-type AT6 from ManyImportsResource
 *
 * @phpstan-type BT1 array{b1: AT1}
 * @phpstan-type BT2 array{b2: AT2}
 * @phpstan-type BT3 array{b3: AT3}
 * @phpstan-type BT4 array{b4: AT4}
 * @phpstan-type BT5 array{b5: AT5}
 * @phpstan-type BT6 array{b6: AT6}
 */
final class ManyImportShapes {}
