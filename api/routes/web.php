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
        'termsUrl' => config('experience.legal.terms_url') ?: route('legal.terms'),
    ]);
})->name('legal.privacy');

/*
 * Terms are required in the listing and in the app for a subscription, and
 * Apple checks that both links open. Same reasoning as the policy above: no
 * authentication, and reachable when there is no app to open it in.
 */
Route::get('/legal/terms', function () {
    return view('legal.terms', [
        'updated' => config('experience.legal.terms_updated', 'October 2026'),
        'contact' => config('experience.legal.privacy_contact') ?: 'privacy@example.com',
        'price' => config('experience.legal.subscription_price'),
        'privacyUrl' => config('experience.legal.privacy_url') ?: route('legal.privacy'),
    ]);
})->name('legal.terms');

/*
 * ── Deep-link association (Android App Links, iOS Universal Links) ────────
 *
 * Both platforms decide whether an https link opens in the app by fetching a
 * file from the domain itself. That is the whole security model: only someone
 * who controls the domain can publish the file, so only they can claim its
 * links. Serving them from the application rather than as static uploads means
 * the association follows the deployment instead of being a separate thing to
 * remember.
 *
 * Neither is served half-built. An assetlinks.json with no certificate
 * fingerprint does not merely fail to verify — Android caches the failure, and
 * a cached failure is much harder to notice than a missing file.
 */
Route::get('/.well-known/assetlinks.json', function () {
    $fingerprints = (array) config('experience.app_links.android_sha256');

    abort_if($fingerprints === [], 404);

    return response()->json([[
        'relation' => ['delegate_permission/common.handle_all_urls'],
        'target' => [
            'namespace' => 'android_app',
            'package_name' => config('experience.app_links.android_package'),
            'sha256_cert_fingerprints' => $fingerprints,
        ],
    ]])->header('Content-Type', 'application/json');
})->name('well-known.assetlinks');

Route::get('/.well-known/apple-app-site-association', function () {
    $team = config('experience.app_links.ios_team_id');

    abort_if(blank($team), 404);

    return response()->json([
        'applinks' => [
            'details' => [[
                'appIDs' => [$team . '.' . config('experience.app_links.ios_bundle_id')],
                'components' => array_map(
                    fn (string $path) => ['/' => $path, 'comment' => 'Opens in the app'],
                    (array) config('experience.app_links.paths'),
                ),
            ]],
        ],
    ])
        /* Apple requires application/json and, unlike Android, will not follow
           a redirect to get it. */
        ->header('Content-Type', 'application/json');
})->name('well-known.aasa');
