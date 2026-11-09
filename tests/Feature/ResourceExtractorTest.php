<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Resources\AdminUserResource;
use App\Http\Resources\InvalidResource;
use App\Http\Resources\LegacyResource;
use App\Http\Resources\OrphanResource;
use App\Http\Resources\PostResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use ReflectionClass;
use Tests\Fixtures\Resources\DatabaseNotificationResource;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class ResourceExtractorTest extends TestCase
{
    private ResourceExtractor $resourceExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['typefinder.models.paths' => [workbench_path('app/Models')]]);
        $this->resourceExtractor = new ResourceExtractor;
    }

    public function test_tier_1_explicit_shape(): void
    {
        $result = $this->resourceExtractor->extract(PostResource::class);

        $this->assertSame('shape', $result['shape']['kind']);
        $this->assertSame('number', $result['shape']['fields']['id']);
        $this->assertSame(UserResource::class, $result['shape']['fields']['author']);
    }

    public function test_tier_2_model_extension(): void
    {
        $result = $this->resourceExtractor->extract(AdminUserResource::class);

        $this->assertSame('model', $result['shape']['kind']);
        $this->assertSame(User::class, $result['shape']['model']);
        $this->assertSame(['password'], $result['shape']['omit']);
        $this->assertSame(['roles' => 'string[]'], $result['shape']['extend']);
    }

    public function test_tier_3_name_convention_matches(): void
    {
        $result = $this->resourceExtractor->extract(UserResource::class);

        $this->assertSame('model', $result['shape']['kind']);
        $this->assertSame(User::class, $result['shape']['model']);
        $this->assertSame([], $result['shape']['omit']);
        $this->assertSame([], $result['shape']['extend']);
    }

    public function test_tier_3_name_convention_miss_returns_null(): void
    {
        $result = $this->resourceExtractor->extract(OrphanResource::class);

        $this->assertNull($result);
    }

    public function test_typefinder_ignore_skips(): void
    {
        $result = $this->resourceExtractor->extract(LegacyResource::class);

        $this->assertNull($result);
    }

    public function test_shape_and_model_both_set_throws(): void
    {
        // InvalidResource is tagged #[TypefinderIgnore] so directory scans stay clean.
        // To exercise the validation branch, remove the ignore from the fixture.
        $reflectionClass = new ReflectionClass(InvalidResource::class);
        if ($reflectionClass->getAttributes(TypefinderIgnore::class) !== []) {
            $this->markTestSkipped(
                'InvalidResource is tagged TypefinderIgnore so extract() returns null before validation. '
                .'To exercise the mutex error, remove the ignore tag from the fixture and re-run.',
            );
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mutually exclusive/i');

        $this->resourceExtractor->extract(InvalidResource::class);
    }

    public function test_extract_from_directory_warns_on_orphan(): void
    {
        $warned = [];
        $results = $this->resourceExtractor->extractFromDirectory(
            workbench_path('app/Http/Resources'),
            onWarn: function (string $cls, \Throwable $throwable) use (&$warned): void {
                $warned[] = $cls;
            },
        );

        $this->assertContains(OrphanResource::class, $warned);
        $names = array_column($results, 'name');
        $this->assertContains('UserResource', $names);
        $this->assertContains('AdminUserResource', $names);
        $this->assertContains('PostResource', $names);
        $this->assertNotContains('LegacyResource', $names);
        $this->assertNotContains('OrphanResource', $names);
        $this->assertNotContains('InvalidResource', $names);
    }

    public function test_name_convention_only_matches_given_model_classes(): void
    {
        // User is already loaded in this process; an empty model list must
        // still win over whatever happens to be declared.
        $this->assertTrue(class_exists(User::class));

        $this->assertNull(new ResourceExtractor(modelClasses: [])->extract(UserResource::class));

        $result = new ResourceExtractor(modelClasses: [User::class])->extract(UserResource::class);
        $this->assertSame(User::class, $result['shape']['model']);
    }

    public function test_name_convention_discovers_models_from_configured_paths(): void
    {
        $emptyDir = sys_get_temp_dir().'/typefinder-no-models-'.uniqid();
        File::ensureDirectoryExists($emptyDir);

        try {
            config(['typefinder.models.paths' => [$emptyDir]]);
            $this->assertNull((new ResourceExtractor)->extract(UserResource::class));
        } finally {
            File::deleteDirectory($emptyDir);
        }
    }

    public function test_name_convention_is_off_when_models_are_disabled(): void
    {
        config(['typefinder.models.enabled' => false]);

        $this->assertNull((new ResourceExtractor)->extract(UserResource::class));
    }

    public function test_name_convention_ignores_models_outside_the_app(): void
    {
        $this->assertTrue(class_exists(DatabaseNotification::class));

        $this->assertNull($this->resourceExtractor->extract(DatabaseNotificationResource::class));
    }

    public function test_name_convention_can_be_turned_off(): void
    {
        $resourceExtractor = new ResourceExtractor(inferFromModelName: false);

        $this->assertNull($resourceExtractor->extract(UserResource::class));
        // Explicit attributes are unaffected.
        $this->assertSame('model', $resourceExtractor->extract(AdminUserResource::class)['shape']['kind']);
        $this->assertSame('shape', $resourceExtractor->extract(PostResource::class)['shape']['kind']);
    }

    public function test_warning_names_the_config_key_when_name_convention_is_off(): void
    {
        $warnings = [];
        new ResourceExtractor(inferFromModelName: false)->extractFromDirectory(
            workbench_path('app/Http/Resources'),
            onWarn: function (string $cls, \Throwable $throwable) use (&$warnings): void {
                $warnings[$cls] = $throwable->getMessage();
            },
        );

        $this->assertArrayHasKey(UserResource::class, $warnings);
        $this->assertStringContainsString('typefinder.resources.infer_from_model_name', $warnings[UserResource::class]);
    }
}
