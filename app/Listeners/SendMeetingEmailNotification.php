<?php

namespace App\Listeners;

use App\Events\MeetingCreated;
use App\Mail\MeetingScheduledMail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendMeetingEmailNotification
{
    public function handleMeetingCreated(MeetingCreated $event): void
    {
        $meeting = $event->meeting;
        $workspace = $meeting->workspace;
        $client = $workspace?->client;
        $manager = $workspace?->manager;

        // A fresh mailable per recipient: Mail::to()->send() appends to the
        // mailable's recipient list rather than replacing it, so a shared
        // instance would leak earlier recipients into later messages.
        $seen = [];

        foreach ([$client?->email, $manager?->email] as $address) {
            $address = trim((string) $address);

            if ($address === '' || isset($seen[mb_strtolower($address)])) {
                continue;
            }

            $seen[mb_strtolower($address)] = true;

            try {
                Mail::to($address)->send(new MeetingScheduledMail($meeting));
            } catch (\Exception $e) {
                Log::warning("Failed to send meeting email to {$address}: " . $e->getMessage());
            }
        }
    }
}
