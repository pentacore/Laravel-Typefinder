<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector\Support;

use PHPStan\PhpDocParser\Ast\Attribute;
use PHPStan\PhpDocParser\Ast\Node;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\ParserException;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;

/**
 * A doc comment as phpdoc-parser tokens, for edits that keep everything
 * outside the edited tokens byte for byte: the lexer is lossless, so the
 * tokens joined are the doc comment. A tag's type is parsed from the doc's
 * own tokens, so its nodes carry token indexes into {@see self::$tokens}
 * (also across the ` * ` of a multi-line type).
 */
final class DocTokens
{
    /** @param  list<array{0: string, 1: int, 2: int}>  $tokens */
    private function __construct(private array $tokens) {}

    public static function of(string $doc): self
    {
        return new self(new Lexer(self::config())->tokenize($doc));
    }

    /**
     * The type of every tag named in $tags (`@return`, `@typefinder-return`,
     * …) that parses as one, in source order. The start and end are token
     * indexes, inclusive.
     *
     * @param  list<string>  $tags
     * @return list<array{tag: string, type: TypeNode, start: int, end: int}>
     */
    public function typeTags(array $tags): array
    {
        $found = [];

        foreach ($this->tokens as $index => [$value, $type]) {
            if ($type !== Lexer::TOKEN_PHPDOC_TAG || ! in_array(strtolower($value), $tags, true)) {
                continue;
            }

            $typeNode = $this->parseTypeAt($index + 1);

            if ($typeNode instanceof TypeNode) {
                $found[] = [
                    'tag' => strtolower($value),
                    'type' => $typeNode,
                    'start' => self::startOf($typeNode),
                    'end' => self::endOf($typeNode),
                ];
            }
        }

        return $found;
    }

    /** The doc comment's text between two token indexes, inclusive. */
    public function between(int $start, int $end): string
    {
        return implode('', array_column(array_slice($this->tokens, $start, $end - $start + 1), 0));
    }

    /**
     * The doc comment with the tokens of each range (inclusive) replaced by
     * its text. Ranges must not overlap; an empty range (`end` = `start` -
     * 1) inserts before `start`.
     *
     * @param  list<array{start: int, end: int, text: string}>  $edits
     */
    public function apply(array $edits): string
    {
        usort($edits, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);
        $tokens = $this->tokens;

        foreach ($edits as ['start' => $start, 'end' => $end, 'text' => $text]) {
            array_splice($tokens, $start, $end - $start + 1, [[$text, Lexer::TOKEN_OTHER, 0]]);
        }

        return implode('', array_column($tokens, 0));
    }

    public function text(): string
    {
        return implode('', array_column($this->tokens, 0));
    }

    /** @return array{0: string, 1: int, 2: int} */
    public function token(int $index): array
    {
        return $this->tokens[$index];
    }

    public static function startOf(Node $node): int
    {
        $start = $node->getAttribute(Attribute::START_INDEX);

        return is_int($start) ? $start : -1;
    }

    public static function endOf(Node $node): int
    {
        $end = $node->getAttribute(Attribute::END_INDEX);

        return is_int($end) ? $end : -1;
    }

    private function parseTypeAt(int $index): ?TypeNode
    {
        $config = self::config();
        $iterator = new TokenIterator($this->tokens, $index);

        try {
            return new TypeParser($config, new ConstExprParser($config))->parse($iterator);
        } catch (ParserException) {
            return null;
        }
    }

    private static function config(): ParserConfig
    {
        return new ParserConfig(['indexes' => true, 'lines' => true]);
    }
}
