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
use Pentacore\Typefinder\Attributes\TypefinderIgnore;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;
use Pentacore\Typefinder\Attributes\TypefinderWriteShape;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;

class ModelExtractor
{
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
            'columns' => $columns,
            'relationships' => $relationships,
            'assignable_columns' => $this->filterAssignable($model, $columns),
        ];
    }

    /**
     * Discover and extract all models from a directory.
     *
     * @return list<array{name: string, fqcn: class-string, columns: list<array>, relationships: list<array>}>
     */
    public function extractFromDirectory(string $path, ?callable $onExtract = null): array
    {
        if (! is_dir($path)) {
            return [];
        }

        $results = [];
        $finder = Finder::create()->files()->name('*.php')->in($path);

        foreach ($finder as $file) {
            $className = $this->resolveClassName($file->getRealPath());
            if ($className === null) {
                continue;
            }

            if (! class_exists($className)) {
                continue;
            }

            if (! is_subclass_of($className, Model::class)) {
                continue;
            }

            if ($this->isIgnored($className)) {
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
                $columns[] = ['type' => $this->castTypeResolver->resolve($casts[$name])] + $base;

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
     * @return list<string>
     */
    protected function getContractServerFilled(Model $model): array
    {
        $attrs = (new ReflectionClass($model))
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

        $attrs = (new ReflectionClass($model))
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
        $attrs = (new ReflectionClass($model))
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
            if ($reflectionMethod->class !== $reflectionClass->getName()) {
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
            'withTimestamps' => $relation->createdAt() !== null,
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
     * Resolve the fully qualified class name from a PHP file.
     */
    protected function isIgnored(string $className): bool
    {
        return (new ReflectionClass($className))
            ->getAttributes(TypefinderIgnore::class, ReflectionAttribute::IS_INSTANCEOF) !== [];
    }

    protected function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if (! preg_match('/namespace\s+(.+?);/', $contents, $nsMatch)) {
            return null;
        }

        if (! preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            return null;
        }

        return $nsMatch[1].'\\'.$classMatch[1];
    }
}
