<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Analysis\KeyDriftDetector;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\Fixtures\SnakeRelations\Author;
use Tests\Fixtures\SnakeRelations\AuthorResource;
use Tests\Fixtures\SnakeRelations\CamelAuthor;
use Tests\TestCase;

/** Relation keys as `toArray()` serialises them: snake-cased unless `$snakeAttributes` is off (#220). */
final class SnakeCaseRelationKeysTest extends TestCase
{
    public function test_multi_word_relations_are_emitted_snake_cased(): void
    {
        $readShape = $this->readShape(Author::class);

        $this->assertStringContainsString('  blog_posts?: Post[];', $readShape);
        $this->assertStringContainsString('  latest_post?: Post | null;', $readShape);
        $this->assertStringContainsString('  tagged_posts?: (Post & { pivot: TaggablesPivot })[];', $readShape);
        $this->assertStringNotContainsString('blogPosts', $readShape);
        $this->assertStringNotContainsString('latestPost', $readShape);
    }

    public function test_hidden_matches_the_method_name(): void
    {
        $readShape = $this->readShape(Author::class);

        $this->assertStringNotContainsString('secret_posts', $readShape);
        $this->assertStringNotContainsString('secretPosts', $readShape);
    }

    public function test_visible_matches_the_method_name(): void
    {
        $model = new class extends Author
        {
            protected $hidden = [];

            protected $visible = ['id', 'blogPosts'];
        };

        $extracted = $this->extractor()->extract($model::class);

        $this->assertSame(['blog_posts'], array_column($extracted['relationships'], 'name'));
    }

    public function test_overrides_accept_the_method_name_or_the_snake_key(): void
    {
        $readShape = $this->readShape(Author::class);

        $this->assertStringContainsString('  pinned_tag?: string;', $readShape);
        $this->assertStringContainsString('  featured_tag?: number;', $readShape);
        $this->assertStringNotContainsString('pinnedTag', $readShape);
        $this->assertStringNotContainsString('Tag | null', $readShape);
    }

    public function test_snake_attributes_off_keeps_the_method_name(): void
    {
        $readShape = $this->readShape(CamelAuthor::class);

        $this->assertStringContainsString('  blogPosts?: Post[];', $readShape);
        $this->assertStringContainsString('  latestPost?: Post | null;', $readShape);
        $this->assertStringContainsString('  pinnedTag?: string;', $readShape);
        $this->assertStringContainsString('  featuredTag?: number;', $readShape);
        $this->assertStringNotContainsString('blog_posts', $readShape);
    }

    public function test_resource_omit_accepts_the_method_name(): void
    {
        $model = $this->extractor()->extract(Author::class);

        $output = new TypeScriptRenderer()->renderResource(
            [
                'name' => 'AuthorResource',
                'fqcn' => 'App\\Http\\Resources\\AuthorResource',
                'shape' => ['kind' => 'model', 'model' => Author::class, 'omit' => ['blogPosts', 'latest_post'], 'extend' => []],
            ],
            [$model],
            [],
            [],
        );

        $this->assertStringContainsString("export type AuthorResource = Omit<Author, 'blog_posts' | 'latest_post'>;", $output);
    }

    public function test_model_drift_compares_the_snake_key(): void
    {
        $this->assertSame([], $this->modelDrift([]));
    }

    public function test_model_drift_omits_a_relation_by_its_method_name(): void
    {
        $warnings = $this->modelDrift(['blogPosts']);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('blog_posts', $warnings[0]);
    }

    /**
     * @param  list<string>  $omit
     * @return list<string>
     */
    private function modelDrift(array $omit): array
    {
        return new KeyDriftDetector()->checkResource([
            'name' => 'AuthorResource',
            'fqcn' => AuthorResource::class,
            'shape' => ['kind' => 'model', 'model' => Author::class, 'omit' => $omit, 'extend' => [], 'via' => 'attribute'],
        ], $this->extractor()->extract(Author::class));
    }

    /**
     * @param  class-string  $class
     */
    private function readShape(string $class): string
    {
        $model = $this->extractor()->extract($class);
        $output = new TypeScriptRenderer()->renderModelFile($model, [], [$model], true, []);

        return (string) strstr($output, 'export type '.$model['name'].'Create', true);
    }

    private function extractor(): ModelExtractor
    {
        return new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver);
    }
}
