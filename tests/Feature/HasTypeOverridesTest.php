<?php

namespace Tests\Feature;

use App\Models\Comment;
use App\Models\Post;
use Tests\TestCase;

class HasTypeOverridesTest extends TestCase
{
    public function test_post_model_has_type_overrides(): void
    {
        $post = new Post;

        $this->assertTrue(method_exists($post, 'typeOverrides'));
    }

    public function test_post_model_returns_overrides_array(): void
    {
        $post = new Post;
        $overrides = $post->typeOverrides();

        $this->assertIsArray($overrides);
        $this->assertArrayHasKey('metadata', $overrides);
        $this->assertSame('Record<string, string>', $overrides['metadata']);
    }

    public function test_model_without_trait_does_not_have_overrides(): void
    {
        $this->assertFalse(method_exists(new Comment, 'typeOverrides'));
    }
}
