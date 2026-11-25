<?php

declare(strict_types=1);

namespace Tests\Feature;

use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Renderers\TypeScriptRenderer;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\Resolvers\MorphToResolver;
use Tests\Fixtures\Morph\Activity;
use Tests\Fixtures\Morph\Doc;
use Tests\Fixtures\Morph\Member;
use Tests\TestCase;

/** A model with two morphTo relations gets one generic per relation (#139, #199). */
final class MultipleMorphToTest extends TestCase
{
    public function test_each_morph_to_is_matched_on_its_own_morph_name(): void
    {
        $models = $this->resolved();
        $relations = collect($models[0]['relationships'])->keyBy('name');

        $this->assertSame([Doc::class], $relations['subject']['morphTargets']);
        $this->assertSame([Member::class], $relations['causer']['morphTargets']);
    }

    public function test_each_morph_to_gets_its_own_generic(): void
    {
        $models = $this->resolved();
        $output = new TypeScriptRenderer()->renderModel($models[0], [], $models);

        $this->assertStringContainsString('export type Activity<TSubject extends Doc = Doc, TCauser extends Member = Member> = {', $output);
        $this->assertStringContainsString('  subject?: TSubject | null;', $output);
        $this->assertStringContainsString('  causer?: TCauser | null;', $output);
        $this->assertStringContainsString("import type { Doc } from './Doc';", $output);
        $this->assertStringContainsString("import type { Member } from './Member';", $output);
    }

    public function test_a_single_morph_to_keeps_the_generic_t(): void
    {
        $model = [
            'name' => 'Note',
            'fqcn' => 'App\\Models\\Note',
            'columns' => [],
            'relationships' => [
                ['name' => 'notable', 'type' => 'morphTo', 'related' => 'App\\Models\\Note', 'relationType' => 'MorphTo', 'morphTargets' => [Doc::class]],
            ],
        ];

        $output = new TypeScriptRenderer()->renderModel($model, [], [$model]);

        $this->assertStringContainsString('export type Note<T extends Doc = Doc> = {', $output);
        $this->assertStringContainsString('  notable?: T | null;', $output);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolved(): array
    {
        $modelExtractor = new ModelExtractor(new ColumnTypeResolver, new CastTypeResolver);

        return new MorphToResolver()->resolve(array_map(
            $modelExtractor->extract(...),
            [Activity::class, Doc::class, Member::class],
        ));
    }
}
