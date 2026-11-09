<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use RuntimeException;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * Two classes that would be emitted under the same type name must fail the
 * run instead of silently overwriting each other (#72).
 */
final class NameCollisionTest extends TestCase
{
    private string $output;

    private string $scratch;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = uniqid();
        $this->output = sys_get_temp_dir().'/typefinder-collide-out-'.$this->id;
        $this->scratch = sys_get_temp_dir().'/typefinder-collide-src-'.$this->id;
        File::ensureDirectoryExists($this->scratch);

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
        File::deleteDirectory($this->scratch);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_requests_with_the_same_short_name_fail_the_run(): void
    {
        $api = $this->writeClass('Api', 'StoreThingRequest', 'class StoreThingRequest extends \Illuminate\Foundation\Http\FormRequest { public function rules(): array { return ["a" => "string"]; } }');
        $web = $this->writeClass('Web', 'StoreThingRequest', 'class StoreThingRequest extends \Illuminate\Foundation\Http\FormRequest { public function rules(): array { return ["b" => "string"]; } }');
        config(['typefinder.requests.paths' => [$this->scratch]]);

        $this->assertCollision('requests', 'StoreThingRequest', [$api, $web]);
    }

    public function test_enums_with_the_same_short_name_fail_the_run(): void
    {
        $a = $this->writeClass('A', 'Flavour', 'enum Flavour: string { case Sweet = "sweet"; }');
        $b = $this->writeClass('B', 'Flavour', 'enum Flavour: string { case Sour = "sour"; }');
        config(['typefinder.enums.paths' => [$this->scratch]]);

        $this->assertCollision('enums', 'Flavour', [$a, $b]);
    }

    public function test_resources_with_the_same_short_name_fail_the_run(): void
    {
        $attr = '#[\Pentacore\Typefinder\Attributes\TypefinderResource(shape: ["id" => "number"])]';
        $a = $this->writeClass('A', 'GadgetResource', $attr.' class GadgetResource extends \Illuminate\Http\Resources\Json\JsonResource {}');
        $b = $this->writeClass('B', 'GadgetResource', $attr.' class GadgetResource extends \Illuminate\Http\Resources\Json\JsonResource {}');
        config(['typefinder.resources.paths' => [$this->scratch]]);

        $this->assertCollision('resources', 'GadgetResource', [$a, $b]);
    }

    public function test_models_with_the_same_short_name_fail_the_run(): void
    {
        $a = $this->writeClass('A', 'Account', 'class Account extends \Illuminate\Database\Eloquent\Model { protected $table = "users"; }');
        $b = $this->writeClass('B', 'Account', 'class Account extends \Illuminate\Database\Eloquent\Model { protected $table = "posts"; }');
        config(['typefinder.models.paths' => [workbench_path('app/Models'), $this->scratch]]);

        $this->assertCollision('models', 'Account', [$a, $b]);
    }

    public function test_the_same_name_in_two_categories_only_warns(): void
    {
        // An enum named like a workbench model: each category barrel is fine,
        // only the top-level index.d.ts re-exports two `User` types.
        $this->writeClass('C', 'User', 'enum User: string { case Admin = "admin"; }');
        config(['typefinder.enums.paths' => [workbench_path('app/Enums'), $this->scratch]]);

        $regenResult = app(Generator::class)->generateFull();

        $warning = collect($regenResult->warnings)->first(fn (string $w): bool => str_contains($w, 'User') && str_contains($w, 'enums') && str_contains($w, 'models'));
        $this->assertNotNull($warning, 'expected a cross-category warning, got: '.implode(' | ', $regenResult->warnings));
        $this->assertFileExists($this->output.'/models/User.d.ts');
        $this->assertFileExists($this->output.'/enums/User.d.ts');
    }

    public function test_incremental_run_rejects_a_new_class_that_takes_an_existing_name(): void
    {
        $dirA = $this->scratch.'/a';
        $dirB = $this->scratch.'/b';
        $first = $this->writeClass('A', 'StoreWidgetRequest', 'class StoreWidgetRequest extends \Illuminate\Foundation\Http\FormRequest { public function rules(): array { return ["first" => "string"]; } }', $dirA);
        config(['typefinder.requests.paths' => [$this->scratch]]);

        $generator = app(Generator::class);
        $generator->generateFull();

        $before = File::get($this->output.'/requests/StoreWidgetRequest.d.ts');
        $this->assertStringContainsString('first', $before);

        $second = $this->writeClass('B', 'StoreWidgetRequest', 'class StoreWidgetRequest extends \Illuminate\Foundation\Http\FormRequest { public function rules(): array { return ["second" => "string"]; } }', $dirB);
        $regenResult = $generator->generatePaths([$dirB.'/StoreWidgetRequest.php']);

        $this->assertCount(1, $regenResult->failed);
        $this->assertStringContainsString($first, $regenResult->failed[0]['message']);
        $this->assertStringContainsString($second, $regenResult->failed[0]['message']);
        $this->assertSame($before, File::get($this->output.'/requests/StoreWidgetRequest.d.ts'));
    }

    public function test_incremental_run_of_the_same_file_is_not_a_collision(): void
    {
        $dir = $this->scratch.'/same';
        $this->writeClass('Same', 'UpdateWidgetRequest', 'class UpdateWidgetRequest extends \Illuminate\Foundation\Http\FormRequest { public function rules(): array { return ["x" => "string"]; } }', $dir);
        config(['typefinder.requests.paths' => [$this->scratch]]);

        $generator = app(Generator::class);
        $generator->generateFull();

        // The cache already holds this file under the same name: regenerating
        // it must not count as a second class taking the name.
        $regenResult = $generator->generatePaths([(string) realpath($dir.'/UpdateWidgetRequest.php')]);

        $this->assertSame([], $regenResult->failed);
        $this->assertFileExists($this->output.'/requests/UpdateWidgetRequest.d.ts');
    }

    /**
     * @param  list<string>  $fqcns
     */
    private function assertCollision(string $category, string $name, array $fqcns): void
    {
        $caught = null;
        try {
            app(Generator::class)->generateFull();
        } catch (RuntimeException $runtimeException) {
            $caught = $runtimeException;
        }

        $this->assertInstanceOf(RuntimeException::class, $caught, 'Expected a name collision to fail the run.');
        $this->assertStringContainsString($category, $caught->getMessage());
        $this->assertStringContainsString($name, $caught->getMessage());
        foreach ($fqcns as $fqcn) {
            $this->assertStringContainsString($fqcn, $caught->getMessage());
        }
    }

    /**
     * Write a class into its own namespace under the scratch dir and load it.
     */
    private function writeClass(string $ns, string $short, string $body, ?string $dir = null): string
    {
        $dir ??= $this->scratch.'/'.$ns;
        File::ensureDirectoryExists($dir);
        $namespace = 'Scratch\\Collide'.$this->id.'\\'.$ns;
        $file = $dir.'/'.$short.'.php';
        File::put($file, "<?php\nnamespace {$namespace};\n{$body}\n");
        require (string) realpath($file);

        return $namespace.'\\'.$short;
    }
}
