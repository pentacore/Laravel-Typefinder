<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Pentacore\Typefinder\Services\Generator;
use Pentacore\Typefinder\Services\RegenWorker;
use Tests\TestCase;

/**
 * {@see RegenWorker} on its own. {@see WatchFreshClassesTest} covers it
 * inside real watcher processes.
 */
final class RegenWorkerTest extends TestCase
{
    private string $scratch;

    private string $output;

    private string $namespace;

    /** @var resource what the worker writes to instead of stderr */
    private $stderr;

    private ?Closure $autoloader = null;

    protected function setUp(): void
    {
        parent::setUp();
        $id = str_replace('.', '', uniqid('', true));
        $this->namespace = 'Scratch\\Worker'.$id;
        $this->output = sys_get_temp_dir().'/typefinder-worker-out-'.$id;
        $this->scratch = sys_get_temp_dir().'/typefinder-worker-src-'.$id;
        File::ensureDirectoryExists($this->scratch);
        $this->scratch = (string) realpath($this->scratch);
        $this->stderr = fopen('php://memory', 'w+');

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
        if ($this->autoloader instanceof Closure) {
            spl_autoload_unregister($this->autoloader);
        }

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
        $this->assertSame('', $this->stderr());
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
        $this->assertSame('[typefinder] '.$answer['message']."\n", $this->stderr());
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
        $this->assertSame(
            sprintf("[typefinder] %s changed since the watcher started (restart the dev server to make it fast again), so every regeneration starts a new PHP process\n", $enum),
            $this->stderr(),
            'told once why it starts a new process',
        );
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

    public function test_a_request_is_answered_by_a_fork_that_loads_the_class_as_it_is_now(): void
    {
        $this->requiresFork();
        $enum = $this->writeEnum('Level', ['one']);
        $this->autoload();
        $worker = $this->worker();

        $answer = $worker->isolated('r1', []);

        $this->assertSame('regen.done', $answer['type']);
        $this->assertSame('r1', $answer['id']);
        $this->assertStringContainsString("export type Level = 'one';", File::get($this->output.'/enums/Level.d.ts'));

        $this->writeEnum('Level', ['one', 'two']);
        $answer = $worker->isolated('r2', [$enum]);

        $this->assertSame(['enums/Level.d.ts'], $answer['changed']);
        $this->assertStringContainsString("export type Level = 'one' | 'two';", File::get($this->output.'/enums/Level.d.ts'));
        $this->assertFalse(enum_exists($this->namespace.'\\Level', false), 'this process never loads the class');
        $this->assertSame('', $this->stderr(), 'no new process was needed');
    }

    public function test_a_fork_that_is_killed_gets_an_error(): void
    {
        $this->requiresFork();
        $this->writeEnum('Level', ['one'], prelude: 'posix_kill(posix_getpid(), SIGKILL);');
        $this->autoload();

        $answer = $this->worker()->isolated('r1', []);

        $this->assertSame(['type' => 'regen.error', 'id' => 'r1', 'message' => 'the regen worker stopped without an answer (signal 9)'], $answer);
    }

    public function test_a_fork_that_exits_gets_an_error(): void
    {
        $this->requiresFork();
        $this->writeEnum('Level', ['one'], prelude: 'exit(3);');
        $this->autoload();

        $answer = $this->worker()->isolated('r1', []);

        $this->assertSame(['type' => 'regen.error', 'id' => 'r1', 'message' => 'the regen worker stopped without an answer (exit code 3)'], $answer);
    }

    public function test_a_fork_that_does_not_answer_in_time_is_stopped(): void
    {
        $this->requiresFork();
        $this->writeEnum('Level', ['one'], prelude: 'sleep(30);');
        $this->autoload();

        $startedAt = microtime(true);
        $answer = $this->worker(timeout: 0.5)->isolated('r1', []);

        $this->assertLessThan(10, microtime(true) - $startedAt);
        $this->assertSame('regen.error', $answer['type']);
        $this->assertSame('r1', $answer['id']);
        $this->assertStringContainsString('did not answer within 0.5 seconds', $answer['message']);
    }

    public function test_a_new_process_that_does_not_answer_in_time_is_stopped(): void
    {
        $enum = $this->writeEnum('Level', ['one']);
        require $enum;
        $worker = $this->worker([PHP_BINARY, '-r', 'sleep(30);'], timeout: 0.5);

        $this->writeEnum('Level', ['one', 'two']);
        $startedAt = microtime(true);
        $answer = $worker->isolated('r1', [$enum]);

        $this->assertLessThan(10, microtime(true) - $startedAt);
        $this->assertSame('regen.error', $answer['type']);
        $this->assertStringContainsString('did not answer within 0.5 seconds', $answer['message']);
    }

    public function test_database_connections_are_closed_before_forking(): void
    {
        $this->requiresFork();
        File::put($this->output.'.sqlite', '');
        config(['database.connections.scratch' => ['driver' => 'sqlite', 'database' => $this->output.'.sqlite', 'prefix' => '']]);
        DB::connection('scratch')->getPdo();
        DB::connection()->getPdo();

        try {
            $this->worker()->isolated('r1', []);

            $this->assertNull(DB::connection('scratch')->getRawPdo(), 'a worker would share the connection');
            $this->assertNotNull(DB::connection()->getRawPdo(), 'an in-memory database is copied to the worker');
            $this->assertTrue(Schema::hasTable('users'));
        } finally {
            DB::purge('scratch');
            @unlink($this->output.'.sqlite');
        }
    }

    private function requiresFork(): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are not available');
        }
    }

    /**
     * Let this process load the scratch enum when it is asked for, as
     * Composer would. A fork inherits the autoloader.
     */
    private function autoload(): void
    {
        $this->autoloader = function (string $class): void {
            if ($class === $this->namespace.'\\Level') {
                require $this->scratch.'/Level.php';
            }
        };
        spl_autoload_register($this->autoloader);
    }

    private function stderr(): string
    {
        rewind($this->stderr);

        return (string) stream_get_contents($this->stderr);
    }

    /**
     * @param  ?list<string>  $command
     */
    private function worker(?array $command = null, ?float $timeout = null): RegenWorker
    {
        return new RegenWorker(
            app(Generator::class),
            $this->stderr,
            $command ?? [PHP_BINARY, dirname(__DIR__, 2).'/vendor/bin/testbench', 'typefinder:watch', '--in-process'],
            $timeout,
        );
    }

    /**
     * @param  list<string>  $cases
     */
    private function writeEnum(string $name, array $cases, ?string $namespace = null, string $prelude = ''): string
    {
        $file = $this->scratch.'/'.$name.'.php';
        $body = implode(' ', array_map(fn (string $case): string => sprintf("case %s = '%s';", ucfirst($case), $case), $cases));
        File::put($file, sprintf("<?php\nnamespace %s;\n%s\nenum %s: string { %s }\n", $namespace ?? $this->namespace, $prelude, basename($name), $body));

        return $file;
    }
}
