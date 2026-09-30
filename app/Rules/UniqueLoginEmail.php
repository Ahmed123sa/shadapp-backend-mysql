<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * An email that signs in anywhere (staff, client or sub-user) must be unique
 * across all three tables — AuthController::clientLogin() tries clients
 * before sub_users, so a shared address silently logs one of them in as the
 * other. See subuser-review-plan.md م٧.
 */
class UniqueLoginEmail implements ValidationRule
{
    public function __construct(
        private ?string $ignoreTable = null,
        private ?int $ignoreId = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $email = strtolower(trim((string) $value));

        foreach (['users', 'clients', 'sub_users'] as $table) {
            $q = DB::table($table)->whereRaw('LOWER(email) = ?', [$email]);
            if ($table === $this->ignoreTable && $this->ignoreId) {
                $q->where('id', '!=', $this->ignoreId);
            }
            if ($q->exists()) {
                $fail('الإيميل ده مستخدم قبل كده.');
                return;
            }
        }
    }
}
