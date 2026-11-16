<?php

use App\Http\Controllers\ApiDocsController;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Support\Facades\Route;

// API documentation (D-051): public, read-only, outside the web group (no session, no
// cookies). Same CSP as the admin screens (SecurityHeaders). Registered in bootstrap/app.php.
Route::middleware(SecurityHeaders::class)->group(function () {
    Route::get('api/docs', [ApiDocsController::class, 'page'])->name('api.docs');
    Route::get('api/openapi.yaml', [ApiDocsController::class, 'document'])->name('api.openapi');
});
