<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Http\Resources\PostSummaryResource;
use App\Models\User;
use Pentacore\Typefinder\Attributes\TypefinderPage;

class PostController
{
    // Resource, model and enum class-strings in page props (#75).
    #[TypefinderPage('Posts/Show', [
        'post' => PostSummaryResource::class,
        'author' => User::class.' | null',
        'status' => PostStatus::class,
    ])]
    public function show(): void
    {
        //
    }
}
