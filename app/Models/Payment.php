<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'client_id', 'contract_id', 'amount', 'currency',
        'due_date', 'installment_label', 'requested_by_manager',
        'method_type', 'proof_file', 'proof_file_url', 'status',
        'notes', 'reviewed_by', 'reviewed_at',
    ];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'currency' => 'string',
            'due_date' => 'date',
            'proof_file_url' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
