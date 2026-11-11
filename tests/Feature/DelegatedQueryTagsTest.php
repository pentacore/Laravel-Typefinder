<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Requests\Delegated\ContainerSetValueRequest;
use Tests\Fixtures\Requests\Delegated\ExtendedSetValueRequest;
use Tests\Fixtures\Requests\Delegated\FactorySetValueRequest;
use Tests\Fixtures\Requests\Delegated\SetValueRequest;
use Tests\TestCase;

/**
 * Issue #108: `@query` tags were only read from the `rules()` method of the
 * request itself, so they were missed when it returns the rules of another
 * class, of its parent or of a helper method.
 */
final class DelegatedQueryTagsTest extends TestCase
{
    private RequestExtractor $requestExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestExtractor = new RequestExtractor;
    }

    public function test_issue_reproduction_reads_the_tags_of_the_other_request(): void
    {
        $expected = <<<'TS'
            export type SetValueRequest = {
              value: unknown;
            };

            export type SetValueRequestQuery = {
              scope?: string;
            };

            TS;

        $output = new TypeScriptRenderer()->renderRequest($this->requestExtractor->extract(SetValueRequest::class), []);

        $this->assertSame($expected, ltrim(str_replace(TypeScriptRenderer::FILE_HEADER, '', $output)));
    }

    public function test_static_factory_delegation_keeps_the_requests_own_tags(): void
    {
        $result = $this->requestExtractor->extract(FactorySetValueRequest::class);

        $this->assertSame(['value', 'note'], array_column($result['fields'], 'name'));
        $this->assertSame(['scope', 'page'], array_column($result['query'], 'name'));
    }

    public function test_container_delegation_reads_every_resolved_class(): void
    {
        $result = $this->requestExtractor->extract(ContainerSetValueRequest::class);

        // `filter` comes from calls that name no class, so its tag is not seen.
        $this->assertSame(['value', 'filter'], array_column($result['fields'], 'name'));
        $this->assertSame(['cursor', 'sort'], array_column($result['query'], 'name'));
    }

    public function test_parent_and_helper_methods_are_followed(): void
    {
        $result = $this->requestExtractor->extract(ExtendedSetValueRequest::class);

        $this->assertSame(['value', 'comment', 'owner'], array_column($result['fields'], 'name'));
        $this->assertSame(['scope', 'sort', 'filter'], array_column($result['query'], 'name'));
        $this->assertArrayNotHasKey('warnings', $result);
    }
}
