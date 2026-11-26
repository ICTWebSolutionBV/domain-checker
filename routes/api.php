<?php

use App\Http\Controllers\Api\PartnerApiController;
use App\Http\Middleware\AuthenticatePartner;
use Illuminate\Support\Facades\Route;

// Partner API: server-to-server, stateless (the api group has no session,
// cookies or CSRF), bearer-token only. Prefixed /api by bootstrap/app.php.
Route::prefix('v1')->middleware(AuthenticatePartner::class)->name('api.')->group(function () {
    Route::post('/check', [PartnerApiController::class, 'check'])->middleware('throttle:partner-api')->name('check');
    Route::post('/bulk-check', [PartnerApiController::class, 'bulkCheck'])->middleware('throttle:partner-api-bulk')->name('bulk-check');
    Route::get('/tlds', [PartnerApiController::class, 'tlds'])->name('tlds');
});
