<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

final class HelpersRenderTest extends TestCase
{
    public function test_emits_all_response_helpers(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;
        $output = $typeScriptRenderer->renderHelpers();

        // JsonResource wrappers
        $this->assertStringContainsString('export type WrappedResource<T> = { data: T };', $output);
        $this->assertStringContainsString('export type WrappedResourceCollection<T> = { data: T[] };', $output);
        $this->assertStringContainsString('export type PaginatedResourceCollection<T>', $output);
        $this->assertStringContainsString('export type CursorPaginatedResourceCollection<T>', $output);
        $this->assertStringContainsString('export type SimplePaginatedResourceCollection<T>', $output);

        // Eloquent paginate() shapes
        $this->assertStringContainsString('export type PaginatedModel<TData>', $output);
        $this->assertStringContainsString('export type PaginationFields = {', $output);

        // Error shapes
        $this->assertStringContainsString('export type ValidationErrorResponse = {', $output);
        $this->assertStringContainsString('export type ErrorResponse = { message: string };', $output);
    }

    public function test_pagination_link_is_declared_and_shared(): void
    {
        $output = (new TypeScriptRenderer)->renderHelpers();

        $this->assertStringContainsString(
            'export type PaginationLink = { url: string | null; label: string; page?: number | null; active: boolean };',
            $output,
        );
        $this->assertStringContainsString('links: PaginationLink[];', $output);
        $this->assertStringNotContainsString('links: Array<{', $output);
    }

    /**
     * The keys Laravel's `PaginatedResourceResponse` and `Paginator` /
     * `CursorPaginator::toArray()` produce (#169).
     */
    public function test_simple_and_cursor_paginators_match_laravels_json(): void
    {
        $output = (new TypeScriptRenderer)->renderHelpers();

        $simple = $this->block($output, 'SimplePaginatedResourceCollection<T>');
        $this->assertStringContainsString("links: {\n    first: string | null;\n    last: null;\n    prev: string | null;\n    next: string | null;\n  };", $simple);
        $this->assertStringContainsString('current_page_url: string;', $simple);

        $cursor = $this->block($output, 'CursorPaginatedResourceCollection<T>');
        $this->assertStringContainsString("links: {\n    first: null;\n    last: null;\n    prev: string | null;\n    next: string | null;\n  };", $cursor);

        $this->assertSame(
            ['current_page', 'current_page_url', 'data', 'first_page_url', 'from', 'next_page_url', 'path', 'per_page', 'prev_page_url', 'to'],
            $this->keys($this->block($output, 'SimplePaginatedModel<TData>')),
        );
        $this->assertSame(
            ['data', 'path', 'per_page', 'next_cursor', 'next_page_url', 'prev_cursor', 'prev_page_url'],
            $this->keys($this->block($output, 'CursorPaginatedModel<TData>')),
        );
    }

    private function block(string $output, string $name): string
    {
        preg_match('/export type '.preg_quote($name, '/').' = (.*?)\n\};?\n/s', $output, $match);
        $this->assertNotEmpty($match, $name.' block not found');

        return $match[1];
    }

    /** @return list<string> */
    private function keys(string $block): array
    {
        preg_match_all('/^  (\w+):/m', $block, $matches);

        return $matches[1];
    }

    public function test_pagination_fields_bounds_are_nullable_for_empty_pages(): void
    {
        $output = (new TypeScriptRenderer)->renderHelpers();

        preg_match('/export type PaginationFields = \{(.*?)\n\}/s', $output, $match);
        $this->assertNotEmpty($match, 'PaginationFields block not found');
        $this->assertMatchesRegularExpression('/\bfrom: number \| null\b/', $match[1]);
        $this->assertMatchesRegularExpression('/\bto: number \| null\b/', $match[1]);
    }
}
