<?php

use App\Models\User;
use App\Models\Client;
use App\Models\SubUser;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return $user instanceof User && (int) $user->id === (int) $id;
});

Broadcast::channel('App.Models.Client.{id}', function ($client, $id) {
    return $client instanceof Client && (int) $client->id === (int) $id;
});

Broadcast::channel('workspace.{workspaceId}', function ($user, $workspaceId) {
    if ($user instanceof User) {
        return \App\Models\Workspace::where('id', $workspaceId)
            ->where('manager_id', $user->id)
            ->exists();
    }
    if ($user instanceof Client) {
        return \App\Models\Workspace::where('id', $workspaceId)
            ->where('client_id', $user->id)
            ->exists();
    }
    // A sub-user acts on behalf of their parent client (see
    // SubUserController/ApprovalController) and needs the same realtime
    // chat/payment events for that client's workspace — there was no branch
    // for this at all, so every sub-user silently got `false` here even
    // after BroadcastServiceProvider let their token through.
    if ($user instanceof SubUser) {
        return \App\Models\Workspace::where('id', $workspaceId)
            ->where('client_id', $user->client_id)
            ->exists();
    }
    return false;
});
