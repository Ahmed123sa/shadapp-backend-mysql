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
        'workspace_id', 'title', 'contract_type', 'status', 'edit_reason', 'value', 'currency', 'tax_percentage', 'ads_percentage', 'conversion_percentage', 'tax_excluded', 'start_date', 'end_date',
        'pdf_url', 'client_signed_at', 'client_signature_data', 'company_signed_at', 'company_signature_data', 'company_signature_type',
        'archived_at', 'created_by',
    ];

    protected $appends = ['tax_amount', 'total_with_tax', 'ads_amount', 'ads_budget', 'conversion_amount', 'total_due'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'currency' => 'string',
            'tax_percentage' => 'float',
            'ads_percentage' => 'float',
            'conversion_percentage' => 'float',
            'tax_excluded' => 'boolean',
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

    /**
     * What the tax is charged on. A normal contract: the whole value. An ads
     * contract: only the agency's own share (the management fee) — the ad
     * budget inside the value is not the agency's income, so it carries no tax.
     */
    public function taxableAmount(): float
    {
        return ($this->attributes['ads_percentage'] ?? null) === null
            ? (float) $this->value
            : (float) $this->ads_amount;
    }

    public function getTaxAmountAttribute(): float
    {
        if ($this->attributes['tax_excluded'] ?? false) {
            return 0.0;
        }

        return round($this->taxableAmount() * $this->effectiveTaxPercentage() / 100, 2);
    }

    public function getTotalWithTaxAttribute(): float
    {
        return round((float) $this->value + $this->tax_amount, 2);
    }

    /**
     * Ads contracts: the agency's management fee is a share of the contract
     * value, not on top of it. These two split the value; the value itself
     * never changes, and the tax is charged on the fee only (taxableAmount).
     * Null for a normal contract.
     */
    public function getAdsAmountAttribute(): ?float
    {
        $pct = $this->attributes['ads_percentage'] ?? null;

        return $pct === null ? null : round((float) $this->value * (float) $pct / 100, 2);
    }

    public function getAdsBudgetAttribute(): ?float
    {
        $amount = $this->ads_amount;

        return $amount === null ? null : round((float) $this->value - $amount, 2);
    }

    /**
     * Dollar-conversion fee: a share of the ad budget, added on top of the
     * contract value and never taxed. Only an ads contract with a percentage
     * above zero has one; otherwise null.
     */
    public function getConversionAmountAttribute(): ?float
    {
        $pct = (float) ($this->attributes['conversion_percentage'] ?? 0);
        $budget = $this->ads_budget;

        return ($budget === null || $pct <= 0) ? null : round($budget * $pct / 100, 2);
    }

    /** Everything the client owes for this contract: value + conversion fee + tax. */
    public function getTotalDueAttribute(): float
    {
        return round((float) $this->value + (float) ($this->conversion_amount ?? 0) + $this->tax_amount, 2);
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
