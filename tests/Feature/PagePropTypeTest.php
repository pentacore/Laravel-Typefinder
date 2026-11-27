<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Http\Resources\UserResource;
use App\Models\User;
use Pentacore\Typefinder\Extractors\ControllerExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Support\GeneratedClasses;
use RuntimeException;
use Tests\Fixtures\Controllers\PhpDocPropController;
use Tests\Fixtures\Controllers\ThrowingAutoloadPropController;
use Tests\TestCase;

/**
 * A `#[TypefinderPage]` prop written as a PHPDoc type is converted to
 * TypeScript; one that is no valid type is `unknown` with a warning (#247).
 */
final class PagePropTypeTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    /** @var array<string, array<string, string>> props by component */
    private array $props = [];

    protected function setUp(): void
    {
        parent::setUp();

        $extractor = new ControllerExtractor(
            new GeneratedClasses([User::class], [PostStatus::class], [UserResource::class]),
            function (string $warning): void {
                $this->warnings[] = $warning;
            },
        );

        foreach ($extractor->extract(PhpDocPropController::class) as $page) {
            $this->props[$page['component']] = $page['props'];
        }
    }

    public function test_phpdoc_types_are_converted(): void
    {
        $props = $this->props['PhpDoc/Show'];

        $this->assertSame('{ id: number; name?: string; tags: string[] }', $props['shape']);
        $this->assertSame('Record<string, number>', $props['counts']);
        $this->assertSame('number[]', $props['ids']);
        $this->assertSame('string | null', $props['maybe']);
        $this->assertSame('unknown', $props['anything']);
    }

    public function test_class_names_in_a_phpdoc_type_resolve_through_the_controller_imports(): void
    {
        $props = $this->props['PhpDoc/Show'];

        $this->assertSame('\\'.User::class.'[]', $props['users']);
        $this->assertSame('\\'.PostStatus::class.'[]', $props['statuses']);
        $this->assertSame('\\'.UserResource::class.' | null', $props['editor']);
        $this->assertSame('\\'.User::class.'[]', $props['classStringList']);
        $this->assertSame('\\'.UserResource::class.' | null', $props['classStringNullable']);
    }

    public function test_typescript_and_class_strings_are_kept_as_written(): void
    {
        $props = $this->props['PhpDoc/Show'];

        $this->assertSame('{ id: number; name: string }', $props['ts']);
        $this->assertSame('Array<number>', $props['tsGeneric']);
        $this->assertSame('Record<string, number>', $props['tsRecord']);
        $this->assertSame("'a' | 'b'", $props['literal']);
        $this->assertSame("{ label: 'a}b' | \"it\\\"s\" | `x>`; run: () => void }", $props['tsQuotesAndArrow']);
        $this->assertSame(User::class, $props['model']);
        $this->assertSame('string | null', $props['nullable']);
    }

    public function test_an_invalid_or_mixed_type_is_unknown_with_a_warning(): void
    {
        $props = $this->props['PhpDoc/Broken'];

        $this->assertSame(['unbalanced' => 'unknown', 'empty' => 'unknown', 'callable' => 'unknown', 'mixed' => 'unknown', 'mismatched' => 'unknown'], $props);

        $origin = PhpDocPropController::class.'::broken() #[TypefinderPage] prop';
        $this->assertContains($origin.' "unbalanced": "array{id: int" is no valid type (unbalanced brackets or quotes) — emitted as unknown', $this->warnings);
        $this->assertContains($origin.' "empty": is empty — emitted as unknown', $this->warnings);
        $this->assertContains($origin.' "callable": "callable" has unsupported type callable — emitted as unknown', $this->warnings);
        $this->assertContains($origin.' "mixed": "array<string, number>" mixes PHPDoc and TypeScript syntax; write it in one of them — emitted as unknown', $this->warnings);
        $this->assertContains($origin.' "mismatched": "{ id: number ]" is no valid type (unbalanced brackets or quotes) — emitted as unknown', $this->warnings);
        $this->assertCount(5, $this->warnings);
    }

    public function test_a_class_whose_autoloader_throws_is_no_class(): void
    {
        $autoload = static function (string $class): void {
            if (str_ends_with($class, 'Broken\\Thing')) {
                throw new RuntimeException('cannot load '.$class);
            }
        };
        spl_autoload_register($autoload);

        try {
            $warnings = [];
            $pages = new ControllerExtractor(new GeneratedClasses([], [], []), function (string $warning) use (&$warnings): void {
                $warnings[] = $warning;
            })->extract(ThrowingAutoloadPropController::class);
        } finally {
            spl_autoload_unregister($autoload);
        }

        $this->assertSame(['things' => 'unknown'], $pages[0]['props']);
        $this->assertStringContainsString('mixes PHPDoc and TypeScript syntax', $warnings[0]);
    }

    public function test_converted_props_render_with_imports_and_resource_wrapping(): void
    {
        $output = new TypeScriptRenderer()->renderPages(
            [['component' => 'PhpDoc/Show', 'props' => $this->props['PhpDoc/Show'], 'optional' => [], 'source' => 'x']],
            [['name' => 'User', 'fqcn' => User::class]],
            [['name' => 'PostStatus', 'fqcn' => PostStatus::class]],
            [['name' => 'UserResource', 'fqcn' => UserResource::class]],
        );

        $this->assertStringContainsString('users: User[]', $output);
        $this->assertStringContainsString('statuses: PostStatus[]', $output);
        $this->assertStringContainsString('editor: WrappedResource<UserResource> | null', $output);
        $this->assertStringContainsString("import type { User } from './models';", $output);
    }
}
