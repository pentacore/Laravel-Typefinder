<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\MorphToResolver;
use Pentacore\Typefinder\Services\Generator;
use ReflectionClass;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * Output must not depend on the order files come back from the filesystem,
 * and a full run must produce the same bytes as an incremental one (#71).
 */
final class DeterministicOrderTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = sys_get_temp_dir().'/typefinder-order-'.uniqid('', true);
        config([
            'typefinder.output_path' => $this->output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_barrel_index_is_sorted_regardless_of_input_order(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;

        $this->assertSame(
            $typeScriptRenderer->renderBarrelIndex(['Alpha', 'Beta', 'RoleUserPivot', 'Zeta']),
            $typeScriptRenderer->renderBarrelIndex(['Zeta', 'RoleUserPivot', 'Alpha', 'Beta']),
        );
    }

    public function test_pages_are_sorted_by_component(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;
        $a = ['component' => 'Users/Index', 'props' => ['q' => 'string'], 'optional' => [], 'source' => 'A::index'];
        $b = ['component' => 'Dashboard', 'props' => [], 'optional' => [], 'source' => 'B::show'];

        $this->assertSame(
            $typeScriptRenderer->renderPages([$a, $b], [], []),
            $typeScriptRenderer->renderPages([$b, $a], [], []),
        );
    }

    public function test_broadcasting_is_sorted_by_event_and_channel(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;
        $a = ['event_class' => 'A', 'broadcast_name' => 'Zebra', 'channels' => [['type' => 'public', 'name' => 'zoo']], 'payload' => []];
        $b = ['event_class' => 'B', 'broadcast_name' => 'Apple', 'channels' => [['type' => 'public', 'name' => 'orchard']], 'payload' => []];
        $c = ['event_class' => 'C', 'broadcast_name' => 'Mango', 'channels' => [['type' => 'public', 'name' => 'zoo']], 'payload' => []];

        $this->assertSame(
            $typeScriptRenderer->renderBroadcasting([$a, $b, $c], [], []),
            $typeScriptRenderer->renderBroadcasting([$c, $b, $a], [], []),
        );
    }

    public function test_morph_targets_are_sorted_regardless_of_model_order(): void
    {
        $comment = ['fqcn' => 'App\Fake\Comment', 'relationships' => [['type' => 'morphTo', 'name' => 'commentable']]];
        $video = ['fqcn' => 'App\Fake\Video', 'relationships' => [['type' => 'morphMany', 'relationType' => MorphMany::class, 'related' => 'App\Fake\Comment']]];
        $article = ['fqcn' => 'App\Fake\Article', 'relationships' => [['type' => 'morphMany', 'relationType' => MorphMany::class, 'related' => 'App\Fake\Comment']]];

        $morphToResolver = new MorphToResolver;
        $targets = fn (array $models): array => collect($morphToResolver->resolve($models))
            ->firstWhere('fqcn', 'App\Fake\Comment')['relationships'][0]['morphTargets'];

        $this->assertSame(['App\Fake\Article', 'App\Fake\Video'], $targets([$comment, $video, $article]));
        $this->assertSame(['App\Fake\Article', 'App\Fake\Video'], $targets([$article, $comment, $video]));
    }

    public function test_full_and_incremental_runs_write_identical_barrels(): void
    {
        $generator = app(Generator::class);
        $generator->generateFull();

        $barrels = ['models/index.d.ts', 'enums/index.d.ts', 'requests/index.d.ts', 'resources/index.d.ts', 'index.d.ts'];
        $before = [];
        foreach ($barrels as $barrel) {
            $before[$barrel] = File::get($this->output.'/'.$barrel);
        }

        // An incremental regen rewrites each touched category's barrel.
        $generator->generatePaths([
            (string) new ReflectionClass(User::class)->getFileName(),
            workbench_path('app/Enums/PostStatus.php'),
            workbench_path('app/Http/Resources/UserResource.php'),
            (string) collect(File::files(workbench_path('app/Http/Requests')))->first()?->getRealPath(),
        ]);

        foreach ($barrels as $barrel) {
            $this->assertSame($before[$barrel], File::get($this->output.'/'.$barrel), $barrel.' differs between full and incremental runs');
        }
    }
}
