<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ApprovalCertificate extends Model
{
    use HasFactory;

    protected $fillable = ['approval_id', 'pdf_url', 'generated_at'];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    /**
     * Signs the stored URL fresh on every read — see App\Support\FileUrl.
     */
    public function getPdfUrlAttribute($value): ?string
    {
        return \App\Support\FileUrl::sign($value);
    }
}
