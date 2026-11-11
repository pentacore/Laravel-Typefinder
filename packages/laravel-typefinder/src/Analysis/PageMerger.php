<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use RuntimeException;

/**
 * Turns the `#[TypefinderPage]` entries of every controller into one entry
 * per Inertia component.
 */
final class PageMerger
{
    /**
     * Merge #[TypefinderPage] entries that name the same component, so one
     * page can be rendered by several actions (create/edit sharing a form,
     * tabs of one page).
     *
     * A prop is required only when every contributing action declares it and
     * none lists it as optional. Two actions giving one prop different types
     * fail the run: a silent union would hide mistakes, and an intended union
     * can be declared as such in both actions.
     *
     * @param  list<array{component: string, props: array<string, string>, optional: list<string>, source: string}>  $pages
     * @return list<array{component: string, props: array<string, string>, optional: list<string>, source: string}>
     */
    public static function merge(array $pages): array
    {
        $byComponent = [];
        foreach ($pages as $page) {
            $byComponent[$page['component']][] = $page;
        }

        $merged = [];
        $conflicts = [];

        foreach ($byComponent as $component => $entries) {
            if (count($entries) === 1) {
                $merged[] = $entries[0];

                continue;
            }

            $props = [];
            $declaredBy = [];
            $optional = [];
            $typesSeen = [];

            foreach ($entries as $entry) {
                foreach ($entry['props'] as $key => $type) {
                    $normalised = ltrim(trim((string) $type), '\\');
                    $props[$key] ??= $normalised;
                    $declaredBy[$key] = ($declaredBy[$key] ?? 0) + 1;
                    $typesSeen[$key][$normalised][] = $entry['source'];

                    if (in_array($key, $entry['optional'], true)) {
                        $optional[$key] = true;
                    }
                }
            }

            foreach ($typesSeen as $key => $byType) {
                if (count($byType) < 2) {
                    continue;
                }

                $declarations = [];
                foreach ($byType as $type => $sources) {
                    foreach ($sources as $source) {
                        $declarations[] = $source.' => '.$type;
                    }
                }

                $conflicts[] = sprintf('  %s.%s: %s', $component, $key, implode(', ', $declarations));
            }

            foreach ($declaredBy as $key => $count) {
                if ($count < count($entries)) {
                    $optional[$key] = true;
                }
            }

            $merged[] = [
                'component' => $component,
                'props' => $props,
                'optional' => array_keys($optional),
                'source' => implode(', ', array_unique(array_column($entries, 'source'))),
            ];
        }

        if ($conflicts !== []) {
            throw new RuntimeException(
                "TypefinderPage props declared with different types for the same component:\n"
                .implode("\n", $conflicts)."\n"
                .'Give the prop the same type in every action (a union if it really varies).',
            );
        }

        return $merged;
    }
}
