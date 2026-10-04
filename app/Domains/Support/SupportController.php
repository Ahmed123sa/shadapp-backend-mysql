<?php

namespace App\Domains\Support;

use App\Mail\SupportRequestMail;
use App\Models\Client;
use App\Models\SubUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * POST /api/support — the public contact form behind the Support URL that
 * App Store Review requires. No authentication.
 *
 * Every message goes to the company support address. If the sender's email
 * belongs to a client or sub-user, their account manager is copied too.
 * The response is identical whether or not the sender is a known account, so
 * the form cannot be used to probe which emails are registered.
 */
class SupportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:190',
            'subject' => 'nullable|string|max:150',
            'message' => 'required|string|min:10|max:5000',
            // Honeypot: real visitors never see or fill this field.
            'website' => 'nullable|string|max:0',
        ]);

        $mail = new SupportRequestMail(
            $data['name'],
            $data['email'],
            $data['subject'] ?: 'Support request',
            $data['message'],
        );

        $pending = Mail::to(config('support.email'));
        if ($managerEmail = $this->managerEmailFor($data['email'])) {
            $pending->cc($managerEmail);
        }

        try {
            $pending->send($mail);
        } catch (\Throwable $e) {
            Log::error('Support form mail failed', ['error' => $e->getMessage()]);

            return response()->json(['message' => 'تعذّر إرسال رسالتك الآن. حاول مرة أخرى بعد قليل.'], 500);
        }

        return response()->json(['message' => 'تم إرسال رسالتك. سنرد عليك قريبًا.']);
    }

    /**
     * The usable address of the active account manager responsible for the
     * client (or sub-user's client) with this email, or null.
     */
    private function managerEmailFor(string $email): ?string
    {
        $email = strtolower($email);

        $client = Client::whereRaw('LOWER(email) = ?', [$email])->first()
            ?? SubUser::whereRaw('LOWER(email) = ?', [$email])->first()?->client;

        $manager = $client?->manager;
        if (! $manager || ! $manager->is_active) {
            return null;
        }

        $address = $manager->official_email ?: $manager->email;

        return ($address && ! str_ends_with($address, '@deleted.invalid')) ? $address : null;
    }
}
