<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Services\OutputTree;
use Tests\TestCase;

final class OutputTreeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/typefinder-tree-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_write_creates_the_file_and_its_directory_and_records_it(): void
    {
        $outputTree = new OutputTree($this->root);
        $outputTree->write('models/User.d.ts', 'export type User = {};');

        $this->assertSame('export type User = {};', File::get($this->root.'/models/User.d.ts'));
        $this->assertSame(['models/User.d.ts'], $outputTree->changed());
    }

    public function test_write_of_identical_content_leaves_the_file_alone(): void
    {
        new OutputTree($this->root)->write('index.d.ts', 'same');
        $mtime = Carbon::now()->subSeconds(100)->getTimestamp();
        touch($this->root.'/index.d.ts', $mtime);

        $outputTree = new OutputTree($this->root);
        $outputTree->write('index.d.ts', 'same');

        clearstatcache();
        $this->assertSame($mtime, filemtime($this->root.'/index.d.ts'));
        $this->assertSame([], $outputTree->changed());
    }

    public function test_remove_deletes_a_file_and_records_it(): void
    {
        $outputTree = new OutputTree($this->root);
        $outputTree->write('pages.d.ts', 'x');
        $outputTree->remove('pages.d.ts');

        $this->assertFileDoesNotExist($this->root.'/pages.d.ts');
        $this->assertSame(['pages.d.ts'], $outputTree->changed());
    }

    public function test_remove_of_a_file_that_is_not_there_changes_nothing(): void
    {
        $outputTree = new OutputTree($this->root);
        $outputTree->remove('pages.d.ts');

        $this->assertSame([], $outputTree->changed());
    }

    public function test_prune_keeps_only_the_named_files_of_a_directory(): void
    {
        $outputTree = new OutputTree($this->root);
        $outputTree->write('enums/Kept.d.ts', 'a');
        $outputTree->write('enums/Stale.d.ts', 'b');
        $outputTree->write('models/Other.d.ts', 'c');

        $outputTree->prune('enums', ['Kept.d.ts', 'index.d.ts']);
        $outputTree->prune('requests', ['index.d.ts']);

        $this->assertFileExists($this->root.'/enums/Kept.d.ts');
        $this->assertFileDoesNotExist($this->root.'/enums/Stale.d.ts');
        $this->assertFileExists($this->root.'/models/Other.d.ts');
    }

    public function test_remove_directory_deletes_its_files_and_the_directory(): void
    {
        $outputTree = new OutputTree($this->root);
        $outputTree->write('enums/Status.d.ts', 'a');
        $outputTree->write('enums/index.d.ts', 'b');

        $outputTree->removeDirectory('enums');

        $this->assertDirectoryDoesNotExist($this->root.'/enums');
    }
}
