<?php

namespace App\Domains\Auth;

use App\Models\User;
use App\Models\Client;
use App\Models\SubUser;
use App\Http\Requests\LoginRequest;
use App\Support\UploadRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
        }

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'avatar_url' => $user->avatar_url],
        ]);
    }

    public function clientLogin(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        // 1. Try Client first
        $client = Client::where('email', $request->email)->first();
        if ($client && Hash::check($request->password, $client->password)) {
            $token = $client->createToken('client-token')->plainTextToken;
            return response()->json([
                'token' => $token,
                'login_type' => 'client',
                'client' => [
                    'id' => $client->id,
                    'company_name' => $client->company_name,
                    'contact_person' => $client->contact_person,
                    'email' => $client->email,
                    'status' => $client->status,
                    'has_signed' => !is_null($client->signed_at),
                ],
                'workspace_id' => $client->workspace?->id,
            ]);
        }

        // 2. Try SubUser
        $subUser = SubUser::where('email', $request->email)->first();
        if ($subUser && Hash::check($request->password, $subUser->password)) {
            $token = $subUser->createToken('sub-user-token')->plainTextToken;
            return response()->json([
                'token' => $token,
                'login_type' => 'sub_user',
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

        throw ValidationException::withMessages(['email' => ['Invalid credentials.']]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }

    public function registerSuperAdmin(Request $request): JsonResponse
    {
        if (User::where('role', User::ROLE_SUPER_ADMIN)->exists()) {
            return response()->json(['message' => 'Registration is closed.'], 403);
        }

        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users',
            'password' => 'required|min:8|regex:/[A-Za-z]/|regex:/[0-9]/',
            'official_email' => 'nullable|email',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password,
            'role' => User::ROLE_SUPER_ADMIN,
            'official_email' => $request->official_email,
        ]);

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email, 'role' => $user->role, 'official_email' => $user->official_email],
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()]);
    }

    public function sign(Request $request): JsonResponse
    {
        if ($request->hasFile('signature_image')) {
            $request->validate(['signature_image' => UploadRules::image(required: true)]);
            $path = $request->file('signature_image')->store('signatures', 'public');
            $signatureData = Storage::url($path);
        } else {
            $request->validate(['signature' => 'required|string']);
            $signatureData = $request->signature;
        }

        $user = $request->user();
        $user->update([
            'signature_data' => $signatureData,
            'signed_at' => now(),
        ]);

        return response()->json(['user' => $user->fresh()]);
    }

    public function deleteSign(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->update([
            'signature_data' => null,
            'signed_at' => null,
        ]);
        return response()->json(['user' => $user->fresh()]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:20',
            'official_email' => 'nullable|email',
            'date_of_birth' => 'nullable|date',
            'avatar' => UploadRules::image(),
        ]);

        $user = $request->user();

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $user->avatar_url = Storage::url($path);
            $user->save();
        }

        $user->update($request->only(['name', 'phone', 'official_email', 'date_of_birth']));

        return response()->json(['user' => $user->fresh()]);
    }
}
