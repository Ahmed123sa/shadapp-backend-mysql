<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// Serve stored files — this route catches /storage/* so files in
// storage/app/public are served even under PHP's built-in server
// (which blocks symlinks pointing outside the document root).
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
})->where('path', '.*');

Route::get('/', function () {
    return view('welcome');
});
