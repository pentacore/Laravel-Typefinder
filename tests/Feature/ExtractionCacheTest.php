<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use Pentacore\Typefinder\Cache\ExtractionCache;
use Tests\TestCase;

final class ExtractionCacheTest extends TestCase
{
    private string $cachePath;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cachePath = sys_get_temp_dir().'/typefinder-cache-'.uniqid('', true).'.json';
        $this->fixturePath = sys_get_temp_dir().'/typefinder-fixture-'.uniqid('', true).'.php';
        file_put_contents($this->fixturePath, "<?php\n// v1\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->cachePath);
        @unlink($this->fixturePath);
        parent::tearDown();
    }

    public function test_put_and_get_round_trips_extraction_data(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $extractionCache->put($this->fixturePath, 'models', ['name' => 'Foo', 'columns' => []]);

        $this->assertSame(['name' => 'Foo', 'columns' => []], $extractionCache->get($this->fixturePath));
    }

    public function test_get_returns_null_when_mtime_changed(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);

        // Bump mtime forward 5 seconds so the stored mtime no longer matches.
        touch($this->fixturePath, Carbon::now()->getTimestamp() + 5);
        clearstatcache(true, $this->fixturePath);

        $this->assertNull($extractionCache->get($this->fixturePath));
    }

    public function test_get_returns_null_when_size_changed(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);

        file_put_contents($this->fixturePath, "<?php\n// v2 longer\n");
        touch($this->fixturePath, (int) filemtime($this->fixturePath) - 10); // keep mtime old
        clearstatcache(true, $this->fixturePath);

        $this->assertNull($extractionCache->get($this->fixturePath));
    }

    public function test_forget_removes_entry(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);

        $extractionCache->forget($this->fixturePath);

        $this->assertNull($extractionCache->get($this->fixturePath));
    }

    public function test_entries_by_category_returns_extractions_for_matching_entries(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['name' => 'Foo']);

        $otherFixture = sys_get_temp_dir().'/typefinder-other-'.uniqid('', true).'.php';
        file_put_contents($otherFixture, "<?php\n");
        $extractionCache->put($otherFixture, 'enums', ['name' => 'Bar']);

        try {
            $entries = $extractionCache->entriesByCategory('models');
            $this->assertCount(1, $entries);
            $this->assertSame(['name' => 'Foo'], $entries[$this->fixturePath]);
        } finally {
            @unlink($otherFixture);
        }
    }

    public function test_persist_and_load_round_trips_to_disk(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);
        $extractionCache->persist();

        $reloaded = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $this->assertSame(['v' => 1], $reloaded->get($this->fixturePath));
    }

    public function test_load_returns_empty_cache_when_hashes_mismatch(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);
        $extractionCache->persist();

        $reloaded = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:DIFFERENT');

        $this->assertNull($reloaded->get($this->fixturePath));
    }

    public function test_load_returns_empty_cache_when_file_missing(): void
    {
        $extractionCache = ExtractionCache::load('/nonexistent/path.json', 'sha256:lock', 'sha256:cfg');

        $this->assertNull($extractionCache->get($this->fixturePath));
    }

    public function test_load_returns_empty_cache_when_file_is_corrupt(): void
    {
        file_put_contents($this->cachePath, 'not json {');

        $extractionCache = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $this->assertNull($extractionCache->get($this->fixturePath));
    }
}
