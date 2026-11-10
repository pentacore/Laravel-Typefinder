<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

/**
 * The decomposition of one returned array expression into ordered parts.
 */
final readonly class ReturnedArray
{
    /**
     * @param  list<ReturnedPart>  $parts  in source order
     * @param  bool  $plus  the expression joins arrays with `+` (the items of a
     *                      literal operand carry no `plus` combine)
     */
    public function __construct(public array $parts, public bool $plus = false) {}

    /** Whether arrays are joined with `+`: flagged, or any `plus` part. */
    public function usesPlus(): bool
    {
        return $this->plus || array_any($this->parts, fn (ReturnedPart $part): bool => $part->combine === 'plus');
    }

    /**
     * True when the array may hold keys that are not listed: any `dynamic`
     * or `expr` part.
     */
    public function isOpen(): bool
    {
        return array_any($this->parts, fn (ReturnedPart $part): bool => $part->kind !== ReturnedPart::ITEM);
    }

    /**
     * Literal keys in order of first appearance, each with whether it is
     * conditional. A key seen both conditionally and unconditionally is
     * unconditional. Keys stay strings (`'0'`, `'123'` are not coerced).
     *
     * @return list<array{key: string, conditional: bool}>
     */
    public function keys(): array
    {
        /** @var array<string, int> $index key => position in $keys */
        $index = [];
        $keys = [];

        foreach ($this->parts as $part) {
            if ($part->kind !== ReturnedPart::ITEM || $part->key === null) {
                continue;
            }

            if (! array_key_exists($part->key, $index)) {
                $index[$part->key] = count($keys);
                $keys[] = ['key' => $part->key, 'conditional' => $part->conditional];

                continue;
            }

            $position = $index[$part->key];
            $keys[$position]['conditional'] = $keys[$position]['conditional'] && $part->conditional;
        }

        return $keys;
    }
}
