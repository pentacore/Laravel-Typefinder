<?php

declare(strict_types=1);

namespace Tests\Fixtures\AbstractRequests;

use Illuminate\Foundation\Http\FormRequest;

/** An abstract form request is skipped silently (#172). */
abstract class BaseRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['name' => ['required', 'string']];
    }
}
