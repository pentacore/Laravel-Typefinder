<?php

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
use Pentacore\Typefinder\Concerns\HasTypeOverrides;
use Pentacore\Typefinder\Resolvers\CastTypeResolver;
use Pentacore\Typefinder\Resolvers\ColumnTypeResolver;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Symfony\Component\Finder\Finder;

class ModelExtractor
{
    public function __construct(
        protected ColumnTypeResolver $columnTypeResolver,
        protected CastTypeResolver $castTypeResolver,
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
        $reflection = new ReflectionClass($model);
        $table = $model->getTable();

        $columns = $this->extractColumns($model, $table);
        $relationships = $this->extractRelationships($model, $reflection);

        return [
            'name' => $reflection->getShortName(),
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

            if ($className === null || ! class_exists($className)) {
                continue;
            }

            if (! is_subclass_of($className, Model::class)) {
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
     * @return list<array{name: string, type: mixed, nullable: bool}>
     */
    protected function extractColumns(Model $model, string $table): array
    {
        $schemaColumns = Schema::getColumns($table);
        $casts = $model->getCasts();
        $overrides = $this->getTypeOverrides($model);
        $hidden = $model->getHidden();
        $visible = $model->getVisible();

        $contractServerFilled = $this->getContractServerFilled($model);
        $columns = [];

        foreach ($schemaColumns as $column) {
            $name = $column['name'];
            $nullable = $column['nullable'];

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

            // Priority 1: HasTypeOverrides
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
            $columns[] = ['type' => $this->columnTypeResolver->resolve($column['type_name'], false)] + $base;
        }

        return $columns;
    }

    /**
     * @return list<string>
     */
    protected function getContractServerFilled(Model $model): array
    {
        if (! method_exists($model, 'typefinderServerFilled')) {
            return [];
        }

        return (array) $model::typefinderServerFilled();
    }

    /**
     * @param  list<array>  $columns
     * @return list<array>
     */
    protected function filterAssignable(Model $model, array $columns): array
    {
        $respect = (bool) config('typefinder.models.respect_mass_assignment', true);
        if (method_exists($model, 'typefinderRespectMassAssignment')) {
            $override = $model::typefinderRespectMassAssignment();
            if ($override !== null) {
                $respect = $override;
            }
        }

        if (! $respect) {
            return $columns;
        }

        $fillable = $model->getFillable();
        if (! empty($fillable)) {
            return array_values(array_filter($columns, fn ($c) => in_array($c['name'], $fillable, true)));
        }

        $guarded = $model->getGuarded();
        if ($guarded === ['*']) {
            return [];
        }

        return array_values(array_filter($columns, fn ($c) => ! in_array($c['name'], $guarded, true)));
    }

    /**
     * Get type overrides from the model if it uses the HasTypeOverrides trait.
     *
     * @return array<string, string>
     */
    protected function getTypeOverrides(Model $model): array
    {
        if (in_array(HasTypeOverrides::class, class_uses_recursive($model), true)) {
            return $model->typeOverrides();
        }

        return [];
    }

    /**
     * Extract relationship information from the model.
     *
     * @return list<array{name: string, type: string, related: class-string, relationType: string, pivot?: array}>
     */
    protected function extractRelationships(Model $model, ReflectionClass $reflection): array
    {
        $relationships = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->class !== $reflection->getName()) {
                continue;
            }

            if ($method->getNumberOfParameters() > 0) {
                continue;
            }

            $returnType = $method->getReturnType();

            if ($returnType === null) {
                continue;
            }

            $returnTypeName = $returnType instanceof ReflectionNamedType ? $returnType->getName() : null;

            if ($returnTypeName === null || ! is_subclass_of($returnTypeName, Relation::class)) {
                continue;
            }

            $relationship = $this->extractRelationship($model, $method, $returnTypeName);

            if ($relationship !== null) {
                $relationships[] = $relationship;
            }
        }

        return $relationships;
    }

    /**
     * Extract a single relationship's information.
     */
    protected function extractRelationship(Model $model, ReflectionMethod $method, string $returnTypeName): ?array
    {
        try {
            $relation = $method->invoke($model);
        } catch (\Throwable) {
            return null;
        }

        if (! $relation instanceof Relation) {
            return null;
        }

        $relatedClass = $relation->getRelated()::class;
        $methodName = $method->getName();

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
