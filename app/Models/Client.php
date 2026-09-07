<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Client extends Authenticatable
{
    use HasFactory, Notifiable, HasApiTokens, Authorizable;

    protected $fillable = [
        'company_name', 'contact_person', 'email', 'phone', 'password',
        'manager_id', 'status', 'notes', 'country', 'industry', 'client_type',
        'contract_value', 'payment_status', 'signature_data', 'signed_at',
        'avatar_url', 'date_of_birth',
        'latitude', 'longitude', 'location_address', 'location_updated_at', 'location_updated_by_ip',
        'address', 'maps_url',
    ];

    protected $hidden = ['password'];

    protected $appends = ['name'];

    protected static function booted(): void
    {
        // `uuid` is deliberately not in $fillable — it's never something a
        // request should be able to set or overwrite, only something the
        // model assigns itself the moment a row is created.
        static::creating(function (Client $client) {
            if (! $client->uuid) {
                $client->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * The dashboard shows this client's uuid in the browser URL instead of
     * its numeric id (see the 2026_09_07 migration for why). Everything that
     * already links or calls the API with the numeric id — the mobile app,
     * other backend code, existing dashboard links that haven't been updated
     * yet — must keep working unchanged, so this accepts either form rather
     * than switching the route key entirely.
     */
    public function resolveRouteBinding($value, $field = null): ?self
    {
        if ($field !== null) {
            return parent::resolveRouteBinding($value, $field);
        }

        if (is_string($value) && Str::isUuid($value)) {
            return $this->where('uuid', $value)->first();
        }

        return parent::resolveRouteBinding($value, $field);
    }

    /**
     * Overrides the framework default, which sends an English message with a
     * link built from APP_URL — i.e. pointing at this API, not at the
     * dashboard the client actually needs to open. The 'client' account type
     * also tells the reset page which broker to submit against.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new \App\Notifications\ResetPasswordNotification($token, 'client'));
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'contract_value' => 'decimal:2',
            'signed_at' => 'datetime',
            'date_of_birth' => 'date',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'location_updated_at' => 'datetime',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function workspace(): HasOne
    {
        return $this->hasOne(Workspace::class);
    }

    public function subUsers(): HasMany
    {
        return $this->hasMany(SubUser::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function getNameAttribute(): ?string
    {
        return $this->contact_person;
    }

    /**
     * Signs the stored URL fresh on every read — see App\Support\FileUrl.
     *
     * signature_data is deliberately NOT given the same treatment: it's
     * copied verbatim into Contract::client_signature_data / Approval::signature
     * at approval time (ContractController::clientAction/companyApprove,
     * ApprovalController::respond) and persisted there indefinitely. Signing
     * it here would bake an expiry into those permanent snapshots. Leaving it
     * unsigned means an uploaded (non-drawn) signature image's preview will
     * 404 through the now-authenticated /files route — a known, narrow gap.
     */
    public function getAvatarUrlAttribute($value): ?string
    {
        return \App\Support\FileUrl::sign($value);
    }
}
