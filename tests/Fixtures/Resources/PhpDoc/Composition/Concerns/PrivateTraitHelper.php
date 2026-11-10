<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Concerns;

use Illuminate\Http\Request;

/**
 * Trait code calling a private trait method: the copy in the using class.
 */
trait PrivateTraitHelper
{
    public function traitPart(Request $request): array
    {
        return [...$this->hidden()];
    }

    /**
     * @return array{hidden: bool}
     */
    private function hidden(): array
    {
        return ['hidden' => true];
    }
}
