<?php

use App\Domains\DataExport\DataExportController;
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

// Data export download (DATA_SAFETY_PLAN.md §3.5.3) — same "signed URL is
// the only proof of authorization" pattern as files.serve above, since a
// data export archive is exactly the same kind of thing (a stored file)
// just far more sensitive in aggregate. See DataExportController::download
// for what the extra check on top of the signature covers.
Route::get('/exports/{dataExport}/download', [DataExportController::class, 'download'])
    ->middleware('signed')
    ->name('exports.download');

Route::get('/', function () {
    return view('welcome');
});
