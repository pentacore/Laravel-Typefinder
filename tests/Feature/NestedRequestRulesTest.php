<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Requests\StoreNestedRulesRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use ReflectionClass;
use Tests\Fixtures\Requests\Issue88ReproRequest;
use Tests\Fixtures\Requests\UnexpressibleRulesRequest;
use Tests\TestCase;

/**
 * Issue #88: rules nested more than one level deep were emitted as dotted
 * property keys (`headers.*.name: string;`), which is not valid TypeScript.
 */
final class NestedRequestRulesTest extends TestCase
{
    private RequestExtractor $requestExtractor;

    private string $outputPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->requestExtractor = new RequestExtractor;
        $this->outputPath = sys_get_temp_dir().'/typefinder-nested-'.uniqid('', true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->outputPath);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_issue_reproduction_renders_inline(): void
    {
        $expected = <<<'TS'
            export type Issue88ReproRequest = {
              request: {
                method: string;
                headers?: {
                  name: string;
                  value?: string | null;
                }[];
                meta?: {
                  source: string;
                };
              };
              items: {
                sku: string;
                address?: {
                  city: string;
                };
              }[];
            };

            TS;

        $this->assertSame($expected, $this->body($this->render(Issue88ReproRequest::class, false)));
    }

    public function test_issue_reproduction_renders_named_types(): void
    {
        $expected = <<<'TS'
            export type Issue88ReproRequestRequest = {
              method: string;
              headers?: Issue88ReproRequestRequestHeaders[];
              meta?: Issue88ReproRequestRequestMeta;
            };

            export type Issue88ReproRequestRequestHeaders = {
              name: string;
              value?: string | null;
            };

            export type Issue88ReproRequestRequestMeta = {
              source: string;
            };

            export type Issue88ReproRequestItems = {
              sku: string;
              address?: Issue88ReproRequestItemsAddress;
            };

            export type Issue88ReproRequestItemsAddress = {
              city: string;
            };

            export type Issue88ReproRequest = {
              request: Issue88ReproRequestRequest;
              items: Issue88ReproRequestItems[];
            };

            TS;

        $this->assertSame($expected, $this->body($this->render(Issue88ReproRequest::class, true)));
    }

    public function test_child_with_children_is_a_nested_object(): void
    {
        $request = $this->findField($this->requestExtractor->extract(Issue88ReproRequest::class), 'request');

        $this->assertSame('object', $request['type']);
        $this->assertSame(['method', 'headers', 'meta'], array_column($request['children'], 'name'));

        $headers = $this->findChild($request, 'headers');
        $this->assertSame('object-array', $headers['type']);
        $this->assertFalse($headers['required']);
        $this->assertSame(['name', 'value'], array_column($headers['children'], 'name'));
        $this->assertTrue($this->findChild($headers, 'value')['nullable']);

        $meta = $this->findChild($request, 'meta');
        $this->assertSame('object', $meta['type']);
        $this->assertTrue($this->findChild($meta, 'source')['required']);
    }

    public function test_object_array_items_can_hold_nested_objects(): void
    {
        $items = $this->findField($this->requestExtractor->extract(Issue88ReproRequest::class), 'items');

        $this->assertSame('object-array', $items['type']);
        $address = $this->findChild($items, 'address');
        $this->assertSame('object', $address['type']);
        $this->assertFalse($address['required']);
        $this->assertSame('string', $this->findChild($address, 'city')['type']);
    }

    public function test_deep_mixed_and_special_shapes_render_inline(): void
    {
        $expected = <<<'TS'
            import type { PostStatus } from '../enums';

            export type StoreNestedRulesRequest = {
              request: {
                method: string;
                headers?: {
                  name: string;
                  value?: string | null;
                }[];
                meta?: {
                  source: string;
                };
              };
              items: {
                sku: string;
                address?: {
                  city: string;
                };
              }[];
              order?: {
                customer: {
                  contacts?: {
                    phones?: string[];
                    kind: 'home' | 'work';
                    status?: PostStatus;
                  }[] | null;
                };
              } | null;
              grid?: number[][];
              boards: {
                label: string;
              }[][];
              shipping: {
                address: {
                  street: string;
                  zip?: string;
                };
                notes?: string[];
              };
              'http-headers'?: {
                'content-type'?: string;
                'x-forwarded-for'?: string[];
              };
              config?: {
                'app.name'?: string;
              };
            };

            TS;

        $this->assertSame($expected, $this->body($this->render(StoreNestedRulesRequest::class, false)));
    }

    public function test_deep_mixed_and_special_shapes_render_named_types(): void
    {
        $output = $this->render(StoreNestedRulesRequest::class, true);

        $this->assertStringContainsString(
            "export type StoreNestedRulesRequestOrder = {\n  customer: StoreNestedRulesRequestOrderCustomer;\n};",
            $output,
        );
        $this->assertStringContainsString(
            "export type StoreNestedRulesRequestOrderCustomer = {\n  contacts?: StoreNestedRulesRequestOrderCustomerContacts[] | null;\n};",
            $output,
        );
        $this->assertStringContainsString(
            "export type StoreNestedRulesRequestOrderCustomerContacts = {\n  phones?: string[];\n  kind: 'home' | 'work';\n  status?: PostStatus;\n};",
            $output,
        );
        $this->assertStringContainsString(
            "export type StoreNestedRulesRequestBoards = {\n  label: string;\n};",
            $output,
        );
        $this->assertStringContainsString(
            "export type StoreNestedRulesRequestShipping = {\n  address: StoreNestedRulesRequestShippingAddress;\n  notes?: string[];\n};",
            $output,
        );
        // A key that is not an identifier still gives a valid type name.
        $this->assertStringContainsString(
            "export type StoreNestedRulesRequestHttpHeaders = {\n  'content-type'?: string;\n  'x-forwarded-for'?: string[];\n};",
            $output,
        );
        $this->assertStringContainsString(
            "export type StoreNestedRulesRequest = {\n"
            ."  request: StoreNestedRulesRequestRequest;\n"
            ."  items: StoreNestedRulesRequestItems[];\n"
            ."  order?: StoreNestedRulesRequestOrder | null;\n"
            ."  grid?: number[][];\n"
            ."  boards: StoreNestedRulesRequestBoards[][];\n"
            ."  shipping: StoreNestedRulesRequestShipping;\n"
            ."  'http-headers'?: StoreNestedRulesRequestHttpHeaders;\n"
            ."  config?: StoreNestedRulesRequestConfig;\n"
            ."};\n",
            $output,
        );
    }

    public function test_no_property_key_keeps_a_dot_or_wildcard(): void
    {
        foreach ([false, true] as $extractNested) {
            foreach ([Issue88ReproRequest::class, StoreNestedRulesRequest::class, UnexpressibleRulesRequest::class] as $class) {
                $output = $this->render($class, $extractNested);

                // Every member line is `key: …` / `key?: …` with an identifier or a quoted key.
                foreach (explode("\n", $output) as $line) {
                    if (preg_match('/^\s+(\S.*?)\??: /', $line, $m) !== 1) {
                        continue;
                    }

                    $this->assertMatchesRegularExpression('/^([A-Za-z_$][A-Za-z0-9_$]*|\'[^\']+\')$/', $m[1], $line);
                }

                $this->assertStringNotContainsString('*', $this->body($output));
            }
        }
    }

    public function test_optional_and_nullable_parents_keep_their_flags(): void
    {
        $result = $this->requestExtractor->extract(StoreNestedRulesRequest::class);

        $order = $this->findField($result, 'order');
        $this->assertFalse($order['required']);
        $this->assertTrue($order['nullable']);

        $customer = $this->findChild($order, 'customer');
        $this->assertTrue($customer['required']);
        $this->assertFalse($customer['nullable']);

        $contacts = $this->findChild($customer, 'contacts');
        $this->assertSame('object-array', $contacts['type']);
        $this->assertFalse($contacts['required']);
        $this->assertTrue($contacts['nullable']);
    }

    public function test_parent_without_a_rule_is_derived_from_its_children(): void
    {
        $shipping = $this->findField($this->requestExtractor->extract(StoreNestedRulesRequest::class), 'shipping');

        // `shipping.address.street` is required, so both parents are too.
        $this->assertSame('object', $shipping['type']);
        $this->assertTrue($shipping['required']);
        $this->assertFalse($shipping['nullable']);
        $this->assertTrue($this->findChild($shipping, 'address')['required']);

        // A wildcard below a parent without a rule never makes it required.
        $notes = $this->findChild($shipping, 'notes');
        $this->assertSame('string[]', $notes['type']);
        $this->assertFalse($notes['required']);
    }

    public function test_wildcard_below_wildcard_nests_arrays(): void
    {
        $result = $this->requestExtractor->extract(StoreNestedRulesRequest::class);

        $this->assertSame('number[][]', $this->findField($result, 'grid')['type']);

        $boards = $this->findField($result, 'boards');
        $this->assertSame('object-array', $boards['type']);
        $this->assertSame(2, $boards['arrayDepth']);
    }

    public function test_one_level_shapes_are_unchanged(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;
        $request = [
            'name' => 'Req',
            'fields' => [
                ['name' => 'address', 'type' => 'object', 'required' => true, 'nullable' => true, 'children' => [
                    ['name' => 'city', 'type' => 'string', 'required' => true, 'nullable' => false],
                ]],
                ['name' => 'rows', 'type' => 'object-array', 'required' => false, 'nullable' => true, 'children' => [
                    ['name' => 'id', 'type' => 'number', 'required' => false, 'nullable' => true],
                ]],
            ],
        ];

        $this->assertSame(
            "export type Req = {\n  address: {\n    city: string;\n  } | null;\n  rows?: {\n    id?: number | null;\n  }[] | null;\n};\n",
            $this->body($typeScriptRenderer->renderRequest($request, [], false)),
        );
        $this->assertSame(
            "export type ReqAddress = {\n  city: string;\n};\n\nexport type ReqRows = {\n  id?: number | null;\n};\n\n"
            ."export type Req = {\n  address: ReqAddress | null;\n  rows?: ReqRows[] | null;\n};\n",
            $this->body($typeScriptRenderer->renderRequest($request, [], true)),
        );
    }

    public function test_renderer_quotes_keys_that_are_not_identifiers(): void
    {
        $request = [
            'name' => 'Req',
            'fields' => [
                ['name' => 'a.b', 'type' => 'string', 'required' => true, 'nullable' => false],
                ['name' => "it's", 'type' => 'string', 'required' => false, 'nullable' => false],
                ['name' => '0', 'type' => 'string', 'required' => true, 'nullable' => false],
                ['name' => 'ok_$1', 'type' => 'string', 'required' => true, 'nullable' => false],
            ],
        ];

        $output = new TypeScriptRenderer()->renderRequest($request, []);

        $this->assertStringContainsString("  'a.b': string;\n", $output);
        $this->assertStringContainsString("  'it\\'s'?: string;\n", $output);
        $this->assertStringContainsString("  '0': string;\n", $output);
        $this->assertStringContainsString("  ok_$1: string;\n", $output);
    }

    public function test_named_types_never_collide(): void
    {
        $leaf = [['name' => 'x', 'type' => 'string', 'required' => true, 'nullable' => false]];
        $request = [
            'name' => 'Req',
            'fields' => [
                ['name' => 'a-b', 'type' => 'object', 'required' => true, 'nullable' => false, 'children' => $leaf],
                ['name' => 'a_b', 'type' => 'object', 'required' => true, 'nullable' => false, 'children' => $leaf],
                ['name' => 'a.b', 'type' => 'object', 'required' => true, 'nullable' => false, 'children' => $leaf],
            ],
        ];

        $output = new TypeScriptRenderer()->renderRequest($request, [], true);

        preg_match_all('/^export type (\w+) =/m', $output, $m);
        $this->assertSame(array_unique($m[1]), $m[1]);
        $this->assertCount(4, $m[1]);
    }

    public function test_unexpressible_shapes_warn_and_stay_valid(): void
    {
        $result = $this->requestExtractor->extract(UnexpressibleRulesRequest::class);

        // The root-level wildcard is left out; everything else is kept.
        $this->assertSame(['title', 'mixed', 'deep'], array_column($result['fields'], 'name'));

        // Wildcard and named keys below one parent: the array shape wins.
        $mixed = $this->findField($result, 'mixed');
        $this->assertSame('object-array', $mixed['type']);
        $this->assertSame(['sku'], array_column($mixed['children'], 'name'));
        $this->assertSame('string[]', $this->findChild($this->findField($result, 'deep'), 'inner')['type']);

        $this->assertCount(3, $result['warnings']);
        $this->assertStringContainsString("'*.name'", $result['warnings'][0]);
        $this->assertStringContainsString("'mixed'", $result['warnings'][1]);
        $this->assertStringContainsString('total', $result['warnings'][1]);
        $this->assertStringContainsString("'deep.inner'", $result['warnings'][2]);
    }

    public function test_expressible_requests_have_no_warnings(): void
    {
        $this->assertArrayNotHasKey('warnings', $this->requestExtractor->extract(StoreNestedRulesRequest::class));
        $this->assertArrayNotHasKey('warnings', $this->requestExtractor->extract(Issue88ReproRequest::class));
    }

    public function test_generate_reports_unexpressible_shapes_so_strict_fails(): void
    {
        $this->useFixtureRequests();

        $this->artisan('typefinder:generate')
            ->expectsOutputToContain("[typefinder] warning: UnexpressibleRulesRequest: rule '*.name'")
            ->assertSuccessful();

        $this->artisan('typefinder:generate --strict')->assertFailed();
    }

    public function test_generate_json_lists_the_warnings(): void
    {
        $this->useFixtureRequests();

        Artisan::call('typefinder:generate', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertCount(3, $payload['warnings']);
    }

    public function test_incremental_run_reports_the_warnings_too(): void
    {
        $this->useFixtureRequests();
        $this->artisan('typefinder:generate')->assertSuccessful();

        $path = new ReflectionClass(UnexpressibleRulesRequest::class)->getFileName();

        $this->artisan('typefinder:generate', ['--strict' => true, '--only' => [$path]])
            ->expectsOutputToContain('UnexpressibleRulesRequest: rule')
            ->assertFailed();
    }

    private function useFixtureRequests(): void
    {
        config([
            'typefinder.output_path' => $this->outputPath,
            'typefinder.models.enabled' => false,
            'typefinder.enums.enabled' => false,
            'typefinder.resources.enabled' => false,
            'typefinder.requests.paths' => [__DIR__.'/../Fixtures/Requests'],
        ]);
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

    private function findField(array $result, string $name): ?array
    {
        return collect($result['fields'])->firstWhere('name', $name);
    }

    private function findChild(array $field, string $name): ?array
    {
        return collect($field['children'])->firstWhere('name', $name);
    }
}
