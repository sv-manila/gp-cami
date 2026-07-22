<?php

use App\Models\Gp\GpIdentityProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/features'));

// Identity search dashboard — server-side query against the hub (no token needed).
Route::get('/search', function (Request $request) {
    $last = trim((string) $request->query('last', ''));
    $first = trim((string) $request->query('first', ''));
    $results = collect();

    if ($last !== '') {
        $results = GpIdentityProfile::query()
            ->where(function ($w) use ($last) {
                $w->whereRaw('LOWER(last_name) LIKE ?', ['%'.mb_strtolower($last).'%'])
                    ->orWhereRaw('LOWER(aliases) LIKE ?', ['%'.mb_strtolower($last).'%']);
            })
            ->when($first !== '', fn ($q) => $q->whereRaw('LOWER(first_name) LIKE ?', ['%'.mb_strtolower($first).'%']))
            ->orderByDesc('record_count')
            ->limit(50)
            ->get();
    }

    return view('search', ['last' => $last, 'first' => $first, 'results' => $results]);
})->name('search');

// Full detail for one golden identity — profile + rollups + source links.
Route::get('/identity/{id}', function ($id) {
    $p = GpIdentityProfile::find($id);
    abort_if(! $p, 404);

    $hub = DB::connection('golden_profile');

    return view('identity', [
        'p' => $p,
        'links' => $hub->table('gp_source_link')->where('identity_id', $id)->orderByDesc('match_score')->get(),
        'creds' => $hub->table('gp_identity_credential')->where('identity_id', $id)->get(),
        'excl' => $hub->table('gp_identity_exclusion')->where('identity_id', $id)->get(),
        'board' => $hub->table('gp_board_action')->where('identity_id', $id)->orderByDesc('action_date')->get(),
        'res' => $hub->table('gp_identity_resolution')->where('identity_id', $id)->where('is_current', 1)->get(),
    ]);
})->whereNumber('id')->name('identity');

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
            'model_tables' => count(array_filter(
                $hub->getSchemaBuilder()->getTableListing(),
                fn ($t) => str_contains($t, 'gp_') || str_contains($t, 'stg_'),
            )),
        ];
    } catch (\Throwable $e) {
        $stats = ['error' => $e->getMessage()];
    }

    return view('features', [
        'baseUrl' => $baseUrl(),
        'stats' => $stats,
        'passb' => config('golden_profile.probabilistic'),
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
