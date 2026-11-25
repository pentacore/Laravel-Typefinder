<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Requests\Issue230EmptyInRequest;
use Tests\TestCase;

/**
 * Issue #230: an empty `in` list rendered an empty type.
 */
final class EmptyInRuleTest extends TestCase
{
    public function test_an_empty_in_list_keeps_the_declared_type(): void
    {
        $expected = <<<'TS'
            export type Issue230EmptyInRequest = {
              pick?: number | null;
              other: number;
              label: string;
              bare: unknown;
              tags: unknown[] | Record<string, unknown>;
              raw: string;
            };

            TS;

        $output = new TypeScriptRenderer()->renderRequest(new RequestExtractor()->extract(Issue230EmptyInRequest::class), []);

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }
}
