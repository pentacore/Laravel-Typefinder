<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PostStatus;
use App\Models\User;
use Pentacore\Typefinder\Analysis\InertiaSharedProps;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * `pages.d.ts` declares `SharedPageProps`, the props the app's Inertia
 * middleware shares with every page (#173).
 */
final class SharedPagePropsTest extends TestCase
{
    private const array UNKNOWN = ['kind' => 'ts', 'ts' => 'unknown'];

    public function test_undocumented_share_keys_are_unknown(): void
    {
        $this->assertSame(
            ['kind' => 'object', 'fields' => [
                ['name' => 'errors', 'type' => ['kind' => 'ts', 'ts' => 'Record<string, string>'], 'optional' => false],
                ['name' => 'auth', 'type' => self::UNKNOWN, 'optional' => false],
                ['name' => 'flash', 'type' => self::UNKNOWN, 'optional' => false],
            ], 'open' => false],
            InertiaSharedProps::shape(workbench_path('app/Http/Middleware')),
        );
    }

    public function test_a_doc_without_a_shape_leaves_keys_unknown(): void
    {
        $fields = InertiaSharedProps::shape(__DIR__.'/../Fixtures/Middleware/Undocumented')['fields'];

        $this->assertSame(['errors', 'locale', 'theme'], array_column($fields, 'name'));
        $this->assertSame([self::UNKNOWN, self::UNKNOWN], array_column(array_slice($fields, 1), 'type'));
    }

    public function test_no_middleware_shares_only_errors(): void
    {
        $this->assertSame(['errors'], array_column(InertiaSharedProps::shape(__DIR__.'/missing')['fields'], 'name'));
    }

    public function test_renders_the_documented_shared_props(): void
    {
        $models = [['name' => 'User', 'fqcn' => User::class]];
        $shape = InertiaSharedProps::shape(
            __DIR__.'/../Fixtures/Middleware/Documented',
            null,
            new GeneratedClasses([User::class], [], []),
        );

        $output = new TypeScriptRenderer()->renderPages(
            [['component' => 'Home', 'props' => ['title' => 'string'], 'optional' => []]],
            $models,
            [],
            [],
            $shape,
        );

        $this->assertStringContainsString("import type { User } from './models';", $output);
        $this->assertStringContainsString(<<<'TS'
            export type SharedPageProps = {
              errors: Record<string, string>;
              auth: { user: User | null };
              flash: { message: string | null };
              ziggy?: Record<string, unknown>;
              undocumented: unknown;
              version: string;
            };

            export type PageProps = {
              'Home': { title: string };
            };
            TS, $output);
    }

    /** #237: a Typefinder-only return tag wins; #236: narrowed keys are kept. */
    public function test_typefinder_return_declares_the_serialised_props(): void
    {
        $enums = [['name' => 'PostStatus', 'fqcn' => PostStatus::class]];
        $shape = InertiaSharedProps::shape(
            __DIR__.'/../Fixtures/Middleware/TypefinderReturn',
            null,
            new GeneratedClasses([], [PostStatus::class], []),
        );

        $output = new TypeScriptRenderer()->renderPages([], [], $enums, [], $shape);

        $this->assertStringContainsString("import type { PostStatus } from './enums';", $output);
        $this->assertStringContainsString(<<<'TS'
            export type SharedPageProps = {
              errors: Record<string, string>;
              name: string;
              nav: { unread: number };
              can: Partial<Record<PostStatus, boolean>>;
              modes: Partial<Record<'light' | 'dark', string>>;
            };
            TS, $output);
    }
}
