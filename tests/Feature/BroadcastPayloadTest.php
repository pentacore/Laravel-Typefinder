<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Resources\Concerns\BaseShapeResource;
use App\Http\Resources\PostResource;
use App\Models\Post;
use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Extractors\BroadcastExtractor;
use Pentacore\Typefinder\Extractors\RequestExtractor;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use Pentacore\Typefinder\Services\Generator;
use Tests\Fixtures\AbstractRequests\BaseRequest;
use Tests\Fixtures\AbstractResources\BaseResource;
use Tests\Fixtures\Broadcasting\BaseEvent;
use Tests\Fixtures\Broadcasting\ConstructorPayloadEvent;
use Tests\Fixtures\Broadcasting\LiteralPayloadEvent;
use Tests\Fixtures\Broadcasting\NumericChannelEvent;
use Tests\Fixtures\Broadcasting\OpaquePayloadEvent;
use Tests\Fixtures\Broadcasting\ThingCreated;
use Tests\Fixtures\Broadcasting\TypedPropertiesEvent;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class BroadcastPayloadTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    private BroadcastExtractor $broadcastExtractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->broadcastExtractor = new BroadcastExtractor(function (string $warning): void {
            $this->warnings[] = $warning;
        });
    }

    public function test_broadcast_with_values_are_typed_by_what_they_are(): void
    {
        $event = $this->broadcastExtractor->extract(LiteralPayloadEvent::class);

        $this->assertSame([
            'action' => 'string',
            'label' => 'string',
            'count' => 'number',
            'ratio' => 'number',
            'done' => 'boolean',
            'none' => 'null',
            'tags' => 'unknown[]',
            'meta' => 'Record<string, unknown>',
            'status' => PostStatus::class,
            'post' => Post::class,
            'at' => 'string',
            'other' => 'unknown',
        ], $event['payload']);

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('"other"', $this->warnings[0]);
        $this->assertStringContainsString('#[TypefinderBroadcast(payload:', $this->warnings[0]);
    }

    public function test_broadcast_with_that_throws_is_typed_from_its_literal_keys(): void
    {
        $event = $this->broadcastExtractor->extract(ConstructorPayloadEvent::class);

        $this->assertSame(['id' => 'unknown', 'title' => 'unknown'], $event['payload']);
        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString(ConstructorPayloadEvent::class, $this->warnings[0]);
        $this->assertStringContainsString('#[TypefinderBroadcast(payload:', $this->warnings[0]);
    }

    public function test_broadcast_with_without_literal_keys_skips_the_event(): void
    {
        $failures = [];
        $event = $this->broadcastExtractor->tryExtract(OpaquePayloadEvent::class, function (string $class, \Throwable $throwable) use (&$failures): void {
            $failures[] = $throwable->getMessage();
        });

        $this->assertNull($event);
        $this->assertCount(1, $failures);
        $this->assertStringContainsString('#[TypefinderBroadcast(payload:', $failures[0]);
    }

    public function test_public_property_types_keep_nullability_and_map_classes(): void
    {
        $event = $this->broadcastExtractor->extract(TypedPropertiesEvent::class);

        $this->assertSame([
            'note' => 'string | null',
            'at' => 'string',
            'items' => 'unknown[]',
            'key' => 'string | number',
            'post' => Post::class.' | null',
            'any' => 'unknown',
            'when' => 'string',
            'status' => PostStatus::class.' | null',
            'resource' => PostResource::class,
            'other' => 'unknown',
            'list' => 'unknown[]',
        ], $event['payload']);

        $this->assertCount(1, $this->warnings);
        $this->assertStringContainsString('$other', $this->warnings[0]);
    }

    public function test_broadcast_as_returning_a_backed_enum_gives_its_value(): void
    {
        $this->assertSame('thing.created', $this->broadcastExtractor->extract(ThingCreated::class)['broadcast_name']);
    }

    public function test_numeric_constructor_properties_become_channel_placeholders(): void
    {
        $this->assertSame(
            [['type' => 'private', 'name' => 'party.{partyId}.zone.{zone}']],
            $this->broadcastExtractor->extract(NumericChannelEvent::class)['channels'],
        );
    }

    public function test_abstract_events_resources_and_requests_are_not_emittable(): void
    {
        $this->assertFalse($this->broadcastExtractor->isEmittable(BaseEvent::class));
        $this->assertFalse(new ResourceExtractor()->isEmittable(BaseResource::class));
        $this->assertFalse(new ResourceExtractor()->isEmittable(BaseShapeResource::class));
        $this->assertFalse(new RequestExtractor()->isEmittable(BaseRequest::class));
    }

    public function test_generation_warns_about_no_abstract_class(): void
    {
        $output = workbench_path('storage/typefinder-abstract-test');
        config([
            'typefinder.output_path' => $output,
            'typefinder.broadcasting.enabled' => true,
            'typefinder.broadcasting.paths' => [workbench_path('app/Events')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests'), __DIR__.'/../Fixtures/AbstractRequests'],
        ]);

        try {
            $this->artisan('typefinder:generate')
                ->doesntExpectOutputToContain('PartyEvent')
                ->doesntExpectOutputToContain('BaseShapeResource')
                ->doesntExpectOutputToContain('BaseRequest')
                ->assertSuccessful();
        } finally {
            File::deleteDirectory($output);
        }
    }

    public function test_two_events_with_one_name_fail_generation_naming_both(): void
    {
        $dir = workbench_path('app/SameNameEvents');
        File::ensureDirectoryExists($dir.'/Billing');
        File::put($dir.'/Created.php', $this->eventSource('App\SameNameEvents', 'orders'));
        File::put($dir.'/Billing/Created.php', $this->eventSource('App\SameNameEvents\Billing', 'invoices'));
        $output = workbench_path('storage/typefinder-same-name-test');

        config([
            'typefinder.output_path' => $output,
            'typefinder.broadcasting.enabled' => true,
            'typefinder.broadcasting.paths' => [$dir],
        ]);

        try {
            app(Generator::class)->generateFull();
            $this->fail('Two events named Created must fail generation.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertStringContainsString('Created: App\\SameNameEvents\\', $runtimeException->getMessage());
            $this->assertStringContainsString('App\\SameNameEvents\\Created', $runtimeException->getMessage());
            $this->assertStringContainsString('App\\SameNameEvents\\Billing\\Created', $runtimeException->getMessage());
        } finally {
            File::deleteDirectory($dir);
            File::deleteDirectory($output);
        }
    }

    private function eventSource(string $namespace, string $channel): string
    {
        return <<<PHP
<?php
namespace {$namespace};
use Illuminate\\Broadcasting\\Channel;
use Illuminate\\Contracts\\Broadcasting\\ShouldBroadcast;
class Created implements ShouldBroadcast {
    public function broadcastOn(): Channel { return new Channel('{$channel}'); }
}
PHP;
    }
}
