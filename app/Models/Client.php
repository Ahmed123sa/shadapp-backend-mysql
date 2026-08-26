<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\Access\Authorizable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
}
