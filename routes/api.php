<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

/*
 * Push ingest for sites that cannot serve a feed. Authenticated by an HMAC signature of the body
 * (see App\Http\Controllers\Api\IngestController), so it carries no session and no CSRF token.
 */
Route::post('/ingest/{source}', App\Http\Controllers\Api\IngestController::class)
    ->middleware('throttle:60,1')
    ->name('api.ingest');
