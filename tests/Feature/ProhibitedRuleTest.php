<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Requests\Issue130ProhibitedRequest;
use Tests\TestCase;

/**
 * Issue #130: a `prohibited` key was emitted as optional `unknown`.
 */
final class ProhibitedRuleTest extends TestCase
{
    public function test_prohibited_key_is_typed_never(): void
    {
        $expected = <<<'TS'
            export type Issue130ProhibitedRequest = {
              id: number;
              path?: never;
              legacy?: never | null;
              when_if?: string;
              when_unless?: string;
            };

            TS;

        $output = new TypeScriptRenderer()->renderRequest(new RequestExtractor()->extract(Issue130ProhibitedRequest::class), []);

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }
}
