<?php

namespace App\Listeners;

use App\Events\ApprovalResponded;
use App\Mail\ApprovalCertificateMail;
use App\Notifications\ApprovalRespondedNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendApprovalEmailNotification
{
    public function handleApprovalResponded(ApprovalResponded $event): void
    {
        $approval = $event->approval;
        $workspace = $approval->workspace;
        $requester = $approval->requester;
        $client = $workspace?->client;

        // A fresh mailable per recipient: Mail::to()->send() appends to the
        // mailable's recipient list rather than replacing it, so a shared
        // instance would leak earlier recipients into later messages.
        $seen = [];

        foreach ([$requester?->email, $client?->email] as $address) {
            $address = trim((string) $address);

            if ($address === '' || isset($seen[mb_strtolower($address)])) {
                continue;
            }

            $seen[mb_strtolower($address)] = true;

            try {
                Mail::to($address)->send(new ApprovalCertificateMail($approval));
            } catch (\Exception $e) {
                Log::warning("Failed to send approval email to {$address}: " . $e->getMessage());
            }
        }

        if ($requester) {
            try {
                $requester->notify(new ApprovalRespondedNotification($approval));
            } catch (\Exception $e) {
                Log::warning('Failed to send approval responded notification: ' . $e->getMessage());
            }
        }
    }
}
