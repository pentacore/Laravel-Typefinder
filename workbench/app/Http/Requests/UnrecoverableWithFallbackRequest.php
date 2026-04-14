<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

#[TypefinderOverrides([
    'name' => 'string',
    'email' => 'string',
])]
class UnrecoverableWithFallbackRequest extends FormRequest
{
    public function rules(): array
    {
        throw new \RuntimeException('rules() cannot be evaluated statically');
    }
}
