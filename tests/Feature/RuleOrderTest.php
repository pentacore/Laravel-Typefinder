<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Requests\Issue122RuleOrderRequest;
use Tests\TestCase;

/**
 * Issue #122: Laravel applies every rule of a field, so `Rule::in()`, `in:`
 * and `Rule::enum()` narrow its type wherever they sit in the rule list.
 */
final class RuleOrderTest extends TestCase
{
    public function test_a_type_rule_before_an_in_or_enum_rule_does_not_hide_it(): void
    {
        $types = $this->types();

        $this->assertSame(['in' => ['out', 'in', 'processed']], $types['folder']);
        $this->assertSame(['in' => ['a', 'b']], $types['kind']);
        $this->assertSame(['in' => ['x', 'y']], $types['mode']);
        $this->assertSame(['enum' => PostStatus::class], $types['status']);
    }

    public function test_an_in_rule_on_a_numeric_field_gives_number_literals(): void
    {
        $types = $this->types();

        $this->assertSame(['in' => [1, 2, 3]], $types['level']);
        $this->assertSame(['in' => [1, 2]], $types['rank']);
    }

    public function test_an_in_rule_on_an_array_field_types_the_items(): void
    {
        $types = $this->types();

        $this->assertSame(['arrayOf' => ['in' => ['s', 'm']]], $types['sizes']);
        $this->assertSame(['arrayOf' => ['in' => ['a', 'b']]], $types['tags']);
    }

    public function test_an_in_rule_leaves_a_type_its_values_cannot_be_written_in(): void
    {
        $types = $this->types();

        $this->assertSame('boolean', $types['flag']);
        $this->assertSame('number', $types['count']);
    }

    public function test_request_type_is_narrowed_in_either_order(): void
    {
        $expected = <<<'TS'
            import type { PostStatus } from '../enums';

            export type Issue122RuleOrderRequest = {
              folder?: 'out' | 'in' | 'processed';
              kind: 'a' | 'b';
              mode?: 'x' | 'y';
              status: PostStatus;
              level: 1 | 2 | 3;
              rank: 1 | 2;
              sizes: ('s' | 'm')[];
              tags: ('a' | 'b')[];
              flag: boolean;
              count: number;
            };

            TS;

        $output = new TypeScriptRenderer()->renderRequest(
            new RequestExtractor()->extract(Issue122RuleOrderRequest::class),
            [['name' => 'PostStatus', 'fqcn' => PostStatus::class]],
        );

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }

    /**
     * @return array<string, mixed>
     */
    private function types(): array
    {
        return array_column(new RequestExtractor()->extract(Issue122RuleOrderRequest::class)['fields'], 'type', 'name');
    }
}
