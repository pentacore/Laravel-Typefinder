<?php

namespace Pentacore\Typefinder\Resolvers;

use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;

class MorphToResolver
{
    /**
     * Resolve morphTo targets across all extracted models.
     *
     * Scans all models for morphMany/morphOne relationships, then annotates
     * the corresponding morphTo relationships with the list of possible target classes.
     *
     * @param  list<array>  $allModels
     * @return list<array>
     */
    public function resolve(array $allModels): array
    {
        // Build a map: morphTo model FQCN + morph name → list of source model FQCNs
        $morphTargetMap = $this->buildMorphTargetMap($allModels);

        // Also check Relation::morphMap()
        $morphMap = Relation::morphMap();

        // Annotate morphTo relationships with their resolved targets
        foreach ($allModels as &$model) {
            foreach ($model['relationships'] as &$relationship) {
                if ($relationship['type'] !== 'morphTo') {
                    continue;
                }

                $morphName = $relationship['name'];
                $modelFqcn = $model['fqcn'];

                $key = $modelFqcn.'.'.$morphName;
                $targets = $morphTargetMap[$key] ?? [];

                // Also check the morph map for any registered aliases
                foreach ($morphMap as $class) {
                    if (! in_array($class, $targets, true)) {
                        foreach ($allModels as $otherModel) {
                            if ($otherModel['fqcn'] !== $class) {
                                continue;
                            }
                            foreach ($otherModel['relationships'] as $otherRel) {
                                if ($this->isMorphRelationPointingTo($otherRel, $model['fqcn'])) {
                                    $targets[] = $class;
                                }
                            }
                        }
                    }
                }

                $relationship['morphTargets'] = array_values(array_unique($targets));
            }
            unset($relationship);
        }
        unset($model);

        return $allModels;
    }

    /**
     * Build a map of morphTo model+name → list of source model FQCNs.
     *
     * @param  list<array>  $allModels
     * @return array<string, list<class-string>>
     */
    protected function buildMorphTargetMap(array $allModels): array
    {
        $map = [];

        foreach ($allModels as $model) {
            foreach ($model['relationships'] as $rel) {
                $relationType = $rel['relationType'] ?? '';

                if ($relationType !== MorphMany::class && $relationType !== MorphOne::class) {
                    continue;
                }

                $relatedFqcn = $rel['related'];
                $morphName = $this->findMorphNameForRelated($allModels, $relatedFqcn);

                if ($morphName !== null) {
                    $key = $relatedFqcn.'.'.$morphName;
                    $map[$key][] = $model['fqcn'];
                }
            }
        }

        return $map;
    }

    /**
     * Find the morphTo relationship name on the related model.
     */
    protected function findMorphNameForRelated(array $allModels, string $relatedFqcn): ?string
    {
        foreach ($allModels as $model) {
            if ($model['fqcn'] !== $relatedFqcn) {
                continue;
            }

            foreach ($model['relationships'] as $rel) {
                if ($rel['type'] === 'morphTo') {
                    return $rel['name'];
                }
            }
        }

        return null;
    }

    /**
     * Check if a relationship is a morph relationship pointing to a specific model.
     */
    protected function isMorphRelationPointingTo(array $rel, string $targetFqcn): bool
    {
        $relationType = $rel['relationType'] ?? '';

        return ($relationType === MorphMany::class || $relationType === MorphOne::class)
            && $rel['related'] === $targetFqcn;
    }
}
