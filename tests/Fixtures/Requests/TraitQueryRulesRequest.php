<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Tests\Fixtures\Requests\Concerns\HasQueryRules;

/**
 * Takes `rules()`, and its `@query` tags, from a trait.
 */
final class TraitQueryRulesRequest extends FormRequest
{
    use HasQueryRules;
}
