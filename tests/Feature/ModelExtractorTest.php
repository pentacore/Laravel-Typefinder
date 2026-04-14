<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\Post;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

class ModelExtractorTest extends TestCase
{
    private ModelExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new ModelExtractor(
            new ColumnTypeResolver,
            new CastTypeResolver,
        );
    }

    public function test_extracts_model_name(): void
    {
        $result = $this->extractor->extract(User::class);

        $this->assertSame('User', $result['name']);
        $this->assertSame(User::class, $result['fqcn']);
    }

    public function test_extracts_columns_with_types(): void
    {
        $result = $this->extractor->extract(User::class);

        $idCol = $this->findColumn($result, 'id');
        $this->assertSame('number', $idCol['type']);
        $this->assertFalse($idCol['nullable']);

        $nameCol = $this->findColumn($result, 'name');
        $this->assertSame('string', $nameCol['type']);

        $emailCol = $this->findColumn($result, 'email');
        $this->assertSame('string', $emailCol['type']);

        $isAdminCol = $this->findColumn($result, 'is_admin');
        $this->assertSame('boolean', $isAdminCol['type']);
    }

    public function test_nullable_columns_include_null(): void
    {
        $result = $this->extractor->extract(User::class);

        $settingsCol = $this->findColumn($result, 'settings');
        $this->assertTrue($settingsCol['nullable']);
    }

    public function test_cast_overrides_column_type(): void
    {
        $result = $this->extractor->extract(User::class);

        $settingsCol = $this->findColumn($result, 'settings');
        $this->assertSame('{ theme: string; notifications: boolean }', $settingsCol['type']);
    }

    public function test_enum_cast_detected(): void
    {
        $result = $this->extractor->extract(Post::class);

        $statusCol = $this->findColumn($result, 'status');
        $this->assertSame(PostStatus::class, $statusCol['type']['enum']);
    }

    public function test_type_overrides_take_priority(): void
    {
        $result = $this->extractor->extract(Post::class);

        $metadataCol = $this->findColumn($result, 'metadata');
        $this->assertSame('Record<string, string>', $metadataCol['type']);
    }

    public function test_datetime_cast_maps_to_string(): void
    {
        $result = $this->extractor->extract(Post::class);

        $publishedCol = $this->findColumn($result, 'published_at');
        $this->assertSame('string', $publishedCol['type']);
    }

    public function test_timestamps_included(): void
    {
        $result = $this->extractor->extract(Post::class);

        $this->assertNotNull($this->findColumn($result, 'created_at'));
        $this->assertNotNull($this->findColumn($result, 'updated_at'));
    }

    public function test_hidden_attributes_excluded(): void
    {
        // User has $hidden = ['password']
        $result = $this->extractor->extract(User::class);

        $this->assertNull($this->findColumn($result, 'password'), 'password should be excluded via $hidden');
        // Non-hidden columns still present
        $this->assertNotNull($this->findColumn($result, 'email'));
    }

    public function test_discovers_models_from_directory(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Models'));

        $names = array_column($results, 'name');
        $this->assertContains('User', $names);
        $this->assertContains('Post', $names);
        $this->assertContains('Comment', $names);
        $this->assertContains('Tag', $names);
        $this->assertContains('Role', $names);
        $this->assertCount(5, $results);
    }

    public function test_extracts_has_many_relationship(): void
    {
        $result = $this->extractor->extract(User::class);
        $rel = $this->findRelationship($result, 'posts');

        $this->assertSame('many', $rel['type']);
        $this->assertSame(Post::class, $rel['related']);
    }

    public function test_extracts_belongs_to_relationship(): void
    {
        $result = $this->extractor->extract(Post::class);
        $rel = $this->findRelationship($result, 'user');

        $this->assertSame('belongsTo', $rel['type']);
        $this->assertSame(User::class, $rel['related']);
    }

    public function test_extracts_morph_many_relationship(): void
    {
        $result = $this->extractor->extract(Post::class);
        $rel = $this->findRelationship($result, 'comments');

        $this->assertSame('many', $rel['type']);
        $this->assertSame(Comment::class, $rel['related']);
    }

    public function test_extracts_morph_to_relationship(): void
    {
        $result = $this->extractor->extract(Comment::class);
        $rel = $this->findRelationship($result, 'commentable');

        $this->assertSame('morphTo', $rel['type']);
    }

    public function test_extracts_belongs_to_many_with_pivot(): void
    {
        $result = $this->extractor->extract(User::class);
        $rel = $this->findRelationship($result, 'roles');

        $this->assertSame('manyWithPivot', $rel['type']);
        $this->assertSame(Role::class, $rel['related']);
        $this->assertArrayHasKey('pivot', $rel);
        $this->assertContains('assigned_at', $rel['pivot']['withPivot']);
        $this->assertTrue($rel['pivot']['withTimestamps']);
    }

    public function test_extracts_morph_to_many_with_pivot(): void
    {
        $result = $this->extractor->extract(Post::class);
        $rel = $this->findRelationship($result, 'tags');

        $this->assertSame('manyWithPivot', $rel['type']);
        $this->assertSame(Tag::class, $rel['related']);
        $this->assertArrayHasKey('morphType', $rel['pivot']);
    }

    public function test_extracts_morphed_by_many(): void
    {
        $result = $this->extractor->extract(Tag::class);
        $rel = $this->findRelationship($result, 'posts');

        $this->assertSame('manyWithPivot', $rel['type']);
        $this->assertSame(Post::class, $rel['related']);
    }

    private function findColumn(array $result, string $name): ?array
    {
        return collect($result['columns'])->firstWhere('name', $name);
    }

    private function findRelationship(array $result, string $name): ?array
    {
        return collect($result['relationships'])->firstWhere('name', $name);
    }
}
