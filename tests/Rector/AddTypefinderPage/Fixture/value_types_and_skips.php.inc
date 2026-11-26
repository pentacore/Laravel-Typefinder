<?php

namespace App\Http\Controllers;

use App\Models\Post;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;

#[TypefinderIgnore]
final class IgnoredController
{
    public function index(): mixed
    {
        return Inertia::render('Ignored');
    }
}

final class ListController
{
    /** @param  Collection<int, Post>  $posts */
    public function index(Collection $posts, string $mode, int $page): mixed
    {
        return match ($mode) {
            'grid' => Inertia::render('Posts/Index', [
                'posts' => $posts,
                'tags' => ['news', 'tips'],
                'note' => null,
                'page' => function () use ($page) {
                    return $page;
                },
                'mixed' => 'one',
            ]),
            default => Inertia::render('Posts/Index', [
                'posts' => $posts,
                'tags' => ['news'],
                'note' => null,
                'page' => $page,
                'mixed' => 2,
            ]),
        };
    }

    public static function make(): mixed
    {
        return Inertia::render('Static');
    }

    private function helper(): mixed
    {
        return Inertia::render('Private');
    }
}
?>
-----
<?php

namespace App\Http\Controllers;

use Pentacore\Typefinder\Attributes\TypefinderPage;
use App\Models\Post;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;

#[TypefinderIgnore]
final class IgnoredController
{
    public function index(): mixed
    {
        return Inertia::render('Ignored');
    }
}

final class ListController
{
    /** @param  Collection<int, Post>  $posts */
    #[TypefinderPage(component: 'Posts/Index', props: [
        'posts' => Post::class . '[]',
        'tags' => 'string[]',
        'note' => 'null',
        'page' => 'number',
        'mixed' => 'unknown',
    ])]
    public function index(Collection $posts, string $mode, int $page): mixed
    {
        return match ($mode) {
            'grid' => Inertia::render('Posts/Index', [
                'posts' => $posts,
                'tags' => ['news', 'tips'],
                'note' => null,
                'page' => function () use ($page) {
                    return $page;
                },
                'mixed' => 'one',
            ]),
            default => Inertia::render('Posts/Index', [
                'posts' => $posts,
                'tags' => ['news'],
                'note' => null,
                'page' => $page,
                'mixed' => 2,
            ]),
        };
    }

    public static function make(): mixed
    {
        return Inertia::render('Static');
    }

    private function helper(): mixed
    {
        return Inertia::render('Private');
    }
}
?>
