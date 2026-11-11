<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Support\Categories;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class CategoriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources')],
            'typefinder.inertia.paths' => [workbench_path('app/Http/Controllers')],
            'typefinder.broadcasting.paths' => [workbench_path('app/Events')],
        ]);
    }

    public function test_roots_lists_the_enabled_categories_in_extraction_order(): void
    {
        $this->assertSame(['enums', 'models', 'requests', 'resources'], array_keys(Categories::roots()));

        config(['typefinder.broadcasting.enabled' => true, 'typefinder.enums.enabled' => false]);

        $this->assertSame(['models', 'requests', 'resources', 'broadcasting'], array_keys(Categories::roots()));
    }

    public function test_roots_are_real_paths(): void
    {
        $dir = sys_get_temp_dir().'/typefinder-categories-'.uniqid();
        mkdir($dir);
        config(['typefinder.models.paths' => [$dir.'/../'.basename($dir)]]);

        try {
            $this->assertSame([(string) realpath($dir)], Categories::roots()['models']);
        } finally {
            rmdir($dir);
        }
    }

    public function test_all_describes_every_category_whether_enabled_or_not(): void
    {
        $all = Categories::all();

        $this->assertSame(Categories::ALL, array_keys($all));
        $this->assertSame(['enabled' => true, 'paths' => [(string) realpath(workbench_path('app/Models'))]], $all['models']);
        $this->assertSame(['enabled' => false, 'paths' => [(string) realpath(workbench_path('app/Http/Controllers'))]], $all['inertia']);
    }

    public function test_real_path_of_a_deleted_file_uses_its_directory(): void
    {
        $dir = (string) realpath(workbench_path('app/Models'));

        $this->assertSame($dir.'/Vanished.php', Categories::realPath(workbench_path('app/Models/../Models/Vanished.php')));
        $this->assertSame('/nowhere/at/all.php', Categories::realPath('/nowhere/at/all.php'));
    }
}
