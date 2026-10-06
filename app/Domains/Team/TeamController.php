<?php

namespace App\Domains\Team;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Rules\UniqueLoginEmail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An account manager's own assistants (MANAGER_ASSISTANT_PLAN.md §4.5).
 *
 * Only the manager can reach this — not the super admin, not an assistant,
 * not another manager (decision ق١/ق٩). A manager touching someone else's
 * assistant gets a 404, the same as an id that does not exist, so ids of other
 * teams are not confirmable.
 *
 * Every write runs in one transaction with its audit row: an assistant change
 * that cannot be recorded does not happen (same rule as SubUserController).
 */
class TeamController extends Controller
{
    public function permissionKeys(Request $request): JsonResponse
    {
        $this->manager($request);

        return response()->json(['permissions' => User::ASSISTANT_PERMISSION_KEYS]);
    }

    public function index(Request $request): JsonResponse
    {
        $manager = $this->manager($request);

        $assistants = $manager->assistants()->whereNull('removed_at')->oldest()->orderBy('id')->get();

        return response()->json(['assistants' => $assistants->map(fn (User $a) => $this->shape($a))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $manager = $this->manager($request);

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'unique:users', new UniqueLoginEmail()],
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'boolean',
            'send_email' => 'sometimes|boolean',
        ]);

        if ($manager->assistants()->whereNull('removed_at')->count() >= (int) config('team.max_assistants', 10)) {
            throw ValidationException::withMessages([
                'limit' => 'وصلت للحد الأقصى لعدد المساعدين (' . config('team.max_assistants', 10) . ').',
            ]);
        }

        $assistant = DB::transaction(function () use ($data, $manager, $request) {
            $assistant = User::create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'phone' => $data['phone'] ?? null,
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'password' => $data['password'],
                'role' => User::ROLE_MANAGER_ASSISTANT,
                'parent_manager_id' => $manager->id,
                'assistant_permissions' => $this->normalize($data['permissions'] ?? []),
            ]);

            $this->audit($request, $assistant, 'team.assistant_created', ['email' => $assistant->email]);

            return $assistant;
        });

        if ($request->boolean('send_email', true)) {
            \App\Support\CredentialsMailer::send($assistant->name, $assistant->email, $data['password'], 'مساعد مدير حساب');
        }

        return response()->json(['assistant' => $this->shape($assistant->fresh())], 201);
    }

    public function update(Request $request, User $assistant): JsonResponse
    {
        $this->ownAssistant($request, $assistant);

        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => ['sometimes', 'email', 'unique:users,email,' . $assistant->id, new UniqueLoginEmail('users', $assistant->id)],
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
            'permissions' => 'sometimes|array',
            'permissions.*' => 'boolean',
        ]);

        DB::transaction(function () use ($data, $assistant, $request) {
            $fields = array_intersect_key($data, array_flip(['name', 'email', 'phone', 'date_of_birth']));
            if (isset($fields['email'])) {
                $fields['email'] = strtolower(trim($fields['email']));
            }

            $before = $this->normalize($assistant->assistant_permissions ?? []);
            if (array_key_exists('permissions', $data)) {
                $fields['assistant_permissions'] = $this->normalize($data['permissions'], $before);
            }

            $assistant->update($fields);

            $this->audit($request, $assistant, 'team.assistant_updated', array_filter([
                'fields' => array_values(array_diff(array_keys($fields), ['assistant_permissions'])),
                'permissions_before' => isset($fields['assistant_permissions']) ? $before : null,
                'permissions_after' => $fields['assistant_permissions'] ?? null,
            ]));
        });

        return response()->json(['assistant' => $this->shape($assistant->fresh())]);
    }

    public function deactivate(Request $request, User $assistant): JsonResponse
    {
        $this->ownAssistant($request, $assistant);

        DB::transaction(function () use ($assistant, $request) {
            $assistant->update([
                'is_active' => false,
                'deactivated_at' => now(),
                'deactivated_by_parent' => false,
            ]);
            // Kills every session, mobile included, immediately.
            $assistant->tokens()->delete();

            $this->audit($request, $assistant, 'team.assistant_deactivated');
        });

        return response()->json(['assistant' => $this->shape($assistant->fresh())]);
    }

    public function activate(Request $request, User $assistant): JsonResponse
    {
        $this->ownAssistant($request, $assistant);

        DB::transaction(function () use ($assistant, $request) {
            $assistant->update([
                'is_active' => true,
                'deactivated_at' => null,
                'deactivated_by_parent' => false,
            ]);

            $this->audit($request, $assistant, 'team.assistant_activated');
        });

        return response()->json(['assistant' => $this->shape($assistant->fresh())]);
    }

    /**
     * Soft removal. The row is kept, with its real name, so the audit log and
     * the team chat still say who did what; everything that lets the person
     * in or reach them is cut: sessions, push tokens, notifications, login
     * email (freed for reuse) and password. Irreversible from the UI.
     */
    public function destroy(Request $request, User $assistant): JsonResponse
    {
        $this->ownAssistant($request, $assistant);

        DB::transaction(function () use ($assistant, $request) {
            $originalEmail = $assistant->email;

            $assistant->tokens()->delete();
            \App\Models\MobileNotificationToken::where('tokenable_type', $assistant::class)
                ->where('tokenable_id', $assistant->getKey())
                ->delete();
            $assistant->notifications()->delete();

            $assistant->forceFill([
                'email' => 'removed-assistant-' . $assistant->id . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)) . '@removed.invalid',
                'password' => \Illuminate\Support\Str::random(48),
                'remember_token' => null,
                'is_active' => false,
                'deactivated_by_parent' => false,
                'deactivated_at' => $assistant->deactivated_at ?? now(),
                'removed_at' => now(),
            ])->save();

            $this->audit($request, $assistant, 'team.assistant_removed', ['name' => $assistant->name, 'email' => $originalEmail]);
        });

        return response()->json(['message' => 'تم مسح المساعد.']);
    }

    public function changePassword(Request $request, User $assistant): JsonResponse
    {
        $this->ownAssistant($request, $assistant);

        $data = $request->validate([
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'send_email' => 'sometimes|boolean',
        ]);

        DB::transaction(function () use ($data, $assistant, $request) {
            $assistant->update(['password' => $data['password']]);
            $assistant->tokens()->delete();

            $this->audit($request, $assistant, 'team.assistant_password_changed');
        });

        // Off by default: a password *change* is usually told to the person
        // directly; the manager can tick the box to have it emailed.
        if ($request->boolean('send_email', false)) {
            \App\Support\CredentialsMailer::send($assistant->name, $assistant->email, $data['password'], 'مساعد مدير حساب');
        }

        return response()->json(['message' => 'تم تغيير الباسورد.']);
    }

    /**
     * Everything this assistant did, newest first — the manager's view of
     * "what did each of my people do" (decision ق٦/ق٩).
     */
    public function activity(Request $request, User $assistant): JsonResponse
    {
        $this->ownAssistant($request, $assistant);

        $logs = AuditLog::with('client')
            ->where('user_id', $assistant->id)
            ->when($request->filled('action'), fn ($q) => $q->where('action', 'like', $request->action . '%'))
            ->latest()
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json(['logs' => $logs]);
    }

    /** Only an active-role account manager. 403 for everyone else, super admin included. */
    private function manager(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->isAccountManager(), 403, 'غير مصرح');

        return $user;
    }

    /** 404, not 403, for someone else's (or a non-assistant) account. */
    private function ownAssistant(Request $request, User $assistant): void
    {
        $manager = $this->manager($request);

        abort_unless(
            $assistant->isAssistant()
                && (int) $assistant->parent_manager_id === (int) $manager->id
                && $assistant->removed_at === null,
            404
        );
    }

    /**
     * Every known key, as a plain boolean map. Unknown keys are dropped;
     * can_view_clients is always on (without it an assistant is pointless).
     */
    private function normalize(array $input, array $base = []): array
    {
        $out = [];
        foreach (User::ASSISTANT_PERMISSION_KEYS as $key) {
            $out[$key] = $key === 'can_view_clients'
                ? true
                : (bool) ($input[$key] ?? $base[$key] ?? false);
        }

        return $out;
    }

    private function shape(User $assistant): array
    {
        return [
            'id' => $assistant->id,
            'name' => $assistant->name,
            'email' => $assistant->email,
            'phone' => $assistant->phone,
            'date_of_birth' => $assistant->date_of_birth?->toDateString(),
            'avatar_url' => $assistant->avatar_url,
            'role' => $assistant->role,
            'is_active' => (bool) $assistant->is_active,
            'deactivated_by_parent' => (bool) $assistant->deactivated_by_parent,
            'permissions' => $this->normalize($assistant->assistant_permissions ?? []),
            'created_at' => $assistant->created_at,
        ];
    }

    private function audit(Request $request, User $assistant, string $action, array $metadata = []): void
    {
        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $assistant->id,
            'client_id' => null,
            'user_id' => $request->user()->id,
            'action' => $action,
            'metadata' => $metadata ?: null,
            'ip_address' => $request->ip(),
        ]);
    }
}
