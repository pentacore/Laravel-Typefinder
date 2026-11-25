<?php

declare(strict_types=1);

namespace Pentacore\Typefinder\Resolvers;

class MorphToResolver
{
    /**
     * Resolve morphTo targets across all extracted models.
     *
     * Every morphOne / morphMany relation names the model it points at and
     * the morph type column it fills (`subject_type`). A morphTo's targets
     * are the models whose relations point at the morphTo's model with the
     * morphTo's own type column, so two morphTo relations on one model
     * (`subject`, `causer`) each get their own targets.
     *
     * @param  list<array>  $allModels
     * @return list<array>
     */
    public function resolve(array $allModels): array
    {
        $morphTargetMap = $this->buildMorphTargetMap($allModels);

        foreach ($allModels as &$model) {
            foreach ($model['relationships'] as &$relationship) {
                if ($relationship['type'] !== 'morphTo') {
                    continue;
                }

                $targets = $morphTargetMap[$this->key($model['fqcn'], $relationship['morphType'] ?? '')] ?? [];
                $targets = array_values(array_unique($targets));
                sort($targets, SORT_STRING);
                $relationship['morphTargets'] = $targets;
            }

            unset($relationship);
        }

        unset($model);

        return $allModels;
    }

    /**
     * The models that invert each morphTo, keyed by the morphTo's model and
     * its morph type column.
     *
     * @param  list<array>  $allModels
     * @return array<string, list<class-string>>
     */
    protected function buildMorphTargetMap(array $allModels): array
    {
        $map = [];

        foreach ($allModels as $allModel) {
            foreach ($allModel['relationships'] as $rel) {
                if ($rel['type'] === 'morphTo' || ! isset($rel['morphType'])) {
                    continue;
                }

                $map[$this->key($rel['related'], $rel['morphType'])][] = $allModel['fqcn'];
            }
        }

        return $map;
    }

    private function key(string $modelFqcn, string $morphType): string
    {
        return $modelFqcn.'|'.$morphType;
    }
}
