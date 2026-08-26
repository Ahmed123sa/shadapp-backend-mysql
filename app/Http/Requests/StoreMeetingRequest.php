<?php

namespace App\Http\Requests;

use App\Models\Meeting;
use Illuminate\Foundation\Http\FormRequest;

class StoreMeetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Meeting::class);
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'scheduled_at' => 'required|date',
            'duration_minutes' => 'integer|min:15|max:480',
            'contract_id' => 'nullable|exists:contracts,id',
            'approval_id' => 'nullable|exists:approvals,id',
            'notes' => 'nullable|string',
        ];
    }
}
