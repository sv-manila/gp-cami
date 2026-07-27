<?php

use App\Models\Gp\GpIdentityProfile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect('/search'));

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
// ?fragment=1 returns just the inner content (for the search modal).
Route::get('/identity/{id}', function (Request $request, $id) {
    $p = GpIdentityProfile::find($id);
    abort_if(! $p, 404);

    $hub = DB::connection('golden_profile');
    $data = [
        'p' => $p,
        'links' => $hub->table('gp_source_link')->where('identity_id', $id)->orderByDesc('match_score')->get(),
        'creds' => $hub->table('gp_identity_credential')->where('identity_id', $id)->get(),
        'excl' => $hub->table('gp_identity_exclusion')->where('identity_id', $id)->get(),
        'board' => $hub->table('gp_board_action')->where('identity_id', $id)->orderByDesc('action_date')->get(),
        'res' => $hub->table('gp_identity_resolution')->where('identity_id', $id)->where('is_current', 1)->get(),
    ];

    // Raw match JSON from the source (read-only): credential_matches.match, matches.metadata.
    $src = DB::connection('streamline_local');
    $credIds = $data['creds']->pluck('credential_match_id')->all();
    $exclIds = $data['excl']->pluck('match_id')->all();
    $data['credJson'] = $credIds ? $src->table('credential_matches')->whereIn('id', $credIds)->pluck('match', 'id')->all() : [];
    $data['exclJson'] = $exclIds ? $src->table('matches')->whereIn('id', $exclIds)->pluck('metadata', 'id')->all() : [];

    return view($request->boolean('fragment') ? '_identity_detail' : 'identity', $data);
})->whereNumber('id')->name('identity');
