<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Enums\PostTag;
use Pentacore\Typefinder\Extractors\EnumExtractor;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

class EnumExtractorTest extends TestCase
{
    private EnumExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new EnumExtractor;
    }

    public function test_extracts_string_backed_enum(): void
    {
        $result = $this->extractor->extract(PostStatus::class);

        $this->assertSame('PostStatus', $result['name']);
        $this->assertSame(PostStatus::class, $result['fqcn']);
        $this->assertSame('string', $result['backingType']);
        $this->assertSame(['draft', 'published', 'archived'], $result['values']);
    }

    public function test_extracts_another_string_enum(): void
    {
        $result = $this->extractor->extract(PostTag::class);

        $this->assertSame('PostTag', $result['name']);
        $this->assertSame('string', $result['backingType']);
        $this->assertSame(['tech', 'science', 'art'], $result['values']);
    }

    public function test_discovers_enums_from_directory(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Enums'));

        $this->assertCount(2, $results);

        $names = array_column($results, 'name');
        $this->assertContains('PostStatus', $names);
        $this->assertContains('PostTag', $names);
    }

    public function test_only_backed_enums_are_returned(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Enums'));

        foreach ($results as $enum) {
            $this->assertNotNull($enum['backingType']);
            $this->assertContains($enum['backingType'], ['string', 'int']);
        }
    }
}
