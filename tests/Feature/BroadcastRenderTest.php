<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\MessageSent;
use App\Events\OrderShipped;
use App\Events\PostPublished;
use App\Models\Post;
use App\Models\User;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Tests\TestCase;

final class BroadcastRenderTest extends TestCase
{
    public function test_emits_public_private_presence_and_events_maps(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;

        $events = [
            ['event_class' => PostPublished::class, 'broadcast_name' => 'PostPublished', 'channels' => [['type' => 'public', 'name' => 'posts']], 'payload' => ['post' => Post::class]],
            ['event_class' => OrderShipped::class, 'broadcast_name' => 'OrderShipped', 'channels' => [['type' => 'private', 'name' => 'orders.{orderId}']], 'payload' => ['order' => Post::class, 'trackingNumber' => 'string']],
            ['event_class' => MessageSent::class, 'broadcast_name' => 'MessageSent', 'channels' => [['type' => 'presence', 'name' => 'chat.{roomId}']], 'payload' => ['user' => User::class, 'body' => 'string']],
        ];

        $allModels = [
            ['name' => 'Post', 'fqcn' => Post::class],
            ['name' => 'User', 'fqcn' => User::class],
        ];

        $output = $typeScriptRenderer->renderBroadcasting($events, $allModels, []);

        $this->assertStringContainsString("import type { Post } from './models';", $output);
        $this->assertStringContainsString("import type { User } from './models';", $output);

        $this->assertStringContainsString('export type BroadcastPublicChannels = {', $output);
        $this->assertStringContainsString("'posts': { 'PostPublished': { post: Post } };", $output);

        $this->assertStringContainsString('export type BroadcastPrivateChannels = {', $output);
        $this->assertStringContainsString("'orders.{orderId}': { 'OrderShipped': { order: Post; trackingNumber: string } };", $output);

        $this->assertStringContainsString('export type BroadcastPresenceChannels = {', $output);
        $this->assertStringContainsString("'chat.{roomId}': { 'MessageSent': { user: User; body: string } };", $output);

        $this->assertStringContainsString('export type BroadcastEvents = {', $output);
        $this->assertStringContainsString("'PostPublished': { post: Post };", $output);
        $this->assertStringContainsString("'OrderShipped': { order: Post; trackingNumber: string };", $output);
        $this->assertStringContainsString("'MessageSent': { user: User; body: string };", $output);
    }

    public function test_passes_through_unknown_payload_types(): void
    {
        $typeScriptRenderer = new TypeScriptRenderer;

        $events = [[
            'event_class' => 'App\\Events\\X',
            'broadcast_name' => 'X',
            'channels' => [['type' => 'public', 'name' => 'x']],
            'payload' => ['raw' => 'Record<string, unknown>'],
        ]];

        $output = $typeScriptRenderer->renderBroadcasting($events, [], []);

        $this->assertStringContainsString("'X': { raw: Record<string, unknown> };", $output);
    }
}
