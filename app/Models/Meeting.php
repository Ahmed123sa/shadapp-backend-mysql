<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'contract_id', 'approval_id', 'title', 'zoom_meeting_id', 'link', 'passcode',
        'scheduled_at', 'duration_minutes', 'status', 'notes', 'recording_url', 'created_by', 'ended_at',
        'zoom_started_at', 'zoom_ended_at', 'zoom_attendees',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'ended_at' => 'datetime',
            'zoom_started_at' => 'datetime',
            'zoom_ended_at' => 'datetime',
            'zoom_attendees' => 'array',
            'duration_minutes' => 'integer',
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

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }
}
