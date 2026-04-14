<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

#[TypefinderOverrides([
    'attachment' => 'File | null',
    'amount' => 'number',
])]
class StoreInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reference' => ['required', 'string'],
            'attachment' => ['nullable', 'file'],
            'amount' => ['required', 'numeric'],
        ];
    }
}
