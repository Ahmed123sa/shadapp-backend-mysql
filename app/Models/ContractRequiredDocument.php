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
        return $this->hasMany(FileEntry::class, 'contract_required_document_id');
    }
}
