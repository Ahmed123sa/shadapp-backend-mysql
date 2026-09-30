<?php

namespace App\Http\Requests;

use App\Rules\UniqueLoginEmail;
use Illuminate\Foundation\Http\FormRequest;

class StoreManagerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            // subuser-review-plan.md م٧
            'email' => ['required', 'email', 'unique:users', new UniqueLoginEmail()],
            'password' => 'nullable|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
        ];
    }
}
