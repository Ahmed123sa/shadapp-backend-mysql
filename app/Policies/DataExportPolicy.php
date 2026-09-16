<?php

namespace App\Policies;

use App\Models\Client;
use App\Models\DataExport;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Mirrors app/Policies/DataExportPolicy.php from shadapp-backend (Postgres)
 * verbatim — no database-specific code involved.
 *
 * DATA_SAFETY_PLAN.md §3.2 — the three export scopes and who can request
 * each. Deliberately checked in a Policy on the server rather than trusted
 * from the frontend: this is the exact same class of bug (IDOR) fixed
 * earlier in the project — a manager asking to export a client that isn't
 * theirs must be rejected by the backend, not just hidden from the UI.
 */
class DataExportPolicy
{
    use HandlesAuthorization;

    /**
     * scope=system — the whole database. Super admin only.
     */
    public function requestSystem($user): bool
    {
        return $user instanceof User && $user->isSuperAdmin();
    }

    /**
     * scope=manager — a manager plus every client of theirs. Super admin can
     * request this for any manager; a manager can only request it for
     * themselves.
     */
    public function requestManager($user, int|string $targetManagerId): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->isSuperAdmin() || (int) $targetManagerId === $user->id;
    }

    /**
     * scope=client — one client's full tree. Super admin can request this
     * for any client; a manager only for a client currently assigned to
     * them.
     */
    public function requestClient($user, Client $client): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        return $user->isSuperAdmin() || $client->manager_id === $user->id;
    }

    /**
     * Governs both seeing a request in the list and pulling its download
     * link (DataExport::getDownloadUrlAttribute is only ever surfaced on
     * rows a request is allowed to see in the first place — see
     * DataExportController::index). The actual download route is
     * unauthenticated by design (it only carries a signed URL, the same
     * pattern as files.serve), so this Policy method is never called there;
     * see DataExportDownloadController's docblock for why that's still safe.
     */
    public function view($user, DataExport $dataExport): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->isSuperAdmin()) {
            return true;
        }

        return $dataExport->requested_by_type === User::class
            && (int) $dataExport->requested_by_id === $user->id;
    }
}
