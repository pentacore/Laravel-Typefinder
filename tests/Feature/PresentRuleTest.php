<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Requests\Issue102PresentRequest;
use Tests\TestCase;

/**
 * Issue #102: a key with the `present` rule was emitted as optional.
 */
final class PresentRuleTest extends TestCase
{
    public function test_present_makes_the_key_required(): void
    {
        $expected = <<<'TS'
            export type Issue102PresentRequest = {
              value: unknown;
              items: unknown[] | Record<string, unknown>;
              note: string | null;
              when_if?: string;
              when_unless?: string;
              when_with?: unknown;
              when_with_all?: unknown;
            };

            TS;

        $output = new TypeScriptRenderer()->renderRequest(new RequestExtractor()->extract(Issue102PresentRequest::class), []);

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }
}
