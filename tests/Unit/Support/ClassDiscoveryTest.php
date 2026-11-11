<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Enums\PostStatus;
use App\Models\Comment;
use App\Models\User;
use Pentacore\Typefinder\Support\ClassDiscovery;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

final class ClassDiscoveryTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir().'/typefinder-discovery-'.str_replace('.', '', uniqid('', true));
        mkdir($this->scratch.'/Nested', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch.'/{,Nested/}*', GLOB_BRACE) ?: [] as $file) {
            is_dir($file) ? null : unlink($file);
        }

        rmdir($this->scratch.'/Nested');
        rmdir($this->scratch);
        parent::tearDown();
    }

    public function test_files_lists_php_files_by_real_path_in_name_order(): void
    {
        touch($this->scratch.'/b.php');
        touch($this->scratch.'/a.php');
        touch($this->scratch.'/Nested/c.php');
        touch($this->scratch.'/notes.txt');

        $real = (string) realpath($this->scratch);

        $this->assertSame([$real.'/Nested/c.php', $real.'/a.php', $real.'/b.php'], ClassDiscovery::files($this->scratch));
    }

    public function test_files_of_a_missing_directory_is_empty(): void
    {
        $this->assertSame([], ClassDiscovery::files($this->scratch.'/nowhere'));
    }

    public function test_in_returns_the_loadable_classes_and_enums_of_a_directory(): void
    {
        $models = dirname((string) new ReflectionClass(User::class)->getFileName());
        $enums = dirname((string) new ReflectionClass(PostStatus::class)->getFileName());

        $this->assertContains(User::class, ClassDiscovery::in($models));
        $this->assertContains(Comment::class, ClassDiscovery::in($models));
        $this->assertContains(PostStatus::class, ClassDiscovery::in($enums));
    }

    public function test_a_class_that_cannot_be_autoloaded_is_left_out_with_a_warning(): void
    {
        $file = $this->scratch.'/Ghost.php';
        file_put_contents($file, "<?php\nnamespace Nowhere\\Discovery;\nclass Ghost {}\n");

        $heard = [];
        $classes = ClassDiscovery::in($this->scratch, function (string $class, Throwable $throwable) use (&$heard): void {
            $heard[$class] = $throwable->getMessage();
        });

        $this->assertSame([], $classes);
        $this->assertSame(['Nowhere\Discovery\Ghost'], array_keys($heard));
        $this->assertStringContainsString('could not be autoloaded', $heard['Nowhere\Discovery\Ghost']);
    }

    public function test_an_interface_or_a_file_without_a_class_is_left_out_silently(): void
    {
        $id = basename($this->scratch);
        $interface = 'DiscoveryContract'.preg_replace('/\W/', '', $id);
        file_put_contents($this->scratch.'/'.$interface.'.php', "<?php\ninterface {$interface} {}\n");
        require $this->scratch.'/'.$interface.'.php';
        file_put_contents($this->scratch.'/helpers.php', "<?php\nreturn [];\n");

        $heard = [];
        $classes = ClassDiscovery::in($this->scratch, function (string $class) use (&$heard): void {
            $heard[] = $class;
        });

        $this->assertSame([], $classes);
        $this->assertSame([], $heard);
        $this->assertNull(ClassDiscovery::loadable($this->scratch.'/helpers.php'));
    }
}
