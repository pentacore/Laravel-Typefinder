<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\Services\RegenWorker;
use Tests\TestCase;

/**
 * The pieces of {@see RegenWorker} that run in the calling process. The
 * fork is covered by {@see WatchFreshClassesTest}, with real watchers.
 */
final class RegenWorkerTest extends TestCase
{
    private string $scratch;

    private string $output;

    private string $namespace;

    /** @var list<string> */
    private array $notices = [];

    protected function setUp(): void
    {
        parent::setUp();
        $id = str_replace('.', '', uniqid('', true));
        $this->namespace = 'Scratch\\Worker'.$id;
        $this->output = sys_get_temp_dir().'/typefinder-worker-out-'.$id;
        $this->scratch = sys_get_temp_dir().'/typefinder-worker-src-'.$id;
        File::ensureDirectoryExists($this->scratch);
        $this->scratch = (string) realpath($this->scratch);

        $config = [
            'typefinder.output_path' => $this->output,
            'typefinder.enums.paths' => [$this->scratch],
            'typefinder.models.paths' => [],
            'typefinder.requests.paths' => [],
            'typefinder.resources.paths' => [],
        ];
        config($config);

        // A process the worker starts reads its configuration from the
        // environment, which symfony/process takes from $_ENV.
        $_ENV['TYPEFINDER_WORKBENCH_CONFIG'] = json_encode($config, JSON_THROW_ON_ERROR);
        $_ENV['TYPEFINDER_WORKBENCH_AUTOLOAD'] = json_encode([$this->namespace.'\\Level' => $this->scratch.'/Level.php'], JSON_THROW_ON_ERROR);
    }

    protected function tearDown(): void
    {
        unset($_ENV['TYPEFINDER_WORKBENCH_CONFIG'], $_ENV['TYPEFINDER_WORKBENCH_AUTOLOAD']);
        File::deleteDirectory($this->output);
        File::deleteDirectory($this->scratch);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_in_process_answers_with_what_the_run_changed(): void
    {
        require $this->writeEnum('Level', ['one']);

        $answer = $this->worker()->inProcess('r1', []);

        $this->assertSame('regen.done', $answer['type']);
        $this->assertSame('r1', $answer['id']);
        $this->assertContains('enums/Level.d.ts', $answer['changed']);
        $this->assertSame([], $answer['failed']);
        $this->assertIsInt($answer['duration_ms']);
        $this->assertSame([], $this->notices);
    }

    public function test_in_process_answers_a_run_that_cannot_finish_with_an_error(): void
    {
        File::ensureDirectoryExists($this->scratch.'/Other');
        require $this->writeEnum('Level', ['one']);
        require $this->writeEnum('Other/Level', ['one'], $this->namespace.'\\Other');

        $answer = $this->worker()->inProcess('r1', []);

        $this->assertSame('regen.error', $answer['type']);
        $this->assertSame('r1', $answer['id']);
        $this->assertStringContainsString('same type name', $answer['message']);
        $this->assertSame([$answer['message']], $this->notices);
    }

    public function test_a_class_this_process_loaded_that_changed_is_regenerated_in_a_new_process(): void
    {
        $enum = $this->writeEnum('Level', ['one']);
        require $enum;
        $worker = $this->worker();

        $this->writeEnum('Level', ['one', 'two']);
        $answer = $worker->isolated('r1', [$enum]);

        $this->assertSame('regen.done', $answer['type']);
        $this->assertSame('r1', $answer['id']);
        $this->assertStringContainsString("export type Level = 'one' | 'two';", File::get($this->output.'/enums/Level.d.ts'));

        $this->writeEnum('Level', ['one', 'two', 'three']);
        $worker->isolated('r2', [$enum]);

        $this->assertStringContainsString("export type Level = 'one' | 'two' | 'three';", File::get($this->output.'/enums/Level.d.ts'));
        $this->assertCount(1, $this->notices, 'told once why it starts a new process');
        $this->assertStringContainsString('every regeneration starts a new PHP process', $this->notices[0]);
    }

    public function test_a_new_process_that_gives_no_answer_is_an_error(): void
    {
        $enum = $this->writeEnum('Level', ['one']);
        require $enum;
        $worker = $this->worker([PHP_BINARY, '-r', 'exit(7);']);

        $this->writeEnum('Level', ['one', 'two']);
        $answer = $worker->isolated('r1', [$enum]);

        $this->assertSame(['type' => 'regen.error', 'id' => 'r1', 'message' => 'the regen process stopped without an answer (exit code 7)'], $answer);
    }

    /**
     * @param  ?list<string>  $command
     */
    private function worker(?array $command = null): RegenWorker
    {
        return new RegenWorker(
            app(Generator::class),
            function (string $message): void {
                $this->notices[] = $message;
            },
            $command ?? [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/testbench', 'typefinder:watch', '--in-process'],
        );
    }

    /**
     * @param  list<string>  $cases
     */
    private function writeEnum(string $name, array $cases, ?string $namespace = null): string
    {
        $file = $this->scratch.'/'.$name.'.php';
        $body = implode(' ', array_map(fn (string $case): string => sprintf("case %s = '%s';", ucfirst($case), $case), $cases));
        File::put($file, sprintf("<?php\nnamespace %s;\nenum %s: string { %s }\n", $namespace ?? $this->namespace, basename($name), $body));

        return $file;
    }
}
