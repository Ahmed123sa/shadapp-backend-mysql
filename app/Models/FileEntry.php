<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FileEntry extends Model
{
    protected $table = 'files';

    use HasFactory;

    protected $fillable = [
        'workspace_id', 'contract_id', 'contract_required_document_id', 'document_definition_id',
        'approval_id',
        'uploaded_by_type', 'uploaded_by_id',
        'file_url', 'name', 'type', 'size', 'status', 'reviewed_by', 'reviewed_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function uploadedBy()
    {
        return $this->morphTo();
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documentDefinition(): BelongsTo
    {
        return $this->belongsTo(DocumentDefinition::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function contractRequiredDocument(): BelongsTo
    {
        return $this->belongsTo(ContractRequiredDocument::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
