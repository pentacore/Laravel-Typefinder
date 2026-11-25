<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Pentacore\Typefinder\Extractors\ResourceExtractor;
use Pentacore\Typefinder\Services\Generator;
use Tests\Fixtures\Resources\Literal\PlainResource;
use Tests\Fixtures\Resources\Literal\SpreadResource;
use Tests\TestCase;

use function Orchestra\Testbench\workbench_path;

/**
 * A resource with no attribute, PHPDoc shape or model match is typed from
 * the literal keys its own `toArray()` returns (#171).
 */
final class LiteralResourceShapeTest extends TestCase
{
    private string $output;

    protected function setUp(): void
    {
        parent::setUp();
        $this->output = sys_get_temp_dir().'/typefinder-literal-'.uniqid('', true);
        config([
            'typefinder.output_path' => $this->output,
            'typefinder.models.paths' => [workbench_path('app/Models')],
            'typefinder.enums.paths' => [workbench_path('app/Enums')],
            'typefinder.requests.paths' => [workbench_path('app/Http/Requests')],
            'typefinder.resources.paths' => [workbench_path('app/Http/Resources'), __DIR__.'/../Fixtures/Resources/Literal'],
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->output);
        @unlink(storage_path('framework/cache/typefinder/extractions.json'));
        parent::tearDown();
    }

    public function test_literal_keys_give_the_shape(): void
    {
        $this->assertSame(
            ['kind' => 'inferred', 'type' => ['kind' => 'object', 'fields' => [
                ['name' => 'id', 'type' => ['kind' => 'ts', 'ts' => 'unknown'], 'optional' => false],
                ['name' => 'posts', 'type' => ['kind' => 'ts', 'ts' => 'unknown'], 'optional' => true],
            ], 'open' => false]],
            new ResourceExtractor()->extract(PlainResource::class)['shape'] ?? null,
        );
    }

    public function test_keys_that_are_not_all_literal_give_no_shape(): void
    {
        $this->assertNull(new ResourceExtractor()->extract(SpreadResource::class));
    }

    public function test_emitted_types(): void
    {
        app(Generator::class)->generateFull();

        $this->assertSame(
            "export type PlainResource = {\n  id: unknown;\n  posts?: unknown;\n};\n",
            $this->body('PlainResource'),
        );
        $this->assertSame(
            "export type TwoReturnsResource = {\n  a: string | number;\n  b?: string;\n};\n",
            $this->body('TwoReturnsResource'),
        );
        $this->assertFileDoesNotExist($this->output.'/resources/SpreadResource.d.ts');

        $typed = (string) file_get_contents($this->output.'/resources/TypedLiteralResource.d.ts');
        $this->assertStringContainsString("import type { User } from '../models';\nimport type { UserResource } from './UserResource';\n\n", $typed);
        $this->assertStringContainsString(<<<'TS'
            export type TypedLiteralResource = {
              id: User['id'];
              name: User['name'];
              posts: unknown;
              label: string;
              count: number;
              flag: boolean;
              nothing: null;
              price: number;
              author: UserResource;
              friend: UserResource;
              friends: UserResource[];
              orphan: unknown;
              meta: { total: number; tag?: string };
              secret?: string;
              extra?: number;
            };
            TS, $typed);
    }

    private function body(string $name): string
    {
        $content = (string) file_get_contents($this->output.'/resources/'.$name.'.d.ts');

        return substr($content, (int) strpos($content, 'export type'));
    }
}
