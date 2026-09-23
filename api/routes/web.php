<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
 * The privacy policy is served from the API rather than kept in the app
 * bundle, because both app stores require a URL they can open themselves —
 * during review, and from the store listing, where there is no app to open it
 * in. It has to stay reachable without authentication for the same reason.
 */
Route::get('/legal/privacy', function () {
    return view('legal.privacy', [
        'updated' => config('experience.legal.privacy_updated', 'September 2026'),
        'contact' => config('experience.legal.privacy_contact') ?: 'privacy@example.com',
    ]);
})->name('legal.privacy');
