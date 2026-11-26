<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Rector\Support;

/**
 * What a value serialises to, as far as a rule can tell without running it:
 * a scalar, a generated class (an Eloquent model or a backed enum), a list
 * of either, or unknown. Rules print it as a TypeScript string (or a
 * class-string expression) or as a PHPDoc type.
 */
final readonly class ValueType
{
    public const string UNKNOWN = 'unknown';

    public const string SCALAR = 'scalar';

    public const string CLASS_NAME = 'class';

    /**
     * @param  self::UNKNOWN|self::SCALAR|self::CLASS_NAME  $kind
     * @param  string  $name  the TypeScript scalar (`string`, `number`, `boolean`, `null`) or the class
     */
    private function __construct(
        public string $kind,
        public string $name = '',
        public bool $list = false,
        public bool $nullable = false,
    ) {}

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    /** @param  'string'|'number'|'boolean'|'null'  $ts */
    public static function scalar(string $ts): self
    {
        return new self(self::SCALAR, $ts);
    }

    /** @param  class-string  $class */
    public static function ofClass(string $class): self
    {
        return new self(self::CLASS_NAME, ltrim($class, '\\'));
    }

    public function asList(): self
    {
        return $this->kind === self::UNKNOWN ? $this : new self($this->kind, $this->name, true, $this->nullable);
    }

    public function orNull(): self
    {
        return $this->kind === self::UNKNOWN || ($this->kind === self::SCALAR && $this->name === 'null')
            ? $this
            : new self($this->kind, $this->name, $this->list, true);
    }

    public function isUnknown(): bool
    {
        return $this->kind === self::UNKNOWN;
    }

    public function equals(self $other): bool
    {
        return $this == $other;
    }

    /**
     * As a PHPDoc type. Classes are written fully qualified, so the doc
     * needs no import; anything but a scalar is `mixed`.
     */
    public function toPhpDoc(): string
    {
        if ($this->kind === self::UNKNOWN) {
            return 'mixed';
        }

        $base = $this->kind === self::CLASS_NAME
            ? '\\'.$this->name
            : ['string' => 'string', 'number' => 'int|float', 'boolean' => 'bool', 'null' => 'null'][$this->name] ?? 'mixed';

        if ($this->list) {
            $base = 'list<'.$base.'>';
        }

        return $this->nullable ? $base.'|null' : $base;
    }
}
