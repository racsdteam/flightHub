<?php

use App\Http\Controllers\Api\ApiController;
use App\Http\Middleware\ApiGate;
use Illuminate\Support\Facades\Route;

// Distribution API v1, under /api/v1 (bootstrap/app.php). Read-only; every call goes
// through ApiGate (key, rate limits, api_calls log), unknown paths included.
Route::middleware(ApiGate::class)->group(function () {
    Route::get('flights', [ApiController::class, 'flights']);
    Route::get('flights/{aodbId}', [ApiController::class, 'flight'])->where('aodbId', '[A-Za-z0-9._:-]{1,64}');
    Route::get('changes', [ApiController::class, 'changes']);
    Route::get('health', [ApiController::class, 'health']);
    Route::fallback([ApiController::class, 'notFound']);
});
