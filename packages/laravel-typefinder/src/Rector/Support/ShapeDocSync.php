<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector\Support;

use Closure;
use Pentacore\Typefinder\Analysis\DriftComparer;
use Pentacore\Typefinder\Analysis\ReturnedArray;
use Pentacore\Typefinder\Analysis\ReturnedPart;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;

/**
 * Brings the `array{…}` a doc comment declares in line with the literal keys
 * the code returns, by the rules of the drift check
 * ({@see DriftComparer::compare()}), so a synced doc reports no drift:
 *  - a key the code returns but the shape lacks is added (`mixed` unless
 *    its type is guessed), optional when it is conditional or not always
 *    returned;
 *  - a required key the code never returns is removed;
 *  - a required key the code does not always return, or only returns
 *    conditionally, is made optional.
 * Only the edited tokens change; layout, comments and the other tags stay
 * as written. A list shape, or a doc whose return tag is not a shape, is
 * left alone.
 */
final readonly class ShapeDocSync
{
    /** Return tags in precedence order, as Typefinder reads them. */
    public const array RETURN_TAGS = ['@typefinder-return', '@phpstan-return', '@psalm-return', '@return'];

    /** @param  ValueTypeGuesser  $guesser  types an added key from its value */
    public function __construct(private ValueTypeGuesser $guesser) {}

    /**
     * The doc with its shape synced, or null when it has no shape or the
     * shape is already in line.
     *
     * @param  list<ReturnedArray>  $returns
     * @param  list<string>  $shared  keys supplied from elsewhere, never removed
     */
    public function sync(string $doc, array $returns, array $shared = []): ?string
    {
        $shape = $this->shape(DocTokens::of($doc));

        if ($shape === null || $returns === []) {
            return null;
        }

        $findings = $this->findings($shape['type'], $returns, $shared);

        if ($findings === []) {
            return null;
        }

        $synced = $this->removeAndMarkOptional($doc, $findings);
        $synced = $this->append($synced, $findings['undeclared'] ?? [], $returns);

        return $synced === $doc ? null : $synced;
    }

    /**
     * A new `@typefinder-return array{…}` of every key the code returns, for
     * a doc without a shape. Null when the code returns no literal key.
     *
     * @param  list<ReturnedArray>  $returns
     */
    public function shapeTag(array $returns, string $indent): ?string
    {
        $items = $this->items($this->codeKeys($returns), $returns);

        if ($items === []) {
            return null;
        }

        $lines = [ShapeDocSync::RETURN_TAGS[0].' array{'];

        foreach ($items as $item) {
            $lines[] = '    '.$item.',';
        }

        $lines[] = '}';

        return implode("\n".$indent.'* ', $lines);
    }

    /**
     * The shape of the first return tag, in precedence order, that has one.
     *
     * @return ?array{tokens: DocTokens, type: ArrayShapeNode}
     */
    private function shape(DocTokens $tokens): ?array
    {
        $tags = $tokens->typeTags(self::RETURN_TAGS);

        foreach (self::RETURN_TAGS as $name) {
            foreach ($tags as $tag) {
                if ($tag['tag'] !== $name) {
                    continue;
                }

                $type = $tag['type'];

                return $type instanceof ArrayShapeNode && $type->kind === ArrayShapeNode::KIND_ARRAY && ! $this->isList($type)
                    ? ['tokens' => $tokens, 'type' => $type]
                    : null;
            }
        }

        return null;
    }

    /**
     * @param  list<ReturnedArray>  $returns
     * @param  list<string>  $shared
     * @return array<'undeclared'|'never'|'notAlways'|'conditional', list<string>>
     */
    private function findings(ArrayShapeNode $type, array $returns, array $shared): array
    {
        $declared = [];

        foreach ($type->items as $item) {
            $declared[] = ['key' => (string) $this->keyOf($item), 'optional' => $item->optional];
        }

        $encode = static fn (string $kind): Closure => static fn (string $key): string => $kind."\0".$key;
        $raw = new DriftComparer()->compare($declared, ! $type->sealed, $returns, [
            'undeclared' => $encode('undeclared'),
            'never' => $encode('never'),
            'notAlways' => $encode('notAlways'),
            'conditional' => $encode('conditional'),
        ], $shared);

        $findings = [];

        foreach ($raw as $finding) {
            [$kind, $key] = explode("\0", $finding, 2);
            $findings[$kind][] = $key;
        }

        /** @var array<'undeclared'|'never'|'notAlways'|'conditional', list<string>> $findings */
        return $findings;
    }

    /** @param  array<string, list<string>>  $findings */
    private function removeAndMarkOptional(string $doc, array $findings): string
    {
        $remove = $findings['never'] ?? [];
        $optional = [...$findings['notAlways'] ?? [], ...$findings['conditional'] ?? []];

        if ($remove === [] && $optional === []) {
            return $doc;
        }

        $shape = $this->shape(DocTokens::of($doc));

        if ($shape === null) {
            return $doc;
        }

        ['tokens' => $tokens, 'type' => $type] = $shape;
        $items = array_values($type->items);
        $edits = [];

        foreach ($items as $position => $item) {
            $key = (string) $this->keyOf($item);

            if (in_array($key, $remove, true)) {
                $next = $items[$position + 1] ?? null;
                $previous = $this->keptBefore($items, $position, $remove);

                if ($next instanceof ArrayShapeItemNode) {
                    $edits[] = ['start' => DocTokens::startOf($item), 'end' => DocTokens::startOf($next) - 1, 'text' => ''];
                } elseif ($previous instanceof ArrayShapeItemNode) {
                    $edits[] = ['start' => DocTokens::endOf($previous) + 1, 'end' => DocTokens::endOf($item), 'text' => ''];
                } else {
                    $edits[] = ['start' => DocTokens::startOf($item), 'end' => DocTokens::endOf($item), 'text' => ''];
                }

                continue;
            }

            if (in_array($key, $optional, true) && ! $item->optional && $item->keyName !== null) {
                $end = DocTokens::endOf($item->keyName);
                $edits[] = ['start' => $end, 'end' => $end, 'text' => $tokens->between($end, $end).'?'];
            }
        }

        return $tokens->apply($this->withoutOverlaps($edits));
    }

    /**
     * @param  list<string>  $keys
     * @param  list<ReturnedArray>  $returns
     */
    private function append(string $doc, array $keys, array $returns): string
    {
        if ($keys === []) {
            return $doc;
        }

        $shape = $this->shape(DocTokens::of($doc));

        if ($shape === null) {
            return $doc;
        }

        ['tokens' => $tokens, 'type' => $type] = $shape;
        $codeKeys = array_intersect_key($this->codeKeys($returns), array_flip($keys));
        $added = $this->items($codeKeys, $returns);
        $items = array_values($type->items);
        $start = DocTokens::startOf($type);
        $end = DocTokens::endOf($type);

        if ($items === []) {
            // `array{}`: the items go between the braces.
            return $tokens->apply([['start' => $end, 'end' => $end - 1, 'text' => implode(', ', $added)]]);
        }

        $last = $items[count($items) - 1];
        $firstStart = DocTokens::startOf($items[0]);
        $separator = count($items) > 1
            ? $this->separator($tokens->between(DocTokens::endOf($items[0]) + 1, DocTokens::startOf($items[1]) - 1))
            : $this->separatorAfterBrace($tokens->between($start + 1, $firstStart - 1));
        $insert = DocTokens::endOf($last) + 1;

        return $tokens->apply([['start' => $insert, 'end' => $insert - 1, 'text' => $separator.implode($separator, $added)]]);
    }

    /**
     * `key: type` for each key, in code order.
     *
     * @param  array<string, bool>  $keys  key => optional
     * @param  list<ReturnedArray>  $returns
     * @return list<string>
     */
    private function items(array $keys, array $returns): array
    {
        $items = [];

        foreach ($keys as $key => $optional) {
            $items[] = $this->keyText((string) $key).($optional ? '?' : '').': '.$this->typeOf((string) $key, $returns)->toPhpDoc();
        }

        return $items;
    }

    /** @param  list<ReturnedArray>  $returns */
    private function typeOf(string $key, array $returns): ValueType
    {
        $type = null;

        foreach ($returns as $returned) {
            foreach ($returned->parts as $part) {
                if ($part->kind !== ReturnedPart::ITEM || $part->key !== $key || $part->value === null) {
                    continue;
                }

                $guess = $this->guesser->guess($part->value);

                if ($type instanceof ValueType && ! $type->equals($guess)) {
                    return ValueType::unknown();
                }

                $type = $guess;
            }
        }

        return $type ?? ValueType::unknown();
    }

    /**
     * Each key any return produces, with whether it may be absent: it is
     * conditional, or some return lacks it.
     *
     * @param  list<ReturnedArray>  $returns
     * @return array<string, bool>
     */
    private function codeKeys(array $returns): array
    {
        $optional = [];

        foreach ($returns as $returned) {
            foreach ($returned->keys() as ['key' => $key, 'conditional' => $conditional]) {
                $optional[$key] = ($optional[$key] ?? false) || $conditional;
            }
        }

        foreach (array_keys($optional) as $key) {
            $everywhere = array_all($returns, static fn (ReturnedArray $returned): bool => in_array((string) $key, array_column($returned->keys(), 'key'), true));
            $optional[$key] = $optional[$key] || ! $everywhere;
        }

        return $optional;
    }

    /** The separator between two items: the comma and what follows it up to the next item. */
    private function separator(string $between): string
    {
        return str_contains($between, ',') ? $between : ', ';
    }

    /** The separator for a shape with one item: multi-line when the item starts on a new line. */
    private function separatorAfterBrace(string $afterBrace): string
    {
        return str_contains($afterBrace, "\n") ? ','.$afterBrace : ', ';
    }

    private function keyText(string $key): string
    {
        return preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/', $key) === 1 || preg_match('/^(0|[1-9]\d*)$/', $key) === 1
            ? $key
            : "'".addcslashes($key, "'\\")."'";
    }

    private function keyOf(ArrayShapeItemNode $item): ?string
    {
        return match (true) {
            $item->keyName instanceof IdentifierTypeNode => $item->keyName->name,
            $item->keyName instanceof ConstExprStringNode => $item->keyName->value,
            $item->keyName instanceof ConstExprIntegerNode => $item->keyName->value,
            default => null,
        };
    }

    private function isList(ArrayShapeNode $type): bool
    {
        return $type->items !== [] && array_all($type->items, static fn (ArrayShapeItemNode $item): bool => $item->keyName === null);
    }

    /**
     * The nearest item before $position that is kept.
     *
     * @param  list<ArrayShapeItemNode>  $items
     * @param  list<string>  $remove
     */
    private function keptBefore(array $items, int $position, array $remove): ?ArrayShapeItemNode
    {
        for ($index = $position - 1; $index >= 0; $index--) {
            if (! in_array((string) $this->keyOf($items[$index]), $remove, true)) {
                return $items[$index];
            }
        }

        return null;
    }

    /**
     * Removing consecutive items yields touching ranges; merge any that
     * overlap so no token is spliced twice.
     *
     * @param  list<array{start: int, end: int, text: string}>  $edits
     * @return list<array{start: int, end: int, text: string}>
     */
    private function withoutOverlaps(array $edits): array
    {
        usort($edits, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $merged = [];

        foreach ($edits as $edit) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];

            if ($last !== null && $edit['start'] <= $last['end'] && $last['text'] === '' && $edit['text'] === '') {
                $merged[count($merged) - 1]['end'] = max($last['end'], $edit['end']);

                continue;
            }

            $merged[] = $edit;
        }

        return $merged;
    }
}
