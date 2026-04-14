<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnrecoverableRequest extends FormRequest
{
    public function rules(): array
    {
        // Typed local assignment that the null-safe proxy can't rescue:
        // strlen() requires string|Stringable, and rejects a proxy cast to
        // int. More importantly, this throws regardless of route/user state.
        throw new \RuntimeException('rules() cannot be evaluated statically');
    }
}
