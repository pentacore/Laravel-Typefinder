<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;
use ReflectionClass;
use Tests\TestCase;

final class TypefinderOverridesTest extends TestCase
{
    public function test_post_model_declares_overrides_attribute(): void
    {
        $attrs = (new ReflectionClass(Post::class))->getAttributes(TypefinderOverrides::class);

        $this->assertCount(1, $attrs);
        $overrides = $attrs[0]->newInstance()->overrides;
        $this->assertArrayHasKey('metadata', $overrides);
        $this->assertSame('Record<string, string>', $overrides['metadata']);
    }

    public function test_model_without_attribute_has_no_overrides(): void
    {
        $attrs = (new ReflectionClass(Comment::class))->getAttributes(TypefinderOverrides::class);

        $this->assertSame([], $attrs);
    }
}
