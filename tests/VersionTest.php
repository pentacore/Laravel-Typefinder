<?php

declare(strict_types=1);

namespace Tests;

use Orchestra\Testbench\TestCase;
use Pentacore\Typefinder\Version;

final class VersionTest extends TestCase
{
    public function test_version_constant_is_semver_string(): void
    {
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/',
            Version::VERSION
        );
    }
}
