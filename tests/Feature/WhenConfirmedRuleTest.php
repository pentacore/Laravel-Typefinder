<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Requests\Issue232WhenConfirmedRequest;
use Tests\TestCase;

/**
 * Issue #232: `confirmed` inside `Rule::when()` did not add the confirmation key.
 */
final class WhenConfirmedRuleTest extends TestCase
{
    public function test_confirmed_in_a_when_branch_adds_the_confirmation_key(): void
    {
        $expected = <<<'TS'
            export type Issue232WhenConfirmedRequest = {
              set_password?: boolean;
              password?: string;
              pin: string;
              password_confirmation?: string;
              pin_repeat: string;
            };

            TS;

        $output = new TypeScriptRenderer()->renderRequest(new RequestExtractor()->extract(Issue232WhenConfirmedRequest::class), []);

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }
}
