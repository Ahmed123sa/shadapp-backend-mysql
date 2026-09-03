<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ChatMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'sender_type', 'sender_id', 'message',
        'type', 'file_url', 'metadata', 'requires_action', 'contract_id', 'approval_id',
        'action_taken', 'action_result', 'responded_at', 'read_at', 'edited_at', 'reply_to_id',
    ];

    protected function casts(): array
    {
        return [
            'requires_action' => 'boolean',
            'action_taken' => 'boolean',
            'responded_at' => 'datetime',
            'read_at' => 'datetime',
            'edited_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function sender()
    {
        return $this->morphTo();
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }

    public function replies()
    {
        return $this->hasMany(ChatMessage::class, 'reply_to_id');
    }

    /**
     * Signs the stored URL fresh on every read — see App\Support\FileUrl.
     */
    public function getFileUrlAttribute($value): ?string
    {
        return \App\Support\FileUrl::sign($value);
    }
}
