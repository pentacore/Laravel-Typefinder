<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\Article;
use App\Models\Category;
use App\Models\Comment;
use App\Models\Invoice;
use App\Models\Post;
use App\Models\Product;
use App\Models\QuirkyThing;
use App\Models\Role;
use App\Models\Tag;
use App\Models\User;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\TypefinderRegistry;
use Tests\Fixtures\Casts\UnmappedCast;
use Tests\Fixtures\Models\JsonCastQuest;
use Tests\Fixtures\Models\ParameterisedCastQuest;
use Tests\Fixtures\Models\UnmappedCastQuest;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class ModelExtractorTest extends TestCase
{
    private ModelExtractor $modelExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->modelExtractor = new ModelExtractor(
            new ColumnTypeResolver,
            new CastTypeResolver,
        );
    }

    public function test_extracts_model_name(): void
    {
        $result = $this->modelExtractor->extract(User::class);

        $this->assertSame('User', $result['name']);
        $this->assertSame(User::class, $result['fqcn']);
    }

    public function test_extracts_columns_with_types(): void
    {
        $result = $this->modelExtractor->extract(User::class);

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
        $result = $this->modelExtractor->extract(User::class);

        $settingsCol = $this->findColumn($result, 'settings');
        $this->assertTrue($settingsCol['nullable']);
    }

    public function test_cast_overrides_column_type(): void
    {
        $result = $this->modelExtractor->extract(User::class);

        $settingsCol = $this->findColumn($result, 'settings');
        $this->assertSame('{ theme: string; notifications: boolean }', $settingsCol['type']);
    }

    public function test_enum_cast_detected(): void
    {
        $result = $this->modelExtractor->extract(Post::class);

        $statusCol = $this->findColumn($result, 'status');
        $this->assertSame(PostStatus::class, $statusCol['type']['enum']);
    }

    public function test_type_overrides_take_priority(): void
    {
        $result = $this->modelExtractor->extract(Post::class);

        $metadataCol = $this->findColumn($result, 'metadata');
        $this->assertSame('Record<string, string>', $metadataCol['type']);
    }

    public function test_datetime_cast_maps_to_string(): void
    {
        $result = $this->modelExtractor->extract(Post::class);

        $publishedCol = $this->findColumn($result, 'published_at');
        $this->assertSame('string', $publishedCol['type']);
    }

    public function test_timestamps_included(): void
    {
        $result = $this->modelExtractor->extract(Post::class);

        $this->assertNotNull($this->findColumn($result, 'created_at'));
        $this->assertNotNull($this->findColumn($result, 'updated_at'));
    }

    public function test_hidden_attributes_excluded(): void
    {
        // User has $hidden = ['password']
        $result = $this->modelExtractor->extract(User::class);

        $this->assertNull($this->findColumn($result, 'password'), 'password should be excluded via $hidden');
        // Non-hidden columns still present
        $this->assertNotNull($this->findColumn($result, 'email'));
    }

    public function test_discovers_models_from_directory(): void
    {
        $results = $this->modelExtractor->extractFromDirectory(workbench_path('app/Models'));

        $names = array_column($results, 'name');
        $this->assertContains('User', $names);
        $this->assertContains('Post', $names);
        $this->assertContains('Comment', $names);
        $this->assertContains('Tag', $names);
        $this->assertContains('Role', $names);
        $this->assertContains('Article', $names);
        $this->assertContains('Product', $names);
        $this->assertContains('Invoice', $names);
        $this->assertContains('QuirkyThing', $names);
        $this->assertContains('Quest', $names);
        $this->assertContains('Category', $names);
        $this->assertNotContains('LegacyModel', $names);
        $this->assertCount(11, $results);
    }

    public function test_unknown_column_type_emits_warning_and_falls_back_to_unknown(): void
    {
        $warnings = [];
        $modelExtractor = new ModelExtractor(
            new ColumnTypeResolver,
            new CastTypeResolver,
            function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
        );

        $result = $modelExtractor->extract(QuirkyThing::class);

        $shapeCol = $this->findColumn($result, 'shape');
        $this->assertSame('unknown', $shapeCol['type']);

        $this->assertCount(1, $warnings, 'expected exactly one warning for the geometry column');
        $this->assertStringContainsString('QuirkyThing', $warnings[0]);
        $this->assertStringContainsString('shape', $warnings[0]);
        $this->assertStringContainsString('geometry', $warnings[0]);
    }

    public function test_unknown_cast_emits_warning(): void
    {
        $warnings = [];
        $result = $this->warningExtractor($warnings)->extract(UnmappedCastQuest::class);

        $this->assertSame('unknown', $this->findColumn($result, 'rewards')['type']);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString(UnmappedCastQuest::class, $warnings[0]);
        $this->assertStringContainsString('rewards', $warnings[0]);
        $this->assertStringContainsString(UnmappedCast::class, $warnings[0]);
        $this->assertStringContainsString('#[TypefinderCast]', $warnings[0]);
        $this->assertStringContainsString('Typefinder::registerCast()', $warnings[0]);
        $this->assertStringContainsString('typefinder.casts.type_map', $warnings[0]);
    }

    public function test_resolvable_casts_emit_no_warning(): void
    {
        $warnings = [];
        $extractor = $this->warningExtractor($warnings);

        // datetime + BackedEnum + #[TypefinderCast] class
        $extractor->extract(Post::class);
        $extractor->extract(User::class);

        $this->assertSame([], $warnings);
    }

    public function test_json_cast_and_explicit_unknown_mappings_emit_no_warning(): void
    {
        $warnings = [];

        // Built-in 'json' => 'unknown'
        $result = $this->warningExtractor($warnings)->extract(JsonCastQuest::class);
        $this->assertSame('unknown', $this->findColumn($result, 'rewards')['type']);

        // typefinder.casts.type_map entry mapping to 'unknown'
        $result = $this->warningExtractor($warnings, new CastTypeResolver([UnmappedCast::class => 'unknown']))
            ->extract(UnmappedCastQuest::class);
        $this->assertSame('unknown', $this->findColumn($result, 'rewards')['type']);

        // Typefinder::registerCast() mapping to 'unknown'
        $registry = new TypefinderRegistry;
        $registry->registerCast(UnmappedCast::class, 'unknown');

        $result = $this->warningExtractor($warnings, new CastTypeResolver([], $registry))
            ->extract(UnmappedCastQuest::class);
        $this->assertSame('unknown', $this->findColumn($result, 'rewards')['type']);

        $this->assertSame([], $warnings);
    }

    public function test_parameterised_casts_emit_no_warning(): void
    {
        $warnings = [];
        $result = $this->warningExtractor($warnings)->extract(ParameterisedCastQuest::class);

        $this->assertSame('number', $this->findColumn($result, 'party_id')['type']);
        $this->assertSame('string', $this->findColumn($result, 'completed_at')['type']);
        $this->assertSame('{ amount: number; currency: string }', $this->findColumn($result, 'rewards')['type']);
        $this->assertSame([], $warnings);
    }

    public function test_cast_mapped_to_real_type_via_config_or_registry_emits_no_warning(): void
    {
        $warnings = [];

        // typefinder.casts.type_map entry
        $result = $this->warningExtractor($warnings, new CastTypeResolver([UnmappedCast::class => 'Reward[]']))
            ->extract(UnmappedCastQuest::class);
        $this->assertSame('Reward[]', $this->findColumn($result, 'rewards')['type']);

        // Typefinder::registerCast()
        $registry = new TypefinderRegistry;
        $registry->registerCast(UnmappedCast::class, 'Loot');

        $result = $this->warningExtractor($warnings, new CastTypeResolver([], $registry))
            ->extract(UnmappedCastQuest::class);
        $this->assertSame('Loot', $this->findColumn($result, 'rewards')['type']);

        $this->assertSame([], $warnings);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function warningExtractor(array &$warnings, ?CastTypeResolver $castTypeResolver = null): ModelExtractor
    {
        return new ModelExtractor(
            new ColumnTypeResolver,
            $castTypeResolver ?? new CastTypeResolver,
            function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
        );
    }

    public function test_extracts_has_many_relationship(): void
    {
        $result = $this->modelExtractor->extract(User::class);
        $rel = $this->findRelationship($result, 'posts');

        $this->assertSame('many', $rel['type']);
        $this->assertSame(Post::class, $rel['related']);
    }

    public function test_extracts_belongs_to_relationship(): void
    {
        $result = $this->modelExtractor->extract(Post::class);
        $rel = $this->findRelationship($result, 'user');

        $this->assertSame('belongsTo', $rel['type']);
        $this->assertSame(User::class, $rel['related']);
    }

    public function test_extracts_morph_many_relationship(): void
    {
        $result = $this->modelExtractor->extract(Post::class);
        $rel = $this->findRelationship($result, 'comments');

        $this->assertSame('many', $rel['type']);
        $this->assertSame(Comment::class, $rel['related']);
    }

    public function test_extracts_morph_to_relationship(): void
    {
        $result = $this->modelExtractor->extract(Comment::class);
        $rel = $this->findRelationship($result, 'commentable');

        $this->assertSame('morphTo', $rel['type']);
    }

    public function test_extracts_belongs_to_many_with_pivot(): void
    {
        $result = $this->modelExtractor->extract(User::class);
        $rel = $this->findRelationship($result, 'roles');

        $this->assertSame('manyWithPivot', $rel['type']);
        $this->assertSame(Role::class, $rel['related']);
        $this->assertArrayHasKey('pivot', $rel);
        $this->assertContains('assigned_at', $rel['pivot']['withPivot']);
        $this->assertTrue($rel['pivot']['withTimestamps']);
    }

    public function test_extracts_morph_to_many_with_pivot(): void
    {
        $result = $this->modelExtractor->extract(Post::class);
        $rel = $this->findRelationship($result, 'tags');

        $this->assertSame('manyWithPivot', $rel['type']);
        $this->assertSame(Tag::class, $rel['related']);
        $this->assertArrayHasKey('morphType', $rel['pivot']);
    }

    public function test_pivot_without_timestamps_reports_with_timestamps_false(): void
    {
        $result = $this->modelExtractor->extract(Post::class);
        $rel = $this->findRelationship($result, 'tags');

        $this->assertFalse($rel['pivot']['withTimestamps']);
    }

    public function test_extracts_self_referencing_relations(): void
    {
        $result = $this->modelExtractor->extract(Category::class);

        $this->assertSame(Category::class, $this->findRelationship($result, 'parent')['related']);
        $this->assertSame(Category::class, $this->findRelationship($result, 'children')['related']);
    }

    public function test_extracts_morphed_by_many(): void
    {
        $result = $this->modelExtractor->extract(Tag::class);
        $rel = $this->findRelationship($result, 'posts');

        $this->assertSame('manyWithPivot', $rel['type']);
        $this->assertSame(Post::class, $rel['related']);
    }

    public function test_extract_flags_primary_key_and_timestamps_as_server_filled(): void
    {
        $result = $this->modelExtractor->extract(User::class);
        $byName = collect($result['columns'])->keyBy('name');

        $this->assertTrue($byName['id']['is_primary']);
        $this->assertTrue($byName['id']['is_server_filled']);
        $this->assertTrue($byName['created_at']['is_server_filled']);
        $this->assertTrue($byName['updated_at']['is_server_filled']);
        $this->assertFalse($byName['name']['is_server_filled']);
        $this->assertFalse($byName['name']['is_primary']);
    }

    public function test_extract_honours_contract_server_filled_override(): void
    {
        $result = $this->modelExtractor->extract(Invoice::class);
        $byName = collect($result['columns'])->keyBy('name');

        $this->assertTrue($byName['reference']['is_server_filled']);
    }

    public function test_extract_assignable_columns_respects_fillable(): void
    {
        $result = $this->modelExtractor->extract(Article::class);
        $names = array_map(fn (array $c) => $c['name'], $result['assignable_columns']);

        $this->assertContains('title', $names);
        $this->assertContains('body', $names);
        $this->assertNotContains('id', $names);
        $this->assertNotContains('created_at', $names);
    }

    public function test_extract_assignable_columns_respects_guarded(): void
    {
        $result = $this->modelExtractor->extract(Product::class);
        $names = array_map(fn (array $c) => $c['name'], $result['assignable_columns']);

        $this->assertContains('name', $names);
        $this->assertNotContains('owner_id', $names);
    }

    public function test_extract_assignable_columns_ignores_mass_assignment_when_disabled(): void
    {
        $result = $this->modelExtractor->extract(Invoice::class);
        $names = array_map(fn (array $c) => $c['name'], $result['assignable_columns']);

        // Invoice opts out of respecting mass assignment → returns all visible cols
        $this->assertContains('internal_note', $names);
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
