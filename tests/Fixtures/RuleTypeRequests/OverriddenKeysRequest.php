<?php

declare(strict_types=1);

namespace Tests\Fixtures\RuleTypeRequests;

use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * Issue #144: `#[TypefinderOverrides]` adds keys that match no rule, and
 * dotted keys reach nested fields.
 */
#[TypefinderOverrides([
    'extra' => 'string',
    'user.name' => 'Foo',
    'meta.source' => "'web' | 'api'",
    'lines.*.sku' => 'Sku',
])]
final class OverriddenKeysRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'user.name' => ['string'],
            'lines' => ['required', 'array'],
            'lines.*.qty' => ['required', 'integer'],
        ];
    }
}
