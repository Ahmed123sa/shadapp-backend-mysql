<?php

namespace App\Notifications;

use App\Models\Meeting;

class MeetingReminderNotification extends BaseNotification
{
    public Meeting $meeting;

    /**
     * 'reminder' = the half-hour reminder (meetings:send-reminders);
     * 'soon' = starts in 5 minutes, 'started' = starting now
     * (meetings:send-alerts). Same notification type for the apps, only the
     * wording differs.
     *
     * The super admin also gets 'created' (a new meeting), 'in15' (starts in
     * 15 minutes) and 'cancelled' (App\Support\SuperAdminMeetingNotifier);
     * those name the client, and $by is who made or cancelled it.
     */
    public string $kind;

    public ?string $by;

    public function __construct(Meeting $meeting, string $kind = 'reminder', ?string $by = null)
    {
        $this->meeting = $meeting;
        $this->kind = $kind;
        $this->by = $by;
    }

    private function clientName(): string
    {
        return (string) ($this->meeting->workspace?->client?->company_name ?? '');
    }

    private function when(): string
    {
        return \App\Support\DisplayTime::format($this->meeting->scheduled_at);
    }

    /** The one-line text the apps show (Arabic). */
    private function line(): string
    {
        $title = $this->meeting->title;
        $client = $this->clientName() !== '' ? " مع {$this->clientName()}" : '';
        $by = $this->by ? " (بواسطة {$this->by})" : '';

        return match ($this->kind) {
            'created' => "اجتماع جديد: {$title}{$client} في {$this->when()}{$by}",
            'in15' => "اجتماع {$title}{$client} هيبدأ كمان 15 دقيقة",
            'cancelled' => "اتلغى اجتماع: {$title}{$client}{$by}",
            default => "{$this->heading()}: {$title}",
        };
    }

    /** Same line in English. */
    private function lineEn(): string
    {
        $title = $this->meeting->title;
        $client = $this->clientName() !== '' ? " with {$this->clientName()}" : '';
        $by = $this->by ? " (by {$this->by})" : '';

        return match ($this->kind) {
            'created' => "New meeting: {$title}{$client} at {$this->when()}{$by}",
            'in15' => "Meeting {$title}{$client} starts in 15 minutes",
            'cancelled' => "Meeting cancelled: {$title}{$client}{$by}",
            default => "{$this->headingEn()}: {$title}",
        };
    }

    private function heading(): string
    {
        return match ($this->kind) {
            'soon' => 'الاجتماع هيبدأ كمان 5 دقايق',
            'started' => 'الاجتماع بدأ دلوقتي',
            'created' => 'اجتماع جديد',
            'in15' => 'اجتماع بعد 15 دقيقة',
            'cancelled' => 'اتلغى اجتماع',
            default => 'تذكير باجتماع',
        };
    }

    private function headingEn(): string
    {
        return match ($this->kind) {
            'soon' => 'The meeting starts in 5 minutes',
            'started' => 'The meeting has started',
            'created' => 'New meeting',
            'in15' => 'Meeting in 15 minutes',
            'cancelled' => 'Meeting cancelled',
            default => 'Meeting reminder',
        };
    }

    private function isForSuperAdmin(): bool
    {
        return in_array($this->kind, ['created', 'in15', 'cancelled'], true);
    }

    public function toDatabase($notifiable): array
    {
        return [
            'type' => 'meeting_reminder',
            'meeting_id' => $this->meeting->id,
            // plans/notifications-badges-toasts-plan.md ن3 — a manager
            // viewing GET /notifications never saw this: the filter there
            // only kept notifications resolvable to one of their managed
            // workspaces via workspace_id/contract_id/payment_id/
            // approval_id, and this had none of those.
            'workspace_id' => $this->meeting->workspace_id,
            'client_id' => $this->meeting->workspace?->client_id,
            'title' => $this->meeting->title,
            'scheduled_at' => $this->meeting->scheduled_at,
            'message' => $this->line(),
            'title_en' => $this->meeting->title,
            'message_en' => $this->lineEn(),
            'kind' => $this->kind,
        ];
    }

    public function toFcm($notifiable): array
    {
        return [
            'title' => $this->heading(),
            'title_en' => $this->headingEn(),
            'body_en' => $this->isForSuperAdmin() ? $this->lineEn() : "Meeting {$this->meeting->title} at " . \App\Support\DisplayTime::format($this->meeting->scheduled_at),
            // Plain text can't be converted per reader like the apps do, so
            // it uses the business timezone (App\Support\DisplayTime).
            'body' => $this->isForSuperAdmin() ? $this->line() : "اجتماع {$this->meeting->title} في " . \App\Support\DisplayTime::format($this->meeting->scheduled_at),
            'data' => [
                'type' => 'meeting',
                'id' => (string) $this->meeting->id,
                'workspace_id' => (string) $this->meeting->workspace_id,
                'client_id' => (string) ($this->meeting->workspace?->client_id ?? ''),
            ],
        ];
    }
    public function toBroadcast($notifiable): array
    {
        return $this->toDatabase($notifiable);
    }
}