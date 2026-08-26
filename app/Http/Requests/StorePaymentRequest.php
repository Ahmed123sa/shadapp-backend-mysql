<?php

namespace App\Http\Requests;

use App\Models\Payment;
use App\Support\UploadRules;
use Illuminate\Foundation\Http\FormRequest;

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
            'proof_files' => 'nullable|array',
            'proof_files.*' => UploadRules::proof(required: true),
            'notes' => 'nullable|string',
        ];
    }
}
