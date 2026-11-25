<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\RuleTypeRequests\ConfirmedRequest;
use Tests\Fixtures\RuleTypeRequests\NestedShapesRequest;
use Tests\Fixtures\RuleTypeRequests\OverriddenKeysRequest;
use Tests\Fixtures\RuleTypeRequests\RouteBranchRequest;
use Tests\TestCase;

/**
 * Request shapes: confirmation keys (#154, #162), nullable items and
 * `array:a,b` keys (#189, #190, #191), override keys (#144) and a `rules()`
 * that branches on request state (#194).
 */
final class RequestShapesTest extends TestCase
{
    public function test_confirmation_keys_follow_the_confirmed_key(): void
    {
        $this->assertRendered(ConfirmedRequest::class, <<<'TS'
            export type ConfirmedRequest = {
              pin: string;
              user: {
                email: string;
                email_confirmation: string;
              };
              code: string;
              pw?: string | null;
              own: string;
              own_confirmation?: string;
              pin_repeat: string;
              code_confirmation: string;
              pw_confirmation?: string | null;
            };

            export type ConfirmedRequestQuery = {
              secret: string;
              secret_confirmation: string;
            };

            TS);
    }

    public function test_nullable_items_and_allowed_keys(): void
    {
        $this->assertRendered(NestedShapesRequest::class, <<<'TS'
            import type { PostStatus } from '../enums';

            export type NestedShapesRequest = {
              tags: (string | null)[];
              states?: (PostStatus | null)[];
              objs: ({
                a: string;
              } | null)[];
              grid?: ({
                v?: number;
              } | null)[][];
              g: {
                anime?: {
                  notes: string;
                };
                tv?: {
                  notes: string;
                };
                movie?: {
                  notes: string;
                };
              };
              l: {
                anime?: string[] | null;
                tv: string[];
              };
              pick: {
                provider: string;
                model?: unknown;
              };
            };

            TS);
    }

    public function test_nullable_items_of_an_extracted_nested_type(): void
    {
        $output = new TypeScriptRenderer()->renderRequest(new RequestExtractor()->extract(NestedShapesRequest::class), [], true);

        $this->assertStringContainsString('  objs: (NestedShapesRequestObjs | null)[];', $output);
        $this->assertStringContainsString('  grid?: (NestedShapesRequestGrid | null)[][];', $output);
    }

    public function test_overrides_add_keys_and_reach_nested_keys(): void
    {
        $this->assertRendered(OverriddenKeysRequest::class, <<<'TS'
            export type OverriddenKeysRequest = {
              user?: {
                name?: Foo;
              };
              lines: {
                qty: number;
                sku: Sku;
              }[];
              extra: string;
              meta: {
                source: 'web' | 'api';
              };
            };

            TS);
    }

    public function test_rules_branching_on_request_state_warns(): void
    {
        $result = new RequestExtractor()->extract(RouteBranchRequest::class);

        $this->assertSame(
            ['RouteBranchRequest: rules() branches on request state (request(), $this->route()), so the type follows the branch taken with no route, user or input bound; declare the fields with #[TypefinderOverrides] if that is not the branch to type'],
            $result['warnings'],
        );
    }

    /**
     * @param  class-string<FormRequest>  $request
     */
    private function assertRendered(string $request, string $expected): void
    {
        $output = new TypeScriptRenderer()->renderRequest(new RequestExtractor()->extract($request), []);

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }
}
