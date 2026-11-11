<?php

declare(strict_types=1);

namespace Tests\Unit\Analysis;

use Pentacore\Typefinder\Analysis\PageMerger;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PageMergerTest extends TestCase
{
    public function test_a_component_declared_once_is_kept_as_it_is(): void
    {
        $page = $this->page('Dashboard', ['greeting' => 'string'], source: 'A::index');

        $this->assertSame([$page], PageMerger::merge([$page]));
    }

    public function test_a_prop_not_declared_by_every_action_becomes_optional(): void
    {
        $merged = PageMerger::merge([
            $this->page('Posts/Form', ['title' => 'string', 'post' => '\Acme\Post'], source: 'A::edit'),
            $this->page('Posts/Form', ['title' => 'string', 'draft' => 'boolean'], optional: ['draft'], source: 'A::create'),
        ]);

        $this->assertSame([[
            'component' => 'Posts/Form',
            'props' => ['title' => 'string', 'post' => 'Acme\\Post', 'draft' => 'boolean'],
            'optional' => ['draft', 'post'],
            'source' => 'A::edit, A::create',
        ]], $merged);
    }

    public function test_one_prop_with_two_types_fails_naming_both_actions(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('  Dashboard.greeting: A::index => string, B::index => number');

        PageMerger::merge([
            $this->page('Dashboard', ['greeting' => 'string'], source: 'A::index'),
            $this->page('Dashboard', ['greeting' => 'number'], source: 'B::index'),
        ]);
    }

    /**
     * @param  array<string, string>  $props
     * @param  list<string>  $optional
     * @return array{component: string, props: array<string, string>, optional: list<string>, source: string}
     */
    private function page(string $component, array $props, array $optional = [], string $source = 'A::index'): array
    {
        return ['component' => $component, 'props' => $props, 'optional' => $optional, 'source' => $source];
    }
}
