<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A rejected sign-in. See the create_login_attempts_table migration for why
 * these live outside audit_logs.
 *
 * Rows are immutable once written — there is no such thing as amending a
 * past attempt — so UPDATED_AT is switched off and the table carries only
 * created_at.
 */
class LoginAttempt extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    /** No account exists with the submitted email. */
    public const REASON_UNKNOWN_EMAIL = 'unknown_email';

    /** The account exists; the password did not match. */
    public const REASON_WRONG_PASSWORD = 'wrong_password';

    /** A staff account that has been deactivated. */
    public const REASON_ACCOUNT_INACTIVE = 'account_inactive';

    /** A client (or a sub-user of one) whose account is archived. */
    public const REASON_CLIENT_ARCHIVED = 'client_archived';

    /** POST /auth/login */
    public const ENDPOINT_STAFF = 'staff';

    /** POST /auth/client/login */
    public const ENDPOINT_CLIENT = 'client';

    protected $fillable = ['email', 'ip_address', 'endpoint', 'reason'];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
