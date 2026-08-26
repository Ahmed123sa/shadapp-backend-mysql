<?php

namespace App\Http\Requests;

use App\Models\Client;
use Illuminate\Foundation\Http\FormRequest;

class StoreClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Client::class);
    }

    public function rules(): array
    {
        return [
            'company_name' => 'required|string|max:255',
            'contact_person' => 'required|string|max:255',
            'email' => 'required|email|unique:clients',
            'phone' => 'required|string|max:20',
            'password' => 'nullable|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'contract_value' => 'nullable|numeric|min:0',
            'country' => 'nullable|string|max:100',
            'industry' => 'nullable|string|max:100',
            'client_type' => 'nullable|string|in:business,individual',
            'notes' => 'nullable|string',
            'address' => 'nullable|string|max:1000',
            'maps_url' => 'nullable|string|max:1000',
            'date_of_birth' => 'nullable|date',
            'send_email' => 'boolean',
        ];
    }
}
