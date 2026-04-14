<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\LegacyEvent;
use App\Events\PostPublished;
use App\Events\UnrecoverableBroadcast;
use App\Events\UnrecoverableWithAttributeBroadcast;
use App\Models\Post;
use Pentacore\Typefinder\Extractors\BroadcastExtractor;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

final class BroadcastExtractorTest extends TestCase
{
    private BroadcastExtractor $extractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->extractor = new BroadcastExtractor;
    }

    public function test_extracts_public_channel_event(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Events'));
        $byName = collect($results)->keyBy('broadcast_name');

        $this->assertArrayHasKey('PostPublished', $byName->toArray());
        $event = $byName['PostPublished'];
        $this->assertSame([['type' => 'public', 'name' => 'posts']], $event['channels']);
        $this->assertSame(PostPublished::class, $event['event_class']);
    }

    public function test_extracts_private_channel_event_with_broadcast_with(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Events'));
        $byName = collect($results)->keyBy('broadcast_name');

        $this->assertArrayHasKey('OrderShipped', $byName->toArray());
        $event = $byName['OrderShipped'];
        $this->assertSame([['type' => 'private', 'name' => 'orders.{orderId}']], $event['channels']);
        $this->assertArrayHasKey('order', $event['payload']);
        $this->assertArrayHasKey('trackingNumber', $event['payload']);
    }

    public function test_extracts_presence_channel(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Events'));
        $byName = collect($results)->keyBy('broadcast_name');

        $channels = $byName['MessageSent']['channels'];
        $this->assertSame([['type' => 'presence', 'name' => 'chat.{roomId}']], $channels);
    }

    public function test_falls_back_to_public_properties_for_payload(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Events'));
        $byName = collect($results)->keyBy('broadcast_name');

        $this->assertArrayHasKey('post', $byName['PostPublished']['payload']);
        $this->assertSame(Post::class, $byName['PostPublished']['payload']['post']);
    }

    public function test_skips_classes_tagged_with_typefinder_ignore(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Events'));
        $classes = array_column($results, 'event_class');

        $this->assertNotContains(LegacyEvent::class, $classes);
    }

    public function test_skips_unrecoverable_event_with_warning(): void
    {
        $warned = [];
        $results = $this->extractor->extractFromDirectory(
            workbench_path('app/Events'),
            onExtract: null,
            onWarn: function (string $cls, \Throwable $throwable) use (&$warned): void {
                $warned[] = $cls;
            },
        );

        $this->assertContains(UnrecoverableBroadcast::class, $warned);
        $classes = array_column($results, 'event_class');
        $this->assertNotContains(UnrecoverableBroadcast::class, $classes);
    }

    public function test_attribute_override_provides_declarative_fallback(): void
    {
        $results = $this->extractor->extractFromDirectory(workbench_path('app/Events'));
        $byName = collect($results)->keyBy('broadcast_name');

        $this->assertArrayHasKey('AuditFired', $byName->toArray());
        $event = $byName['AuditFired'];
        $this->assertSame(UnrecoverableWithAttributeBroadcast::class, $event['event_class']);
        $this->assertSame([['type' => 'private', 'name' => 'audit']], $event['channels']);
        $this->assertSame(['id' => 'number', 'reason' => 'string'], $event['payload']);
    }
}
