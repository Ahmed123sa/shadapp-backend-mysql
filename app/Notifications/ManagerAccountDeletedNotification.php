<?php

namespace App\Notifications;

/**
 * Sent to every super admin when an account manager deletes their own
 * account while still owning clients — those clients now point at a
 * deactivated manager and need to be transferred to someone else.
 *
 * Holds the manager's display name and client count as plain values (not the
 * model): by the time this is delivered the account has been scrubbed, so a
 * model lookup would only show "Deleted account".
 */
class ManagerAccountDeletedNotification extends BaseNotification
{
    public function __construct(
        public string $managerName,
        public int $clientCount,
    ) {
    }

    private function body(): string
    {
        return 'قام مدير الحساب ' . $this->managerName . ' بحذف حسابه، وكان مسؤولًا عن '
            . $this->clientCount . ' عميل. حوّل العملاء لمدير تاني.';
    }

    private function bodyEn(): string
    {
        return 'Account manager ' . $this->managerName . ' deleted their account and was responsible for '
            . $this->clientCount . ' client(s). Reassign the clients to another manager.';
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'manager_account_deleted',
            'title' => 'حذف حساب مدير',
            'message' => $this->body(),
            'title_en' => 'Manager account deleted',
            'message_en' => $this->bodyEn(),
            'client_count' => $this->clientCount,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'حذف حساب مدير',
            'body' => $this->body(),
            'title_en' => 'Manager account deleted',
            'body_en' => $this->bodyEn(),
            'data' => [
                'type' => 'manager_account_deleted',
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
