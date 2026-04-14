<?php

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Requests\StorePostRequest;
use App\Http\Requests\UpdatePostRequest;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

class RequestExtractorTest extends TestCase
{
    private RequestExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new RequestExtractor;
    }

    public function test_extracts_request_name(): void
    {
        $result = $this->extractor->extract(StorePostRequest::class);

        $this->assertSame('StorePostRequest', $result['name']);
        $this->assertSame(StorePostRequest::class, $result['fqcn']);
    }

    public function test_required_string_field(): void
    {
        $result = $this->extractor->extract(StorePostRequest::class);
        $field = $this->findField($result, 'title');

        $this->assertSame('string', $field['type']);
        $this->assertTrue($field['required']);
    }

    public function test_required_enum_field(): void
    {
        $result = $this->extractor->extract(StorePostRequest::class);
        $field = $this->findField($result, 'status');

        $this->assertSame(PostStatus::class, $field['type']['enum']);
        $this->assertTrue($field['required']);
    }

    public function test_nullable_array_field_with_typed_items(): void
    {
        $result = $this->extractor->extract(StorePostRequest::class);
        $field = $this->findField($result, 'tags');

        $this->assertSame('string[]', $field['type']);
        $this->assertFalse($field['required']);
        $this->assertTrue($field['nullable']);
    }

    public function test_nested_object_fields(): void
    {
        $result = $this->extractor->extract(StorePostRequest::class);
        $field = $this->findField($result, 'metadata');

        $this->assertSame('object', $field['type']);
        $this->assertFalse($field['required']);
        $this->assertTrue($field['nullable']);
        $this->assertCount(2, $field['children']);

        $keyChild = collect($field['children'])->firstWhere('name', 'key');
        $this->assertSame('string', $keyChild['type']);
    }

    public function test_sometimes_field_is_optional(): void
    {
        $result = $this->extractor->extract(StorePostRequest::class);
        $field = $this->findField($result, 'publish_now');

        $this->assertFalse($field['required']);
    }

    public function test_accepted_rule_maps_to_boolean(): void
    {
        $result = $this->extractor->extract(StorePostRequest::class);
        $field = $this->findField($result, 'publish_now');

        $this->assertSame('boolean', $field['type']);
    }

    public function test_in_rule_creates_literal_union(): void
    {
        $result = $this->extractor->extract(StorePostRequest::class);
        $field = $this->findField($result, 'category');

        $this->assertSame(['tech', 'science', 'art'], $field['type']['in']);
    }

    public function test_confirmed_generates_confirmation_field(): void
    {
        $result = $this->extractor->extract(UpdatePostRequest::class);

        $confirmField = $this->findField($result, 'password_confirmation');

        $this->assertNotNull($confirmField, 'password_confirmation field should be auto-generated');
        $this->assertSame('string', $confirmField['type']);
        $this->assertFalse($confirmField['required']);
    }

    public function test_sometimes_fields_are_optional(): void
    {
        $result = $this->extractor->extract(UpdatePostRequest::class);

        $titleField = $this->findField($result, 'title');
        $this->assertFalse($titleField['required']);

        $bodyField = $this->findField($result, 'body');
        $this->assertFalse($bodyField['required']);
    }

    public function test_discovers_requests_from_directory(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Http/Requests'));

        $this->assertCount(2, $results);
        $names = array_column($results, 'name');
        $this->assertContains('StorePostRequest', $names);
        $this->assertContains('UpdatePostRequest', $names);
    }

    /**
     * @return array{name: string, type: mixed, required: bool, nullable: bool, children?: list<array>}|null
     */
    private function findField(array $result, string $name): ?array
    {
        return collect($result['fields'])->firstWhere('name', $name);
    }
}
