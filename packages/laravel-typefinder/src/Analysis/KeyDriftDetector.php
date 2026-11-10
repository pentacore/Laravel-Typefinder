<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Closure;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use Pentacore\Typefinder\Support\GeneratedClasses;
use Pentacore\Typefinder\Support\ParsedSource;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionClass;
use Throwable;

/**
 * Compares the literal keys code produces with the keys a declaration
 * promises, key names only: a resource's `toArray()` against its
 * `#[TypefinderResource] shape` or PHPDoc `@return` shape.
 *
 * Precision over recall: a key is reported missing only when every return is
 * closed (fully literal), an undeclared key only when it is a literal key, and
 * a key as conditional only when it is conditional everywhere it appears.
 * Unreadable or unparsable source reports nothing.
 */
final readonly class KeyDriftDetector
{
    private const string ATTRIBUTE_SHAPE = '#[TypefinderResource] shape';

    private const string PHPDOC_SHAPE = 'PHPDoc @return shape';

    private PhpDocShapeResolver $phpDocShapes;

    private ReturnedArrayReader $reader;

    /**
     * @param  ?PhpDocShapeResolver  $phpDocShapes  Reads the PHPDoc shape of a
     *                                              resource that also has an attribute shape; build it without
     *                                              `onWarn` so its warnings are not repeated.
     */
    public function __construct(?PhpDocShapeResolver $phpDocShapes = null, ?ReturnedArrayReader $reader = null)
    {
        $this->phpDocShapes = $phpDocShapes ?? new PhpDocShapeResolver(new GeneratedClasses);
        $this->reader = $reader ?? new ReturnedArrayReader;
    }

    /**
     * Drift between a resource extraction's declared keys and the literal keys
     * its own `toArray()` returns. Model-typed resources (attribute `model:`
     * or the name convention) are not checked.
     *
     * @param  array{name: string, fqcn: class-string, shape: array<string, mixed>}  $extraction
     * @return list<string>
     */
    public function checkResource(array $extraction): array
    {
        $fqcn = ltrim($extraction['fqcn'], '\\');
        $shape = $extraction['shape'];

        if ($shape['kind'] === 'shape') {
            /** @var array<array-key, string> $fields */
            $fields = $shape['fields'];
            $declared = array_map(
                static fn (int|string $key): array => ['key' => (string) $key, 'optional' => false],
                array_keys($fields),
            );

            return $this->unique([
                ...$this->shapeDisagreement($fqcn, array_column($declared, 'key')),
                ...$this->resourceDrift($fqcn, $declared, false, self::ATTRIBUTE_SHAPE),
            ]);
        }

        if ($shape['kind'] === 'phpdoc') {
            /** @var array{fields: list<array{name: string, optional: bool}>, open?: bool} $type */
            $type = $shape['type'];
            $declared = array_map(
                static fn (array $field): array => ['key' => (string) $field['name'], 'optional' => $field['optional']],
                $type['fields'],
            );

            return $this->unique($this->resourceDrift($fqcn, $declared, $type['open'] ?? false, self::PHPDOC_SHAPE));
        }

        return [];
    }

    /**
     * @param  list<array{key: string, optional: bool}>  $declared
     * @return list<string>
     */
    private function resourceDrift(string $fqcn, array $declared, bool $declaredOpen, string $src): array
    {
        $method = $this->methodNode($fqcn, 'toArray');

        if (! $method instanceof ClassMethod) {
            return [];
        }

        return $this->compare($declared, $declaredOpen, $this->reader->returns($method), [
            'undeclared' => static fn (string $key): string => sprintf('%s::toArray() returns key "%s" not declared in %s', $fqcn, $key, $src),
            'never' => static fn (string $key): string => sprintf('%s::toArray(): %s declares "%s" but toArray() never returns it', $fqcn, $src, $key),
            'notAlways' => static fn (string $key): string => sprintf('%s::toArray(): %s declares "%s" as required but toArray() does not always return it (make it optional?)', $fqcn, $src, $key),
            'conditional' => static fn (string $key): string => sprintf('%s::toArray(): "%s" is conditional in code but required in %s', $fqcn, $key, $src),
        ]);
    }

    /**
     * A resource with an attribute shape whose `toArray()` also documents a
     * PHPDoc shape: the two key sets should agree.
     *
     * @param  list<string>  $attributeKeys
     * @return list<string>
     */
    private function shapeDisagreement(string $fqcn, array $attributeKeys): array
    {
        try {
            $type = $this->phpDocShapes->resolve(new ReflectionClass($fqcn));
        } catch (Throwable) {
            return [];
        }

        if ($type === null) {
            return [];
        }

        /** @var list<array{name: string}> $fields */
        $fields = $type['fields'];
        $docKeys = array_map(static fn (array $field): string => (string) $field['name'], $fields);

        $clauses = [];
        $onlyInAttribute = array_values(array_diff($attributeKeys, $docKeys));
        $onlyInDoc = array_values(array_diff($docKeys, $attributeKeys));

        if ($onlyInAttribute !== []) {
            $clauses[] = 'only in attribute: {'.implode(', ', $onlyInAttribute).'}';
        }

        if ($onlyInDoc !== []) {
            $clauses[] = 'only in PHPDoc: {'.implode(', ', $onlyInDoc).'}';
        }

        if ($clauses === []) {
            return [];
        }

        return [sprintf('%s: %s and %s disagree (%s)', $fqcn, self::ATTRIBUTE_SHAPE, self::PHPDOC_SHAPE, implode('; ', $clauses))];
    }

    /**
     * The shared rules. Undeclared literal keys (in order of first
     * appearance) come first, then one finding per required declared key (in
     * declaration order):
     *  - absent from every return, all closed → `never`;
     *  - absent from some return, all closed → `notAlways`;
     *  - otherwise, conditional in every return that has it → `conditional`.
     * A key conditional in one return and unconditional in another is not
     * reported: some path returns it unconditionally, so the code alone does
     * not prove the declaration wrong.
     *
     * @param  list<array{key: string, optional: bool}>  $declared
     * @param  list<ReturnedArray>  $returns
     * @param  array{undeclared: Closure(string): string, never: Closure(string): string, notAlways: Closure(string): string, conditional: Closure(string): string}  $messages
     * @return list<string>
     */
    private function compare(array $declared, bool $declaredOpen, array $returns, array $messages): array
    {
        if ($returns === []) {
            return [];
        }

        $declaredKeys = array_column($declared, 'key');
        $allClosed = array_all($returns, static fn (ReturnedArray $returned): bool => ! $returned->isOpen());

        /** @var array<string, list<bool>> $seen key => conditional flag per return that has it */
        $seen = [];

        foreach ($returns as $returned) {
            foreach ($returned->keys() as ['key' => $key, 'conditional' => $conditional]) {
                $seen[$key][] = $conditional;
            }
        }

        $warnings = [];

        if (! $declaredOpen) {
            foreach (array_keys($seen) as $key) {
                if (! in_array((string) $key, $declaredKeys, true)) {
                    $warnings[] = $messages['undeclared']((string) $key);
                }
            }
        }

        foreach ($declared as ['key' => $key, 'optional' => $optional]) {
            if ($optional) {
                continue;
            }

            $flags = $seen[$key] ?? [];

            if ($flags === []) {
                if ($allClosed) {
                    $warnings[] = $messages['never']($key);
                }

                continue;
            }

            if ($allClosed && count($flags) < count($returns)) {
                $warnings[] = $messages['notAlways']($key);

                continue;
            }

            if (! in_array(false, $flags, true)) {
                $warnings[] = $messages['conditional']($key);
            }
        }

        return $warnings;
    }

    private function methodNode(string $fqcn, string $method): ?ClassMethod
    {
        try {
            $file = new ReflectionClass($fqcn)->getFileName();
        } catch (Throwable) {
            return null;
        }

        if ($file === false) {
            return null;
        }

        return ParsedSource::forFile($file)?->methodNode($fqcn, $method);
    }

    /**
     * @param  list<string>  $warnings
     * @return list<string>
     */
    private function unique(array $warnings): array
    {
        return array_values(array_unique($warnings));
    }
}
