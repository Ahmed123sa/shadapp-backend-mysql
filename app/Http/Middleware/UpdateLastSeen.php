<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;

class UpdateLastSeen
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $user = Auth::user();
        if ($user !== null && Schema::hasColumn($user->getTable(), 'last_seen_at')) {
            $user->last_seen_at = now();
            $user->saveQuietly();
        }

        return $response;
    }
}
