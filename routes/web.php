<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/features'));

$baseUrl = fn () => rtrim(config('app.url'), '/') === 'http://localhost'
    ? 'http://127.0.0.1:8137'
    : rtrim(config('app.url'), '/');

// Features & capabilities overview — with live hub stats.
Route::get('/features', function () use ($baseUrl) {
    $stats = [];
    try {
        $hub = DB::connection('golden_profile');
        $stats = [
            'identities' => $hub->table('gp_identity')->count(),
            'source_rows' => $hub->table('gp_source_link')->count(),
            'profiles' => $hub->table('gp_identity_profile')->count(),
            'licenses' => $hub->table('gp_license')->count(),
            'exclusions' => $hub->table('gp_identity_exclusion')->count(),
            'audit' => $hub->table('gp_survivorship_audit')->count(),
            'sources' => $hub->table('gp_source_system')->count(),
            'tables' => $hub->getSchemaBuilder()->getTableListing(),
        ];
    } catch (\Throwable $e) {
        $stats = ['error' => $e->getMessage()];
    }

    return view('features', [
        'baseUrl' => $baseUrl(),
        'stats' => $stats,
        'generatedAt' => now()->toDayDateTimeString(),
    ]);
})->name('features');

// API documentation dashboard — rendered from live config.
Route::get('/docs', function () use ($baseUrl) {
    return view('docs', [
        'baseUrl' => $baseUrl(),
        'qualifying' => config('golden_profile.credential_search.qualifying_status_codes', []),
        'excluded' => config('golden_profile.credential_search.excluded_status_codes', []),
        'generatedAt' => now()->toDayDateTimeString(),
    ]);
})->name('docs');
