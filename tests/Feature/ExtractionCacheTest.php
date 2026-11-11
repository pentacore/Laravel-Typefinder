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

    public function test_put_round_trips_extraction_data(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $extractionCache->put($this->fixturePath, 'models', ['name' => 'Foo', 'columns' => []]);

        $this->assertSame(['name' => 'Foo', 'columns' => []], $extractionCache->entriesByCategory('models')[$this->fixturePath]);
        $this->assertTrue($extractionCache->isFresh($this->fixturePath, 'models'));
    }

    public function test_entry_is_not_fresh_when_mtime_changed(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);

        // Bump mtime forward 5 seconds so the stored mtime no longer matches.
        touch($this->fixturePath, Carbon::now()->getTimestamp() + 5);
        clearstatcache(true, $this->fixturePath);

        $this->assertFalse($extractionCache->isFresh($this->fixturePath, 'models'));
        // The last extraction stays available until the file is extracted again.
        $this->assertSame(['v' => 1], $extractionCache->entriesByCategory('models')[$this->fixturePath]);
    }

    public function test_entry_is_not_fresh_when_size_changed(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);

        file_put_contents($this->fixturePath, "<?php\n// v2 longer\n");
        touch($this->fixturePath, (int) filemtime($this->fixturePath) - 10); // keep mtime old
        clearstatcache(true, $this->fixturePath);

        $this->assertFalse($extractionCache->isFresh($this->fixturePath, 'models'));
        // The last extraction stays available until the file is extracted again.
        $this->assertSame(['v' => 1], $extractionCache->entriesByCategory('models')[$this->fixturePath]);
    }

    public function test_forget_removes_entry(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);

        $extractionCache->forget($this->fixturePath, 'models');

        $this->assertSame([], $extractionCache->entriesByCategory('models'));
        $this->assertFalse($extractionCache->isFresh($this->fixturePath, 'models'));
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

    public function test_a_file_that_yields_nothing_is_fresh_but_not_an_entry(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', null);
        $extractionCache->persist();

        $reloaded = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $this->assertTrue($reloaded->isFresh($this->fixturePath, 'models'));
        $this->assertSame([], $reloaded->entriesByCategory('models'));
    }

    public function test_one_file_is_tracked_per_category(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'enums', null);
        $extractionCache->put($this->fixturePath, 'requests', ['name' => 'Foo']);

        $this->assertSame([], $extractionCache->entriesByCategory('enums'));
        $this->assertSame([$this->fixturePath => ['name' => 'Foo']], $extractionCache->entriesByCategory('requests'));

        $extractionCache->forget($this->fixturePath, 'requests');

        $this->assertTrue($extractionCache->isFresh($this->fixturePath, 'enums'));
        $this->assertFalse($extractionCache->isFresh($this->fixturePath, 'requests'));
    }

    public function test_persist_and_load_round_trips_to_disk(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);
        $extractionCache->persist();

        $reloaded = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $this->assertSame(['v' => 1], $reloaded->entriesByCategory('models')[$this->fixturePath]);
        $this->assertTrue($reloaded->isFresh($this->fixturePath, 'models'));
        $this->assertTrue($reloaded->restored());
    }

    public function test_load_returns_empty_cache_when_hashes_mismatch(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);
        $extractionCache->persist();

        $reloaded = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:DIFFERENT');

        $this->assertSame([], $reloaded->entriesByCategory('models'));
        $this->assertFalse($reloaded->restored());
    }

    public function test_load_returns_empty_cache_when_file_missing(): void
    {
        $extractionCache = ExtractionCache::load('/nonexistent/path.json', 'sha256:lock', 'sha256:cfg');

        $this->assertSame([], $extractionCache->entriesByCategory('models'));
        $this->assertFalse($extractionCache->restored());
    }

    public function test_load_returns_empty_cache_when_file_is_corrupt(): void
    {
        file_put_contents($this->cachePath, 'not json {');

        $extractionCache = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $this->assertSame([], $extractionCache->entriesByCategory('models'));
        $this->assertFalse($extractionCache->restored());
    }

    public function test_load_returns_empty_cache_when_schema_version_mismatch(): void
    {
        // Persist a cache, then hand-edit the schema_version and confirm load() drops it.
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);
        $extractionCache->persist();

        $raw = (array) json_decode((string) file_get_contents($this->cachePath), true);
        $raw['schema_version'] = 999;
        file_put_contents($this->cachePath, (string) json_encode($raw));

        $reloaded = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $this->assertSame([], $reloaded->entriesByCategory('models'));
        $this->assertFalse($reloaded->restored());
    }

    public function test_load_returns_empty_cache_when_entries_are_malformed(): void
    {
        file_put_contents($this->cachePath, (string) json_encode([
            'schema_version' => ExtractionCache::SCHEMA_VERSION,
            'composer_lock_hash' => 'sha256:lock',
            'config_hash' => 'sha256:cfg',
            'entries' => 'not a map',
        ]));

        $extractionCache = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');

        $this->assertSame([], $extractionCache->entriesByCategory('models'));
        $this->assertFalse($extractionCache->restored());
    }

    public function test_load_evicts_entries_for_deleted_files(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);

        // Add an entry for a file that will be deleted before reload.
        $ephemeral = sys_get_temp_dir().'/typefinder-ephemeral-'.uniqid('', true).'.php';
        file_put_contents($ephemeral, "<?php\n");
        $extractionCache->put($ephemeral, 'models', ['v' => 2]);
        $extractionCache->persist();

        // Delete the ephemeral file, then reload.
        unlink($ephemeral);
        $reloaded = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');

        // The surviving fixture is still present.
        $this->assertSame([$this->fixturePath => ['v' => 1]], $reloaded->entriesByCategory('models'));
        // The deleted file's entry was evicted.
        $this->assertFalse($reloaded->isFresh($ephemeral, 'models'));
    }

    public function test_persist_evicts_entries_for_deleted_files(): void
    {
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);

        $ephemeral = sys_get_temp_dir().'/typefinder-ephemeral-'.uniqid('', true).'.php';
        file_put_contents($ephemeral, "<?php\n");
        $extractionCache->put($ephemeral, 'models', ['v' => 2]);

        // Delete before persisting.
        unlink($ephemeral);
        $extractionCache->persist();

        // Reload and verify the deleted entry was not persisted.
        $reloaded = ExtractionCache::load($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $this->assertSame([$this->fixturePath => ['v' => 1]], $reloaded->entriesByCategory('models'));
    }

    public function test_persist_does_not_corrupt_existing_cache_when_write_fails(): void
    {
        // Write a valid cache, then force persist() to a path we can't write to.
        $extractionCache = new ExtractionCache($this->cachePath, 'sha256:lock', 'sha256:cfg');
        $extractionCache->put($this->fixturePath, 'models', ['v' => 1]);
        $extractionCache->persist();

        $valid = (string) file_get_contents($this->cachePath);
        $this->assertJson($valid);

        // Swap to a path inside a nonexistent, un-mkdir-able location.
        // Use a file-as-directory to guarantee mkdir + write both fail.
        $trap = sys_get_temp_dir().'/typefinder-trap-'.uniqid('', true);
        file_put_contents($trap, 'blocking file');

        try {
            $bad = new ExtractionCache($trap.'/nested/cache.json', 'sha256:lock', 'sha256:cfg');
            $bad->put($this->fixturePath, 'models', ['v' => 2]);
            $bad->persist(); // must not throw

            // Verify no .tmp droppings next to the trap file.
            $tmps = glob($trap.'.*.tmp');
            $this->assertEmpty($tmps ?: []);
        } finally {
            @unlink($trap);
        }
    }
}
