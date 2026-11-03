<?php

namespace App\Support;

use App\Models\Approval;
use App\Models\AssistantQuestion;
use App\Models\Client;
use App\Models\FileEntry;
use App\Models\Meeting;
use App\Models\SubUser;
use App\Models\Workspace;
use Carbon\Carbon;

/**
 * Builds what the client assistant shows (CLIENT_ASSISTANT_PLAN.md ٥.٤).
 *
 * The numbers come from the client's own data, read only; the wording comes
 * from the assistant_questions rows the super admin edits, with the default in
 * config/assistant.php as the fallback. The server builds the final sentence
 * in both languages, so any edit shows on the web and the app at once.
 */
class AssistantRenderer
{
    private const MONTHS_AR = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
    private const MONTHS_EN = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

    /** Sub-user permission each data question needs; null = no permission. */
    private const NEEDS = [
        'todo' => null,
        'balance' => 'can_view_payments',
        'next_installment' => 'can_view_payments',
        'contract_status' => 'can_view_contracts',
        'next_meeting' => 'can_view_meetings',
        'contact_manager' => 'can_chat',
    ];

    /** @var Client|SubUser */
    private $actor;

    public function __construct(private Workspace $workspace, $actor)
    {
        $this->actor = $actor;
    }

    /** Fills {placeholders} in a template; a placeholder with no value becomes empty. */
    public static function fill(string $template, array $values): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => (string) ($values[$m[1]] ?? ''), $template);
    }

    public function build(): array
    {
        $rows = AssistantQuestion::query()->orderBy('sort_order')->orderBy('id')->get();
        $settings = $rows->firstWhere('kind', AssistantQuestion::KIND_SETTINGS);
        $client = $this->workspace->client;
        $name = $this->actor instanceof SubUser ? $this->actor->name : ($client->contact_person ?: $client->company_name);

        $welcomeTpl = $settings?->answers['welcome'] ?? config('assistant.settings.welcome');
        $assistantName = $settings?->question ?? config('assistant.settings.name');

        $questions = [];
        $dataRows = $rows->where('kind', AssistantQuestion::KIND_DATA)->keyBy('key');

        // Every fixed question is always considered, even if its row was
        // deleted from the table: the default stands in for it.
        $fixed = collect(config('assistant.questions'))->map(function ($def, $key) use ($dataRows) {
            $row = $dataRows->get($key);

            return [
                'row' => $row,
                'key' => $key,
                'enabled' => $row ? $row->is_enabled : true,
                'sort' => $row ? $row->sort_order : $def['sort_order'],
                'id' => $row?->id,
            ];
        });

        $entries = [];
        foreach ($fixed as $f) {
            $entries[] = ['sort' => $f['sort'], 'tie' => 0, 'item' => $f];
        }
        foreach ($rows->where('kind', AssistantQuestion::KIND_FAQ) as $faq) {
            $entries[] = ['sort' => $faq->sort_order, 'tie' => 1, 'item' => $faq];
        }
        usort($entries, fn ($a, $b) => [$a['sort'], $a['tie']] <=> [$b['sort'], $b['tie']]);

        foreach ($entries as $e) {
            $item = $e['item'];
            if ($item instanceof AssistantQuestion) {
                if ($item->is_enabled) {
                    $questions[] = $this->faq($item);
                }
                continue;
            }
            if (! $item['enabled'] || ! $this->allowed($item['key'])) {
                continue;
            }
            $questions[] = $this->data($item['key'], $item['row'], $item['id']);
        }

        return [
            'assistant' => [
                'name' => $assistantName,
                'welcome' => [
                    'ar' => self::fill($welcomeTpl['ar'], ['name' => $name]),
                    'en' => self::fill($welcomeTpl['en'], ['name' => $name]),
                ],
            ],
            'questions' => array_values($questions),
        ];
    }

    private function allowed(string $key): bool
    {
        $need = self::NEEDS[$key] ?? null;
        if ($need === null || ! $this->actor instanceof SubUser) {
            return true;
        }

        return $this->actor->hasPermission($need);
    }

    private function can(string $permission): bool
    {
        return ! $this->actor instanceof SubUser || $this->actor->hasPermission($permission);
    }

    private function faq(AssistantQuestion $row): array
    {
        $target = $row->action_target;

        return [
            'id' => $row->id,
            'key' => null,
            'kind' => 'faq',
            'question' => $row->question,
            'answer' => $row->answers['default'] ?? ['ar' => '', 'en' => ''],
            'actions' => $target && in_array($target, config('assistant.targets'), true) ? [['target' => $target]] : [],
        ];
    }

    /** The wording for a state: the stored one, else the default. */
    private function tpl(?AssistantQuestion $row, string $key, string $state): array
    {
        $stored = $row?->answers[$state] ?? null;
        if (is_array($stored) && ! empty($stored['ar']) && ! empty($stored['en'])) {
            return ['ar' => $stored['ar'], 'en' => $stored['en']];
        }
        $d = config("assistant.questions.{$key}.states.{$state}");

        return ['ar' => $d['ar'], 'en' => $d['en']];
    }

    private function say(?AssistantQuestion $row, string $key, string $state, array $values): array
    {
        $t = $this->tpl($row, $key, $state);

        return [
            'ar' => self::fill($t['ar'], $this->localised($values, 'ar')),
            'en' => self::fill($t['en'], $this->localised($values, 'en')),
        ];
    }

    /** Dates arrive as Carbon and are written per language; everything else is passed through. */
    private function localised(array $values, string $lang): array
    {
        foreach ($values as $k => $v) {
            if ($v instanceof Carbon) {
                $values[$k] = $k === 'time' ? $this->time($v, $lang) : $this->date($v, $lang);
            }
        }

        return $values;
    }

    private function date(Carbon $d, string $lang): string
    {
        $months = $lang === 'ar' ? self::MONTHS_AR : self::MONTHS_EN;

        return $d->day . ' ' . $months[$d->month] . ' ' . $d->year;
    }

    private function time(Carbon $d, string $lang): string
    {
        $h = (int) $d->format('g');
        $suffix = $d->format('A') === 'AM' ? ($lang === 'ar' ? 'ص' : 'AM') : ($lang === 'ar' ? 'م' : 'PM');

        return $h . ':' . $d->format('i') . ' ' . $suffix;
    }

    private function money($n): string
    {
        return number_format((float) $n, 2, '.', ',');
    }

    private function data(string $key, ?AssistantQuestion $row, ?int $id): array
    {
        $question = $row?->question ?? config("assistant.questions.{$key}.question");
        $out = ['id' => $id, 'key' => $key, 'kind' => 'data', 'question' => $question];

        return $out + match ($key) {
            'todo' => $this->todo($row),
            'balance' => $this->balance($row),
            'next_installment' => $this->nextInstallment($row),
            'contract_status' => $this->contractStatus($row),
            'next_meeting' => $this->nextMeeting($row),
            'contact_manager' => $this->contactManager($row),
        };
    }

    // ── data questions ───────────────────────────────────────────────────

    private function todo(?AssistantQuestion $row): array
    {
        $ws = $this->workspace;
        $items = [];   // [state, values, target]

        if ($this->can('can_view_contracts')) {
            $sent = $ws->contracts()->where('status', 'sent')->with('requiredDocuments.files')->get();
            if ($sent->isNotEmpty()) {
                $items[] = ['item_contract', [], 'contracts'];
            }
            $missing = 0;
            foreach ($sent as $contract) {
                foreach ($contract->requiredDocuments as $doc) {
                    if ($doc->is_required && ! $doc->files->contains(fn (FileEntry $f) => $f->status !== 'rejected')) {
                        $missing++;
                    }
                }
            }
            if ($missing > 0) {
                $items[] = ['item_documents', ['count' => $missing], 'contracts'];
            }
        }

        if ($this->can('can_view_approvals')) {
            $pending = Approval::where('workspace_id', $ws->id)->where('status', 'pending')->count();
            if ($pending > 0) {
                $items[] = ['item_approval', ['count' => $pending], 'approvals'];
            }
        }

        if ($this->can('can_view_payments')) {
            $today = now()->toDateString();
            $fix = $ws->payments()
                ->where(fn ($q) => $q->whereIn('status', ['overdue', 'rejected'])
                    ->orWhere(fn ($s) => $s->where('status', 'scheduled')->whereDate('due_date', '<', $today)))
                ->orderBy('due_date')->limit(5)->get();
            foreach ($fix as $p) {
                if ($p->status === 'rejected') {
                    $items[] = ['item_payment_rejected', ['amount' => $this->money($p->amount), 'currency' => $p->currency], 'payments'];
                } else {
                    $items[] = ['item_payment_overdue', [
                        'amount' => $this->money($p->amount),
                        'currency' => $p->currency,
                        'due_date' => $p->due_date ? Carbon::parse($p->due_date) : '',
                    ], 'payments'];
                }
            }
        }

        if (! $items) {
            return ['answer' => $this->say($row, 'todo', 'nothing', []), 'actions' => []];
        }

        $answer = $this->say($row, 'todo', 'has_items', ['count' => count($items)]);
        $actions = [];
        foreach ($items as $i => [$state, $values, $target]) {
            $line = $this->say($row, 'todo', $state, $values);
            foreach (['ar', 'en'] as $l) {
                $answer[$l] .= "\n" . ($i + 1) . '. ' . $line[$l];
            }
            if (! in_array(['target' => $target], $actions, true)) {
                $actions[] = ['target' => $target];
            }
        }

        return ['answer' => $answer, 'actions' => $actions];
    }

    private function balance(?AssistantQuestion $row): array
    {
        $summary = WorkspaceTaxSummary::for($this->workspace);
        $total = (float) $summary['grand_total'];
        $paid = (float) $this->workspace->payments()->where('status', 'approved')->sum('amount');
        $currency = $this->workspace->contracts()
            ->whereIn('status', ['company_approved', 'completed'])->value('currency') ?: 'SAR';

        if ($total <= 0) {
            return ['answer' => $this->say($row, 'balance', 'no_contract', []), 'actions' => []];
        }

        $remaining = max(0.0, $total - $paid);
        $values = [
            'remaining' => $this->money($remaining),
            'paid' => $this->money($paid),
            'total' => $this->money($total),
            'currency' => $currency,
        ];
        $state = $remaining <= 0 ? 'fully_paid' : 'default';

        return [
            'answer' => $this->say($row, 'balance', $state, $values),
            'progress' => ['paid' => round($paid, 2), 'total' => round($total, 2)],
            'actions' => [['target' => 'payments']],
        ];
    }

    private function nextInstallment(?AssistantQuestion $row): array
    {
        $today = now()->toDateString();
        $payments = $this->workspace->payments();

        $overdue = (clone $payments)
            ->where(fn ($q) => $q->where('status', 'overdue')
                ->orWhere(fn ($s) => $s->where('status', 'scheduled')->whereDate('due_date', '<', $today)))
            ->orderBy('due_date')->first();

        $payment = $overdue ?: (clone $payments)->where('status', 'scheduled')->orderBy('due_date')->first();

        if (! $payment) {
            return ['answer' => $this->say($row, 'next_installment', 'none', []), 'actions' => []];
        }

        return [
            'answer' => $this->say($row, 'next_installment', $overdue ? 'overdue' : 'upcoming', [
                'amount' => $this->money($payment->amount),
                'currency' => $payment->currency,
                'due_date' => $payment->due_date ? Carbon::parse($payment->due_date) : '',
            ]),
            'actions' => [['target' => 'payments']],
        ];
    }

    private function contractStatus(?AssistantQuestion $row): array
    {
        $labels = config('assistant.contract_status_labels');
        $contract = $this->workspace->contracts()
            ->whereIn('status', array_keys($labels))
            ->orderByDesc('id')->first();

        if (! $contract) {
            return ['answer' => $this->say($row, 'contract_status', 'no_contract', []), 'actions' => []];
        }

        $label = $labels[$contract->status];

        return [
            'answer' => [
                'ar' => self::fill($this->tpl($row, 'contract_status', 'default')['ar'], ['title' => $contract->title, 'status' => $label['ar']]),
                'en' => self::fill($this->tpl($row, 'contract_status', 'default')['en'], ['title' => $contract->title, 'status' => $label['en']]),
            ],
            'actions' => [['target' => 'contracts']],
        ];
    }

    private function nextMeeting(?AssistantQuestion $row): array
    {
        $tz = config('app.display_timezone', 'Africa/Cairo');
        $meeting = Meeting::where('workspace_id', $this->workspace->id)
            ->where('status', 'scheduled')
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at')->first();

        if (! $meeting) {
            return ['answer' => $this->say($row, 'next_meeting', 'none', []), 'actions' => []];
        }

        $at = Carbon::instance($meeting->scheduled_at)->setTimezone($tz);

        return [
            'answer' => $this->say($row, 'next_meeting', 'default', [
                'title' => $meeting->title,
                'date' => $at,
                'time' => $at,
            ]),
            'actions' => [['target' => 'meetings']],
        ];
    }

    private function contactManager(?AssistantQuestion $row): array
    {
        $manager = $this->workspace->manager;

        return [
            'answer' => $this->say($row, 'contact_manager', 'default', ['manager' => $manager?->name ?? '']),
            'actions' => [['target' => 'chat']],
        ];
    }
}
