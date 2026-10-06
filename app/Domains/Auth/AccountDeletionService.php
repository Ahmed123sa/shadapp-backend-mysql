<?php

namespace App\Domains\Auth;

use App\Models\AuditLog;
use App\Models\ChatMessage;
use App\Models\Client;
use App\Models\MobileNotificationToken;
use App\Models\SubUser;
use App\Models\User;
use App\Notifications\ManagerAccountDeletedNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * In-app account deletion (App Store Guideline 5.1.1(v)).
 *
 * This is an anonymisation, not a row delete: the person's identity and
 * credentials are removed and the account can never be signed into again, but
 * the contracts, payments, uploaded files and audit entries that belong to the
 * business relationship stay in place, detached from any personal data. That is
 * what DATA_SAFETY_PLAN.md promises ("nothing is deleted") while still giving
 * the user a real, irreversible deletion of their personal data.
 *
 * Per account type:
 *  - User (account manager): profile scrubbed, deactivated. Their clients are
 *    NOT reassigned here — super admins are notified to transfer them.
 *  - Client: profile scrubbed, status = 'deleted' (freezes the workspace like
 *    an archived client does), and every sub-user is removed.
 *  - SubUser: profile scrubbed by removing the row. The owning client is
 *    untouched.
 *
 * For every type: all Sanctum tokens, push (FCM) tokens, in-app notifications,
 * pending password-reset tokens and chat messages the account sent are
 * deleted. Super admins are rejected by the controller before reaching this.
 *
 * Deliberately left alone: signature_data copied into contract/approval
 * snapshots (a signed contract must stay valid), payment-proof and document
 * files, and messages other people sent in the workspace.
 */
class AccountDeletionService
{
    public const PLACEHOLDER_NAME = 'Deleted account';

    public function delete(Authenticatable $actor, Request $request): void
    {
        DB::transaction(function () use ($actor, $request) {
            match (true) {
                $actor instanceof Client => $this->deleteClient($actor, $request),
                $actor instanceof SubUser => $this->deleteSubUser($actor, $request),
                $actor instanceof User => $this->deleteStaff($actor, $request),
                default => throw new \InvalidArgumentException('Unsupported account type: ' . $actor::class),
            };
        });
    }

    private function deleteClient(Client $client, Request $request): void
    {
        $oldEmail = $client->email;
        $oldAvatar = $client->getRawOriginal('avatar_url');

        // Sub-users first: they act on behalf of this client and must not
        // outlive it.
        $subUsers = $client->subUsers()->get();
        foreach ($subUsers as $subUser) {
            $this->purgeFootprint($subUser);
            $this->deleteAvatarFile($subUser->getRawOriginal('avatar_url'));
            $subUser->delete();
        }

        $this->purgeFootprint($client);

        $client->forceFill([
            'company_name' => self::PLACEHOLDER_NAME,
            'contact_person' => self::PLACEHOLDER_NAME,
            'email' => $this->tombstoneEmail('client', $client->id),
            // clients.phone is NOT NULL.
            'phone' => '',
            'password' => Str::random(48),
            'status' => 'deleted',
            'notes' => null,
            'signature_data' => null,
            'signed_at' => null,
            'avatar_url' => null,
            'date_of_birth' => null,
            'latitude' => null,
            'longitude' => null,
            'location_address' => null,
            'location_updated_at' => null,
            'location_updated_by_ip' => null,
            'address' => null,
            'maps_url' => null,
        ])->save();

        $this->deleteAvatarFile($oldAvatar);
        $this->forgetPasswordResets($oldEmail);

        AuditLog::create([
            'auditable_type' => Client::class,
            'auditable_id' => $client->id,
            'client_id' => $client->id,
            // audit_logs.user_id is an FK into users: staff only.
            'user_id' => null,
            'action' => 'account.deleted',
            'metadata' => ['account_type' => 'client', 'sub_users_removed' => $subUsers->count()],
            'ip_address' => $request->ip(),
        ]);
    }

    private function deleteSubUser(SubUser $subUser, Request $request): void
    {
        $clientId = $subUser->client_id;
        $subUserId = $subUser->id;
        $oldEmail = $subUser->email;
        $oldAvatar = $subUser->getRawOriginal('avatar_url');

        $this->purgeFootprint($subUser);
        $subUser->delete();

        $this->deleteAvatarFile($oldAvatar);
        $this->forgetPasswordResets($oldEmail);

        AuditLog::create([
            'auditable_type' => SubUser::class,
            'auditable_id' => $subUserId,
            'client_id' => $clientId,
            'user_id' => null,
            'action' => 'account.deleted',
            'metadata' => ['account_type' => 'sub_user'],
            'ip_address' => $request->ip(),
        ]);
    }

    private function deleteStaff(User $user, Request $request): void
    {
        // Captured before the scrub: afterwards the name is a placeholder.
        $displayName = $user->name;
        $clientCount = Client::where('manager_id', $user->id)
            ->where('status', '!=', 'deleted')
            ->count();

        // A manager's assistants are scrubbed with them: they work under that
        // manager and must not outlive the account (plan §4.6).
        $assistantCount = 0;
        foreach ($user->assistants()->get() as $assistant) {
            $this->scrubStaff($assistant);
            $assistantCount++;
        }

        $this->scrubStaff($user);

        AuditLog::create([
            'auditable_type' => User::class,
            'auditable_id' => $user->id,
            'client_id' => null,
            'user_id' => $user->id,
            'action' => 'account.deleted',
            'metadata' => [
                'account_type' => $user->isAssistant() ? 'manager_assistant' : 'account_manager',
                'clients_needing_transfer' => $clientCount,
                'assistants_removed' => $assistantCount,
            ],
            'ip_address' => $request->ip(),
        ]);

        if ($clientCount > 0) {
            $admins = User::where('role', User::ROLE_SUPER_ADMIN)->get();
            Notification::send($admins, new ManagerAccountDeletedNotification($displayName, $clientCount));
        }
    }

    /**
     * Anonymises one staff row (manager or assistant): footprint purged,
     * personal data wiped, left deactivated so every "active staff" query
     * skips it.
     */
    private function scrubStaff(User $user): void
    {
        $oldEmail = $user->email;
        $oldAvatar = $user->getRawOriginal('avatar_url');

        $this->purgeFootprint($user);

        $user->forceFill([
            'name' => self::PLACEHOLDER_NAME,
            'email' => $this->tombstoneEmail('staff', $user->id),
            'official_email' => null,
            'phone' => null,
            'password' => Str::random(48),
            'remember_token' => null,
            'signature_data' => null,
            'signed_at' => null,
            'avatar_url' => null,
            'date_of_birth' => null,
            // Same state the existing deactivate flow produces.
            'is_active' => false,
            'deactivated_at' => now(),
        ])->save();

        $this->deleteAvatarFile($oldAvatar);
        $this->forgetPasswordResets($oldEmail);
    }

    /**
     * Everything tied to one account's identity or login that is not part of
     * the shared business record: auth tokens, device push tokens, in-app
     * notifications, and the chat messages that account wrote.
     */
    private function purgeFootprint(Authenticatable $account): void
    {
        $account->tokens()->delete();

        MobileNotificationToken::where('tokenable_type', $account::class)
            ->where('tokenable_id', $account->getKey())
            ->delete();

        $account->notifications()->delete();

        ChatMessage::where('sender_type', $account::class)
            ->where('sender_id', $account->getKey())
            ->delete();
    }

    private function forgetPasswordResets(?string $email): void
    {
        if ($email) {
            DB::table('password_reset_tokens')->where('email', $email)->delete();
        }
    }

    /**
     * Unique (the email columns are unique), unmistakably not real
     * (.invalid is reserved by RFC 2606), and carries no personal data.
     */
    private function tombstoneEmail(string $kind, int $id): string
    {
        return 'deleted-' . $kind . '-' . $id . '-' . Str::lower(Str::random(8)) . '@deleted.invalid';
    }

    /**
     * Avatars are stored as Storage::url() of a path on the public disk, i.e.
     * "/storage/avatars/xyz.jpg". Only files under avatars/ are removed;
     * signature images are deliberately kept (see the class docblock).
     */
    private function deleteAvatarFile(?string $url): void
    {
        if (! $url) {
            return;
        }

        $marker = '/storage/';
        $pos = strpos($url, $marker);
        $path = $pos === false ? ltrim($url, '/') : substr($url, $pos + strlen($marker));
        $path = strtok($path, '?');

        if ($path && str_starts_with($path, 'avatars/')) {
            Storage::disk('public')->delete($path);
        }
    }
}
