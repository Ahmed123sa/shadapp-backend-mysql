<?php

namespace App\Notifications;

use App\Models\Contract;

/**
 * Sent to the *client* when a manager sends them a contract.
 *
 * Distinct from ContractSentNotification, which goes to the manager and is
 * worded from the company's side ("تم إرسال عقد..."). The client needs the
 * opposite framing — something arrived and is waiting on them.
 *
 * Before this existed the client got only an email at this step, so a client
 * sitting in the onboarding screen waiting for their contract had nothing in
 * the app telling them it had arrived. That is the one point in onboarding
 * where the client has to act for the flow to continue.
 *
 * The `type` starts with "contract" on purpose: the mobile app's
 * fcmTabIndex() routes any contract-prefixed type to the client's contracts
 * tab, so tapping the push lands on the contract itself with no routing
 * changes needed.
 */
class ContractReceivedNotification extends BaseNotification
{
    public Contract $contract;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'contract_received',
            'contract_id' => $this->contract->id,
            'title' => $this->contract->title,
            'message' => "وصلك عقد جديد بانتظار مراجعتك: {$this->contract->title}",
            'workspace_id' => $this->contract->workspace_id,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => 'تم استلام العقد',
            'body' => "عقد {$this->contract->title} بانتظار مراجعتك وموافقتك",
            'data' => [
                'type' => 'contract.received',
                'id' => (string) $this->contract->id,
                'workspace_id' => (string) $this->contract->workspace_id,
            ],
        ];
    }

    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}
