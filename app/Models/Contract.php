<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Contract extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'title', 'contract_type', 'status', 'edit_reason', 'value', 'currency', 'start_date', 'end_date',
        'pdf_url', 'client_signed_at', 'client_signature_data', 'company_signed_at', 'company_signature_data', 'company_signature_type',
        'archived_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'currency' => 'string',
            'client_signed_at' => 'datetime',
            'company_signed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function clauses(): HasMany
    {
        return $this->hasMany(ContractClause::class)->orderBy('sort_order');
    }

    public function requiredDocuments(): HasMany
    {
        return $this->hasMany(ContractRequiredDocument::class)->orderBy('sort_order');
    }
}
