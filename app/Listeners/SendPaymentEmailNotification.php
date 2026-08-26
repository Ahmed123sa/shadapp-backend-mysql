<?php

namespace App\Listeners;

use App\Events\PaymentCreated;
use App\Events\PaymentReviewed;
use App\Mail\PaymentCreatedMail;
use App\Mail\PaymentApprovedMail;
use App\Models\User;
use App\Notifications\PaymentCreatedNotification;
use App\Notifications\PaymentReviewedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPaymentEmailNotification
{
    public function handlePaymentCreated(PaymentCreated $event): void
    {
        $payment = $event->payment;
        $manager = $payment->workspace?->manager;

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
        $mail = new PaymentApprovedMail($payment);

        if ($client?->email) {
            try {
                Mail::to($client->email)->send($mail);
            } catch (\Exception $e) {
                Log::warning('Failed to send payment review email: ' . $e->getMessage());
            }
        }

        if ($client) {
            try {
                $workspace = $payment->workspace;
                $activated = $workspace && $workspace->status === 'active';
                $client->notify(new PaymentReviewedNotification($payment, $event->action, $activated));
            } catch (\Exception $e) {
                Log::warning('Failed to send payment reviewed notification: ' . $e->getMessage());
            }
        }
    }
}
