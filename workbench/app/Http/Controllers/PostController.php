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

    // create and edit share one form page; `post` only exists on edit, so it
    // merges as optional (#73).
    #[TypefinderPage('Posts/Form', ['title' => 'string', 'status' => PostStatus::class])]
    public function create(): void
    {
        //
    }

    #[TypefinderPage('Posts/Form', ['title' => 'string', 'status' => PostStatus::class, 'post' => PostSummaryResource::class])]
    public function edit(): void
    {
        //
    }
}
