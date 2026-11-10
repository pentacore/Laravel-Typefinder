<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Shapes;

use App\Models\User;
use Tests\Fixtures\Resources\PhpDoc\Shapes\People\PersonShapes;

/**
 * Holds aliases only. `User` resolves through this file's imports; `Person`
 * is imported in turn from a third class.
 *
 * @phpstan-import-type Person from PersonShapes
 *
 * @phpstan-type Address array{city: string, owner: User}
 * @phpstan-type Contact array{person: Person}
 */
final class SharedShapes {}
