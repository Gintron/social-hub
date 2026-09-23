<?php

declare(strict_types=1);

use App\Http\Controllers\Meta\MetaOAuthController;
use App\Http\Controllers\Meta\MetaWebhookController;
use App\Http\Controllers\TikTok\TikTokBusinessOAuthController;
use App\Http\Controllers\TikTok\TikTokOAuthController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

/*
 * Own legal pages for the hub itself (Meta/TikTok app review need working, verifiable URLs —
 * the brands' own ToS/Privacy pages describe their consumer products, not this internal tool).
 */
Route::view('/uvjeti', 'legal.terms')->name('legal.terms');
Route::view('/privatnost', 'legal.privacy')->name('legal.privacy');

/*
 * Facebook login round-trip. Behind the panel's auth: only a signed-in hub admin may connect
 * accounts, and the callback needs the same session to verify its state.
 */
Route::middleware(['web', 'auth'])->group(function (): void {
    Route::get('/meta/connect/{brand}', [MetaOAuthController::class, 'connect'])->name('meta.connect');
    Route::get('/meta/callback', [MetaOAuthController::class, 'callback'])->name('meta.callback');

    Route::get('/tiktok/connect/{brand}', [TikTokOAuthController::class, 'connect'])->name('tiktok.connect');
    Route::get('/tiktok/callback', [TikTokOAuthController::class, 'callback'])->name('tiktok.callback');

    /*
     * TikTok API for Business. Its redirect URL must end in a slash, so the route does too — the
     * portal rejects the version without it. See docs/tiktok-business-api.md.
     */
    Route::get('/tiktok/business/connect/{brand}', [TikTokBusinessOAuthController::class, 'connect'])->name('tiktok.business.connect');
    Route::get('/tiktok/business/callback/', [TikTokBusinessOAuthController::class, 'callback'])->name('tiktok.business.callback');
});

/*
 * Called by Meta, not by a browser: no session, no CSRF token, authenticated by signed_request.
 */
Route::post('/meta/deauthorize', [MetaWebhookController::class, 'deauthorize'])->name('meta.deauthorize');
Route::post('/meta/data-deletion', [MetaWebhookController::class, 'dataDeletion'])->name('meta.data-deletion');
Route::get('/meta/data-deletion/{code}', [MetaWebhookController::class, 'dataDeletionStatus'])->name('meta.data-deletion.status');
