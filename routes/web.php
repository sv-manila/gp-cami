<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/docs'));

// API documentation dashboard — rendered from live config.
Route::get('/docs', function () {
    return view('docs', [
        'baseUrl' => rtrim(config('app.url'), '/') === 'http://localhost'
            ? 'http://127.0.0.1:8137'
            : rtrim(config('app.url'), '/'),
        'qualifying' => config('golden_profile.credential_search.qualifying_status_codes', []),
        'excluded' => config('golden_profile.credential_search.excluded_status_codes', []),
        'generatedAt' => now()->toDayDateTimeString(),
    ]);
})->name('docs');
