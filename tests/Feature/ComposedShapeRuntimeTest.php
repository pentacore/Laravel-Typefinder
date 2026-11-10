<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\GeneratedClasses;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use stdClass;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\DisjointMergeResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\DisjointMergeUnlessResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\EmptyMergeWhenResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\HelperMergeThenLaterResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\LiteralThenMergeResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\MaybeMergeLastResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\MaybeMergeThenTailResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\MergeWhenBranchesResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\MergeWhenThenSpreadResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\OpenAfterHelperResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\OpenFirstResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\ParentMergeThenLaterResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\PlusMergeResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\SpreadOptionalResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\SpreadThenMergeResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\SpreadThenWhenResource;
use Tests\Fixtures\Resources\PhpDoc\Composition\Precision\UnlessResource;
use Tests\TestCase;

/**
 * Composition must never claim a shape the resource does not produce: each
 * fixture is resolved statically and run through Laravel's own
 * `JsonResource::resolve()` with the `flag` condition off and on.
 */
final class ComposedShapeRuntimeTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    private PhpDocShapeResolver $phpDocShapeResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->warnings = [];
        $this->phpDocShapeResolver = new PhpDocShapeResolver(
            new GeneratedClasses(models: [], enums: [], resources: []),
            function (string $message): void {
                $this->warnings[] = $message;
            },
        );
    }

    /**
     * Each with what the runtime does that a later-wins composition would
     * get wrong: [resource, flag, key, runtime value (null = absent)].
     *
     * @return array<string, array{class-string<JsonResource>, bool, string, mixed}>
     */
    public static function notComposed(): array
    {
        return [
            '1: literal then merge() of the same key' => [LiteralThenMergeResource::class, false, 'a', 'literal'],
            '2: spread then merge() of the same key' => [SpreadThenMergeResource::class, false, 'a', 1],
            '3: mergeWhen() then spread of the same key' => [MergeWhenThenSpreadResource::class, true, 'a', 'x'],
            '4: spread then when() of the same key' => [SpreadThenWhenResource::class, false, 'a', null],
            '5: spread then an optional helper key' => [SpreadOptionalResource::class, false, 'a', 1],
            '8: open helper after another helper' => [OpenAfterHelperResource::class, false, 'a', 'override'],
            'w2-1: undocumented helper with a merge, then a later key' => [HelperMergeThenLaterResource::class, false, 'b', 1],
            'w2-2: undocumented parent with a merge, then a later key' => [ParentMergeThenLaterResource::class, false, 'b', 1],
            'w2-3: plus with merges on both sides' => [PlusMergeResource::class, false, 'c', null],
            'w2-4: mergeWhen() with an empty default, then more keys' => [EmptyMergeWhenResource::class, false, 'b', null],
            'w2-5: merge of an all-optional helper, then more keys' => [MaybeMergeThenTailResource::class, false, 'b', null],
        ];
    }

    public function test_a_merge_of_an_all_optional_helper_as_the_last_part_composes(): void
    {
        $this->assertConsistent(MaybeMergeLastResource::class, $this->object([
            $this->field('a', $this->ts('number')),
            $this->field('m', $this->ts('number'), true),
        ]));
        $this->assertSame([], $this->warnings);
    }

    /**
     * @param  class-string<JsonResource>  $class
     */
    #[DataProvider('notComposed')]
    public function test_uncertain_merge_order_or_presence_is_not_composed(string $class, bool $flag, string $key, mixed $runtime): void
    {
        $this->assertNull($this->resolve($class));
        $this->assertSame([], $this->warnings);

        $output = $this->runtimeOutput($class, $flag);
        $this->assertSame($runtime, $output[$key] ?? null);
    }

    public function test_merge_when_branches_are_optional(): void
    {
        $this->assertConsistent(MergeWhenBranchesResource::class, $this->object([
            $this->field('id', $this->ts('unknown')),
            $this->field('secret', $this->ts('string'), true),
            $this->field('guest', $this->ts('unknown'), true),
        ]));
        $this->assertSame([
            MergeWhenBranchesResource::class.'::toArray() composes a shape but "id", "guest" have no documented type — add @return array{…} to toArray()',
        ], $this->warnings);
    }

    public function test_unless_without_a_default_is_optional(): void
    {
        $this->assertConsistent(UnlessResource::class, $this->object([
            $this->field('a', $this->ts('number')),
            $this->field('secret', $this->ts('unknown'), true),
        ]));
        $this->assertSame([
            UnlessResource::class.'::toArray() composes a shape but "secret" has no documented type — add @return array{…} to toArray()',
        ], $this->warnings);
    }

    public function test_open_helper_first_composes_open(): void
    {
        $this->assertConsistent(OpenFirstResource::class, $this->object([
            $this->field('v', $this->ts('number')),
            $this->field('x', $this->ts('unknown')),
        ], open: true));
    }

    public function test_merge_without_a_key_collision_composes(): void
    {
        $this->assertConsistent(DisjointMergeResource::class, $this->object([
            $this->field('a', $this->ts('number')),
            $this->field('b', $this->ts('string')),
        ]));
        $this->assertSame([], $this->warnings);
    }

    public function test_merge_unless_without_a_key_collision_composes_with_optional_keys(): void
    {
        $this->assertConsistent(DisjointMergeUnlessResource::class, $this->object([
            $this->field('a', $this->ts('number')),
            $this->field('b', $this->ts('string'), true),
        ]));
        $this->assertSame([], $this->warnings);
    }

    /**
     * The composed shape is $expected, and the runtime output with the flag
     * off and on has every required key and, unless open, no other key.
     *
     * @param  class-string<JsonResource>  $class
     * @param  array<string, mixed>  $expected
     */
    private function assertConsistent(string $class, array $expected): void
    {
        $this->assertSame($expected, $this->resolve($class));

        $declared = array_column($expected['fields'], 'name');
        $required = array_column(array_filter($expected['fields'], static fn (array $field): bool => ! $field['optional']), 'name');

        foreach ([false, true] as $flag) {
            $keys = array_map(strval(...), array_keys($this->runtimeOutput($class, $flag)));

            $this->assertSame([], array_values(array_diff($required, $keys)), "required key missing at runtime (flag: {$this->bool($flag)})");

            if (! $expected['open']) {
                $this->assertSame([], array_values(array_diff($keys, $declared)), "undeclared key at runtime (flag: {$this->bool($flag)})");
            }
        }
    }

    /**
     * @param  class-string<JsonResource>  $class
     * @return array<array-key, mixed>
     */
    private function runtimeOutput(string $class, bool $flag): array
    {
        return new $class(new stdClass)->resolve(Request::create('/', 'GET', $flag ? ['flag' => '1'] : []));
    }

    /**
     * @param  class-string  $class
     * @return ?array<string, mixed>
     */
    private function resolve(string $class): ?array
    {
        return $this->phpDocShapeResolver->resolve(new ReflectionClass($class));
    }

    private function bool(bool $value): string
    {
        return $value ? 'on' : 'off';
    }

    /** @return array{kind: 'ts', ts: string} */
    private function ts(string $ts): array
    {
        return ['kind' => 'ts', 'ts' => $ts];
    }

    /**
     * @param  array<string, mixed>  $type
     * @return array{name: string, type: array<string, mixed>, optional: bool}
     */
    private function field(string $name, array $type, bool $optional = false): array
    {
        return ['name' => $name, 'type' => $type, 'optional' => $optional];
    }

    /**
     * @param  list<array{name: string, type: array<string, mixed>, optional: bool}>  $fields
     * @return array{kind: 'object', fields: list<array{name: string, type: array<string, mixed>, optional: bool}>, open: bool}
     */
    private function object(array $fields, bool $open = false): array
    {
        return ['kind' => 'object', 'fields' => $fields, 'open' => $open];
    }
}
