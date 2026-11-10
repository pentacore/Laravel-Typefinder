<?php

declare(strict_types=1);

namespace Tests\Fixtures\Ignored\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderIgnore;

#[TypefinderIgnore]
final class IgnoredRequest extends FormRequest
{
    /** @return array<string, string> */
    public function rules(): array
    {
        return ['name' => 'required|string'];
    }
}
