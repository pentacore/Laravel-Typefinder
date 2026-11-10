<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Support\Enumerable;
use Pentacore\Typefinder\Support\GeneratedClasses;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeItemNode;
use PHPStan\PhpDocParser\Ast\Type\ObjectShapeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use stdClass;
use Throwable;

/**
 * Turns a phpdoc-parser type AST into a JSON-safe type tree for a resource
 * shape. Class leaves are classified here against the classes Typefinder
 * generates (model / enum / resource); everything it cannot express becomes
 * `unknown` with a warning.
 *
 * Nodes: `ts` (`ts`), `model`|`enum`|`resource` (`fqcn`), `array`|`record`
 * (`of`), `tuple` (`items`), `union`|`intersection` (`of`), `object`
 * (`fields`, `open`).
 */
final class PhpDocTypeMapper
{
    private const string RECORD = 'Record<string, unknown>';

    private const array INT_KEYWORDS = [
        'int', 'integer', 'positive-int', 'negative-int', 'non-negative-int', 'non-positive-int', 'non-zero-int',
    ];

    private const array ARRAY_KEYWORDS = ['array', 'non-empty-array', 'iterable'];

    private const array LIST_KEYWORDS = ['list', 'non-empty-list'];

    private const array UNSUPPORTED_KEYWORDS = ['void', 'never', 'callable', 'closure', 'resource'];

    /** Keywords with a fixed TypeScript type. */
    private const array SIMPLE_KEYWORDS = [
        'float' => 'number',
        'double' => 'number',
        'string' => 'string',
        'numeric' => 'number | string',
        'bool' => 'boolean',
        'boolean' => 'boolean',
        'true' => 'true',
        'false' => 'false',
        'null' => 'null',
        'mixed' => 'unknown',
        'array-key' => 'string | number',
        'scalar' => 'string | number | boolean',
    ];

    /** keyword() markers for keywords that are not plain TypeScript text. */
    private const string KEYWORD_LIST = ':list';

    private const string KEYWORD_SELF = ':self';

    private const string KEYWORD_UNSUPPORTED = ':unsupported';

    private const string CLASS_NAME = '/^\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*$/';

    private readonly ?Closure $onWarn;

    /** @var list<int> object ids of the alias types being expanded (cycle guard) */
    private array $expanding = [];

    /** @var array<string, true> warnings already emitted by the current map() call */
    private array $warned = [];

    /**
     * @param  null|callable(string): void  $onWarn
     */
    public function __construct(private readonly GeneratedClasses $classes, ?callable $onWarn = null)
    {
        $this->onWarn = $onWarn === null ? null : $onWarn(...);
    }

    /**
     * Map one type tree. Identical warnings within one call are emitted once.
     *
     * @return array<string, mixed>
     */
    public function map(TypeNode $type, PhpDocScope $scope, string $fieldPath): array
    {
        $this->warned = [];

        return $this->mapType($type, $scope, $fieldPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapType(TypeNode $type, PhpDocScope $scope, string $fieldPath): array
    {
        return match (true) {
            $type instanceof IdentifierTypeNode => $this->mapIdentifier($type, $scope, $fieldPath),
            $type instanceof ThisTypeNode => $this->leaf('resource', $scope->selfFqcn),
            $type instanceof NullableTypeNode => $this->union([$this->mapType($type->type, $scope, $fieldPath), $this->ts('null')]),
            $type instanceof UnionTypeNode => $this->union($this->mapEach($type->types, $scope, $fieldPath)),
            $type instanceof IntersectionTypeNode => ['kind' => 'intersection', 'of' => $this->mapEach($type->types, $scope, $fieldPath)],
            $type instanceof ArrayTypeNode => ['kind' => 'array', 'of' => $this->mapType($type->type, $scope, $fieldPath)],
            $type instanceof GenericTypeNode => $this->mapGeneric($type, $scope, $fieldPath),
            $type instanceof ArrayShapeNode => $this->mapArrayShape($type, $scope, $fieldPath),
            $type instanceof ObjectShapeNode => $this->mapFields($type->items, false, $scope, $fieldPath),
            $type instanceof ConstTypeNode => $this->mapConst($type, $scope, $fieldPath),
            default => $this->unsupported($type, $scope, $fieldPath),
        };
    }

    /**
     * @param  array<TypeNode>  $types
     * @return list<array<string, mixed>>
     */
    private function mapEach(array $types, PhpDocScope $scope, string $fieldPath): array
    {
        return array_values(array_map(fn (TypeNode $type): array => $this->mapType($type, $scope, $fieldPath), $types));
    }

    /**
     * @return array<string, mixed>
     */
    private function mapIdentifier(IdentifierTypeNode $type, PhpDocScope $scope, string $fieldPath): array
    {
        $keyword = $this->keyword($type->name);

        if ($keyword !== null) {
            return match ($keyword) {
                self::KEYWORD_LIST => ['kind' => 'array', 'of' => $this->ts('unknown')],
                self::KEYWORD_SELF => $this->leaf('resource', $scope->selfFqcn),
                self::KEYWORD_UNSUPPORTED => $this->unsupported($type, $scope, $fieldPath),
                default => $this->ts($keyword),
            };
        }

        $alias = $scope->alias($type->name);

        if ($alias !== null) {
            return $this->expandAlias($type, $alias, $scope, $fieldPath);
        }

        $fqcn = $this->resolveClass($type->name, $scope);

        return $fqcn === null
            ? $this->unsupported($type, $scope, $fieldPath)
            : $this->classify($fqcn, $type, $scope, $fieldPath);
    }

    /**
     * @param  array{type: TypeNode, scope: ?PhpDocScope}  $alias
     * @return array<string, mixed>
     */
    private function expandAlias(IdentifierTypeNode $type, array $alias, PhpDocScope $scope, string $fieldPath): array
    {
        $id = spl_object_id($alias['type']);

        if (in_array($id, $this->expanding, true)) {
            return $this->unsupported($type, $scope, $fieldPath);
        }

        $this->expanding[] = $id;

        try {
            return $this->mapType($alias['type'], $alias['scope'] ?? $scope, $fieldPath);
        } finally {
            array_pop($this->expanding);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function classify(string $fqcn, TypeNode $type, PhpDocScope $scope, string $fieldPath): array
    {
        if (is_a($fqcn, DateTimeInterface::class, true)) {
            return $this->ts('string');
        }

        if ($this->isCollection($fqcn)) {
            $this->warn($type, $scope, $fieldPath, 'unknown[]');

            return ['kind' => 'array', 'of' => $this->ts('unknown')];
        }

        if (is_a($fqcn, JsonResource::class, true) && ($fqcn === $scope->selfFqcn || $this->classes->isResource($fqcn))) {
            return $this->leaf('resource', $fqcn);
        }

        if (is_a($fqcn, Model::class, true) && $this->classes->isModel($fqcn)) {
            return $this->leaf('model', $fqcn);
        }

        if (is_a($fqcn, BackedEnum::class, true) && $this->classes->isEnum($fqcn)) {
            return $this->leaf('enum', $fqcn);
        }

        if ($fqcn === stdClass::class) {
            return $this->ts(self::RECORD);
        }

        return $this->unsupported($type, $scope, $fieldPath);
    }

    /**
     * @return array<string, mixed>
     */
    private function mapGeneric(GenericTypeNode $type, PhpDocScope $scope, string $fieldPath): array
    {
        $base = strtolower($type->type->name);
        $args = array_values($type->genericTypes);

        if (in_array($base, self::LIST_KEYWORDS, true)) {
            return ['kind' => 'array', 'of' => $this->mapType($args[count($args) - 1], $scope, $fieldPath)];
        }

        if (in_array($base, self::ARRAY_KEYWORDS, true)) {
            if (count($args) === 1 && $args[0] instanceof IdentifierTypeNode && strtolower($args[0]->name) === 'mixed') {
                return $this->ts(self::RECORD);
            }

            return $this->mapElements($args, $scope, $fieldPath);
        }

        if ($base === 'int') {
            return $this->ts('number');
        }

        if ($base === 'string' || str_ends_with($base, '-string')) {
            return $this->ts('string');
        }

        $fqcn = $this->resolveClass($type->type->name, $scope);

        if ($fqcn !== null && $this->isCollection($fqcn)) {
            return $this->mapElements($args, $scope, $fieldPath);
        }

        return $this->unsupported($type, $scope, $fieldPath);
    }

    /**
     * The last generic argument is the element; a non-integer key makes it a record.
     *
     * @param  list<TypeNode>  $args
     * @return array<string, mixed>
     */
    private function mapElements(array $args, PhpDocScope $scope, string $fieldPath): array
    {
        $of = $this->mapType($args[count($args) - 1], $scope, $fieldPath);

        return ['kind' => count($args) === 1 || $this->isIntKey($args[0]) ? 'array' : 'record', 'of' => $of];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapArrayShape(ArrayShapeNode $type, PhpDocScope $scope, string $fieldPath): array
    {
        if (! self::isListShape($type)) {
            return $this->mapFields($type->items, ! $type->sealed, $scope, $fieldPath);
        }

        $items = [];
        foreach (array_values($type->items) as $index => $item) {
            $items[] = [
                'type' => $this->mapType($item->valueType, $scope, $this->path($fieldPath, (string) $index)),
                'optional' => $item->optional,
            ];
        }

        return ['kind' => 'tuple', 'items' => $items];
    }

    /**
     * @param  array<ArrayShapeItemNode|ObjectShapeItemNode>  $items
     * @return array<string, mixed>
     */
    private function mapFields(array $items, bool $open, PhpDocScope $scope, string $fieldPath): array
    {
        $fields = [];
        $nextIndex = 0;

        foreach ($items as $item) {
            $key = $item->keyName;

            if ($key === null) {
                $name = (string) $nextIndex++;
            } elseif ($key instanceof ConstExprIntegerNode) {
                $name = (string) self::intValue($key->value);
                $nextIndex = max($nextIndex, (int) $name + 1);
            } else {
                $name = $key instanceof IdentifierTypeNode ? $key->name : $key->value;
            }

            [$valueType, $missing] = $this->withoutMissingValue($item->valueType, $scope);

            $fields[] = [
                'name' => $name,
                'type' => $this->mapType($valueType, $scope, $this->path($fieldPath, $name)),
                'optional' => $item->optional || $missing,
            ];
        }

        return ['kind' => 'object', 'fields' => $fields, 'open' => $open];
    }

    /**
     * `X|MissingValue` is the `whenLoaded()` / `when()` idiom: the key may be
     * absent. Drop the member and report that the field is optional.
     *
     * @return array{TypeNode, bool}
     */
    private function withoutMissingValue(TypeNode $type, PhpDocScope $scope): array
    {
        if (! $type instanceof UnionTypeNode) {
            return [$type, false];
        }

        $remaining = array_values(array_filter(
            $type->types,
            fn (TypeNode $member): bool => ! $member instanceof IdentifierTypeNode
                || $this->keyword($member->name) !== null
                || $this->resolveClass($member->name, $scope) !== MissingValue::class,
        ));

        return match (count($remaining)) {
            count($type->types), 0 => [$type, false],
            1 => [$remaining[0], true],
            default => [new UnionTypeNode($remaining), true],
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function mapConst(ConstTypeNode $type, PhpDocScope $scope, string $fieldPath): array
    {
        $expr = $type->constExpr;

        return match (true) {
            $expr instanceof ConstExprStringNode => $this->ts("'".str_replace(['\\', "'"], ['\\\\', "\\'"], $expr->value)."'"),
            $expr instanceof ConstExprIntegerNode => $this->ts((string) self::intValue($expr->value)),
            $expr instanceof ConstExprFloatNode => $this->ts(str_replace('_', '', $expr->value)),
            default => $this->unsupported($type, $scope, $fieldPath),
        };
    }

    /**
     * Resolve a docblock identifier to an existing class, or null. Never throws.
     */
    private function resolveClass(string $name, PhpDocScope $scope): ?string
    {
        if (preg_match(self::CLASS_NAME, $name) !== 1) {
            return null;
        }

        $fqcn = $scope->resolveName($name);

        try {
            return $fqcn !== null && (class_exists($fqcn) || interface_exists($fqcn)) ? $fqcn : null;
        } catch (Throwable) {
            // A class file that fails to load is as unknown as a missing class.
            return null;
        }
    }

    /**
     * The single source of keyword knowledge: the TypeScript text a bare
     * PHPDoc keyword maps to (or a KEYWORD_* marker), or null when the
     * identifier is not a keyword and so names a class or alias.
     */
    private function keyword(string $name): ?string
    {
        $keyword = strtolower($name);

        return match (true) {
            isset(self::SIMPLE_KEYWORDS[$keyword]) => self::SIMPLE_KEYWORDS[$keyword],
            in_array($keyword, self::INT_KEYWORDS, true) => 'number',
            str_ends_with($keyword, '-string') => 'string',
            in_array($keyword, self::ARRAY_KEYWORDS, true), $keyword === 'object' => self::RECORD,
            in_array($keyword, self::LIST_KEYWORDS, true) => self::KEYWORD_LIST,
            in_array($keyword, self::UNSUPPORTED_KEYWORDS, true) => self::KEYWORD_UNSUPPORTED,
            $keyword === 'static', $keyword === 'self' => self::KEYWORD_SELF,
            default => null,
        };
    }

    /**
     * @return array{kind: 'ts', ts: 'unknown'}
     */
    private function unsupported(TypeNode $type, PhpDocScope $scope, string $fieldPath): array
    {
        $this->warn($type, $scope, $fieldPath);

        return $this->ts('unknown');
    }

    private function warn(TypeNode $type, PhpDocScope $scope, string $fieldPath, string $emitted = 'unknown'): void
    {
        $message = sprintf(
            '%s::toArray() @return: "%s" has unsupported type %s — emitted as %s',
            $scope->resourceFqcn,
            $fieldPath,
            (string) $type,
            $emitted,
        );

        if ($this->onWarn instanceof Closure && ! isset($this->warned[$message])) {
            $this->warned[$message] = true;
            ($this->onWarn)($message);
        }
    }

    private function isCollection(string $fqcn): bool
    {
        return is_a($fqcn, Enumerable::class, true) || is_a($fqcn, ResourceCollection::class, true);
    }

    private function isIntKey(TypeNode $key): bool
    {
        if ($key instanceof GenericTypeNode) {
            return strtolower($key->type->name) === 'int';
        }

        return $key instanceof IdentifierTypeNode && in_array(strtolower($key->name), self::INT_KEYWORDS, true);
    }

    /**
     * `array{int, string}`, `array{int, 1?: string}` and `list{…}` are tuples.
     */
    public static function isListShape(ArrayShapeNode $type): bool
    {
        if (in_array($type->kind, [ArrayShapeNode::KIND_LIST, ArrayShapeNode::KIND_NON_EMPTY_LIST], true)) {
            return true;
        }

        foreach (array_values($type->items) as $index => $item) {
            $key = $item->keyName;

            if ($key !== null && (! $key instanceof ConstExprIntegerNode || self::intValue($key->value) !== $index)) {
                return false;
            }
        }

        return true;
    }

    private static function intValue(string $literal): int
    {
        return intval(str_replace('_', '', $literal), 0);
    }

    private function path(string $parent, string $name): string
    {
        return $parent === '' ? $name : $parent.'.'.$name;
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return array{kind: 'union', of: list<array<string, mixed>>}
     */
    private function union(array $members): array
    {
        $flat = [];
        foreach ($members as $member) {
            array_push($flat, ...($member['kind'] === 'union' ? $member['of'] : [$member]));
        }

        return ['kind' => 'union', 'of' => $flat];
    }

    /**
     * @return array{kind: string, fqcn: string}
     */
    private function leaf(string $kind, string $fqcn): array
    {
        return ['kind' => $kind, 'fqcn' => $fqcn];
    }

    /**
     * @return array{kind: 'ts', ts: string}
     */
    private function ts(string $ts): array
    {
        return ['kind' => 'ts', 'ts' => $ts];
    }
}
