<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Shapes\People;

use App\Enums\PostStatus;

/**
 * @phpstan-type Person array{name: string, status: PostStatus}
 */
final class PersonShapes {}
