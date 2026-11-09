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

    public function test_pagination_fields_bounds_are_nullable_for_empty_pages(): void
    {
        $output = (new TypeScriptRenderer)->renderHelpers();

        preg_match('/export type PaginationFields = \{(.*?)\n\}/s', $output, $match);
        $this->assertNotEmpty($match, 'PaginationFields block not found');
        $this->assertMatchesRegularExpression('/\bfrom: number \| null\b/', $match[1]);
        $this->assertMatchesRegularExpression('/\bto: number \| null\b/', $match[1]);
    }
}
