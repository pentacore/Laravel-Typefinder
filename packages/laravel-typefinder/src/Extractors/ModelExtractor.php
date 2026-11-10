<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Extractors;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use Pentacore\Typefinder\Support\ClassNameResolver;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Symfony\Component\Finder\Finder;

class ModelExtractor
{
    /** PHP scalar types an accessor can declare, as TypeScript. */
    private const array SCALAR_TYPES = [
        'string' => 'string',
        'int' => 'number',
        'float' => 'number',
        'bool' => 'boolean',
        'true' => 'true',
        'false' => 'false',
    ];

    public function __construct(
        protected ColumnTypeResolver $columnTypeResolver,
        protected CastTypeResolver $castTypeResolver,
        /** @var null|callable(string): void */
        protected $onWarn = null,
    ) {}

    /**
     * Extract type information from a single model class.
     *
     * @param  class-string<Model>  $modelClass
     * @return array{name: string, fqcn: class-string, columns: list<array>, relationships: list<array>}
     */
    public function extract(string $modelClass): array
    {
        $model = new $modelClass;
        $reflectionClass = new ReflectionClass($model);
        $table = $model->getTable();

        $columns = $this->extractColumns($model, $table, $modelClass);
        $relationships = $this->extractRelationships($model, $reflectionClass);

        return [
            'name' => $reflectionClass->getShortName(),
            'fqcn' => $modelClass,
            'columns' => [...$columns, ...$this->extractAppends($model, $reflectionClass, $columns)],
            'relationships' => $relationships,
            // Appended attributes are read-only: computed before they join the columns.
            'assignable_columns' => $this->filterAssignable($model, $columns),
        ];
    }

    /**
     * Discover and extract all models from a directory.
     *
     * @return list<array{name: string, fqcn: class-string, columns: list<array>, relationships: list<array>}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null, ?callable $onWarn = null): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $results = [];
        $finder = Finder::create()->files()->name('*.php')->in($path)->sortByName();

        foreach ($finder as $file) {
            $className = ClassNameResolver::fromFile($file->getRealPath());
            if ($className === null) {
                continue;
            }

            if (! class_exists($className)) {
                $failure = ClassNameResolver::autoloadFailure($className, $file->getRealPath());
                if ($failure instanceof RuntimeException && $onWarn !== null) {
                    $onWarn($className, $failure);
                }

                continue;
            }

            if (! is_subclass_of($className, Model::class)) {
                continue;
            }

            if (! $this->isEmittable($className)) {
                continue;
            }

            if ($onExtract !== null) {
                $onExtract($className);
            }

            $results[] = $this->extract($className);
        }

        return $results;
    }

    /**
     * Extract column information from the model's database table.
     *
     * Respects $visible/$hidden on the model: hidden columns are excluded;
     * if $visible is set, only those columns pass through.
     *
     * @param  class-string<Model>|null  $modelClass
     * @return list<array{name: string, type: mixed, nullable: bool}>
     */
    protected function extractColumns(Model $model, string $table, ?string $modelClass = null): array
    {
        $schemaColumns = Schema::getColumns($table);
        $casts = $model->getCasts();
        $overrides = $this->getTypeOverrides($model);
        $hidden = $model->getHidden();
        $visible = $model->getVisible();

        $contractServerFilled = $this->getContractServerFilled($model);
        $columns = [];

        foreach ($schemaColumns as $schemaColumn) {
            $name = $schemaColumn['name'];
            $nullable = $schemaColumn['nullable'];

            // Visibility filtering
            if (in_array($name, $hidden, true)) {
                continue;
            }

            if (! empty($visible) && ! in_array($name, $visible, true)) {
                continue;
            }

            $isPrimary = $name === $model->getKeyName();
            $isServerFilled = $isPrimary
                || $name === $model->getCreatedAtColumn()
                || $name === $model->getUpdatedAtColumn()
                || (method_exists($model, 'getDeletedAtColumn') && $name === $model->getDeletedAtColumn())
                || in_array($name, $contractServerFilled, true);

            $base = [
                'name' => $name,
                'nullable' => $nullable,
                'is_primary' => $isPrimary,
                'is_server_filled' => $isServerFilled,
            ];

            // Priority 1: TypefinderOverrides
            if (isset($overrides[$name])) {
                $columns[] = ['type' => $overrides[$name]] + $base;

                continue;
            }

            // Priority 2: Cast resolution
            if (isset($casts[$name])) {
                $castType = $this->castTypeResolver->resolveOrNull($casts[$name]);
                if ($castType === null) {
                    $castType = 'unknown';
                    if ($this->onWarn !== null) {
                        ($this->onWarn)(sprintf(
                            "%s.%s: cast '%s' has no TypeScript mapping — emitted as `unknown`. Add #[TypefinderCast] to the cast class, register it with Typefinder::registerCast(), or map it in typefinder.casts.type_map.",
                            $modelClass ?? $model::class,
                            $name,
                            $casts[$name],
                        ));
                    }
                }

                $columns[] = ['type' => $castType] + $base;

                continue;
            }

            // Priority 3: DB column type
            $resolved = $this->columnTypeResolver->resolve($schemaColumn['type_name'], false);
            if ($resolved === 'unknown' && $this->onWarn !== null) {
                ($this->onWarn)(sprintf(
                    "%s.%s: column type '%s' not recognised — emitted as `unknown`. Add a #[TypefinderOverrides] entry or open an issue if this is a common DB type.",
                    $modelClass ?? $model::class,
                    $name,
                    $schemaColumn['type_name'],
                ));
            }

            $columns[] = ['type' => $resolved] + $base;
        }

        return $columns;
    }

    /**
     * Attributes in `$appends` are part of the serialized model, after the
     * columns. `$hidden`/`$visible` apply as they do to columns, and an
     * appended name that is also a column keeps the column's entry.
     *
     * @param  list<array{name: string}>  $columns
     * @return list<array{name: string, type: string, nullable: bool, is_primary: bool, is_server_filled: bool, is_appended: bool}>
     */
    protected function extractAppends(Model $model, ReflectionClass $reflectionClass, array $columns): array
    {
        $overrides = $this->getTypeOverrides($model);
        $hidden = $model->getHidden();
        $visible = $model->getVisible();
        $taken = array_column($columns, 'name');
        $appends = [];

        foreach ($model->getAppends() as $name) {
            if (in_array($name, $taken, true) || in_array($name, $hidden, true)) {
                continue;
            }

            if (! empty($visible) && ! in_array($name, $visible, true)) {
                continue;
            }

            $taken[] = $name;
            [$type, $nullable] = isset($overrides[$name])
                ? [$overrides[$name], false]
                : $this->accessorType($reflectionClass, $name);

            $appends[] = [
                'type' => $type,
                'name' => $name,
                'nullable' => $nullable,
                'is_primary' => false,
                'is_server_filled' => true,
                'is_appended' => true,
            ];
        }

        return $appends;
    }

    /**
     * The TypeScript type of an accessor, read from `getFooAttribute()`'s
     * return type or from the `Attribute<TGet, TSet>` generic documented on
     * `foo()`. Anything else is `unknown`.
     *
     * @return array{string, bool} type and nullability
     */
    protected function accessorType(ReflectionClass $reflectionClass, string $name): array
    {
        $legacy = 'get'.Str::studly($name).'Attribute';
        if ($reflectionClass->hasMethod($legacy)) {
            $returnType = $reflectionClass->getMethod($legacy)->getReturnType();
            if ($returnType instanceof ReflectionNamedType) {
                return [
                    self::SCALAR_TYPES[$returnType->getName()] ?? 'unknown',
                    $returnType->allowsNull() && $returnType->getName() !== 'mixed',
                ];
            }

            return ['unknown', false];
        }

        $modern = Str::camel($name);
        $doc = $reflectionClass->hasMethod($modern) ? $reflectionClass->getMethod($modern)->getDocComment() : false;
        if ($doc === false || preg_match('/@return\s+[\w\\\\]*Attribute<([^,<>]+),/', $doc, $match) !== 1) {
            return ['unknown', false];
        }

        $members = array_map(trim(...), explode('|', ltrim(trim($match[1]), '?')));
        $nullable = str_starts_with(trim($match[1]), '?') || in_array('null', $members, true);
        $members = array_values(array_filter($members, fn (string $member): bool => $member !== 'null'));

        $types = [];
        foreach ($members as $member) {
            if (! isset(self::SCALAR_TYPES[$member])) {
                return ['unknown', false];
            }

            $types[] = self::SCALAR_TYPES[$member];
        }

        return $types === [] ? ['unknown', false] : [implode(' | ', array_unique($types)), $nullable];
    }

    /**
     * @return list<string>
     */
    protected function getContractServerFilled(Model $model): array
    {
        $attrs = new ReflectionClass($model)
            ->getAttributes(TypefinderWriteShape::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs === []) {
            return [];
        }

        return $attrs[0]->newInstance()->serverFilled;
    }

    /**
     * @param  list<array>  $columns
     * @return list<array>
     */
    protected function filterAssignable(Model $model, array $columns): array
    {
        $respect = (bool) config('typefinder.models.respect_mass_assignment', true);

        $attrs = new ReflectionClass($model)
            ->getAttributes(TypefinderWriteShape::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs !== []) {
            $override = $attrs[0]->newInstance()->respectMassAssignment;
            if ($override !== null) {
                $respect = $override;
            }
        }

        if (! $respect) {
            return $columns;
        }

        $fillable = $model->getFillable();
        if (! empty($fillable)) {
            return array_values(array_filter($columns, fn (array $c): bool => in_array($c['name'], $fillable, true)));
        }

        $guarded = $model->getGuarded();
        if ($guarded === ['*']) {
            return [];
        }

        return array_values(array_filter($columns, fn (array $c): bool => ! in_array($c['name'], $guarded, true)));
    }

    /**
     * @return array<string, string>
     */
    protected function getTypeOverrides(Model $model): array
    {
        $attrs = new ReflectionClass($model)
            ->getAttributes(TypefinderOverrides::class, ReflectionAttribute::IS_INSTANCEOF);
        if ($attrs === []) {
            return [];
        }

        return $attrs[0]->newInstance()->overrides;
    }

    /**
     * Extract relationship information from the model.
     *
     * @return list<array{name: string, type: string, related: class-string, relationType: string, pivot?: array}>
     */
    protected function extractRelationships(Model $model, ReflectionClass $reflectionClass): array
    {
        $relationships = [];

        foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $reflectionMethod) {
            // Relations declared on a parent model are inherited; only the
            // framework base class itself has none to offer.
            if ($reflectionMethod->class === Model::class) {
                continue;
            }

            if ($reflectionMethod->getNumberOfParameters() > 0) {
                continue;
            }

            $returnType = $reflectionMethod->getReturnType();

            if ($returnType === null) {
                continue;
            }

            $returnTypeName = $returnType instanceof ReflectionNamedType ? $returnType->getName() : null;
            if ($returnTypeName === null) {
                continue;
            }

            if (! is_subclass_of($returnTypeName, Relation::class)) {
                continue;
            }

            $relationship = $this->extractRelationship($model, $reflectionMethod, $returnTypeName);

            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }

        return $relationships;
    }

    /**
     * Extract a single relationship's information.
     */
    protected function extractRelationship(Model $model, ReflectionMethod $reflectionMethod, string $returnTypeName): ?array
    {
        try {
            $relation = $reflectionMethod->invoke($model);
        } catch (\Throwable) {
            return null;
        }

        if (! $relation instanceof Relation) {
            return null;
        }

        $relatedClass = $relation->getRelated()::class;
        $methodName = $reflectionMethod->getName();

        $result = [
            'name' => $methodName,
            'related' => $relatedClass,
            'relationType' => $returnTypeName,
        ];

        // Determine cardinality
        $result['type'] = match (true) {
            $relation instanceof MorphTo => 'morphTo',
            $relation instanceof HasOne, $relation instanceof MorphOne, $relation instanceof HasOneThrough => 'one',
            $relation instanceof BelongsTo => 'belongsTo',
            $relation instanceof HasMany, $relation instanceof MorphMany, $relation instanceof HasManyThrough => 'many',
            $relation instanceof BelongsToMany, $relation instanceof MorphToMany => 'manyWithPivot',
            default => 'unknown',
        };

        // Extract pivot information for belongsToMany and morphToMany
        if ($result['type'] === 'manyWithPivot') {
            $result['pivot'] = $this->extractPivotInfo($relation);
        }

        return $result;
    }

    /**
     * Extract pivot table information from a BelongsToMany or MorphToMany relation.
     *
     * @return array{table: string, foreignKey: string, relatedKey: string, withPivot: list<string>, withTimestamps: bool, morphType?: string, using?: class-string|null}
     */
    protected function extractPivotInfo(BelongsToMany|MorphToMany $relation): array
    {
        $pivot = [
            'table' => $relation->getTable(),
            'foreignKey' => $relation->getForeignPivotKeyName(),
            'relatedKey' => $relation->getRelatedPivotKeyName(),
            'withPivot' => $relation->getPivotColumns(),
            // createdAt() always returns a column name (falling back to the
            // parent's CREATED_AT), so read the flag withTimestamps() sets.
            'withTimestamps' => $relation->withTimestamps,
        ];

        if ($relation instanceof MorphToMany) {
            $pivot['morphType'] = $relation->getMorphType();
        }

        $using = $relation->getPivotClass();
        if ($using !== Pivot::class
            && $using !== MorphPivot::class) {
            $pivot['using'] = $using;
        }

        return $pivot;
    }

    /**
     * Whether a model class gets a type of its own: not `#[TypefinderIgnore]`d,
     * and not abstract (a base model has no table to read; its relations and
     * accessors are emitted on the models that extend it).
     */
    public function isEmittable(string $className): bool
    {
        $reflectionClass = new ReflectionClass($className);

        return ! $reflectionClass->isAbstract()
            && $reflectionClass->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) === [];
    }
}
