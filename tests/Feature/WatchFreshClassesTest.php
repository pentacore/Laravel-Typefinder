<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Protocol\ProtocolCodec;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Issue #124: PHP cannot reload a class, so the long-lived watcher answers
 * each request in a process that has not loaded the edited class yet.
 */
final class WatchFreshClassesTest extends TestCase
{
    private string $scratch;

    private string $output;

    private string $namespace;

    private ?Process $process = null;

    private ?InputStream $inputStream = null;

    protected function setUp(): void
    {
        parent::setUp();
        $id = str_replace('.', '', uniqid('', true));
        $this->namespace = 'Scratch\\Watch'.$id;
        $this->output = sys_get_temp_dir().'/typefinder-watch-out-'.$id;
        $this->scratch = sys_get_temp_dir().'/typefinder-watch-src-'.$id;
        File::ensureDirectoryExists($this->scratch.'/Enums');
        File::ensureDirectoryExists($this->scratch.'/Controllers');
        $this->scratch = (string) realpath($this->scratch);
    }

    protected function tearDown(): void
    {
        $this->inputStream?->close();
        $this->process?->stop(1.0);
        File::deleteDirectory($this->output);
        File::deleteDirectory($this->scratch);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_an_edit_to_an_enum_the_watcher_has_extracted_is_regenerated(): void
    {
        $this->requiresFork();
        $enum = $this->writeEnum(['one']);
        $this->startWatch();

        $this->regen([]);
        $this->assertStringContainsString("export type Level = 'one';", $this->generated('enums/Level.d.ts'));

        $this->writeEnum(['one', 'two']);
        $response = $this->regen([$enum]);

        $this->assertSame('regen.done', $response['type']);
        $this->assertSame(['enums/Level.d.ts'], $response['changed']);
        $this->assertStringContainsString("export type Level = 'one' | 'two';", $this->generated('enums/Level.d.ts'));

        $this->writeEnum(['one', 'two', 'three']);
        $this->regen([$enum]);

        $this->assertStringContainsString("export type Level = 'one' | 'two' | 'three';", $this->generated('enums/Level.d.ts'));
        $this->assertStringNotContainsString('new PHP process', $this->process->getErrorOutput(), 'answered by a fork');
    }

    public function test_an_edit_to_a_page_attribute_is_regenerated(): void
    {
        $this->requiresFork();
        $controller = $this->writeController("['a' => 'number']");
        $this->startWatch();

        $this->regen([]);
        $this->assertStringNotContainsString('b: number', $this->generated('pages.d.ts'));

        $this->writeController("['a' => 'number', 'b' => 'number']");
        $this->regen([$controller]);

        $this->assertStringContainsString('b: number', $this->generated('pages.d.ts'));
    }

    public function test_without_pcntl_an_edit_is_regenerated_in_a_new_process(): void
    {
        $enum = $this->writeEnum(['one']);
        $this->startWatch(phpOptions: ['-d', 'disable_functions=pcntl_fork']);

        $this->regen([]);
        $this->writeEnum(['one', 'two']);
        $response = $this->regen([$enum]);

        $this->assertSame('regen.done', $response['type']);
        $this->assertSame(['enums/Level.d.ts'], $response['changed']);
        $this->assertStringContainsString("export type Level = 'one' | 'two';", $this->generated('enums/Level.d.ts'));
    }

    public function test_a_class_loaded_while_booting_is_regenerated_in_a_new_process_once_it_changes(): void
    {
        $enum = $this->writeEnum(['one']);
        $this->startWatch(env: ['TYPEFINDER_WORKBENCH_PRELOAD' => $enum]);

        $this->regen([]);
        $this->writeEnum(['one', 'two']);
        $this->regen([$enum]);

        $this->assertStringContainsString("export type Level = 'one' | 'two';", $this->generated('enums/Level.d.ts'));
        $this->assertStringContainsString('changed since the watcher started', $this->process->getErrorOutput());
    }

    public function test_a_request_that_kills_its_worker_gets_an_error_and_the_watcher_goes_on(): void
    {
        $this->requiresFork();
        $enum = $this->writeEnum(['one']);
        $this->startWatch();
        $this->regen([]);

        // Loading the file ends the process, as a fatal error would.
        File::put($enum, "<?php\nnamespace {$this->namespace};\nexit(3);\nenum Level: string { case One = 'one'; }\n");
        $response = $this->regen([$enum]);

        $this->assertSame('regen.error', $response['type']);
        $this->assertSame('the regen worker stopped without an answer (exit code 3)', $response['message']);

        $this->writeEnum(['one', 'two']);
        $response = $this->regen([$enum]);

        $this->assertSame('regen.done', $response['type']);
        $this->assertStringContainsString("export type Level = 'one' | 'two';", $this->generated('enums/Level.d.ts'));
    }

    private function requiresFork(): void
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl not available');
        }
    }

    /**
     * @param  list<string>  $cases
     */
    private function writeEnum(array $cases): string
    {
        $file = $this->scratch.'/Enums/Level.php';
        $body = implode(' ', array_map(fn (string $case): string => sprintf("case %s = '%s';", ucfirst($case), $case), $cases));
        File::put($file, "<?php\nnamespace {$this->namespace};\nenum Level: string { {$body} }\n");

        return $file;
    }

    private function writeController(string $props): string
    {
        $file = $this->scratch.'/Controllers/ExampleController.php';
        File::put($file, <<<PHP
            <?php
            namespace {$this->namespace};
            use Pentacore\\Typefinder\\Attributes\\TypefinderPage;
            class ExampleController
            {
                #[TypefinderPage(component: 'Example', props: {$props})]
                public function page(): void {}
            }
            PHP);

        return $file;
    }

    /**
     * @param  list<string>  $phpOptions
     * @param  array<string, string>  $env
     */
    private function startWatch(array $phpOptions = [], array $env = []): void
    {
        $this->inputStream = new InputStream;
        $this->process = new Process(
            [PHP_BINARY, ...$phpOptions, dirname(__DIR__, 2).'/vendor/bin/testbench', 'typefinder:watch'],
            env: [
                'TYPEFINDER_WORKBENCH_CONFIG' => json_encode([
                    'typefinder.output_path' => $this->output,
                    'typefinder.enums.paths' => [$this->scratch.'/Enums'],
                    'typefinder.models.paths' => [],
                    'typefinder.requests.paths' => [],
                    'typefinder.resources.paths' => [],
                    'typefinder.inertia.enabled' => true,
                    'typefinder.inertia.paths' => [$this->scratch.'/Controllers'],
                ], JSON_THROW_ON_ERROR),
                'TYPEFINDER_WORKBENCH_AUTOLOAD' => json_encode([
                    $this->namespace.'\\Level' => $this->scratch.'/Enums/Level.php',
                    $this->namespace.'\\ExampleController' => $this->scratch.'/Controllers/ExampleController.php',
                ], JSON_THROW_ON_ERROR),
                ...$env,
            ],
        );
        $this->process->setTimeout(30.0);
        $this->process->setInput($this->inputStream);
        $this->process->start();
    }

    /**
     * Send a regen request and wait for its answer.
     *
     * @param  list<string>  $paths
     * @return array<string, mixed>
     */
    private function regen(array $paths): array
    {
        static $counter = 0;
        $id = 'r'.++$counter;
        $this->inputStream->write(ProtocolCodec::encode(['type' => 'regen', 'id' => $id, 'paths' => $paths]));

        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            foreach (explode("\n", $this->process->getOutput()) as $line) {
                $message = json_decode($line, true);
                if (is_array($message) && ($message['id'] ?? null) === $id) {
                    return $message;
                }
            }

            if (! $this->process->isRunning()) {
                break;
            }

            usleep(20_000);
        }

        $this->fail(sprintf("no answer to %s.\nstdout: %s\nstderr: %s", $id, $this->process->getOutput(), $this->process->getErrorOutput()));
    }

    private function generated(string $relative): string
    {
        return File::get($this->output.'/'.$relative);
    }
}
