<?php

namespace App\Http\Requests;

use App\Models\Payment;
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
        ];
    }
}
