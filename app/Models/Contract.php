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
        'workspace_id', 'title', 'contract_type', 'status', 'edit_reason', 'value', 'currency', 'tax_percentage', 'start_date', 'end_date',
        'pdf_url', 'client_signed_at', 'client_signature_data', 'company_signed_at', 'company_signature_data', 'company_signature_type',
        'archived_at', 'created_by',
    ];

    protected $appends = ['tax_amount', 'total_with_tax'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'currency' => 'string',
            'tax_percentage' => 'float',
            'client_signed_at' => 'datetime',
            'company_signed_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * The tax % this contract is priced with. Individuals pay none. Once the
     * client has approved, the % saved on the contract wins; before that the
     * contract follows the client's current %. Falls back to the global
     * setting for a business client that has none of its own.
     */
    public function effectiveTaxPercentage(bool $ignoreLock = false): float
    {
        $client = $this->workspace?->client;
        if (! $client || $client->client_type !== 'business') {
            return 0.0;
        }
        $locked = $ignoreLock ? null : ($this->attributes['tax_percentage'] ?? null);
        if ($locked !== null) {
            return (float) $locked;
        }
        if ($client->tax_percentage !== null) {
            return (float) $client->tax_percentage;
        }
        try {
            return (float) \App\Models\SystemSetting::getValue('corporate_tax_percentage', 0);
        } catch (\Exception $e) {
            return 0.0;
        }
    }

    public function getTaxPercentageAttribute($value): float
    {
        return $this->effectiveTaxPercentage();
    }

    public function getTaxAmountAttribute(): float
    {
        return round((float) $this->value * $this->effectiveTaxPercentage() / 100, 2);
    }

    public function getTotalWithTaxAttribute(): float
    {
        return round((float) $this->value + $this->tax_amount, 2);
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

    /**
     * Signs the stored URL fresh on every read — see App\Support\FileUrl.
     * ensureFreshPdf()/CleanupContractPdfs/RegenerateContractPdfs all only
     * parse the filename out of this value, which a signed URL still
     * preserves (the signature lives in the query string).
     */
    public function getPdfUrlAttribute($value): ?string
    {
        return \App\Support\FileUrl::sign($value);
    }
}
