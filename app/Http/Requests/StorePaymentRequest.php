<?php

namespace App\Http\Requests;

use App\Models\Payment;
use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Payment::class);
    }

    public function rules(): array
    {
        return [
            'amount' => 'required|numeric|min:0',
            'method_type' => 'required|string',
            'currency' => 'nullable|string|max:10',
            'contract_id' => [
                'nullable',
                'integer',
                Rule::exists('contracts', 'id')->where(fn ($q) => $q->where('workspace_id', $this->route('workspace')->id)),
            ],
            // The proof is what the reviewer decides on: a payment without one is not reviewable.
            'proof_files' => 'required|array|min:1',
            'proof_files.*' => UploadRules::proof(required: true),
            'notes' => 'nullable|string',
        ];
    }

    public function messages(): array
    {
        return [
            'proof_files.required' => 'ارفع إثبات الدفع (صورة أو PDF).',
            'proof_files.min' => 'ارفع إثبات الدفع (صورة أو PDF).',
        ];
    }
}
