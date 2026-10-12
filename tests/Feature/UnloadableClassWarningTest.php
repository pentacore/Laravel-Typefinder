<?php

declare(strict_types=1);

namespace Tests\Feature;

use Closure;
use Pentacore\Typefinder\Extractors\BroadcastExtractor;
use Pentacore\Typefinder\Extractors\ControllerExtractor;
use Pentacore\Typefinder\Extractors\EnumExtractor;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Throwable;

/**
 * A file whose declared class can't be autoloaded (namespace doesn't match
 * its PSR-4 path) must surface a warning from every extractor rather than
 * being skipped silently.
 */
final class UnloadableClassWarningTest extends TestCase
{
    /**
     * @return iterable<string, array{Closure(): object, string}>
     */
    public static function extractors(): iterable
    {
        yield 'enums' => [fn (): EnumExtractor => new EnumExtractor, "enum Ghost: string { case A = 'a'; }"];

        yield 'models' => [fn (): ModelExtractor => new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver), 'class Ghost {}'];

        yield 'requests' => [fn (): RequestExtractor => new RequestExtractor, 'class Ghost {}'];

        yield 'resources' => [fn (): ResourceExtractor => new ResourceExtractor, 'class Ghost {}'];

        yield 'controllers' => [fn (): ControllerExtractor => new ControllerExtractor, 'class Ghost {}'];

        yield 'broadcasting' => [fn (): BroadcastExtractor => new BroadcastExtractor, 'class Ghost {}'];
    }

    /**
     * @param  Closure(): object  $makeExtractor
     */
    #[DataProvider('extractors')]
    public function test_warns_when_a_declared_class_cannot_be_autoloaded(Closure $makeExtractor, string $declaration): void
    {
        $dir = sys_get_temp_dir().'/typefinder-ghost-'.uniqid('', true);
        mkdir($dir);
        file_put_contents($dir.'/Ghost.php', "<?php\nnamespace App\\Ghosts;\n{$declaration}\n");

        $warnings = [];

        try {
            $results = $makeExtractor()->extractFromDirectory($dir, null, function (string $cls, Throwable $throwable) use (&$warnings): void {
                $warnings[] = $cls.': '.$throwable->getMessage();
            });
        } finally {
            @unlink($dir.'/Ghost.php');
            @rmdir($dir);
        }

        $this->assertSame([], $results);
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('App\Ghosts\Ghost', $warnings[0]);
        $this->assertStringContainsString('Ghost.php', $warnings[0]);
        $this->assertStringContainsString('could not be autoloaded', $warnings[0]);
    }
}
