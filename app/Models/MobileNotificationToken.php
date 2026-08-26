<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class MobileNotificationToken extends Model
{
    protected $fillable = [
        'token',
        'device_type',
        'tokenable_id',
        'tokenable_type',
    ];

    public function tokenable(): MorphTo
    {
        return $this->morphTo();
    }
}
