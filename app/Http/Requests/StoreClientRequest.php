<?php

namespace App\Http\Requests;

use App\Models\Client;
use App\Rules\UniqueLoginEmail;
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
            // subuser-review-plan.md م٧ — unique:clients alone let a new
            // client share an email with an existing sub-user or staff
            // account, and login lookups aren't scoped by intended role.
            'email' => ['required', 'email', 'unique:clients', new UniqueLoginEmail()],
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
