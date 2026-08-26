<?php

namespace App\Http\Requests;

use App\Models\Approval;
use Illuminate\Foundation\Http\FormRequest;

class RespondApprovalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('respond', $this->route('approval'));
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:approved,edit_requested',
            'reason' => 'nullable|string|max:1000',
        ];
    }
}
