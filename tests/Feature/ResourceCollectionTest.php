<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\ResourceExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\Fixtures\Resources\Collections\AttributeCollection;
use Tests\Fixtures\Resources\Collections\CustomArrayCollection;
use Tests\Fixtures\Resources\Collections\GadgetCollection;
use Tests\Fixtures\Resources\Collections\NothingCollection;
use Tests\Fixtures\Resources\Collections\WidgetCollection;
use Tests\Fixtures\Resources\Collections\WidgetResource;
use Tests\TestCase;

/**
 * `ResourceCollection` subclasses are typed as a list of the resource they
 * collect (#170).
 */
final class ResourceCollectionTest extends TestCase
{
    private const array RESOURCES = [WidgetResource::class, WidgetCollection::class, GadgetCollection::class];

    private ResourceExtractor $resourceExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resourceExtractor = new ResourceExtractor(modelClasses: [], enumClasses: [], resourceClasses: self::RESOURCES);
    }

    public function test_collects_property_names_the_resource(): void
    {
        $this->assertSame(
            ['kind' => 'collection', 'type' => ['kind' => 'array', 'of' => ['kind' => 'resource', 'fqcn' => WidgetResource::class]]],
            $this->resourceExtractor->extract(GadgetCollection::class)['shape'] ?? null,
        );
    }

    public function test_collects_attribute_names_the_resource(): void
    {
        $this->assertSame(
            ['kind' => 'array', 'of' => ['kind' => 'resource', 'fqcn' => WidgetResource::class]],
            $this->resourceExtractor->extract(AttributeCollection::class)['shape']['type'] ?? null,
        );
    }

    public function test_collection_name_convention_names_the_resource(): void
    {
        $this->assertSame(
            ['kind' => 'array', 'of' => ['kind' => 'resource', 'fqcn' => WidgetResource::class]],
            $this->resourceExtractor->extract(WidgetCollection::class)['shape']['type'] ?? null,
        );
    }

    public function test_collection_overriding_to_array_is_not_a_plain_list(): void
    {
        $this->assertNull($this->resourceExtractor->extract(CustomArrayCollection::class));
    }

    public function test_collection_collecting_nothing_is_skipped(): void
    {
        $this->assertNull($this->resourceExtractor->extract(NothingCollection::class));
    }

    public function test_renders_a_list_of_the_collected_resource(): void
    {
        $resources = array_map($this->resourceExtractor->extract(...), self::RESOURCES);
        $collection = $resources[1];

        $output = new TypeScriptRenderer()->renderResource($collection, [], [], $resources);

        $this->assertStringContainsString("import type { WidgetResource } from './WidgetResource';", $output);
        $this->assertStringContainsString('export type WidgetCollection = WidgetResource[];', $output);
    }
}
