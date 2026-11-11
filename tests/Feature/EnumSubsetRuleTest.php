<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Enums\Issue117Priority;
use Tests\Fixtures\Enums\Issue117Suit;
use Tests\Fixtures\Requests\Issue117EnumSubsetRequest;
use Tests\TestCase;

/**
 * Issue #117: `Rule::enum(X::class)->only(…)` / `->except(…)` accepts a
 * subset of the enum, so the generated type is that subset.
 */
final class EnumSubsetRuleTest extends TestCase
{
    public function test_extraction_records_the_backing_values_a_rule_keeps_or_refuses(): void
    {
        $types = array_column(new RequestExtractor()->extract(Issue117EnumSubsetRequest::class)['fields'], 'type', 'name');

        $this->assertSame(['enum' => PostStatus::class, 'only' => ['draft', 'published']], $types['only']);
        $this->assertSame(['enum' => PostStatus::class, 'except' => ['archived']], $types['except']);
        $this->assertSame(['enum' => PostStatus::class, 'only' => ['draft']], $types['both']);
        $this->assertSame(['enum' => PostStatus::class], $types['whole']);
        $this->assertSame(['enum' => PostStatus::class, 'only' => ['archived']], $types['collected']);
        $this->assertSame(['enum' => Issue117Priority::class, 'only' => [1, 3]], $types['priority']);
        $this->assertSame(['enum' => Issue117Suit::class], $types['pure']);
    }

    public function test_request_type_narrows_the_enum(): void
    {
        $expected = <<<'TS'
            import type { Issue117Priority } from '../enums';
            import type { Issue117Suit } from '../enums';
            import type { PostStatus } from '../enums';

            export type Issue117EnumSubsetRequest = {
              only: Extract<PostStatus, 'draft' | 'published'>;
              except: Exclude<PostStatus, 'archived'>;
              both: Extract<PostStatus, 'draft'>;
              whole: PostStatus;
              maybe?: Exclude<PostStatus, 'draft'> | null;
              collected: Extract<PostStatus, 'archived'>;
              list: (Extract<PostStatus, 'draft' | 'published'>)[];
              priority: Extract<Issue117Priority, 1 | 3>;
              pure: Issue117Suit;
            };

            TS;

        $this->assertSame($expected, $this->render());
    }

    public function test_a_value_with_a_quote_is_escaped(): void
    {
        $rendered = new TypeScriptRenderer()->renderRequest([
            'name' => 'QuoteRequest',
            'fqcn' => 'App\Http\Requests\QuoteRequest',
            'fields' => [['name' => 'kind', 'type' => ['enum' => PostStatus::class, 'except' => ["it's"]], 'required' => true, 'nullable' => false]],
        ], [['name' => 'PostStatus', 'fqcn' => PostStatus::class]]);

        $this->assertStringContainsString("  kind: Exclude<PostStatus, 'it\\'s'>;", $rendered);
    }

    private function render(): string
    {
        $enums = [
            ['name' => 'PostStatus', 'fqcn' => PostStatus::class],
            ['name' => 'Issue117Priority', 'fqcn' => Issue117Priority::class],
        ];
        $output = new TypeScriptRenderer()->renderRequest(new RequestExtractor()->extract(Issue117EnumSubsetRequest::class), $enums);

        return ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output));
    }
}
