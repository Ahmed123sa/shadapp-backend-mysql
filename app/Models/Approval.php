<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Approval extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'approvable_type', 'approvable_id', 'title', 'description',
        'status', 'client_action', 'signature', 'reference_no', 'requested_by',
        'responded_at', 'reason',
    ];

    protected function casts(): array
    {
        return ['responded_at' => 'datetime'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function approvable()
    {
        return $this->morphTo();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(ApprovalCertificate::class);
    }

    public function chatMessage(): HasOne
    {
        return $this->hasOne(ChatMessage::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(FileEntry::class);
    }
}
