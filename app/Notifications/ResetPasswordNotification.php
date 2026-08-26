<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password reset email for both staff Users and Clients.
 *
 * The reset link points at the dashboard (a normal web page) rather than at a
 * deep link into the mobile app. That is a deliberate simplification: deep
 * links need Universal Links / App Links, which require verification files
 * hosted on the production domain and cannot be exercised against a local
 * dev server at all. A client who taps the link on their phone resets in the
 * browser, then signs back into the app with the new password.
 *
 * `$accountType` selects the dashboard route so the reset form posts to the
 * matching broker — a token issued for a Client is meaningless to the staff
 * broker and vice versa, since they use separate token tables.
 */
class ResetPasswordNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $token,
        public string $accountType = 'staff',
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $email = $notifiable->getEmailForPasswordReset();
        $url = rtrim(config('app.frontend_url'), '/')
            .'/reset-password?token='.$this->token
            .'&email='.urlencode($email)
            .'&type='.$this->accountType;

        // expire is in minutes, and is what the broker actually enforces —
        // read it rather than hardcoding 60, so the email can't drift out of
        // sync with config/auth.php.
        $broker = $this->accountType === 'client' ? 'clients' : 'users';
        $minutes = config("auth.passwords.{$broker}.expire", 60);

        return (new MailMessage)
            ->subject('إعادة تعيين كلمة المرور - ShadApp')
            ->view('emails.reset-password', [
                'url' => $url,
                'minutes' => $minutes,
            ]);
    }
}
