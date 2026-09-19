<?php

namespace App\Domains\SubUser;

use App\Models\SubUser;
use App\Models\Client;
use App\Models\AuditLog;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use App\Http\Controllers\Controller;

class SubUserController extends Controller
{
    // login() used to live here as a second sub-user login route
    // (/auth/sub-user/login). It had no caller anywhere in the dashboard,
    // mobile app, or tests, and unlike AuthController::clientLogin it never
    // checked whether the parent client was archived — an open side door
    // around client archiving. Removed along with its route; sub-users log in
    // through /auth/client/login, which AuthController::clientLogin already
    // handles (see SUBUSER_PLAN.md §1.3).

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('create', [SubUser::class, $client]);

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:sub_users',
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'date_of_birth' => 'nullable|date',
        ]);

        $subUser = $client->subUsers()->create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'permissions' => [],
            'date_of_birth' => $request->date_of_birth,
        ]);

        AuditLog::create([
            'auditable_type' => SubUser::class,
            'auditable_id' => $subUser->id,
            'user_id' => $request->user()?->id,
            'action' => 'sub_user.created',
            'metadata' => ['email' => $subUser->email],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['sub_user' => $this->present($subUser)], 201);
    }

    /**
     * SUBUSER_PLAN.md §6.1 — the 11 permission keys a sub-user can hold,
     * for the dashboard and mobile to build their permission-toggle UI from
     * instead of each keeping its own hardcoded copy of SubUser::PERMISSION_KEYS.
     */
    public function permissionKeys(): JsonResponse
    {
        return response()->json(['permissions' => SubUser::PERMISSION_KEYS]);
    }

    public function show(SubUser $subUser): JsonResponse
    {
        $this->authorize('view', $subUser);

        return response()->json(['sub_user' => $this->present($subUser)]);
    }

    public function updatePermissions(Request $request, SubUser $subUser): JsonResponse
    {
        $this->authorize('updatePermissions', $subUser);

        $request->validate([
            'permissions' => 'required|array',
        ]);

        $permissions = collect($request->permissions)
            ->only(SubUser::PERMISSION_KEYS)
            ->mapWithKeys(fn ($value, $key) => [$key => (bool) $value])
            ->toArray();

        $subUser->update(['permissions' => $permissions]);

        AuditLog::create([
            'auditable_type' => SubUser::class,
            'auditable_id' => $subUser->id,
            'user_id' => $request->user()?->id,
            'action' => 'sub_user.permissions_updated',
            'metadata' => ['permissions' => $permissions],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['sub_user' => $this->present($subUser->fresh())]);
    }

    public function destroy(Request $request, SubUser $subUser): JsonResponse
    {
        $this->authorize('delete', $subUser);

        $subUser->delete();

        AuditLog::create([
            'auditable_type' => SubUser::class,
            'auditable_id' => $subUser->id,
            'user_id' => $request->user()?->id,
            'action' => 'sub_user.deleted',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'تم حذف المستخدم']);
    }

    public function updateProfile(Request $request, SubUser $subUser): JsonResponse
    {
        $this->authorize('updateProfile', $subUser);

        $request->validate([
            'name' => 'sometimes|string|max:255',
            'email' => 'nullable|email|unique:sub_users,email,' . $subUser->id,
            'phone' => 'nullable|string|max:20',
            'date_of_birth' => 'nullable|date',
            'avatar' => UploadRules::image(),
        ]);

        $updateData = $request->only(['name', 'email', 'phone', 'date_of_birth']);

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $updateData['avatar_url'] = \Illuminate\Support\Facades\Storage::url($path);
        }

        $subUser->update($updateData);

        // store()/destroy()/updatePermissions()/changePassword() all log to
        // AuditLog; this one didn't, even though changing a sub-user's email
        // is effectively a handover of the account's login (SUBUSER_PLAN.md
        // §5.4).
        AuditLog::create([
            'auditable_type' => SubUser::class,
            'auditable_id' => $subUser->id,
            'user_id' => $request->user()?->id,
            'action' => 'sub_user.profile_updated',
            'metadata' => ['fields' => array_keys($updateData)],
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['sub_user' => $this->present($subUser->fresh())]);
    }

    /**
     * SUBUSER_PLAN.md §6.2/§6.3 — the single response shape every action
     * above returns. Before this, store() serialized the raw Eloquent model,
     * show() and updatePermissions() each hand-built their own (different)
     * subset of fields, and updateProfile() called $subUser->fresh() five
     * separate times (five queries) to build one array. One fetch, one
     * shape, used everywhere.
     */
    private function present(SubUser $subUser): array
    {
        return [
            'id' => $subUser->id,
            'name' => $subUser->name,
            'email' => $subUser->email,
            'phone' => $subUser->phone,
            'date_of_birth' => $subUser->date_of_birth?->toDateString(),
            'permissions' => $subUser->getPermissionsArray(),
            'avatar_url' => $subUser->avatar_url,
            'client_id' => $subUser->client_id,
        ];
    }

    /**
     * SUBUSER_PLAN.md §4.1. Before this, a sub-user who forgot their
     * password had no way back in — updateProfile() never accepted a
     * password field, and password reset is deliberately excluded for
     * sub-users (see PasswordResetController). The only fix was the client
     * deleting the account and creating a new one, losing every audit trail
     * tied to the old id.
     *
     * Two actors, two rules: the owning client resets a sub-user's password
     * without knowing the old one (same as a company admin resetting an
     * employee's password); the sub-user changing their own must prove they
     * still know the current one first.
     */
    public function changePassword(Request $request, SubUser $subUser): JsonResponse
    {
        $this->authorize('changePassword', $subUser);

        $actor = $request->user();

        $rules = [
            'password' => 'required|string|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
        ];
        if ($actor instanceof SubUser) {
            $rules['current_password'] = 'required|string';
        }
        $request->validate($rules);

        if ($actor instanceof SubUser && ! Hash::check($request->current_password, $subUser->password)) {
            return response()->json(['message' => 'كلمة المرور الحالية غير صحيحة'], 422);
        }

        $subUser->update(['password' => $request->password]);

        // Invalidate every existing token so a session started with the old
        // password (or a colleague who knew it) can't keep using it.
        $subUser->tokens()->delete();

        AuditLog::create([
            'auditable_type' => SubUser::class,
            'auditable_id' => $subUser->id,
            'user_id' => $request->user()?->id,
            'action' => 'sub_user.password_changed',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['message' => 'تم تغيير كلمة المرور بنجاح']);
    }
}
