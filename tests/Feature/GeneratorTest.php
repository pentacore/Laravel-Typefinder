<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Services\RegenResult;
use Tests\TestCase;

final class GeneratorTest extends TestCase
{
    public function test_regen_result_holds_changed_warnings_failed_and_duration(): void
    {
        $regenResult = new RegenResult(
            changed: ['models/User.d.ts', 'models/index.d.ts'],
            warnings: ['App\\Models\\Foo.bar: unknown column type "geography"'],
            failed: [['path' => '/abs/Broken.php', 'message' => 'boom']],
            durationMs: 42,
        );

        $this->assertSame(['models/User.d.ts', 'models/index.d.ts'], $regenResult->changed);
        $this->assertSame(['App\\Models\\Foo.bar: unknown column type "geography"'], $regenResult->warnings);
        $this->assertSame([['path' => '/abs/Broken.php', 'message' => 'boom']], $regenResult->failed);
        $this->assertSame(42, $regenResult->durationMs);
    }
}
