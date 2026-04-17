<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Cache\CacheKeyFactory;
use Tests\TestCase;

final class CacheKeyFactoryTest extends TestCase
{
    public function test_composer_lock_hash_is_stable_across_calls(): void
    {
        $cacheKeyFactory = new CacheKeyFactory(base_path());
        $first = $cacheKeyFactory->composerLockHash();
        $second = $cacheKeyFactory->composerLockHash();

        $this->assertSame($first, $second);
        $this->assertStringStartsWith('sha256:', $first);
    }

    public function test_composer_lock_hash_is_zero_when_file_missing(): void
    {
        $cacheKeyFactory = new CacheKeyFactory('/nonexistent');

        $this->assertSame('sha256:missing', $cacheKeyFactory->composerLockHash());
    }

    public function test_config_hash_changes_when_config_changes(): void
    {
        $cacheKeyFactory = new CacheKeyFactory(base_path());
        $before = $cacheKeyFactory->configHash(['output_path' => '/a', 'models' => ['enabled' => true]]);
        $after = $cacheKeyFactory->configHash(['output_path' => '/b', 'models' => ['enabled' => true]]);

        $this->assertNotSame($before, $after);
        $this->assertStringStartsWith('sha256:', $before);
    }

    public function test_config_hash_is_key_order_independent(): void
    {
        $cacheKeyFactory = new CacheKeyFactory(base_path());
        $a = $cacheKeyFactory->configHash(['x' => 1, 'y' => 2]);
        $b = $cacheKeyFactory->configHash(['y' => 2, 'x' => 1]);

        $this->assertSame($a, $b);
    }
}
