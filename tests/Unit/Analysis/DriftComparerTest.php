<?php

declare(strict_types=1);

namespace Tests\Unit\Analysis;

use App\Http\Controllers\DriftingPageController;
use App\Http\Resources\DriftingResource;
use Pentacore\Typefinder\Analysis\DriftComparer;
use Pentacore\Typefinder\Analysis\PageDrift;
use Pentacore\Typefinder\Analysis\ResourceDrift;
use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedArrayReader;
use Pentacore\Typefinder\Analysis\ReturnedPart;
use Pentacore\Typefinder\Extractors\ControllerExtractor;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * The halves of the drift check on their own: the shared comparison, the
 * resource drift and the page drift (#203).
 */
final class DriftComparerTest extends TestCase
{
    public function test_compare_reports_each_kind(): void
    {
        $returns = [
            new ReturnedArray([new ReturnedPart(ReturnedPart::ITEM, key: 'a'), new ReturnedPart(ReturnedPart::ITEM, key: 'extra'), new ReturnedPart(ReturnedPart::ITEM, key: 'c', conditional: true)]),
            new ReturnedArray([new ReturnedPart(ReturnedPart::ITEM, key: 'c', conditional: true)]),
        ];
        $declared = [
            ['key' => 'a', 'optional' => false],
            ['key' => 'b', 'optional' => false],
            ['key' => 'c', 'optional' => false],
        ];
        $messages = [
            'undeclared' => static fn (string $key): string => 'undeclared '.$key,
            'never' => static fn (string $key): string => 'never '.$key,
            'notAlways' => static fn (string $key): string => 'notAlways '.$key,
            'conditional' => static fn (string $key): string => 'conditional '.$key,
        ];

        $this->assertSame(
            ['undeclared extra', 'notAlways a', 'never b', 'conditional c'],
            new DriftComparer()->compare($declared, false, $returns, $messages),
        );
        $this->assertSame(['undeclared extra', 'notAlways a', 'conditional c'], new DriftComparer()->compare($declared, false, $returns, $messages, ['b']));
    }

    public function test_resource_drift_on_its_own(): void
    {
        config(['typefinder.models.paths' => [workbench_path('app/Models')]]);
        $extraction = new ResourceExtractor()->extract(DriftingResource::class);
        $this->assertNotNull($extraction);

        $this->assertSame([
            DriftingResource::class.'::toArray() returns key "name" not declared in #[TypefinderResource] shape',
            DriftingResource::class.'::toArray(): #[TypefinderResource] shape declares "title" but toArray() never returns it',
        ], new ResourceDrift(new PhpDocShapeResolver(new GeneratedClasses), new ReturnedArrayReader)->check($extraction));
    }

    public function test_page_drift_on_its_own(): void
    {
        $pages = array_values(array_filter(
            new ControllerExtractor()->extract(DriftingPageController::class),
            static fn (array $page): bool => $page['source'] === DriftingPageController::class.'::index',
        ));

        $this->assertSame([
            DriftingPageController::class.'::index(): prop "stats" of "Drift/Index" is optional or deferred in code but required in #[TypefinderPage] (add it to optional?)',
        ], new PageDrift(new ReturnedArrayReader(['Inertia\Inertia::defer']), ['errors'])->checkPages($pages));
    }
}
