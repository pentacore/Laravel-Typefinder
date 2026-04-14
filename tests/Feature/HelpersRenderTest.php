<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

final class HelpersRenderTest extends TestCase
{
    public function test_emits_all_seven_response_helpers(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;
        $output = $typeScriptRenderer->renderHelpers();

        $this->assertStringContainsString('export type Wrapped<T> = { data: T };', $output);
        $this->assertStringContainsString('export type WrappedCollection<T> = { data: T[] };', $output);
        $this->assertStringContainsString('export type PaginatedCollection<T>', $output);
        $this->assertStringContainsString('export type CursorPaginatedCollection<T>', $output);
        $this->assertStringContainsString('export type SimplePaginatedCollection<T>', $output);
        $this->assertStringContainsString('export type ValidationErrorResponse = {', $output);
        $this->assertStringContainsString('export type ErrorResponse = { message: string };', $output);
    }
}
