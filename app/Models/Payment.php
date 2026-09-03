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
            // Still needed for writes (Payment::create/update, array -> JSON):
            // a get-mutator only overrides reads, not the set path. See the
            // accessor below for why reads bypass this cast.
            'proof_file_url' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * A get-mutator always takes precedence over a cast for the same key on
     * *read* (Laravel's HasAttributes::transformModelValue checks mutators
     * before casts), so this decodes the raw JSON itself, same as the cast
     * above would, and signs each URL fresh on every read — see
     * App\Support\FileUrl. Writes are unaffected and still go through the
     * cast normally, since no set-mutator is defined here.
     */
    public function getProofFileUrlAttribute($value): ?array
    {
        if ($value === null) {
            return null;
        }
        $urls = is_array($value) ? $value : (json_decode((string) $value, true) ?: []);
        return \App\Support\FileUrl::signMany($urls);
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
