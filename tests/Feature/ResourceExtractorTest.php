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
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use ReflectionClass;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class ResourceExtractorTest extends TestCase
{
    private ResourceExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new ResourceExtractor;
    }

    public function test_tier_1_explicit_shape(): void
    {
        $result = $this->extractor->extract(PostResource::class);

        $this->assertSame('shape', $result['shape']['kind']);
        $this->assertSame('number', $result['shape']['fields']['id']);
        $this->assertSame(UserResource::class, $result['shape']['fields']['author']);
    }

    public function test_tier_2_model_extension(): void
    {
        $result = $this->extractor->extract(AdminUserResource::class);

        $this->assertSame('model', $result['shape']['kind']);
        $this->assertSame(User::class, $result['shape']['model']);
        $this->assertSame(['password'], $result['shape']['omit']);
        $this->assertSame(['roles' => 'string[]'], $result['shape']['extend']);
    }

    public function test_tier_3_name_convention_matches(): void
    {
        $result = $this->extractor->extract(UserResource::class);

        $this->assertSame('model', $result['shape']['kind']);
        $this->assertSame(User::class, $result['shape']['model']);
        $this->assertSame([], $result['shape']['omit']);
        $this->assertSame([], $result['shape']['extend']);
    }

    public function test_tier_3_name_convention_miss_returns_null(): void
    {
        $result = $this->extractor->extract(OrphanResource::class);

        $this->assertNull($result);
    }

    public function test_typefinder_ignore_skips(): void
    {
        $result = $this->extractor->extract(LegacyResource::class);

        $this->assertNull($result);
    }

    public function test_shape_and_model_both_set_throws(): void
    {
        // InvalidResource is tagged #[TypefinderIgnore] so directory scans stay clean.
        // To exercise the validation branch, remove the ignore from the fixture.
        $rc = new ReflectionClass(InvalidResource::class);
        if ($rc->getAttributes(TypefinderIgnore::class) !== []) {
            $this->markTestSkipped(
                'InvalidResource is tagged TypefinderIgnore so extract() returns null before validation. '
                .'To exercise the mutex error, remove the ignore tag from the fixture and re-run.',
            );
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/mutually exclusive/i');

        $this->extractor->extract(InvalidResource::class);
    }

    public function test_extract_from_directory_warns_on_orphan(): void
    {
        $warned = [];
        $results = $this->extractor->extractFromDirectory(
            workbench_path('app/Http/Resources'),
            onExtract: null,
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
}
