<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\AnyOf;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\RuleTypeRequests\InjectedRulesRequest;
use Tests\Fixtures\RuleTypeRequests\NoRulesRequest;
use Tests\Fixtures\RuleTypeRequests\RecentRuleObjectsRequest;
use Tests\Fixtures\RuleTypeRequests\RuleObjectsRequest;
use Tests\Fixtures\RuleTypeRequests\RuleOrderAndBranchesRequest;
use Tests\Fixtures\RuleTypeRequests\RuleValuesRequest;
use Tests\TestCase;

/**
 * Request rule typing: rule objects (#152, #153, #159), `in` / `accepted` /
 * `declined` / `missing` values (#155, #156, #157), `Rule::when()` and
 * `Rule::forEach()` (#158), rule order (#160, #161) and how `rules()` is
 * called (#151).
 */
final class RequestRuleTypesTest extends TestCase
{
    public function test_rule_objects_and_file_rules_are_typed(): void
    {
        if (! method_exists(Rule::class, 'date') || ! method_exists(Rule::class, 'numeric') || ! method_exists(Rule::class, 'email')) {
            $this->markTestSkipped('Rule::date(), Rule::numeric() and Rule::email() need a recent Laravel.');
        }

        $this->assertRendered(RuleObjectsRequest::class, <<<'TS'
            export type RuleObjectsRequest = {
              avatar: File;
              doc: File;
              born: string;
              amount: number;
              email: string;
              password: string;
              upload: File;
              types: File;
              ext: File;
              photo: File;
              tuple?: string;
            };

            TS);
    }

    public function test_recent_rule_objects_are_typed(): void
    {
        if (! class_exists(AnyOf::class) || ! method_exists(Rule::class, 'url') || ! method_exists(Rule::class, 'uuid') || ! method_exists(Rule::class, 'string')) {
            $this->markTestSkipped('Rule::url(), Rule::uuid(), Rule::string() and Rule::anyOf() need a recent Laravel.');
        }

        $this->assertRendered(RecentRuleObjectsRequest::class, <<<'TS'
            import type { Issue117Priority } from '../enums';

            export type RecentRuleObjectsRequest = {
              site: string;
              ref: string;
              name: string;
              any?: string | Issue117Priority;
            };

            TS);
    }

    public function test_in_accepted_declined_and_missing_values(): void
    {
        $this->assertRendered(RuleValuesRequest::class, <<<'TS'
            import type { Issue117Priority } from '../enums';

            export type RuleValuesRequest = {
              size: 1 | 2 | 3;
              prio?: Extract<Issue117Priority, 1 | 3>;
              flag?: boolean;
              pair?: 'a,b' | 'c';
              greeting?: 'say "hi"' | 'x';
              csv?: 'a,b' | 'c';
              label?: '1' | '2';
              terms: true | 'yes' | 'on' | 1 | '1' | 'true';
              optout: false | 'no' | 'off' | 0 | '0' | 'false';
              maybe?: boolean;
              gone?: never;
              gone_if?: string;
              words?: number;
              numbers?: 1 | 2.5;
              on?: '1' | '';
              yes?: true;
              mixed?: 'a' | false;
              picks?: (1 | 2)[];
              tuple_if?: string;
            };

            TS);
    }

    public function test_branches_for_each_and_rule_order(): void
    {
        $this->assertRendered(RuleOrderAndBranchesRequest::class, <<<'TS'
            export type RuleOrderAndBranchesRequest = {
              when?: number;
              when2?: number | string | null;
              both: string;
              lines: number[];
              rows: {
                qty: number;
              }[];
              a: unknown;
              pin: string;
              count: number;
              items?: string[];
              broken_when?: unknown;
              many?: string;
              broken_each?: unknown[];
            };

            TS);
    }

    public function test_unreadable_branches_and_for_each_rules_warn(): void
    {
        $warnings = new RequestExtractor()->extract(RuleOrderAndBranchesRequest::class)['warnings'] ?? [];

        $this->assertCount(2, $warnings);
        $this->assertStringContainsString('a Rule::when() branch could not be read (needs input)', $warnings[0]);
        $this->assertStringContainsString("the Rule::forEach() rules of 'broken_each.*' could not be read", $warnings[1]);
    }

    public function test_rules_is_called_through_the_container(): void
    {
        $this->assertRendered(InjectedRulesRequest::class, <<<'TS'
            export type InjectedRulesRequest = {
              title: string;
            };

            TS);
    }

    public function test_a_request_without_rules_is_an_empty_type(): void
    {
        $this->assertRendered(NoRulesRequest::class, <<<'TS'
            export type NoRulesRequest = Record<string, never>;

            TS);
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
