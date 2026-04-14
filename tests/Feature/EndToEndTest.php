<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

class EndToEndTest extends TestCase
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
        // HasTypeDefinition cast on settings
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
        // HasTypeOverrides: metadata should be Record<string, string>
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

        // Verify pivot output
        $this->assertDirectoryExists($this->outputPath.'/pivots');

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

    public function test_inertia_collision_throws(): void
    {
        $collisionDir = workbench_path('app/Http/CollisionControllers');
        File::ensureDirectoryExists($collisionDir);
        File::put($collisionDir.'/A.php', <<<'PHP'
<?php
namespace App\Http\CollisionControllers;
use Pentacore\Typefinder\Attributes\TypefinderPage;
class A { #[TypefinderPage('Shared')] public function a(): void {} }
PHP);
        File::put($collisionDir.'/B.php', <<<'PHP'
<?php
namespace App\Http\CollisionControllers;
use Pentacore\Typefinder\Attributes\TypefinderPage;
class B { #[TypefinderPage('Shared')] public function b(): void {} }
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
}
