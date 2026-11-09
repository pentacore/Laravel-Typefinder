<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use RuntimeException;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * One Inertia component rendered by several actions merges into one PageProps
 * entry (#73), and pages.d.ts follows controller edits in incremental runs.
 */
final class InertiaPageMergeTest extends TestCase
{
    private string $output;

    private string $scratch;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = uniqid();
        $this->output = sys_get_temp_dir().'/typefinder-pages-out-'.$this->id;
        $this->scratch = sys_get_temp_dir().'/typefinder-pages-src-'.$this->id;
        File::ensureDirectoryExists($this->scratch);

        config([
            'typefinder.output_path' => $this->output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
            'typefinder.inertia.enabled' => true,
            'typefinder.inertia.paths' => [$this->scratch],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        File::deleteDirectory($this->scratch);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_identical_props_on_two_actions_collapse_into_one_entry(): void
    {
        $this->writeController('FormController', <<<'PHP'
            #[TypefinderPage('Posts/Form', ['title' => 'string', 'body' => 'string'])]
            public function create(): void {}

            #[TypefinderPage('Posts/Form', ['title' => 'string', 'body' => 'string'])]
            public function edit(): void {}
            PHP);

        $pages = $this->generate();

        $this->assertSame(1, substr_count($pages, "'Posts/Form'"));
        $this->assertStringContainsString("'Posts/Form': { title: string; body: string };", $pages);
    }

    public function test_keys_missing_from_some_actions_become_optional(): void
    {
        $this->writeController('FormController', <<<'PHP'
            #[TypefinderPage('Posts/Form', ['title' => 'string', 'categories' => 'string[]'])]
            public function create(): void {}

            #[TypefinderPage('Posts/Form', ['title' => 'string', 'categories' => 'string[]', 'post' => 'number'])]
            public function edit(): void {}
            PHP);

        $this->assertStringContainsString(
            "'Posts/Form': { title: string; categories: string[]; post?: number };",
            $this->generate(),
        );
    }

    public function test_a_key_optional_in_any_action_stays_optional(): void
    {
        $this->writeController('TabsController', <<<'PHP'
            #[TypefinderPage('Settings', ['user' => 'string', 'tab' => 'string'], optional: ['tab'])]
            public function show(): void {}

            #[TypefinderPage('Settings', ['user' => 'string', 'tab' => 'string'])]
            public function export(): void {}
            PHP);

        $this->assertStringContainsString("'Settings': { user: string; tab?: string };", $this->generate());
    }

    public function test_actions_in_different_controllers_merge(): void
    {
        $this->writeController('ShowController', <<<'PHP'
            #[TypefinderPage('Dashboard', ['greeting' => 'string'])]
            public function index(): void {}
            PHP);
        $this->writeController('StatsController', <<<'PHP'
            #[TypefinderPage('Dashboard', ['greeting' => 'string', 'stats' => 'number'])]
            public function index(): void {}
            PHP);

        $this->assertStringContainsString("'Dashboard': { greeting: string; stats?: number };", $this->generate());
    }

    public function test_repeated_attribute_on_one_action_merges(): void
    {
        $this->writeController('WizardController', <<<'PHP'
            #[TypefinderPage('Wizard', ['step' => 'number'])]
            #[TypefinderPage('Wizard', ['step' => 'number', 'summary' => 'string'])]
            public function show(): void {}
            PHP);

        $this->assertStringContainsString("'Wizard': { step: number; summary?: string };", $this->generate());
    }

    public function test_conflicting_types_for_one_key_fail_with_both_sources(): void
    {
        $fqcn = $this->writeController('FormController', <<<'PHP'
            #[TypefinderPage('Posts/Form', ['post' => 'number'])]
            public function create(): void {}

            #[TypefinderPage('Posts/Form', ['post' => 'string'])]
            public function edit(): void {}
            PHP);

        $caught = null;
        try {
            $this->generate();
        } catch (RuntimeException $runtimeException) {
            $caught = $runtimeException;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught);
        $message = $caught->getMessage();
        $this->assertStringContainsString('Posts/Form', $message);
        $this->assertStringContainsString('post', $message);
        $this->assertStringContainsString($fqcn.'::create => number', $message);
        $this->assertStringContainsString($fqcn.'::edit => string', $message);
    }

    public function test_types_that_differ_only_in_a_leading_backslash_or_spacing_are_equal(): void
    {
        $this->writeController('FormController', <<<'PHP'
            #[TypefinderPage('Posts/Form', ['author' => 'App\Models\User'])]
            public function create(): void {}

            #[TypefinderPage('Posts/Form', ['author' => ' \App\Models\User '])]
            public function edit(): void {}
            PHP);

        $this->assertStringContainsString("'Posts/Form': { author: User };", $this->generate());
    }

    public function test_incremental_run_refreshes_pages_after_a_controller_edit(): void
    {
        $this->writeController('ShowController', <<<'PHP'
            #[TypefinderPage('Dashboard', ['greeting' => 'string'])]
            public function index(): void {}
            PHP);
        $generator = app(Generator::class);
        $generator->generateFull();

        $full = File::get($this->output.'/pages.d.ts');

        // Simulate a stale file, as left behind before this fix.
        File::put($this->output.'/pages.d.ts', '// stale');
        $regenResult = $generator->generatePaths([$this->scratch.'/ShowController.php']);

        $this->assertSame([], $regenResult->failed);
        $this->assertContains('pages.d.ts', $regenResult->changed);
        $this->assertSame($full, File::get($this->output.'/pages.d.ts'));
    }

    public function test_incremental_run_drops_pages_of_a_deleted_controller(): void
    {
        $this->writeController('ShowController', <<<'PHP'
            #[TypefinderPage('Dashboard', ['greeting' => 'string'])]
            public function index(): void {}
            PHP);
        $this->writeController('StatsController', <<<'PHP'
            #[TypefinderPage('Stats', ['total' => 'number'])]
            public function index(): void {}
            PHP);
        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertStringContainsString("'Stats'", File::get($this->output.'/pages.d.ts'));

        // Watchers report real paths; a deleted file can no longer be resolved.
        $deleted = realpath($this->scratch).'/StatsController.php';
        File::delete($deleted);
        $generator->generatePaths([$deleted]);

        $pages = File::get($this->output.'/pages.d.ts');
        $this->assertStringNotContainsString("'Stats'", $pages);
        $this->assertStringContainsString("'Dashboard'", $pages);
    }

    public function test_incremental_run_deletes_pages_when_no_page_is_left(): void
    {
        $this->writeController('ShowController', <<<'PHP'
            #[TypefinderPage('Dashboard', ['greeting' => 'string'])]
            public function index(): void {}
            PHP);
        $generator = app(Generator::class);
        $generator->generateFull();
        $this->assertFileExists($this->output.'/pages.d.ts');

        $deleted = realpath($this->scratch).'/ShowController.php';
        File::delete($deleted);
        $regenResult = $generator->generatePaths([$deleted]);

        $this->assertFileDoesNotExist($this->output.'/pages.d.ts');
        $this->assertContains('pages.d.ts', $regenResult->changed);
        $this->assertStringNotContainsString("'./pages'", File::get($this->output.'/index.d.ts'));
    }

    public function test_incremental_type_conflict_is_reported_as_failed(): void
    {
        $this->writeController('ShowController', <<<'PHP'
            #[TypefinderPage('Dashboard', ['greeting' => 'string'])]
            public function index(): void {}
            PHP);
        $generator = app(Generator::class);
        $generator->generateFull();

        $before = File::get($this->output.'/pages.d.ts');

        $this->writeController('OtherController', <<<'PHP'
            #[TypefinderPage('Dashboard', ['greeting' => 'number'])]
            public function index(): void {}
            PHP);
        $regenResult = $generator->generatePaths([(string) realpath($this->scratch.'/OtherController.php')]);

        $this->assertCount(1, $regenResult->failed);
        $this->assertStringContainsString('Dashboard.greeting', $regenResult->failed[0]['message']);
        $this->assertSame($before, File::get($this->output.'/pages.d.ts'));
    }

    public function test_incremental_rescan_warns_about_unloadable_controllers(): void
    {
        $this->writeController('ShowController', <<<'PHP'
            #[TypefinderPage('Dashboard', ['greeting' => 'string'])]
            public function index(): void {}
            PHP);
        // Declares a class that no autoloader can find.
        File::put($this->scratch.'/Ghost.php', "<?php\nnamespace Nowhere\\Ghost{$this->id};\nclass Ghost {}\n");

        $regenResult = app(Generator::class)->generatePaths([(string) realpath($this->scratch.'/ShowController.php')]);

        $this->assertSame([], $regenResult->failed);
        $this->assertStringContainsString('could not be autoloaded', implode("\n", $regenResult->warnings));
        $this->assertStringContainsString("'Dashboard'", File::get($this->output.'/pages.d.ts'));
    }

    private function generate(): string
    {
        app(Generator::class)->generateFull();

        return File::get($this->output.'/pages.d.ts');
    }

    /**
     * Write a controller with the given method bodies into its own namespace
     * and load it. Returns the FQCN.
     */
    private function writeController(string $short, string $methods): string
    {
        $namespace = 'Scratch\\Pages'.$this->id;
        $file = $this->scratch.'/'.$short.'.php';
        File::put($file, "<?php\nnamespace {$namespace};\nuse Pentacore\\Typefinder\\Attributes\\TypefinderPage;\nclass {$short}\n{\n{$methods}\n}\n");
        require (string) realpath($file);

        return $namespace.'\\'.$short;
    }
}
