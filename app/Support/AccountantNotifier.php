<?php

namespace App\Support;

use App\Models\Payment;
use App\Models\User;
use App\Notifications\PaymentOverdueStaffNotification;
use Illuminate\Support\Facades\Log;

class AccountantNotifier
{
    /** Tells every active accountant that [$payment] just became overdue. Never throws. */
    public static function paymentOverdue(Payment $payment): void
    {
        try {
            foreach (User::where('role', User::ROLE_ACCOUNTANT)->active()->get() as $accountant) {
                $accountant->notify(new PaymentOverdueStaffNotification($payment));
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to notify accountants of an overdue payment: ' . $e->getMessage());
        }
    }
}
