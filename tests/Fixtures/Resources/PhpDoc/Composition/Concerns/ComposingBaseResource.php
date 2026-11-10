<?php

declare(strict_types=1);

namespace Tests\Fixtures\Resources\PhpDoc\Composition\Concerns;

use App\Enums\PostStatus;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class ComposingBaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return $this->state();
    }

    /**
     * @return array{status: PostStatus}
     */
    protected function state(): array
    {
        return ['status' => $this->status];
    }

    /**
     * @return array{author: User}
     */
    protected function author(): array
    {
        return ['author' => $this->user];
    }
}
