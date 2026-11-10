<?php

declare(strict_types=1);

namespace Tests\Unit\Analysis;

use Pentacore\Typefinder\Analysis\QueryRuleKeys;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use stdClass;
use Tests\Fixtures\Requests\Issue101QueryRequest;

final class QueryRuleKeysTest extends TestCase
{
    public function test_finds_the_tagged_keys(): void
    {
        $this->assertSame(
            ['keys' => ['scope'], 'unreadable' => 0],
            QueryRuleKeys::of(new ReflectionClass(Issue101QueryRequest::class)),
        );
    }

    public function test_class_without_a_rules_method_has_no_tagged_keys(): void
    {
        $this->assertSame(
            ['keys' => [], 'unreadable' => 0],
            QueryRuleKeys::of(new ReflectionClass(stdClass::class)),
        );
    }

    public function test_rules_method_without_a_source_file_has_no_tagged_keys(): void
    {
        // Declared by eval(), so there is no file to read the tags from.
        $class = 'QueryRuleKeysEvalRequest'.bin2hex(random_bytes(4));
        eval(sprintf("final class %s { public function rules(): array { return [/** @query */ 'page' => ['integer']]; } }", $class));

        $this->assertSame(
            ['keys' => [], 'unreadable' => 0],
            QueryRuleKeys::of(new ReflectionClass($class)),
        );
    }
}
