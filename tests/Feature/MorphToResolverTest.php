<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\Resolvers\MorphToResolver;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

class MorphToResolverTest extends TestCase
{
    public function test_resolves_morph_to_targets(): void
    {
        $extractor = new ModelExtractor(
            new ColumnTypeResolver,
            new CastTypeResolver,
        );

        $allModels = $extractor->extractFromDirectory(workbench_path('app/Models'));
        $resolver = new MorphToResolver;

        $resolved = $resolver->resolve($allModels);

        // Comment has morphTo 'commentable'
        // Post has morphMany comments (commentable)
        $commentModel = collect($resolved)->firstWhere('fqcn', Comment::class);
        $commentableRel = collect($commentModel['relationships'])->firstWhere('name', 'commentable');

        $this->assertSame('morphTo', $commentableRel['type']);
        $this->assertContains(Post::class, $commentableRel['morphTargets']);
    }

    public function test_morph_many_side_unchanged(): void
    {
        $extractor = new ModelExtractor(
            new ColumnTypeResolver,
            new CastTypeResolver,
        );

        $allModels = $extractor->extractFromDirectory(workbench_path('app/Models'));
        $resolver = new MorphToResolver;

        $resolved = $resolver->resolve($allModels);

        // Post should still have comments as 'many' with Comment as related
        $postModel = collect($resolved)->firstWhere('fqcn', Post::class);
        $commentsRel = collect($postModel['relationships'])->firstWhere('name', 'comments');

        $this->assertSame('many', $commentsRel['type']);
        $this->assertSame(Comment::class, $commentsRel['related']);
    }
}
