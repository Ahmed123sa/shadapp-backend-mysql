<?php

namespace App\Http\Requests;

use App\Models\Payment;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class ReviewPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('review', $this->route('payment'));
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:approved,rejected',
            'notes' => 'nullable|string|max:1000',
            'rejection_reason' => 'nullable|string|max:1000',
        ];
    }

    /**
     * A rejection must say why (the client reads it). `notes` is accepted as
     * the reason too, because the apps have always sent it that way.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->input('action') !== 'rejected') {
                return;
            }

            $reason = trim((string) ($this->input('rejection_reason') ?? $this->input('notes') ?? ''));
            if (mb_strlen($reason) < 5) {
                $v->errors()->add('rejection_reason', 'اكتب سبب الرفض (5 حروف على الأقل).');
            }
        });
    }
}
