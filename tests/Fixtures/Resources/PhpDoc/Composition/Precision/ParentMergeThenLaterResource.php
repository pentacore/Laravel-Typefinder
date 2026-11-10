<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Precision;

use Illuminate\Http\Request;

/**
 * Wave 2, case 2: the parent's raw merge() is spliced into this array,
 * where it keeps "b" against the later spread. Not composed.
 */
class ParentMergeThenLaterResource extends MergeBaseResource
{
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), ...$this->later()];
    }

    /**
     * @return array{b: string}
     */
    private function later(): array
    {
        return ['b' => 'later'];
    }
}
