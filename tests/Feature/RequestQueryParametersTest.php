<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\QueryRequests\DynamicQueryKeyRequest;
use Tests\Fixtures\Requests\InheritedQueryRulesRequest;
use Tests\Fixtures\Requests\Issue101QueryRequest;
use Tests\Fixtures\Requests\Issue88ReproRequest;
use Tests\Fixtures\Requests\QueryOnlyRequest;
use Tests\Fixtures\Requests\QueryRulesRequest;
use Tests\Fixtures\Requests\TraitQueryRulesRequest;
use Tests\TestCase;

/**
 * Issue #101: a rule key tagged `@query` is a query-string parameter. It was
 * emitted in the request (body) type; it now goes to `{Name}Query`.
 */
final class RequestQueryParametersTest extends TestCase
{
    private RequestExtractor $requestExtractor;

    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestExtractor = new RequestExtractor;
        $this->outputPath = sys_get_temp_dir().'/typefinder-query-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputPath);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_issue_reproduction_keeps_the_query_key_out_of_the_body(): void
    {
        $expected = <<<'TS'
            export type Issue101QueryRequest = {
              value: string;
            };

            export type Issue101QueryRequestQuery = {
              scope?: string;
            };

            TS;

        $this->assertSame($expected, $this->body($this->render(Issue101QueryRequest::class, false)));
    }

    public function test_extraction_separates_query_fields(): void
    {
        $result = $this->requestExtractor->extract(Issue101QueryRequest::class);

        $this->assertSame(['value'], array_column($result['fields'], 'name'));
        $this->assertSame(['scope'], array_column($result['query'], 'name'));
        $this->assertArrayNotHasKey('warnings', $result);
    }

    public function test_request_without_tags_has_no_query_type(): void
    {
        $result = $this->requestExtractor->extract(Issue88ReproRequest::class);

        $this->assertArrayNotHasKey('query', $result);
        $this->assertStringNotContainsString('Issue88ReproRequestQuery', $this->render(Issue88ReproRequest::class, false));
    }

    public function test_tags_are_read_from_an_inherited_rules_method(): void
    {
        $result = $this->requestExtractor->extract(InheritedQueryRulesRequest::class);

        $this->assertSame(['value'], array_column($result['fields'], 'name'));
        $this->assertSame(['scope'], array_column($result['query'], 'name'));
    }

    public function test_tags_are_read_from_a_rules_method_in_a_trait(): void
    {
        $result = $this->requestExtractor->extract(TraitQueryRulesRequest::class);

        $this->assertSame(['name'], array_column($result['fields'], 'name'));
        $this->assertSame(['cursor'], array_column($result['query'], 'name'));
    }

    public function test_tagged_keys_render_inline(): void
    {
        $expected = <<<'TS'
            import type { PostStatus } from '../enums';

            export type QueryRulesRequest = {
              title: string;
              body?: string;
              meta?: string;
              query?: {
                term?: string;
              };
            };

            export type QueryRulesRequestQuery = {
              page?: number;
              filter?: {
                status?: PostStatus;
                tags?: string[];
              };
              sort?: {
                by?: string;
                direction?: 'asc' | 'desc';
              };
            };

            TS;

        $this->assertSame($expected, $this->body($this->render(QueryRulesRequest::class, false)));
    }

    public function test_tagged_keys_render_named_types(): void
    {
        $expected = <<<'TS'
            import type { PostStatus } from '../enums';

            export type QueryRulesRequestQuery2 = {
              term?: string;
            };

            export type QueryRulesRequest = {
              title: string;
              body?: string;
              meta?: string;
              query?: QueryRulesRequestQuery2;
            };

            export type QueryRulesRequestQueryFilter = {
              status?: PostStatus;
              tags?: string[];
            };

            export type QueryRulesRequestQuerySort = {
              by?: string;
              direction?: 'asc' | 'desc';
            };

            export type QueryRulesRequestQuery = {
              page?: number;
              filter?: QueryRulesRequestQueryFilter;
              sort?: QueryRulesRequestQuerySort;
            };

            TS;

        $this->assertSame($expected, $this->body($this->render(QueryRulesRequest::class, true)));
    }

    public function test_tags_on_keys_that_are_not_string_literals_warn(): void
    {
        $result = $this->requestExtractor->extract(DynamicQueryKeyRequest::class);

        $this->assertSame(['title', 'constant', 'computed_key'], array_column($result['fields'], 'name'));
        $this->assertSame(['page'], array_column($result['query'], 'name'));
        $this->assertSame(
            ['DynamicQueryKeyRequest: 2 @query tags were ignored: only a rule key written as a string literal can be tagged'],
            $result['warnings'],
        );
    }

    public function test_readable_tags_do_not_warn(): void
    {
        $this->assertArrayNotHasKey('warnings', $this->requestExtractor->extract(QueryRulesRequest::class));
    }

    public function test_request_with_only_query_keys_has_an_empty_body(): void
    {
        $expected = <<<'TS'
            export type QueryOnlyRequest = Record<string, never>;

            export type QueryOnlyRequestQuery = {
              page: number;
              per_page?: number;
            };

            TS;

        $this->assertSame($expected, $this->body($this->render(QueryOnlyRequest::class, false)));
    }

    public function test_generate_writes_the_query_type_next_to_the_request_type(): void
    {
        config([
            'typefinder.output_path' => $this->outputPath,
            'typefinder.models.enabled' => false,
            'typefinder.enums.enabled' => false,
            'typefinder.resources.enabled' => false,
            'typefinder.requests.paths' => [__DIR__.'/../Fixtures/Requests'],
        ]);

        Artisan::call('typefinder:generate');

        $content = File::get($this->outputPath.'/requests/Issue101QueryRequest.d.ts');
        $this->assertStringContainsString("export type Issue101QueryRequest = {\n  value: string;\n};", $content);
        $this->assertStringContainsString("export type Issue101QueryRequestQuery = {\n  scope?: string;\n};", $content);
        $this->assertFileDoesNotExist($this->outputPath.'/requests/Issue101QueryRequestQuery.d.ts');
    }

    private function render(string $class, bool $extractNested): string
    {
        $enums = [['name' => 'PostStatus', 'fqcn' => PostStatus::class]];

        return new TypeScriptRenderer()->renderRequest($this->requestExtractor->extract($class), $enums, $extractNested);
    }

    /**
     * The rendered file without its header comment.
     */
    private function body(string $output): string
    {
        return ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output));
    }
}
