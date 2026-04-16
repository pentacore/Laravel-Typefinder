<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Renderers\Concerns;

use Pentacore\Typefinder\Renderers\TypeScriptRenderer;

/**
 * Broadcasting type rendering — emits the four maps
 * (`BroadcastPublicChannels`, `BroadcastPrivateChannels`,
 * `BroadcastPresenceChannels`, `BroadcastEvents`) grouped by channel
 * visibility with per-channel event payload records.
 *
 * Mixed into {@see TypeScriptRenderer};
 * relies on the host class's `resolvePagePropType` for payload
 * class-string resolution and `FILE_HEADER`.
 */
trait RendersBroadcasting
{
    /**
     * Render the consolidated broadcasting.d.ts content.
     *
     * @param  list<array{event_class: string, broadcast_name: string, channels: list<array{type: string, name: string}>, payload: array<string, string>}>  $events
     * @param  list<array>  $allModels
     * @param  list<array>  $allEnums
     */
    public function renderBroadcasting(array $events, array $allModels, array $allEnums): string
    {
        $imports = [];

        $byType = ['public' => [], 'private' => [], 'presence' => []];
        $flatEvents = [];

        foreach ($events as $event) {
            $payloadStr = $this->renderPayloadRecord($event['payload'], $allModels, $allEnums, $imports);
            $flatEvents[] = sprintf("  '%s': %s;", $event['broadcast_name'], $payloadStr);

            foreach ($event['channels'] as $channel) {
                $byType[$channel['type']][$channel['name']][] = sprintf("'%s': %s", $event['broadcast_name'], $payloadStr);
            }
        }

        $sections = [];
        $sections[] = $this->renderChannelMap('BroadcastPublicChannels', $byType['public']);
        $sections[] = $this->renderChannelMap('BroadcastPrivateChannels', $byType['private']);
        $sections[] = $this->renderChannelMap('BroadcastPresenceChannels', $byType['presence']);
        $sections[] = "export type BroadcastEvents = {\n".implode("\n", $flatEvents)."\n};\n";

        $imports = array_values(array_unique($imports));
        sort($imports);

        $output = self::FILE_HEADER."\n";
        if ($imports !== []) {
            $output .= implode("\n", $imports)."\n\n";
        }

        return $output.implode("\n", $sections);
    }

    /**
     * @param  array<string, list<string>>  $entries
     */
    protected function renderChannelMap(string $typeName, array $entries): string
    {
        if ($entries === []) {
            return "export type {$typeName} = Record<string, never>;\n";
        }

        $lines = [];
        foreach ($entries as $channelName => $eventBodies) {
            $lines[] = sprintf("  '%s': { ", $channelName).implode('; ', $eventBodies).' };';
        }

        return "export type {$typeName} = {\n".implode("\n", $lines)."\n};\n";
    }

    /**
     * @param  array<string, string>  $payload
     * @param  list<array>  $allModels
     * @param  list<array>  $allEnums
     * @param  list<string>  $imports
     */
    protected function renderPayloadRecord(array $payload, array $allModels, array $allEnums, array &$imports): string
    {
        if ($payload === []) {
            return 'Record<string, never>';
        }

        $parts = [];
        foreach ($payload as $name => $type) {
            $resolved = $this->resolvePagePropType((string) $type, $allModels, $allEnums, $imports);
            $parts[] = sprintf('%s: %s', $name, $resolved);
        }

        return '{ '.implode('; ', $parts).' }';
    }
}
