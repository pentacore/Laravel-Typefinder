<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\PostTag;
use Pentacore\Typefinder\Extractors\EnumExtractor;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class EnumExtractorTest extends TestCase
{
    private EnumExtractor $enumExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->enumExtractor = new EnumExtractor;
    }

    public function test_extracts_string_backed_enum(): void
    {
        $result = $this->enumExtractor->extract(PostStatus::class);

        $this->assertSame('PostStatus', $result['name']);
        $this->assertSame(PostStatus::class, $result['fqcn']);
        $this->assertSame('string', $result['backingType']);
        $this->assertSame(['draft', 'published', 'archived'], $result['values']);
    }

    public function test_extracts_another_string_enum(): void
    {
        $result = $this->enumExtractor->extract(PostTag::class);

        $this->assertSame('PostTag', $result['name']);
        $this->assertSame('string', $result['backingType']);
        $this->assertSame(['tech', 'science', 'art'], $result['values']);
    }

    public function test_discovers_enums_from_directory(): void
    {
        $results = $this->enumExtractor->extractFromDirectory(workbench_path('app/Enums'));

        $this->assertCount(2, $results);

        $names = array_column($results, 'name');
        $this->assertContains('PostStatus', $names);
        $this->assertContains('PostTag', $names);
    }

    public function test_only_backed_enums_are_returned(): void
    {
        $results = $this->enumExtractor->extractFromDirectory(workbench_path('app/Enums'));

        foreach ($results as $result) {
            $this->assertNotNull($result['backingType']);
            $this->assertContains($result['backingType'], ['string', 'int']);
        }
    }

    public function test_does_not_warn_for_a_loadable_class_that_is_not_an_enum(): void
    {
        $dir = $this->tempDirWith([
            'User.php' => "<?php\nnamespace App\\Models;\nclass User {}\n",
        ]);

        $warnings = [];
        $results = $this->enumExtractor->extractFromDirectory($dir, null, function (string $cls) use (&$warnings): void {
            $warnings[] = $cls;
        });

        $this->assertSame([], $results);
        $this->assertSame([], $warnings);
    }

    /**
     * @param  array<string, string>  $files
     */
    private function tempDirWith(array $files): string
    {
        $dir = sys_get_temp_dir().'/typefinder-enums-'.uniqid('', true);
        mkdir($dir);
        foreach ($files as $name => $contents) {
            file_put_contents($dir.'/'.$name, $contents);
        }

        $this->beforeApplicationDestroyed(function () use ($dir, $files): void {
            foreach (array_keys($files) as $name) {
                @unlink($dir.'/'.$name);
            }

            @rmdir($dir);
        });

        return $dir;
    }
}
