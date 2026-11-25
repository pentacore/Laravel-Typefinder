<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * Issue #233: an override acknowledges the branch warning, and a key
 * ending in `?` declares an optional key.
 */
#[TypefinderOverrides(['password?' => 'string', 'nested.note?' => 'string'])]
final class Issue233BranchOverrideRequest extends FormRequest
{
    public function rules(): array
    {
        $rules = ['set_password' => ['boolean'], 'nested.title' => ['required', 'string']];

        if ($this->boolean('set_password')) {
            $rules['password'] = ['required', 'string'];
        }

        return $rules;
    }
}
