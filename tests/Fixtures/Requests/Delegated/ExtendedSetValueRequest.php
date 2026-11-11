<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\Delegated;

/**
 * Adds to the parent's rules, and takes more from its own helpers.
 */
class ExtendedSetValueRequest extends ApiSetValueRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            ...$this->sortRules(),
            ...$this->filterRules(),
            ...static::loop(),
            'comment' => collect(['string'])->all(),
            // Calls into the framework are not followed.
            'owner' => [$this->user() === null ? 'nullable' : 'required'],
        ];
    }

    private function sortRules(): array
    {
        return [
            /** @query */
            'sort' => ['in:asc,desc'],
        ];
    }

    private function filterRules(): array
    {
        return [
            /** @query */
            'filter' => ['string'],
        ];
    }

    /**
     * Calls itself: following it must end.
     */
    protected static function loop(int $depth = 0): array
    {
        return $depth > 0 ? [] : self::loop($depth + 1);
    }
}
