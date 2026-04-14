<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BrokenRequest extends FormRequest
{
    public function rules(): array
    {
        // Simulates the real-world pattern where rules() depends on route
        // context. At generation time there is no route, so $this->route()
        // returns null and the next access explodes.
        return [
            'email' => 'required|email|unique:users,email,'.$this->route('user')->id,
        ];
    }
}
