<?php

use App\Models\User;
use App\Models\Client;
use App\Models\SubUser;
use App\Models\Workspace;
use App\Policies\WorkspacePolicy;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return $user instanceof User && (int) $user->id === (int) $id;
});

Broadcast::channel('App.Models.Client.{id}', function ($client, $id) {
    return $client instanceof Client && (int) $client->id === (int) $id;
});

Broadcast::channel('workspace.{workspaceId}', function ($user, $workspaceId) {
    // The accountant's live updates come through their own user channel only;
    // a workspace channel carries chat events they must never receive.
    if ($user instanceof User && $user->isAccountant()) {
        return false;
    }
    $workspace = Workspace::find($workspaceId);
    $canView = $workspace !== null && app(WorkspacePolicy::class)->view($user, $workspace);
    return $canView;
});