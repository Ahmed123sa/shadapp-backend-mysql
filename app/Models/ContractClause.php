<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ContractClause extends Model
{
    use HasFactory;

    protected $fillable = ['contract_id', 'content', 'type', 'sort_order'];

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
