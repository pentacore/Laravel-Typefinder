<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Analysis;

use Closure;
use Pentacore\Typefinder\Extractors\ModelExtractor;
use Pentacore\Typefinder\Resolvers\PhpDocShapeResolver;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionClass;

/**
 * Drift between a resource's declared keys and the literal keys its own
 * `toArray()` returns: a `#[TypefinderResource] shape` or PHPDoc `@return`
 * shape, or the model type a model-typed resource is emitted as.
 * Precision over recall, as {@see DriftComparer::compare()}.
 */
final readonly class ResourceDrift
{
    private const string ATTRIBUTE_SHAPE = '#[TypefinderResource] shape';

    private const string PHPDOC_SHAPE = 'PHPDoc @return shape';

    private DriftComparer $comparer;

    /**
     * @param  PhpDocShapeResolver  $phpDocShapes  Reads the PHPDoc shape of a
     *                                             resource that also has an attribute shape.
     */
    public function __construct(private PhpDocShapeResolver $phpDocShapes, private ReturnedArrayReader $reader)
    {
        $this->comparer = new DriftComparer;
    }

    /**
     * Drift between a resource extraction's declared keys and the literal keys
     * its own `toArray()` returns. A model-typed resource (attribute `model:`
     * or the name convention) is compared with its model's keys, as one
     * aggregated warning, when `$model` (that model's extraction) is given.
     *
     * @param  array{name: string, fqcn: class-string, shape: array<string, mixed>}  $extraction
     * @param  ?array{name: string, columns: list<array{name: string}>, relationships: list<array{name: string}>}  $model
     * @return list<string>
     */
    public function check(array $extraction, ?array $model = null): array
    {
        $fqcn = ltrim($extraction['fqcn'], '\\');
        $shape = $extraction['shape'];

        if ($shape['kind'] === 'shape') {
            /** @var array<array-key, string> $fields */
            $fields = $shape['fields'];
            // `'note?' => 'string'` is emitted verbatim as an optional field.
            $declared = array_map(
                static fn (int|string $key): array => str_ends_with((string) $key, '?')
                    ? ['key' => substr((string) $key, 0, -1), 'optional' => true]
                    : ['key' => (string) $key, 'optional' => false],
                array_keys($fields),
            );

            return DriftComparer::unique([
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

            return DriftComparer::unique($this->resourceDrift($fqcn, $declared, $type['open'] ?? false, self::PHPDOC_SHAPE));
        }

        if ($shape['kind'] === 'model' && $model !== null) {
            return $this->modelDrift($fqcn, $shape, $model);
        }

        return [];
    }

    /**
     * A model-typed resource's `toArray()` against the model type it is
     * emitted as: columns (required; nullable ones are still present) and
     * relations (optional), less `omit`, plus `extend`. One warning per
     * resource, as a convention mismatch usually differs in many keys.
     *
     * @param  array<string, mixed>  $shape
     * @param  array{name: string, columns: list<array{name: string}>, relationships: list<array{name: string}>}  $model
     * @return list<string>
     */
    private function modelDrift(string $fqcn, array $shape, array $model): array
    {
        /** @var list<string> $omit */
        $omit = array_map(strval(...), (array) ($shape['omit'] ?? []));
        /** @var array<array-key, string> $extend */
        $extend = (array) ($shape['extend'] ?? []);

        $declared = [];

        foreach ($model['columns'] as $column) {
            // An overridden relation stays optional.
            $declared[(string) $column['name']] = ! empty($column['optional']);
        }

        foreach ($model['relationships'] as $relationship) {
            $declared[(string) $relationship['name']] ??= true;
        }

        foreach ($omit as $key) {
            // A relation may be omitted by its method name.
            unset($declared[ModelExtractor::serialisedRelationKey($model, $key)]);
        }

        foreach (array_keys($extend) as $key) {
            $key = (string) $key;

            if (str_ends_with($key, '?')) {
                $declared[substr($key, 0, -1)] = true;
            } else {
                $declared[$key] = false;
            }
        }

        $list = [];

        foreach ($declared as $key => $optional) {
            $list[] = ['key' => strval($key), 'optional' => $optional];
        }

        $kinds = ['undeclared', 'never', 'notAlways', 'conditional'];
        $tag = static fn (string $kind): Closure => static fn (string $key): string => $kind."\0".$key;
        $found = $this->comparer->compare($list, false, $this->resourceReturns($fqcn), array_combine($kinds, array_map($tag, $kinds)));

        if ($found === []) {
            return [];
        }

        $keys = array_fill_keys($kinds, []);

        foreach ($found as $finding) {
            [$kind, $key] = explode("\0", $finding, 2);
            $keys[$kind][] = '"'.$key.'"';
        }

        $clauses = [];

        if ($keys['undeclared'] !== []) {
            $clauses[] = 'returns undeclared '.implode(', ', $keys['undeclared']);
        }

        if ($keys['never'] !== []) {
            $clauses[] = 'never returns '.implode(', ', $keys['never']);
        }

        if ($keys['notAlways'] !== []) {
            $clauses[] = 'does not always return '.implode(', ', $keys['notAlways']);
        }

        if ($keys['conditional'] !== []) {
            $clauses[] = implode(', ', $keys['conditional']).(count($keys['conditional']) === 1 ? ' is' : ' are').' conditional but required';
        }

        $viaAttribute = ($shape['via'] ?? ($omit === [] && $extend === [] ? 'convention' : 'attribute')) === 'attribute';

        return [sprintf(
            '%s::toArray() does not match the %s model type it is typed as (%s): %s — %s',
            $fqcn,
            $model['name'],
            $viaAttribute ? '#[TypefinderResource(model:)]' : 'by name',
            implode('; ', $clauses),
            $viaAttribute
                ? 'adjust omit/extend in #[TypefinderResource] or declare a shape instead'
                : 'adjust it with #[TypefinderResource(model: …, omit: …, extend: …)], declare its shape with #[TypefinderResource(shape: …)] or a PHPDoc @return array{…} on toArray(), or set typefinder.resources.infer_from_model_name to false',
        )];
    }

    /**
     * The returns of the resource's own `toArray()`; an empty-array guard
     * (`if ($this->resource === null) { return []; }`) does not make keys
     * "not always returned", so it is dropped unless every return is empty.
     *
     * @return list<ReturnedArray>
     */
    private function resourceReturns(string $fqcn): array
    {
        $method = DriftComparer::methodNode($fqcn, 'toArray');

        if (! $method instanceof ClassMethod) {
            return [];
        }

        $returns = $this->reader->returns($method);
        $withKeys = array_values(array_filter($returns, static fn (ReturnedArray $returned): bool => $returned->parts !== []));

        return $withKeys === [] ? $returns : $withKeys;
    }

    /**
     * @param  list<array{key: string, optional: bool}>  $declared
     * @return list<string>
     */
    private function resourceDrift(string $fqcn, array $declared, bool $declaredOpen, string $src): array
    {
        return $this->comparer->compare($declared, $declaredOpen, $this->resourceReturns($fqcn), [
            'undeclared' => static fn (string $key): string => sprintf('%s::toArray() returns key "%s" not declared in %s', $fqcn, $key, $src),
            'never' => static fn (string $key): string => sprintf('%s::toArray(): %s declares "%s" but toArray() never returns it', $fqcn, $src, $key),
            'notAlways' => static fn (string $key): string => sprintf('%s::toArray(): %s declares "%s" as required but toArray() does not always return it (make it optional?)', $fqcn, $src, $key),
            'conditional' => static fn (string $key): string => sprintf('%s::toArray(): "%s" is conditional in code but required in %s', $fqcn, $key, $src),
        ]);
    }

    /**
     * A resource with an attribute shape whose own `toArray()` also documents
     * a PHPDoc shape: the two key sets should agree. An inherited doc is not
     * compared, and an open PHPDoc shape (`...`) may hold the attribute's
     * extra keys.
     *
     * @param  list<string>  $attributeKeys
     * @return list<string>
     */
    private function shapeDisagreement(string $fqcn, array $attributeKeys): array
    {
        $doc = DriftComparer::methodNode($fqcn, 'toArray')?->getDocComment()?->getText();

        if ($doc === null || preg_match('/@(?:phpstan-|psalm-)?return\b/', $doc) !== 1) {
            return [];
        }

        $type = $this->phpDocShapes->resolveDocumented(new ReflectionClass($fqcn));

        if ($type === null) {
            return [];
        }

        /** @var list<array{name: string}> $fields */
        $fields = $type['fields'];
        $docKeys = array_map(static fn (array $field): string => (string) $field['name'], $fields);

        $clauses = [];
        $onlyInAttribute = ($type['open'] ?? false) ? [] : array_values(array_diff($attributeKeys, $docKeys));
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
}
