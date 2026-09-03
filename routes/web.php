<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Serve stored files — this route catches /storage/* so files in
// storage/app/public are served even under PHP's built-in server
// (which blocks symlinks pointing outside the document root).
//
// `signed`: every URL that reaches here must carry a valid signature +
// expiry (see App\Support\FileUrl), generated only after the normal API
// authorization checks already passed when the URL was built. Without this,
// any file path — contracts, payment proofs, client documents — was
// reachable by anyone who could guess or intercept it, with zero auth of
// any kind. In production, remove the storage:link symlink too
// (public/storage), since it bypasses this route (and this check) entirely.
Route::get('/files/{path}', function (string $path) {
    $clean = ltrim($path, '/');
    if (! Storage::disk('public')->exists($clean)) {
        abort(404);
    }
    $response = Storage::disk('public')->response($clean);
    $response->headers->set('Access-Control-Allow-Origin', '*');
    $response->headers->set('Access-Control-Allow-Methods', 'GET, OPTIONS');
    $response->headers->set('Access-Control-Allow-Headers', '*');
    return $response;
})->where('path', '.*')->middleware('signed')->name('files.serve');

Route::get('/', function () {
    return view('welcome');
});
