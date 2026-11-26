<?php

declare(strict_types=1);

namespace Tests\Rector;

use Pentacore\Typefinder\Rector\AddTypefinderPageRector;
use Pentacore\Typefinder\Rector\MigrateLegacyTypefinderApiRector;
use Pentacore\Typefinder\Rector\QualifyTypefinderReturnRector;
use Pentacore\Typefinder\Rector\SyncResourceShapeRector;
use Pentacore\Typefinder\Rector\SyncSharedPropsShapeRector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rector\Rector\AbstractRector;

/** Every rule documents itself with a before/after sample (`rector list-rules`, rule docs). */
final class RuleDefinitionTest extends TestCase
{
    /** @param  class-string<AbstractRector>  $rule */
    #[DataProvider('rules')]
    public function test_the_rule_has_a_description_and_a_code_sample(string $rule): void
    {
        $definition = new \ReflectionClass($rule)->newInstanceWithoutConstructor()->getRuleDefinition();

        $this->assertNotSame('', $definition->getDescription());
        $this->assertCount(1, $definition->getCodeSamples());
        $this->assertNotSame($definition->getCodeSamples()[0]->getBadCode(), $definition->getCodeSamples()[0]->getGoodCode());
    }

    /** @return iterable<array{class-string<AbstractRector>}> */
    public static function rules(): iterable
    {
        yield [QualifyTypefinderReturnRector::class];
        yield [SyncResourceShapeRector::class];
        yield [MigrateLegacyTypefinderApiRector::class];
        yield [AddTypefinderPageRector::class];
        yield [SyncSharedPropsShapeRector::class];
    }
}
