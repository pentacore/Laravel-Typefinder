<?php

declare(strict_types=1);

namespace Tests\Fixtures\RuleTypeRequests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Issue #151: `rules()` with an injected parameter.
 */
final class InjectedRulesRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(Repository $config): array
    {
        return ['title' => ['required', 'string']];
    }
}
