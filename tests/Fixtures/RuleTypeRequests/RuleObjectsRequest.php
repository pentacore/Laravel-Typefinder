<?php

declare(strict_types=1);

namespace Tests\Fixtures\RuleTypeRequests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\Rules\Password;

/**
 * Issues #152 and #153: Laravel's rule objects and the file rules.
 */
final class RuleObjectsRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'avatar' => ['required', File::image()],
            'doc' => ['required', File::types(['pdf'])],
            'born' => ['required', Rule::date()],
            'amount' => ['required', Rule::numeric()],
            'email' => ['required', Rule::email()],
            'password' => ['required', Password::defaults()],
            'upload' => ['required', 'mimes:pdf,jpg'],
            'types' => ['required', 'mimetypes:application/pdf'],
            'ext' => ['required', 'extensions:pdf'],
            'photo' => ['required', 'dimensions:min_width=100'],
            'tuple' => [['max', 10], 'string'],
        ];
    }
}
