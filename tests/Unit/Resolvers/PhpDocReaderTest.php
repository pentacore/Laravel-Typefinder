<?php

declare(strict_types=1);

namespace Tests\Unit\Resolvers;

use Pentacore\Typefinder\Resolvers\PhpDocReader;
use Pentacore\Typefinder\Resolvers\PhpDocScope;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Tests\TestCase;

/**
 * The doc-reading half of the PHPDoc shape resolver on its own (#204).
 */
final class PhpDocReaderTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    private function reader(): PhpDocReader
    {
        return new PhpDocReader(function (string $message): void {
            $this->warnings[] = $message;
        });
    }

    public function test_return_tag_precedence(): void
    {
        $reader = $this->reader();
        $doc = $reader->parseDoc("/**\n * @return array<string, mixed>\n * @phpstan-return array{a: int}\n */");

        $this->assertTrue($reader->hasReturnTag($doc));
        $this->assertSame('array{a: int}', (string) $reader->returnType($doc));
        $this->assertFalse($reader->hasReturnTag($reader->parseDoc('/** {@inheritDoc} */')));
        $this->assertNull($reader->parseDoc(null));
        $this->assertNull($reader->parseDoc('not a doc comment'));
    }

    public function test_shape_of_accepts_a_keyed_shape_with_laravel_return_members_only(): void
    {
        $reader = $this->reader();
        $scope = new PhpDocScope('X', 'X', static fn (string $name): string => $name);
        $type = static fn (string $text): ?TypeNode => $reader->returnType($reader->parseDoc("/** @return {$text} */"));

        $this->assertInstanceOf(ArrayShapeNode::class, $reader->shapeOf($type('array{a: int}|array'), $scope)[0] ?? null);
        $this->assertNull($reader->shapeOf($type('array{int, string}'), $scope));
        $this->assertNull($reader->shapeOf($type('array{a: int}|string'), $scope));
        $this->assertNull($reader->shapeOf($type('array{a: int}|list<int>'), $scope));
    }

    public function test_unparsable_shape_tag_warns(): void
    {
        $reader = $this->reader();
        $doc = $reader->parseDoc('/** @return array{a: */');

        $reader->warnUnparsable($doc, 'App\\R', PhpDocReader::RETURN_TAGS);

        $this->assertCount(1, $this->warnings);
        $this->assertStringStartsWith('Could not parse @return on App\\R::toArray()', $this->warnings[0]);
    }
}
