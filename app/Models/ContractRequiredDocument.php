<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContractRequiredDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'contract_id', 'name', 'description', 'is_required', 'sort_order',
    ];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function files(): HasMany
    {
        // Only files that live in the same workspace as the contract count.
        // Older rows could point at another workspace's document (the upload
        // endpoint used to accept any id); they must never satisfy a review.
        return $this->hasMany(FileEntry::class, 'contract_required_document_id')
            ->whereExists(function ($q) {
                $q->selectRaw('1')
                    ->from('contract_required_documents as crd')
                    ->join('contracts as c', 'c.id', '=', 'crd.contract_id')
                    ->whereColumn('crd.id', 'files.contract_required_document_id')
                    ->whereColumn('c.workspace_id', 'files.workspace_id');
            });
    }
}
