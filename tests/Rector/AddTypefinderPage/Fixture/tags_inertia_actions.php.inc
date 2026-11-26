<?php

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Http\Resources\CategoryResource;
use App\Models\Post;
use App\Models\User;
use Inertia\Inertia;

final class PostController
{
    public function show(Post $post, ?User $editor): mixed
    {
        return Inertia::render('Posts/Show', [
            'post' => $post,
            'editor' => $editor,
            'title' => 'Post',
            'views' => 3,
            'status' => PostStatus::Draft,
            'category' => CategoryResource::make($post),
            'comments' => Inertia::optional(fn () => 'many'),
        ]);
    }

    public function edit(Post $post, bool $create): mixed
    {
        return $create
            ? inertia('Posts/Form', ['title' => 'New'])
            : inertia('Posts/Form', compact('post'))->with('title', 'Edit');
    }

    public function plain(): mixed
    {
        return Inertia::render('Dashboard');
    }
}
?>
-----
<?php

namespace App\Http\Controllers;

use Pentacore\Typefinder\Attributes\TypefinderPage;
use App\Enums\PostStatus;
use App\Http\Resources\CategoryResource;
use App\Models\Post;
use App\Models\User;
use Inertia\Inertia;

final class PostController
{
    #[TypefinderPage(component: 'Posts/Show', props: [
        'post' => Post::class,
        'editor' => User::class . ' | null',
        'title' => 'string',
        'views' => 'number',
        'status' => PostStatus::class,
        'category' => 'unknown',
        'comments' => 'string',
    ], optional: ['comments'])]
    public function show(Post $post, ?User $editor): mixed
    {
        return Inertia::render('Posts/Show', [
            'post' => $post,
            'editor' => $editor,
            'title' => 'Post',
            'views' => 3,
            'status' => PostStatus::Draft,
            'category' => CategoryResource::make($post),
            'comments' => Inertia::optional(fn () => 'many'),
        ]);
    }

    #[TypefinderPage(component: 'Posts/Form', props: ['title' => 'string', 'post' => Post::class], optional: ['post'])]
    public function edit(Post $post, bool $create): mixed
    {
        return $create
            ? inertia('Posts/Form', ['title' => 'New'])
            : inertia('Posts/Form', compact('post'))->with('title', 'Edit');
    }

    #[TypefinderPage(component: 'Dashboard')]
    public function plain(): mixed
    {
        return Inertia::render('Dashboard');
    }
}
?>
