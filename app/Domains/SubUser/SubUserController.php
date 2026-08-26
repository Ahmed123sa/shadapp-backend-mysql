<?php

namespace App\Domains\SubUser;

use App\Models\SubUser;
use App\Models\Client;
use App\Models\AuditLog;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Controller;

class SubUserController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $subUser = SubUser::where('email', $request->email)->first();

        if (!$subUser || !Hash::check($request->password, $subUser->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }

        $token = $subUser->createToken('sub-user-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'sub_user' => [
                'id' => $subUser->id,
                'name' => $subUser->name,
                'email' => $subUser->email,
                'permissions' => $subUser->getPermissionsArray(),
                'avatar_url' => $subUser->avatar_url,
            ],
            'client' => [
                'id' => $subUser->client->id,
                'company_name' => $subUser->client->company_name,
                'contact_person' => $subUser->client->contact_person,
            ],
            'workspace_id' => $subUser->client->workspace?->id,
        ]);
    }

    public function store(Request $request, Client $client): JsonResponse
    {
        $this->authorize('create', SubUser::class);

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

        return response()->json(['sub_user' => $subUser], 201);
    }

    public function show(SubUser $subUser): JsonResponse
    {
        $this->authorize('view', $subUser);

        return response()->json([
            'sub_user' => [
                'id' => $subUser->id,
                'name' => $subUser->name,
                'email' => $subUser->email,
                'permissions' => $subUser->getPermissionsArray(),
                'avatar_url' => $subUser->avatar_url,
                'client_id' => $subUser->client_id,
            ],
        ]);
    }

    public function updatePermissions(Request $request, SubUser $subUser): JsonResponse
    {
        $this->authorize('updatePermissions', $subUser);

        $validKeys = [
            'can_chat', 'can_view_contracts', 'can_approve_contracts',
            'can_view_payments', 'can_upload_payment_proof',
            'can_view_approvals', 'can_respond_approvals',
            'can_view_files', 'can_upload_files',
            'can_view_meetings', 'can_join_meetings',
        ];

        $request->validate([
            'permissions' => 'required|array',
        ]);

        $permissions = collect($request->permissions)
            ->only($validKeys)
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

        return response()->json(['sub_user' => [
            'id' => $subUser->id,
            'permissions' => $subUser->fresh()->getPermissionsArray(),
        ]]);
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

        return response()->json([
            'sub_user' => [
                'id' => $subUser->id,
                'name' => $subUser->fresh()->name,
                'email' => $subUser->fresh()->email,
                'phone' => $subUser->fresh()->phone,
                'date_of_birth' => $subUser->fresh()->date_of_birth?->toDateString(),
                'avatar_url' => $subUser->fresh()->avatar_url,
            ],
        ]);
    }
}
