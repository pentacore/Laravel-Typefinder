<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\Support\Categories;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * Keyed `requests.paths` prefix the type names of the requests under each
 * directory, so requests with the same short name can both be emitted (#195).
 */
final class RequestPathPrefixTest extends TestCase
{
    private string $output;

    private string $scratch;

    private string $id;

    protected function setUp(): void
    {
        parent::setUp();
        $this->id = str_replace('.', '', uniqid('', true));
        $this->output = sys_get_temp_dir().'/typefinder-prefix-out-'.$this->id;
        $this->scratch = sys_get_temp_dir().'/typefinder-prefix-src-'.$this->id;
        File::ensureDirectoryExists($this->scratch);

        config([
            'typefinder.output_path' => $this->output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
            'typefinder.requests.extract_nested' => true,
        ]);

        $this->writeRequest('Api', 'StoreThingRequest', '["name" => "string", "meta" => "array", "meta.tag" => "string", /** @query */ "page" => "integer"]');
        $this->writeRequest('Web', 'StoreThingRequest', '["title" => "string"]');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        File::deleteDirectory($this->scratch);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_keyed_paths_prefix_the_type_names(): void
    {
        config(['typefinder.requests.paths' => ['Api' => $this->scratch.'/Api', 'Web' => $this->scratch.'/Web']]);

        app(Generator::class)->generateFull();

        $api = File::get($this->output.'/requests/ApiStoreThingRequest.d.ts');
        $this->assertStringContainsString('export type ApiStoreThingRequest = {', $api);
        $this->assertStringContainsString('export type ApiStoreThingRequestQuery = {', $api);
        $this->assertStringContainsString('export type ApiStoreThingRequestMeta = {', $api);

        $web = File::get($this->output.'/requests/WebStoreThingRequest.d.ts');
        $this->assertStringContainsString('export type WebStoreThingRequest = {', $web);

        $this->assertFileDoesNotExist($this->output.'/requests/StoreThingRequest.d.ts');
        $barrel = File::get($this->output.'/requests/index.d.ts');
        $this->assertStringContainsString('ApiStoreThingRequestQuery', $barrel);
        $this->assertStringContainsString('WebStoreThingRequest', $barrel);
    }

    public function test_keyed_and_list_paths_mix(): void
    {
        config(['typefinder.requests.paths' => [workbench_path('app/Http/Requests'), 'Api' => $this->scratch.'/Api']]);

        app(Generator::class)->generateFull();

        $this->assertFileExists($this->output.'/requests/ApiStoreThingRequest.d.ts');
        // A list path keeps the short name.
        $this->assertFileExists($this->output.'/requests/StorePostRequest.d.ts');
        $this->assertSame(
            [(string) realpath(workbench_path('app/Http/Requests')), (string) realpath($this->scratch.'/Api')],
            Categories::paths('requests'),
        );
    }

    public function test_incremental_run_of_each_request_leaves_the_full_output(): void
    {
        config(['typefinder.requests.paths' => ['Api' => $this->scratch.'/Api', 'Web' => $this->scratch.'/Web']]);

        $generator = app(Generator::class);
        $generator->generateFull();

        $full = $this->tree();

        foreach (Finder::create()->files()->name('*.php')->in($this->scratch)->sortByName() as $source) {
            $regenResult = $generator->generatePaths([(string) $source->getRealPath()]);

            $this->assertSame([], $regenResult->failed);
            $this->assertSame($full, $this->tree(), $source->getRelativePathname().' changed the output');
        }
    }

    public function test_list_paths_still_collide_and_point_at_keyed_paths(): void
    {
        config(['typefinder.requests.paths' => [$this->scratch.'/Api', $this->scratch.'/Web']]);

        try {
            app(Generator::class)->generateFull();
            $this->fail('Expected a name collision to fail the run.');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('StoreThingRequest', $runtimeException->getMessage());
            $this->assertStringContainsString("'Api' => app_path('Http/Requests/Api')", $runtimeException->getMessage());
        }
    }

    public function test_a_prefix_must_be_an_identifier(): void
    {
        config(['typefinder.requests.paths' => ['api/v1' => $this->scratch.'/Api']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('api/v1');

        app(Generator::class)->generateFull();
    }

    /**
     * @return array<string, string> file contents by path relative to the output dir
     */
    private function tree(): array
    {
        $tree = [];
        foreach (Finder::create()->files()->in($this->output)->sortByName() as $file) {
            $tree[$file->getRelativePathname()] = $file->getContents();
        }

        return $tree;
    }

    private function writeRequest(string $ns, string $short, string $rules): void
    {
        $dir = $this->scratch.'/'.$ns;
        File::ensureDirectoryExists($dir);
        $file = $dir.'/'.$short.'.php';
        File::put($file, <<<PHP
            <?php
            namespace Scratch\\Prefix{$this->id}\\{$ns};

            class {$short} extends \\Illuminate\\Foundation\\Http\\FormRequest
            {
                public function rules(): array
                {
                    return {$rules};
                }
            }
            PHP);
        require (string) realpath($file);
    }
}
