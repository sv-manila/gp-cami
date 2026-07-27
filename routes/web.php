<?php

use Illuminate\Support\Facades\Route;

/*
 * gp-cami is an API-only service. Identity/credential search and the wide
 * golden profile are served under routes/api.php (/api/v1/*). The read-only
 * web UI lives in the separate gp-cami-dashboard app.
 *
 * Root returns a small liveness pointer so hitting the host isn't a bare 404.
 */
Route::get('/', fn () => response()->json([
    'service' => 'gp-cami',
    'api' => '/api/v1',
]));
