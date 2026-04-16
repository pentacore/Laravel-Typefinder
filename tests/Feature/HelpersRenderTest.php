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
}
