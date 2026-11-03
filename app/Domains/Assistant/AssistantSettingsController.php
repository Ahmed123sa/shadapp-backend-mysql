<?php

namespace App\Domains\Assistant;

use App\Http\Controllers\Controller;
use App\Models\AssistantQuestion;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\AssistantRenderer;
use App\Support\AssistantWording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The super admin's page for the client assistant (CLIENT_ASSISTANT_PLAN.md
 * ٥.٥). Everything here is super admin only, and every write runs in one
 * transaction with its audit row — a change that cannot be recorded does not
 * happen (same rule as AccountantController).
 */
class AssistantSettingsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->superAdmin($request);
        $this->ensureRows();

        $rows = AssistantQuestion::orderBy('sort_order')->orderBy('id')->get();

        return response()->json([
            'enabled' => (bool) config('assistant.enabled'),
            'settings' => $this->shapeSettings($rows->firstWhere('kind', AssistantQuestion::KIND_SETTINGS)),
            'questions' => $rows->where('kind', AssistantQuestion::KIND_DATA)->map(fn ($r) => $this->shapeData($r))->values(),
            'faqs' => $rows->where('kind', AssistantQuestion::KIND_FAQ)->map(fn ($r) => $this->shapeFaq($r))->values(),
            'targets' => config('assistant.targets'),
            'limits' => config('assistant.limits'),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $admin = $this->superAdmin($request);
        $limits = config('assistant.limits');

        if (AssistantQuestion::where('kind', AssistantQuestion::KIND_FAQ)->count() >= $limits['faq_max']) {
            throw ValidationException::withMessages(['question' => "الحد الأقصى {$limits['faq_max']} سؤال شائع."]);
        }

        $data = $this->validateFaq($request);

        $row = DB::transaction(function () use ($data, $admin, $request) {
            $row = AssistantQuestion::create([
                'key' => null,
                'kind' => AssistantQuestion::KIND_FAQ,
                'question' => $data['question'],
                'answers' => ['default' => $data['answer']],
                'action_target' => $data['action_target'] ?? null,
                'is_enabled' => $data['is_enabled'] ?? true,
                'sort_order' => ((int) AssistantQuestion::max('sort_order')) + 1,
                'updated_by' => $admin->id,
            ]);
            $this->audit($request, $row, 'assistant.faq_created', ['question' => $row->question]);

            return $row;
        });

        return response()->json(['faq' => $this->shapeFaq($row)], 201);
    }

    public function update(Request $request, AssistantQuestion $question): JsonResponse
    {
        $admin = $this->superAdmin($request);

        $before = ['question' => $question->question, 'answers' => $question->answers, 'is_enabled' => $question->is_enabled, 'action_target' => $question->action_target];

        $changes = match ($question->kind) {
            AssistantQuestion::KIND_FAQ => $this->faqChanges($request),
            AssistantQuestion::KIND_DATA => $this->dataChanges($request, $question),
            default => $this->settingsChanges($request, $question),
        };

        DB::transaction(function () use ($question, $changes, $admin, $request, $before) {
            $question->update($changes + ['updated_by' => $admin->id]);
            $this->audit($request, $question, 'assistant.question_updated', [
                'key' => $question->key,
                'before' => $before,
                'after' => ['question' => $question->question, 'answers' => $question->answers, 'is_enabled' => $question->is_enabled, 'action_target' => $question->action_target],
            ]);
        });

        $fresh = $question->fresh();

        return response()->json([
            'question' => match ($fresh->kind) {
                AssistantQuestion::KIND_FAQ => $this->shapeFaq($fresh),
                AssistantQuestion::KIND_DATA => $this->shapeData($fresh),
                default => $this->shapeSettings($fresh),
            },
        ]);
    }

    public function destroy(Request $request, AssistantQuestion $question): JsonResponse
    {
        $this->superAdmin($request);

        if ($question->kind !== AssistantQuestion::KIND_FAQ) {
            return response()->json(['message' => 'أسئلة البيانات ثابتة. تقدر توقفها لكن مش تحذفها.'], 422);
        }

        DB::transaction(function () use ($question, $request) {
            $this->audit($request, $question, 'assistant.faq_deleted', ['question' => $question->question]);
            $question->delete();
        });

        return response()->json(['message' => 'تم الحذف.']);
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        $data = $request->validate(['ids' => 'required|array|min:1', 'ids.*' => 'integer|distinct']);

        $valid = AssistantQuestion::whereIn('kind', [AssistantQuestion::KIND_DATA, AssistantQuestion::KIND_FAQ])->pluck('id')->all();
        if (array_diff($data['ids'], $valid)) {
            throw ValidationException::withMessages(['ids' => 'فيه سؤال مش موجود في الترتيب.']);
        }

        DB::transaction(function () use ($data, $request) {
            foreach ($data['ids'] as $i => $id) {
                AssistantQuestion::where('id', $id)->update(['sort_order' => $i + 1]);
            }
            $this->audit($request, null, 'assistant.reordered', ['ids' => $data['ids']]);
        });

        return response()->json(['message' => 'تم الترتيب.']);
    }

    /** Puts a data question back to its default wording (the question text and every answer). */
    public function reset(Request $request, AssistantQuestion $question): JsonResponse
    {
        $admin = $this->superAdmin($request);
        abort_unless($question->kind === AssistantQuestion::KIND_DATA && config("assistant.questions.{$question->key}"), 422, 'الرجوع للأصل لأسئلة البيانات بس.');

        $def = config("assistant.questions.{$question->key}");
        $answers = [];
        foreach ($def['states'] as $state => $s) {
            $answers[$state] = ['ar' => $s['ar'], 'en' => $s['en']];
        }

        DB::transaction(function () use ($question, $def, $answers, $admin, $request) {
            $question->update(['question' => $def['question'], 'answers' => $answers, 'updated_by' => $admin->id]);
            $this->audit($request, $question, 'assistant.question_reset', ['key' => $question->key]);
        });

        return response()->json(['question' => $this->shapeData($question->fresh())]);
    }

    /** Checks one wording and shows it with sample numbers. Saves nothing. */
    public function preview(Request $request): JsonResponse
    {
        $this->superAdmin($request);

        $data = $request->validate([
            'key' => 'required|string',
            'state' => 'required|string',
            'ar' => 'nullable|string',
            'en' => 'nullable|string',
        ]);

        [$allowed, $required] = $this->rulesFor($data['key'], $data['state']);

        $out = [];
        foreach (['ar', 'en'] as $lang) {
            $text = (string) ($data[$lang] ?? '');
            $out[$lang] = [
                'text' => AssistantRenderer::fill($text, AssistantWording::sample($lang)),
                'error' => $text === '' ? 'الرد مطلوب.' : AssistantWording::problem($text, $allowed, $required),
            ];
        }

        return response()->json($out);
    }

    // ── what each kind of row accepts ────────────────────────────────────

    private function dataChanges(Request $request, AssistantQuestion $question): array
    {
        $limits = config('assistant.limits');
        $data = $request->validate([
            'question' => 'sometimes|array',
            'question.ar' => "required_with:question|string|max:{$limits['question']}",
            'question.en' => "required_with:question|string|max:{$limits['question']}",
            'is_enabled' => 'sometimes|boolean',
            'answers' => 'sometimes|array',
        ]);

        $changes = [];
        if (isset($data['question'])) {
            $changes['question'] = ['ar' => trim($data['question']['ar']), 'en' => trim($data['question']['en'])];
            if ($changes['question']['ar'] === '' || $changes['question']['en'] === '') {
                throw ValidationException::withMessages(['question' => 'السؤال لازم يتكتب بالعربي والإنجليزي.']);
            }
        }
        if (isset($data['is_enabled'])) {
            $changes['is_enabled'] = $data['is_enabled'];
        }
        if (isset($data['answers'])) {
            $answers = $question->answers ?? [];
            $errors = [];
            foreach ($data['answers'] as $state => $pair) {
                $def = config("assistant.questions.{$question->key}.states.{$state}");
                if (! $def) {
                    $errors["answers.{$state}"] = 'حالة مش معروفة.';
                    continue;
                }
                foreach (['ar', 'en'] as $lang) {
                    $text = trim((string) ($pair[$lang] ?? ''));
                    if ($text === '') {
                        $errors["answers.{$state}.{$lang}"] = 'الرد مطلوب بالعربي والإنجليزي.';
                    } elseif (mb_strlen($text) > $limits['answer']) {
                        $errors["answers.{$state}.{$lang}"] = "الحد الأقصى {$limits['answer']} حرف.";
                    } elseif ($p = AssistantWording::problem($text, $def['allowed'], $def['required'])) {
                        $errors["answers.{$state}.{$lang}"] = $p;
                    } else {
                        $answers[$state][$lang] = $text;
                    }
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $changes['answers'] = $answers;
        }

        return $changes;
    }

    private function faqChanges(Request $request): array
    {
        $data = $this->validateFaq($request, partial: true);
        $changes = [];
        if (isset($data['question'])) {
            $changes['question'] = $data['question'];
        }
        if (isset($data['answer'])) {
            $changes['answers'] = ['default' => $data['answer']];
        }
        if (array_key_exists('action_target', $data)) {
            $changes['action_target'] = $data['action_target'];
        }
        if (isset($data['is_enabled'])) {
            $changes['is_enabled'] = $data['is_enabled'];
        }

        return $changes;
    }

    private function settingsChanges(Request $request, AssistantQuestion $row): array
    {
        $limits = config('assistant.limits');
        $data = $request->validate([
            'name' => 'sometimes|array',
            'name.ar' => "required_with:name|string|max:{$limits['name']}",
            'name.en' => "required_with:name|string|max:{$limits['name']}",
            'welcome' => 'sometimes|array',
            'welcome.ar' => "required_with:welcome|string|max:{$limits['welcome']}",
            'welcome.en' => "required_with:welcome|string|max:{$limits['welcome']}",
        ]);

        $changes = [];
        if (isset($data['name'])) {
            $changes['question'] = ['ar' => trim($data['name']['ar']), 'en' => trim($data['name']['en'])];
        }
        if (isset($data['welcome'])) {
            $errors = [];
            foreach (['ar', 'en'] as $lang) {
                $text = trim($data['welcome'][$lang]);
                if ($p = AssistantWording::problem($text, config('assistant.settings.welcome_placeholders'), [])) {
                    $errors["welcome.{$lang}"] = $p;
                }
            }
            if ($errors) {
                throw ValidationException::withMessages($errors);
            }
            $changes['answers'] = ['welcome' => ['ar' => trim($data['welcome']['ar']), 'en' => trim($data['welcome']['en'])]];
        }

        return $changes;
    }

    private function validateFaq(Request $request, bool $partial = false): array
    {
        $limits = config('assistant.limits');
        $req = $partial ? 'sometimes' : 'required';

        $data = $request->validate([
            'question' => "{$req}|array",
            'question.ar' => "required_with:question|string|max:{$limits['question']}",
            'question.en' => "required_with:question|string|max:{$limits['question']}",
            'answer' => "{$req}|array",
            'answer.ar' => "required_with:answer|string|max:{$limits['faq_answer']}",
            'answer.en' => "required_with:answer|string|max:{$limits['faq_answer']}",
            'action_target' => 'nullable|string|in:' . implode(',', config('assistant.targets')),
            'is_enabled' => 'sometimes|boolean',
        ]);

        $errors = [];
        foreach (['question', 'answer'] as $field) {
            foreach (['ar', 'en'] as $lang) {
                $text = trim((string) ($data[$field][$lang] ?? ''));
                if (isset($data[$field])) {
                    $data[$field][$lang] = $text;
                    if ($text === '') {
                        $errors["{$field}.{$lang}"] = 'مطلوب بالعربي والإنجليزي.';
                    } elseif ($field === 'answer' && AssistantWording::found($text)) {
                        $errors["{$field}.{$lang}"] = 'الأسئلة الشائعة إجاباتها ثابتة ومفيهاش خانات { }.';
                    }
                }
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /** @return array{0: list<string>, 1: list<string>} */
    private function rulesFor(string $key, string $state): array
    {
        if ($key === 'settings') {
            return [config('assistant.settings.welcome_placeholders'), []];
        }
        $def = config("assistant.questions.{$key}.states.{$state}");
        abort_unless($def, 422, 'سؤال أو حالة مش معروفة.');

        return [$def['allowed'], $def['required']];
    }

    // ── shapes ───────────────────────────────────────────────────────────

    private function shapeData(AssistantQuestion $r): array
    {
        $def = config("assistant.questions.{$r->key}");
        $states = [];
        foreach ($def['states'] as $state => $s) {
            $now = $r->answers[$state] ?? null;
            $states[] = [
                'state' => $state,
                'ar' => $now['ar'] ?? $s['ar'],
                'en' => $now['en'] ?? $s['en'],
                'allowed' => $s['allowed'],
                'required' => $s['required'],
                'default' => ['ar' => $s['ar'], 'en' => $s['en']],
            ];
        }

        return [
            'id' => $r->id,
            'key' => $r->key,
            'kind' => 'data',
            'question' => $r->question ?? $def['question'],
            'default_question' => $def['question'],
            'is_enabled' => $r->is_enabled,
            'sort_order' => $r->sort_order,
            'states' => $states,
        ];
    }

    private function shapeFaq(AssistantQuestion $r): array
    {
        return [
            'id' => $r->id,
            'kind' => 'faq',
            'question' => $r->question,
            'answer' => $r->answers['default'] ?? ['ar' => '', 'en' => ''],
            'action_target' => $r->action_target,
            'is_enabled' => $r->is_enabled,
            'sort_order' => $r->sort_order,
        ];
    }

    private function shapeSettings(?AssistantQuestion $r): array
    {
        $d = config('assistant.settings');

        return [
            'id' => $r?->id,
            'name' => $r?->question ?? $d['name'],
            'welcome' => $r?->answers['welcome'] ?? $d['welcome'],
            'welcome_placeholders' => $d['welcome_placeholders'],
        ];
    }

    // ── plumbing ─────────────────────────────────────────────────────────

    /** Recreates a fixed row that was deleted from the table, so the page never has a hole. */
    private function ensureRows(): void
    {
        foreach (config('assistant.questions') as $key => $def) {
            if (AssistantQuestion::where('key', $key)->exists()) {
                continue;
            }
            $answers = [];
            foreach ($def['states'] as $state => $s) {
                $answers[$state] = ['ar' => $s['ar'], 'en' => $s['en']];
            }
            AssistantQuestion::create([
                'key' => $key, 'kind' => AssistantQuestion::KIND_DATA, 'question' => $def['question'],
                'answers' => $answers, 'is_enabled' => true, 'sort_order' => $def['sort_order'],
            ]);
        }
        if (! AssistantQuestion::where('key', 'settings')->exists()) {
            $s = config('assistant.settings');
            AssistantQuestion::create([
                'key' => 'settings', 'kind' => AssistantQuestion::KIND_SETTINGS, 'question' => $s['name'],
                'answers' => ['welcome' => $s['welcome']], 'is_enabled' => true, 'sort_order' => 0,
            ]);
        }
    }

    /** 403 for everyone but the super admin. */
    private function superAdmin(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isSuperAdmin(), 403, 'غير مصرح');

        return $user;
    }

    private function audit(Request $request, ?AssistantQuestion $row, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'auditable_type' => AssistantQuestion::class,
            'auditable_id' => $row?->id ?? 0,
            'client_id' => null,
            'user_id' => $request->user()->id,
            'action' => $action,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
        ]);
    }
}
