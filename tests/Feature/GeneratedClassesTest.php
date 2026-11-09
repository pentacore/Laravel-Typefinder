<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Resources\LegacyResource;
use App\Http\Resources\UserResource;
use App\Models\LegacyModel;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Tests\Fixtures\AbstractResources\BaseResource;
use Tests\Fixtures\Enums\OutsideStatus;
use Tests\Fixtures\Resources\DatabaseNotificationResource;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class GeneratedClassesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
        ]);
    }

    public function test_discovers_models_like_resource_convention_did(): void
    {
        $models = new GeneratedClasses()->models();

        $this->assertContains(User::class, $models);
        $this->assertNotContains(DatabaseNotification::class, $models);
        $this->assertNotContains(LegacyModel::class, $models);
        $this->assertTrue(new GeneratedClasses()->isModel(User::class));
    }

    public function test_is_enum_only_for_backed_enums_in_paths(): void
    {
        $generated = new GeneratedClasses;

        $this->assertTrue($generated->isEnum(PostStatus::class));
        $this->assertFalse($generated->isEnum(OutsideStatus::class));
        $this->assertFalse($generated->isEnum(User::class));
    }

    public function test_is_resource_excludes_ignored_and_abstract(): void
    {
        $generated = new GeneratedClasses;

        $this->assertTrue($generated->isResource(UserResource::class));
        $this->assertFalse($generated->isResource(LegacyResource::class));
        $this->assertFalse($generated->isResource(DatabaseNotificationResource::class));

        config(['typefinder.resources.paths' => [workbench_path('app/Http/Resources'), __DIR__.'/../Fixtures/AbstractResources']]);
        $withAbstract = new GeneratedClasses;

        $this->assertTrue($withAbstract->isResource(UserResource::class));
        $this->assertFalse($withAbstract->isResource(BaseResource::class));
    }

    public function test_explicit_lists_skip_discovery(): void
    {
        $this->assertFalse(new GeneratedClasses(models: [])->isModel(User::class));
        $this->assertFalse(new GeneratedClasses(enums: [])->isEnum(PostStatus::class));
        $this->assertFalse(new GeneratedClasses(resources: [])->isResource(UserResource::class));
        $this->assertTrue(new GeneratedClasses(models: [User::class])->isModel(User::class));
    }

    public function test_disabled_categories_are_empty(): void
    {
        config([
            'typefinder.models.enabled' => false,
            'typefinder.enums.enabled' => false,
            'typefinder.resources.enabled' => false,
        ]);
        $generated = new GeneratedClasses;

        $this->assertSame([], $generated->models());
        $this->assertFalse($generated->isEnum(PostStatus::class));
        $this->assertFalse($generated->isResource(UserResource::class));
    }
}
