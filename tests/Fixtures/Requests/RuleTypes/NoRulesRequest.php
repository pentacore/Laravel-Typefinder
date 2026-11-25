<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests\RuleTypes;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue #151: a request without `rules()`.
 */
final class NoRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
