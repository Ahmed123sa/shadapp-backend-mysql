<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('client'));
    }

    public function rules(): array
    {
        return [
            'company_name' => 'string|max:255',
            'contact_person' => 'string|max:255',
            'email' => 'sometimes|email|unique:clients,email,' . $this->route('client')->id,
            'phone' => 'string|max:20',
            'password' => 'nullable|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'country' => 'nullable|string|max:100',
            'industry' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
            'address' => 'nullable|string|max:1000',
            'maps_url' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'status' => 'in:active,inactive,blocked',
            'client_type' => 'nullable|string|in:business,individual',
        ];
    }
}
