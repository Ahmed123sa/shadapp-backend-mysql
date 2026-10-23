<?php

namespace App\Listeners;

use App\Events\PaymentCreated;
use App\Events\PaymentReviewed;
use App\Mail\PaymentCreatedMail;
use App\Mail\PaymentApprovedMail;
use App\Models\User;
use App\Notifications\PaymentCreatedNotification;
use App\Notifications\PaymentReviewedNotification;
use App\Notifications\PaymentReviewedStaffNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentEmailNotification
{
    public function handlePaymentCreated(PaymentCreated $event): void
    {
        $payment = $event->payment;
        $manager = $payment->workspace?->manager;
        // Defensive: structurally a deactivated manager should never own a
        // workspace (deactivation requires zero managed clients first, and
        // only active managers can receive a transferred client), but
        // guarding here too means a future violation of that invariant
        // fails safe — a deactivated account simply stays silent — instead
        // of emailing/notifying someone who's been told they're locked out.
        if ($manager && !$manager->isActive()) {
            $manager = null;
        }

        $admins = User::where('role', User::ROLE_SUPER_ADMIN)->get();

        $recipients = collect();
        if ($manager) {
            $recipients->push($manager);
        }
        foreach ($admins as $admin) {
            if ($admin->id !== $manager?->id) {
                $recipients->push($admin);
            }
        }

        // Accountants are told in-app and on the phone — no e-mail for them yet.
        foreach (User::where('role', User::ROLE_ACCOUNTANT)->active()->get() as $accountant) {
            $recipients->push($accountant);
        }

        if ($manager?->email) {
            try {
                Mail::to($manager->email)->send(new PaymentCreatedMail($payment));
            } catch (\Exception $e) {
                Log::warning('Failed to send payment created email: ' . $e->getMessage());
            }
        }

        foreach ($recipients as $user) {
            try {
                $user->notify(new PaymentCreatedNotification($payment));
            } catch (\Exception $e) {
                Log::warning('Failed to send payment created notification: ' . $e->getMessage());
            }
        }
    }

    public function handlePaymentReviewed(PaymentReviewed $event): void
    {
        $payment = $event->payment;
        $client = $payment->client;

        if ($event->action === 'approved' && $client?->email) {
            try {
                Mail::to($client->email)->send(new PaymentApprovedMail($payment));
            } catch (\Exception $e) {
                Log::warning('Failed to send payment review email: ' . $e->getMessage());
            }
        }

        $this->notifyCompanySide($payment, $event->action);

        if ($client) {
            try {
                $activated = $event->action === 'approved' && $event->wasWorkspaceActivated;
                $client->notify(new PaymentReviewedNotification($payment, $event->action, $activated));
            } catch (\Exception $e) {
                Log::warning('Failed to send payment reviewed notification: ' . $e->getMessage());
            }
        }
    }

    /**
     * The other reviewers and the client's manager learn the outcome, so their
     * pending lists refresh. Whoever made the decision is not told about it.
     */
    private function notifyCompanySide($payment, string $action): void
    {
        $reviewerId = $payment->reviewed_by;
        $recipients = User::where('role', User::ROLE_ACCOUNTANT)->active()->get();

        // The manager hears about it when the accountant decided (the super
        // admin's own decisions already show on the manager's screens as before).
        $reviewer = $reviewerId ? User::find($reviewerId) : null;
        $manager = $payment->workspace?->manager;
        if ($reviewer?->isAccountant() && $manager && $manager->isActive()) {
            $recipients->push($manager);
        }

        foreach ($recipients->unique('id') as $user) {
            if ($reviewerId && (int) $user->id === (int) $reviewerId) {
                continue;
            }
            try {
                $user->notify(new PaymentReviewedStaffNotification($payment, $action));
            } catch (\Exception $e) {
                Log::warning('Failed to send payment reviewed staff notification: ' . $e->getMessage());
            }
        }
    }
}
