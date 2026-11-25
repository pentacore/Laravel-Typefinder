<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Requests\Issue233BranchOverrideRequest;
use Tests\TestCase;

/**
 * Issue #233: the branch warning's remedy (#[TypefinderOverrides]) neither
 * silenced it nor could declare an optional key.
 */
final class BranchOverrideRequestTest extends TestCase
{
    public function test_overrides_silence_the_branch_warning_and_declare_optional_keys(): void
    {
        $result = new RequestExtractor()->extract(Issue233BranchOverrideRequest::class);

        $this->assertArrayNotHasKey('warnings', $result);

        $expected = <<<'TS'
            export type Issue233BranchOverrideRequest = {
              set_password?: boolean;
              nested: {
                title: string;
                note?: string;
              };
              password?: string;
            };

            TS;

        $output = new TypeScriptRenderer()->renderRequest($result, []);

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }
}
