<?php

use App\Http\Controllers\Api\V1\CredentialSearchController;
use App\Http\Controllers\Api\V1\IdentitySearchController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::prefix('v1')
    ->middleware(['auth:sanctum', 'throttle:120,1'])
    ->group(function () {
        Route::post('/identity-search', IdentitySearchController::class);
        Route::post('/credential-search', CredentialSearchController::class);
    });
