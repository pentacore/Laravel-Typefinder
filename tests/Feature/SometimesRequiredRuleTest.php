<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Requests\Issue103SometimesRequiredRequest;
use Tests\TestCase;

/**
 * Issue #103: a key with `sometimes` and `required` was emitted as non-optional.
 */
final class SometimesRequiredRuleTest extends TestCase
{
    public function test_sometimes_keeps_the_key_optional(): void
    {
        $expected = <<<'TS'
            export type Issue103SometimesRequiredRequest = {
              title?: string;
              body?: string;
              plain?: string;
              flag?: boolean;
              late?: number;
              meta?: {
                source: string;
              };
            };

            TS;

        $output = new TypeScriptRenderer()->renderRequest(new RequestExtractor()->extract(Issue103SometimesRequiredRequest::class), []);

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }
}
