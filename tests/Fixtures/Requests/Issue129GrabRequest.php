<?php

declare(strict_types=1);

namespace Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Tests\Fixtures\Requests\Concerns\TargetRules;

/**
 * Issue #129: spreads the same helper without a tag, so its keys stay in the body.
 */
final class Issue129GrabRequest extends FormRequest
{
    use TargetRules;

    public function rules(): array
    {
        return [...$this->targetRules(), 'guid' => ['required', 'string']];
    }
}
