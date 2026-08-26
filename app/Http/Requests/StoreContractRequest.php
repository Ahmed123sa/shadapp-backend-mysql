<?php

namespace App\Http\Requests;

use App\Models\Contract;
use Illuminate\Foundation\Http\FormRequest;

class StoreContractRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Contract::class);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'contract_type' => 'nullable|string|in:main,additional',
            'value' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:10',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
            'clauses' => 'nullable|array',
            'clauses.*.content' => 'required|string',
            'clauses.*.type' => 'in:fixed,optional,custom',
            'required_documents' => 'nullable|array',
            'required_documents.*.name' => 'required|string|max:255',
            'required_documents.*.description' => 'nullable|string',
        ];
    }
}
