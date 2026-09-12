<?php

namespace App\Listeners;

use App\Events\ContractSent;
use App\Events\ContractClientApproved;
use App\Events\ContractCompanyApproved;
use App\Events\ContractCompleted;
use App\Mail\ContractSentMail;
use App\Mail\ContractClientApprovedMail;
use App\Mail\ContractCompanyApprovedMail;
use App\Mail\ContractCompletedMail;
use App\Models\User;
use App\Notifications\ContractClientApprovedNotification;
use App\Notifications\ContractCompanyApprovedNotification;
use App\Notifications\ContractReceivedNotification;
use App\Notifications\ContractSentNotification;
use App\Notifications\ContractCompletedNotification;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendContractEmailNotification
{
    private function getOfficialEmails(): array
    {
        return User::where('role', User::ROLE_SUPER_ADMIN)
            ->whereNotNull('official_email')
            ->pluck('official_email')
            ->toArray();
    }

    /**
     * Send a mailable to a set of addresses, skipping blanks and duplicates.
     *
     * The same physical inbox can legitimately appear more than once in a
     * recipient list — a super admin's `official_email` may be the same as the
     * account manager's login email, for instance. Without a de-dupe pass that
     * person receives the identical message two or three times. Comparison is
     * case-insensitive and trims whitespace, since addresses are entered by
     * hand in the dashboard.
     *
     * $makeMailable is a factory, not a ready-made instance, on purpose:
     * Mail::to()->send() *appends* to the mailable's recipient list rather
     * than replacing it, so reusing one instance across sends would leak
     * earlier recipients into later messages — the exact duplication this
     * method exists to prevent.
     *
     * @param array<int, string|null> $addresses
     * @param callable(): Mailable    $makeMailable
     */
    private function mailUnique(array $addresses, callable $makeMailable, string $context): void
    {
        $seen = [];

        foreach ($addresses as $address) {
            $address = trim((string) $address);

            if ($address === '') {
                continue;
            }

            $key = mb_strtolower($address);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;

            try {
                Mail::to($address)->send($makeMailable());
            } catch (\Exception $e) {
                Log::warning("Failed to send {$context} email to {$address}: " . $e->getMessage());
            }
        }
    }

    public function handleContractSent(ContractSent $event): void
    {
        $contract = $event->contract;
        $client = $contract->workspace->client;
        $manager = $contract->creator;

        $this->mailUnique(
            array_merge(
                [$client->email, $manager?->email],
                $this->getOfficialEmails(),
            ),
            fn () => new ContractSentMail($contract),
            'contract sent',
        );

        if ($manager) {
            try {
                $manager->notify(new ContractSentNotification($contract));
            } catch (\Exception $e) {
                Log::warning('Failed to send contract sent notification: ' . $e->getMessage());
            }
        }

        // The client only ever got an email at this step, so a client sitting
        // in the onboarding screen had no in-app signal that the contract they
        // are waiting on had arrived — and this is the one onboarding step that
        // can't progress without them acting. ContractSentNotification above is
        // worded from the company's side, hence a separate client-facing one.
        try {
            $client->notify(new ContractReceivedNotification($contract));
        } catch (\Exception $e) {
            Log::warning('Failed to send contract received notification to client: ' . $e->getMessage());
        }
    }

    public function handleClientApproved(ContractClientApproved $event): void
    {
        $contract = $event->contract;
        $manager = $contract->creator;

        try {
            $pdfPath = app(\App\Services\ContractPdfService::class)->generateWithClientSignature($contract);
        } catch (\Exception $e) {
            Log::warning('Failed to generate contract PDF: ' . $e->getMessage());
            $pdfPath = null;
        }

        $admins = User::where('role', User::ROLE_SUPER_ADMIN)->get();

        // Staff who should get an in-app/push notification, not just an email.
        $notifiables = collect();
        if ($manager) {
            $notifiables->push($manager);
        }
        foreach ($admins as $admin) {
            if ($admin->id !== $manager?->id) {
                $notifiables->push($admin);
            }
        }

        $this->mailUnique(
            array_merge(
                [$manager?->email],
                $admins->pluck('email')->all(),
                $this->getOfficialEmails(),
            ),
            fn () => new ContractClientApprovedMail($contract, $pdfPath),
            'client approved',
        );

        foreach ($notifiables as $user) {
            try {
                $user->notify(new ContractClientApprovedNotification($contract));
            } catch (\Exception $e) {
                Log::warning('Failed to send notification: ' . $e->getMessage());
            }
        }
    }

    public function handleCompanyApproved(ContractCompanyApproved $event): void
    {
        $contract = $event->contract;
        $client = $contract->workspace->client;

        try {
            $pdfPath = app(\App\Services\ContractPdfService::class)->generateWithBothSignatures($contract);
        } catch (\Exception $e) {
            Log::warning('Failed to generate final contract PDF: ' . $e->getMessage());
            $pdfPath = null;
        }

        $this->mailUnique(
            array_merge(
                [$client->email],
                $this->getOfficialEmails(),
            ),
            fn () => new ContractCompanyApprovedMail($contract, $pdfPath),
            'company approved',
        );

        // Skip database notification if triggered by payment review (PaymentReviewed already notifies the client)
        if ($event->fromPaymentReview) {
            Log::info('Skipping client notification — came from payment review');
            return;
        }

        try {
            $client->notify(new ContractCompanyApprovedNotification($contract));
        } catch (\Exception $e) {
            Log::warning('Failed to send notification to client: ' . $e->getMessage());
        }
    }

    public function handleCompleted(ContractCompleted $event): void
    {
        $contract = $event->contract;
        $client = $contract->workspace->client;
        $manager = $contract->creator;

        $this->mailUnique(
            [$client->email, $manager?->email],
            fn () => new ContractCompletedMail($contract),
            'contract completed',
        );

        $recipients = collect();
        if ($manager) {
            $recipients->push($manager);
        }

        $admins = User::where('role', User::ROLE_SUPER_ADMIN)->get();
        foreach ($admins as $admin) {
            if ($admin->id !== $manager?->id) {
                $recipients->push($admin);
            }
        }

        foreach ($recipients as $user) {
            try {
                $user->notify(new ContractCompletedNotification($contract));
            } catch (\Exception $e) {
                Log::warning('Failed to send contract completed notification: ' . $e->getMessage());
            }
        }
    }
}
