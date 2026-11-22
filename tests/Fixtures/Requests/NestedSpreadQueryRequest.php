<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A tagged spread whose method takes its keys from `array_merge()`, a nested
 * spread and another method, and declares a closure with its own `return`.
 */
final class NestedSpreadQueryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
            /** @query */
            ...self::listingRules(),
        ];
    }

    private static function listingRules(): array
    {
        return array_merge(['page' => ['integer']], [...self::sortRules()]);
    }

    private static function sortRules(): array
    {
        $direction = static function (string $value): string {
            return strtolower($value);
        };

        return [
            'sort' => ['string'],
            'direction' => ['in:'.$direction('ASC').','.$direction('DESC')],
        ];
    }
}
