<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\ComposedLiteralResource;
use App\Http\Resources\ComposedParentResource;
use App\Http\Resources\ComposedResource;
use App\Http\Resources\TagResource;
use App\Http\Resources\UnmappableShapeResource;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class EndToEndTest extends TestCase
{
    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outputPath = sys_get_temp_dir().'/typefinder-e2e-'.uniqid();
        config(['typefinder.output_path' => $this->outputPath]);
        config(['typefinder.models.paths' => [workbench_path('app/Models')]]);
        config(['typefinder.enums.paths' => [workbench_path('app/Enums')]]);
        config(['typefinder.requests.paths' => [workbench_path('app/Http/Requests')]]);
        config(['typefinder.resources.paths' => [workbench_path('app/Http/Resources')]]);
    }

    protected function tearDown(): void
    {
        if (File::isDirectory($this->outputPath)) {
            File::deleteDirectory($this->outputPath);
        }

        parent::tearDown();
    }

    public function test_full_generation_pipeline(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        // Verify enum output
        $postStatusContent = File::get($this->outputPath.'/enums/PostStatus.d.ts');
        $this->assertStringContainsString("export type PostStatus = 'draft' | 'published' | 'archived';", $postStatusContent);

        $postTagContent = File::get($this->outputPath.'/enums/PostTag.d.ts');
        $this->assertStringContainsString("export type PostTag = 'tech' | 'science' | 'art';", $postTagContent);

        // Verify model output — User
        $userContent = File::get($this->outputPath.'/models/User.d.ts');
        $this->assertStringContainsString('export type User = {', $userContent);
        $this->assertStringContainsString('id: number;', $userContent);
        $this->assertStringContainsString('name: string;', $userContent);
        $this->assertStringContainsString('email: string;', $userContent);
        $this->assertStringContainsString('is_admin: boolean;', $userContent);
        // #[TypefinderCast] attribute on SettingsCast
        $this->assertStringContainsString('{ theme: string; notifications: boolean }', $userContent);
        // Relationships
        $this->assertStringContainsString('posts?: Post[];', $userContent);
        $this->assertStringContainsString('roles?:', $userContent);
        // $hidden filtering: password should not be present
        $this->assertStringNotContainsString('password:', $userContent);

        // Verify model output — Post
        $postContent = File::get($this->outputPath.'/models/Post.d.ts');
        $this->assertStringContainsString('export type Post = {', $postContent);
        // Enum cast
        $this->assertStringContainsString('status: PostStatus;', $postContent);
        // #[TypefinderOverrides]: metadata should be Record<string, string>
        $this->assertStringContainsString('Record<string, string>', $postContent);
        // Relationships
        $this->assertStringContainsString('user?: User | null;', $postContent);
        $this->assertStringContainsString('comments?:', $postContent);
        $this->assertStringContainsString('tags?:', $postContent);

        // Verify model output — Comment (morphTo with generics)
        $commentContent = File::get($this->outputPath.'/models/Comment.d.ts');
        $this->assertStringContainsString('Comment<T extends', $commentContent);
        $this->assertStringContainsString('commentable?: T | null;', $commentContent);

        // Verify request output
        $storePostContent = File::get($this->outputPath.'/requests/StorePostRequest.d.ts');
        $this->assertStringContainsString('export type StorePostRequest = {', $storePostContent);
        $this->assertStringContainsString('title: string;', $storePostContent);
        $this->assertStringContainsString('status: PostStatus;', $storePostContent);
        $this->assertStringContainsString('tags?: string[]', $storePostContent);
        // 'in' rule
        $this->assertStringContainsString("'tech' | 'science' | 'art'", $storePostContent);

        // Verify update request with confirmed field
        $updatePostContent = File::get($this->outputPath.'/requests/UpdatePostRequest.d.ts');
        $this->assertStringContainsString('password_confirmation', $updatePostContent);

        // Verify pivot output (pivots now live alongside models)
        $this->assertFileExists($this->outputPath.'/models/RoleUserPivot.d.ts');

        // Verify barrel files
        $topBarrel = File::get($this->outputPath.'/index.d.ts');
        $this->assertStringContainsString("export type * from './models';", $topBarrel);
        $this->assertStringContainsString("export type * from './enums';", $topBarrel);
        $this->assertStringContainsString("export type * from './requests';", $topBarrel);

        $modelBarrel = File::get($this->outputPath.'/models/index.d.ts');
        $this->assertStringContainsString("export type { User } from './User';", $modelBarrel);
        $this->assertStringContainsString("export type { Post } from './Post';", $modelBarrel);
    }

    public function test_idempotent_generation(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();
        $firstRun = File::get($this->outputPath.'/models/User.d.ts');

        $this->artisan('typefinder:generate')->assertSuccessful();
        $secondRun = File::get($this->outputPath.'/models/User.d.ts');

        $this->assertSame($firstRun, $secondRun);
    }

    public function test_config_overrides_cast_types(): void
    {
        config(['typefinder.casts.type_map' => ['datetime' => 'Date']]);

        $this->artisan('typefinder:generate')->assertSuccessful();

        $postContent = File::get($this->outputPath.'/models/Post.d.ts');
        // published_at is cast to 'datetime', which should now map to 'Date'
        $this->assertStringContainsString('Date', $postContent);
    }

    public function test_selective_generation(): void
    {
        config([
            'typefinder.models.enabled' => true,
            'typefinder.enums.enabled' => false,
            'typefinder.requests.enabled' => false,
        ]);

        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertDirectoryExists($this->outputPath.'/models');
        $this->assertDirectoryDoesNotExist($this->outputPath.'/enums');
        $this->assertDirectoryDoesNotExist($this->outputPath.'/requests');
    }

    public function test_write_shapes_are_emitted_in_the_same_file_by_default(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileExists($this->outputPath.'/models/User.d.ts');
        $this->assertFileDoesNotExist($this->outputPath.'/models/UserCreate.d.ts');
        $this->assertFileDoesNotExist($this->outputPath.'/models/UserUpdate.d.ts');

        $content = File::get($this->outputPath.'/models/User.d.ts');
        $this->assertStringContainsString('export type User', $content);
        $this->assertStringContainsString('export type UserCreate = {', $content);
        $this->assertStringContainsString('export type UserUpdate = {', $content);
    }

    public function test_write_shapes_can_be_disabled(): void
    {
        config(['typefinder.models.emit_write_shapes' => false]);

        $this->artisan('typefinder:generate')->assertSuccessful();

        $content = File::get($this->outputPath.'/models/User.d.ts');
        $this->assertStringContainsString('export type User', $content);
        $this->assertStringNotContainsString('UserCreate', $content);
        $this->assertStringNotContainsString('UserUpdate', $content);
    }

    public function test_appends_output_path_to_root_gitignore_when_enabled(): void
    {
        $gitignore = base_path('.gitignore');
        $original = File::exists($gitignore)
            ? File::get($gitignore)
            : null;

        try {
            // Put the output inside base_path so the relative-path check succeeds.
            $outputPath = base_path('generated-test-'.uniqid());
            config(['typefinder.output_path' => $outputPath]);
            File::put($gitignore, "/vendor\n");

            $this->artisan('typefinder:generate')->assertSuccessful();

            $content = File::get($gitignore);
            $relative = '/'.basename($outputPath);
            $this->assertStringContainsString('# Generated by typefinder', $content);
            $this->assertStringContainsString($relative, $content);

            // Idempotent: running again does not duplicate the entry.
            $this->artisan('typefinder:generate')->assertSuccessful();
            $contentAfterSecondRun = File::get($gitignore);
            $this->assertSame(
                substr_count($content, $relative),
                substr_count($contentAfterSecondRun, $relative),
            );

            File::deleteDirectory($outputPath);
        } finally {
            if ($original === null) {
                File::delete($gitignore);
            } else {
                File::put($gitignore, $original);
            }
        }
    }

    public function test_respects_gitignore_generated_false(): void
    {
        $gitignore = base_path('.gitignore');
        $original = File::exists($gitignore)
            ? File::get($gitignore)
            : null;

        try {
            $outputPath = base_path('generated-test-'.uniqid());
            config([
                'typefinder.output_path' => $outputPath,
                'typefinder.gitignore_generated' => false,
            ]);
            File::put($gitignore, "/vendor\n");

            $this->artisan('typefinder:generate')->assertSuccessful();

            $content = File::get($gitignore);
            $this->assertStringNotContainsString('typefinder', $content);
            $this->assertStringNotContainsString(basename($outputPath), $content);

            File::deleteDirectory($outputPath);
        } finally {
            if ($original === null) {
                File::delete($gitignore);
            } else {
                File::put($gitignore, $original);
            }
        }
    }

    public function test_write_shapes_respect_per_model_contract(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $content = File::get($this->outputPath.'/models/Invoice.d.ts');

        preg_match('/export type InvoiceCreate = \{(.+?)\n\};/s', $content, $createMatch);
        $this->assertNotEmpty($createMatch, 'InvoiceCreate block not found');
        $this->assertStringNotContainsString('reference', $createMatch[1]);

        preg_match('/export type InvoiceUpdate = \{(.+?)\n\};/s', $content, $updateMatch);
        $this->assertNotEmpty($updateMatch, 'InvoiceUpdate block not found');
        $this->assertStringNotContainsString('customer_id', $updateMatch[1]);
    }

    public function test_inertia_is_skipped_by_default(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileDoesNotExist($this->outputPath.'/pages.d.ts');

        $barrel = File::get($this->outputPath.'/index.d.ts');
        $this->assertStringNotContainsString("export type * from './pages';", $barrel);
    }

    public function test_inertia_pages_are_generated_when_enabled(): void
    {
        config([
            'typefinder.inertia.enabled' => true,
            'typefinder.inertia.paths' => [workbench_path('app/Http/Controllers')],
        ]);

        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileExists($this->outputPath.'/pages.d.ts');
        $content = File::get($this->outputPath.'/pages.d.ts');
        $this->assertStringContainsString('export type PageProps = {', $content);
        $this->assertStringContainsString("'Users/Show':", $content);
        $this->assertStringContainsString("'Dashboard':", $content);
        $this->assertStringContainsString('export type PageName = keyof PageProps;', $content);

        $barrel = File::get($this->outputPath.'/index.d.ts');
        $this->assertStringContainsString("export type * from './pages';", $barrel);
    }

    public function test_inertia_conflicting_prop_types_fail_the_command(): void
    {
        $collisionDir = workbench_path('app/Http/CollisionControllers');
        File::ensureDirectoryExists($collisionDir);
        File::put($collisionDir.'/A.php', <<<'PHP'
<?php
namespace App\Http\CollisionControllers;
use Pentacore\Typefinder\Attributes\TypefinderPage;
class A { #[TypefinderPage('Shared', ['x' => 'string'])] public function a(): void {} }
PHP);
        File::put($collisionDir.'/B.php', <<<'PHP'
<?php
namespace App\Http\CollisionControllers;
use Pentacore\Typefinder\Attributes\TypefinderPage;
class B { #[TypefinderPage('Shared', ['x' => 'number'])] public function b(): void {} }
PHP);

        config([
            'typefinder.inertia.enabled' => true,
            'typefinder.inertia.paths' => [$collisionDir],
        ]);

        try {
            $this->artisan('typefinder:generate')->assertFailed();
        } finally {
            File::deleteDirectory($collisionDir);
        }
    }

    public function test_broadcasting_is_skipped_by_default(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileDoesNotExist($this->outputPath.'/broadcasting.d.ts');

        $barrel = File::get($this->outputPath.'/index.d.ts');
        $this->assertStringNotContainsString("export type * from './broadcasting';", $barrel);
    }

    public function test_broadcasting_events_are_generated_when_enabled(): void
    {
        config([
            'typefinder.broadcasting.enabled' => true,
            'typefinder.broadcasting.paths' => [workbench_path('app/Events')],
        ]);

        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileExists($this->outputPath.'/broadcasting.d.ts');
        $content = File::get($this->outputPath.'/broadcasting.d.ts');
        $this->assertStringContainsString('export type BroadcastPublicChannels = {', $content);
        $this->assertStringContainsString('export type BroadcastPrivateChannels = {', $content);
        $this->assertStringContainsString('export type BroadcastPresenceChannels = {', $content);
        $this->assertStringContainsString('export type BroadcastEvents = {', $content);
        $this->assertStringContainsString("'PostPublished':", $content);
        $this->assertStringContainsString("'orders.{orderId}':", $content);
        $this->assertStringContainsString("'chat.{roomId}':", $content);

        // Subclass events whose `broadcastOn()` interpolates a parent
        // ctor-promoted readonly property must still resolve, with the
        // property name surfacing as a `{name}` placeholder in the channel.
        $this->assertStringContainsString("'party.{partyId}':", $content);
        $this->assertStringContainsString("'QuestAdded':", $content);
        $this->assertStringContainsString("'QuestRemoved':", $content);
        $this->assertStringContainsString("'QuestUpdated':", $content);

        $barrel = File::get($this->outputPath.'/index.d.ts');
        $this->assertStringContainsString("export type * from './broadcasting';", $barrel);
    }

    public function test_broadcasting_collision_throws(): void
    {
        $collisionDir = workbench_path('app/CollisionEvents');
        File::ensureDirectoryExists($collisionDir);
        File::put($collisionDir.'/A.php', <<<'PHP'
<?php
namespace App\CollisionEvents;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
class A implements ShouldBroadcast {
    public function broadcastOn(): Channel { return new Channel('shared'); }
    public function broadcastAs(): string { return 'Dup'; }
}
PHP);
        File::put($collisionDir.'/B.php', <<<'PHP'
<?php
namespace App\CollisionEvents;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
class B implements ShouldBroadcast {
    public function broadcastOn(): Channel { return new Channel('shared'); }
    public function broadcastAs(): string { return 'Dup'; }
}
PHP);

        config([
            'typefinder.broadcasting.enabled' => true,
            'typefinder.broadcasting.paths' => [$collisionDir],
        ]);

        try {
            $this->artisan('typefinder:generate')->assertFailed();
        } finally {
            File::deleteDirectory($collisionDir);
        }
    }

    public function test_check_mode_passes_when_output_is_up_to_date(): void
    {
        // Generate once so the real output directory matches what the command would produce.
        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->artisan('typefinder:generate', ['--check' => true])
            ->expectsOutputToContain('TypeScript types are up to date.')
            ->assertSuccessful();
    }

    public function test_check_mode_fails_when_file_is_missing(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();
        File::delete($this->outputPath.'/models/User.d.ts');

        $this->artisan('typefinder:generate', ['--check' => true])
            ->expectsOutputToContain('models/User.d.ts')
            ->assertFailed();
    }

    public function test_check_mode_explains_missing_output_directory(): void
    {
        // No prior generation: the output directory does not exist, as in a
        // fresh CI checkout where the output is git-ignored.
        $this->artisan('typefinder:generate', ['--check' => true])
            ->expectsOutputToContain('does not exist, so there is nothing to compare against')
            ->assertFailed();
    }

    public function test_check_mode_json_reports_missing_output_directory_as_warning(): void
    {
        $exitCode = Artisan::call('typefinder:generate', ['--check' => true, '--json' => true]);
        $this->assertSame(1, $exitCode);

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success']);
        $this->assertNotEmpty(array_filter(
            $decoded['warnings'],
            fn (string $w): bool => str_contains($w, 'does not exist'),
        ));
    }

    public function test_check_mode_fails_when_file_is_stale(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();
        File::put($this->outputPath.'/models/User.d.ts', "// manually edited\n");

        $this->artisan('typefinder:generate', ['--check' => true])
            ->expectsOutputToContain('models/User.d.ts')
            ->assertFailed();
    }

    public function test_resources_generated_when_resources_directory_exists(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileExists($this->outputPath.'/resources/UserResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/AdminUserResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/PostResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/CommentResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/ProductResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/ShapeShowcaseResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/UnmappableShapeResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/InheritedShapeResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/ComposedResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/ComposedParentResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/ComposedLiteralResource.d.ts');
        $this->assertFileExists($this->outputPath.'/resources/TagResource.d.ts');
        $this->assertFileDoesNotExist($this->outputPath.'/resources/BaseShapeResource.d.ts');
        $this->assertFileDoesNotExist($this->outputPath.'/resources/LegacyResource.d.ts');
        $this->assertFileDoesNotExist($this->outputPath.'/resources/OrphanResource.d.ts');
        $this->assertFileDoesNotExist($this->outputPath.'/resources/InvalidResource.d.ts');

        $adminContent = File::get($this->outputPath.'/resources/AdminUserResource.d.ts');
        $this->assertStringContainsString("export type AdminUserResource = Omit<User, 'password'> & { roles: string[] };", $adminContent);
    }

    public function test_phpdoc_resources_rendered(): void
    {
        $regenResult = app(Generator::class)->generateFull();
        $dir = $this->outputPath.'/resources';

        $comment = File::get($dir.'/CommentResource.d.ts');
        $this->assertStringContainsString("import type { UserResource } from './UserResource';", $comment);
        $this->assertStringContainsString('export type CommentResource = {', $comment);
        $this->assertStringContainsString('  id: number;', $comment);
        $this->assertStringContainsString('  body: string;', $comment);
        $this->assertStringContainsString('  author: UserResource;', $comment);
        $this->assertStringContainsString('  created_at: string | null;', $comment);
        $this->assertStringNotContainsString("from '../models'", $comment);

        // The make:resource stub docblock is not a shape: unchanged output.
        $product = File::get($dir.'/ProductResource.d.ts');
        $this->assertStringContainsString("import type { Product } from '../models';", $product);
        $this->assertStringContainsString("export type ProductResource = Product;\n", $product);

        $showcase = File::get($dir.'/ShapeShowcaseResource.d.ts');
        $this->assertStringContainsString("import type { PostStatus } from '../enums';", $showcase);
        $this->assertStringContainsString("import type { User } from '../models';", $showcase);
        $this->assertStringContainsString("import type { CommentResource } from './CommentResource';", $showcase);
        $this->assertStringContainsString("import type { PostResource } from './PostResource';", $showcase);
        $this->assertStringContainsString('  meta: { total: number; tags: string[] };', $showcase);
        $this->assertStringContainsString('  comments: CommentResource[];', $showcase);
        $this->assertStringContainsString('  scores: Record<string, number>;', $showcase);
        $this->assertStringContainsString('  status: PostStatus;', $showcase);
        $this->assertStringContainsString('  editor: User | null;', $showcase);
        $this->assertStringContainsString('  posts?: PostResource[];', $showcase);
        $this->assertStringContainsString('  pair: [number, string];', $showcase);
        $this->assertStringContainsString("  stage: 'draft' | 'published';", $showcase);
        $this->assertStringContainsString('  replies: CommentResource[];', $showcase);
        $this->assertStringContainsString('  published_at: string;', $showcase);
        $this->assertStringContainsString('  extra: { source: string; [key: string]: unknown };', $showcase);
        $this->assertStringContainsString("  'foo-bar': boolean;", $showcase);

        $this->assertStringContainsString('  handler: unknown;', File::get($dir.'/UnmappableShapeResource.d.ts'));

        // Abstract parent: no file of its own; the child inherits its doc.
        $this->assertFileDoesNotExist($dir.'/BaseShapeResource.d.ts');
        $inherited = File::get($dir.'/InheritedShapeResource.d.ts');
        $this->assertStringContainsString("import type { User } from '../models';", $inherited);
        $this->assertStringContainsString('  owner: User;', $inherited);
        $this->assertStringContainsString('  self: InheritedShapeResource | null;', $inherited);

        $barrel = File::get($dir.'/index.d.ts');
        $this->assertStringContainsString('CommentResource', $barrel);
        $this->assertStringNotContainsString('BaseShapeResource', $barrel);

        $unmappable = array_filter(
            $regenResult->warnings,
            static fn (string $warning): bool => str_contains($warning, UnmappableShapeResource::class),
        );
        $this->assertSame(
            [UnmappableShapeResource::class.'::toArray() @return: "handler" has unsupported type callable — emitted as unknown'],
            array_values($unmappable),
        );
    }

    public function test_composed_resources_rendered(): void
    {
        $regenResult = app(Generator::class)->generateFull();
        $dir = $this->outputPath.'/resources';

        $composed = File::get($dir.'/ComposedResource.d.ts');
        $this->assertStringContainsString("import type { PostStatus } from '../enums';\nimport type { UserResource } from './UserResource';\n", $composed);
        $this->assertStringContainsString(
            "export type ComposedResource = {\n  id: number;\n  title: string;\n  status: PostStatus;\n  comments_count: number;\n  author?: UserResource;\n};\n",
            $composed,
        );

        // `User` comes from the abstract parent's imports, `static` is the child.
        $parent = File::get($dir.'/ComposedParentResource.d.ts');
        $this->assertStringContainsString("import type { User } from '../models';\n", $parent);
        $this->assertStringContainsString(
            "export type ComposedParentResource = {\n  id: number;\n  owner: User;\n  self: ComposedParentResource | null;\n  tags: string[];\n  featured?: boolean;\n};\n",
            $parent,
        );

        $literal = File::get($dir.'/ComposedLiteralResource.d.ts');
        $this->assertStringNotContainsString('import type', $literal);
        $this->assertStringContainsString(
            "export type ComposedLiteralResource = {\n  id: number;\n  title: string;\n  note: unknown;\n};\n",
            $literal,
        );

        // parent::toArray() from JsonResource is not composable: the name convention, as before.
        $tag = File::get($dir.'/TagResource.d.ts');
        $this->assertStringContainsString("import type { Tag } from '../models';", $tag);
        $this->assertStringContainsString("export type TagResource = Tag;\n", $tag);

        $barrel = File::get($dir.'/index.d.ts');
        foreach (['ComposedResource', 'ComposedParentResource', 'ComposedLiteralResource', 'TagResource'] as $name) {
            $this->assertStringContainsString("'./{$name}'", $barrel);
        }

        // The composition warning, exactly once; no other warning for these resources.
        foreach ([ComposedResource::class, ComposedParentResource::class, ComposedLiteralResource::class, TagResource::class] as $class) {
            $mentions = array_values(array_filter(
                $regenResult->warnings,
                static fn (string $warning): bool => str_contains($warning, $class.':'),
            ));
            $expected = match ($class) {
                ComposedLiteralResource::class => [ComposedLiteralResource::class.'::toArray() composes a shape but "note" has no documented type — add @return array{…} to toArray()'],
                // Typed `Tag` by name, but `toArray()` adds a key the model lacks: model drift.
                TagResource::class => [TagResource::class.'::toArray() does not match the Tag model type it is typed as (by name): returns undeclared "x" — adjust it with #[TypefinderResource(model: …, omit: …, extend: …)], declare its shape with #[TypefinderResource(shape: …)] or a PHPDoc @return array{…} on toArray(), or set typefinder.resources.infer_from_model_name to false'],
                default => [],
            };
            $this->assertSame($expected, $mentions, $class);
        }
    }

    public function test_helpers_file_is_always_emitted(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileExists($this->outputPath.'/helpers.d.ts');
        $content = File::get($this->outputPath.'/helpers.d.ts');
        $this->assertStringContainsString('export type WrappedResource<T>', $content);
        $this->assertStringContainsString('export type PaginatedResourceCollection<T>', $content);
        $this->assertStringContainsString('export type PaginatedModel<TData>', $content);
        $this->assertStringContainsString('export type PaginationFields', $content);
        $this->assertStringContainsString('export type ValidationErrorResponse', $content);
    }

    public function test_top_level_barrel_re_exports_helpers_and_resources(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $barrel = File::get($this->outputPath.'/index.d.ts');
        $this->assertStringContainsString("export type * from './helpers';", $barrel);
        $this->assertStringContainsString("export type * from './resources';", $barrel);
    }

    public function test_resource_with_typefinder_ignore_is_skipped(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $barrel = File::get($this->outputPath.'/resources/index.d.ts');
        $this->assertStringNotContainsString('LegacyResource', $barrel);
    }

    public function test_check_mode_with_json_outputs_structured_result_on_pass(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        $exitCode = Artisan::call('typefinder:generate', ['--check' => true, '--json' => true]);
        $this->assertSame(0, $exitCode);

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['success']);
        $this->assertIsInt($decoded['duration_ms']);
    }

    public function test_check_mode_with_json_outputs_drift_on_fail(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();
        File::put($this->outputPath.'/models/User.d.ts', "// manually edited\n");

        $exitCode = Artisan::call('typefinder:generate', ['--check' => true, '--json' => true]);
        $this->assertSame(1, $exitCode);

        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['success']);
    }

    public function test_check_mode_detects_stale_files_on_disk(): void
    {
        $this->artisan('typefinder:generate')->assertSuccessful();

        // Plant a file that the generator wouldn't produce
        File::put($this->outputPath.'/models/StaleExtra.d.ts', '// stale');

        $this->artisan('typefinder:generate', ['--check' => true])
            ->expectsOutputToContain('StaleExtra.d.ts')
            ->assertFailed();
    }

    public function test_enum_emit_values_produces_ts_files_via_command(): void
    {
        config(['typefinder.enums.emit_values' => true]);

        $this->artisan('typefinder:generate')->assertSuccessful();

        $this->assertFileExists($this->outputPath.'/enums/PostStatus.ts');
        $this->assertFileDoesNotExist($this->outputPath.'/enums/PostStatus.d.ts');

        $content = File::get($this->outputPath.'/enums/PostStatus.ts');
        $this->assertStringContainsString('as const', $content);

        $barrel = File::get($this->outputPath.'/enums/index.ts');
        $this->assertStringContainsString('export * from', $barrel);
    }
}
