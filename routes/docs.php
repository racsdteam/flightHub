<?php

use App\Http\Controllers\ApiDocsController;
use Illuminate\Support\Facades\Route;

// API documentation (D-051): public, read-only, outside the web group (no session, no
// cookies). Registered in bootstrap/app.php. The security headers and the CSP come from the
// global SecurityHeaders middleware, as on every response.
Route::get('api/docs', [ApiDocsController::class, 'page'])->name('api.docs');
Route::get('api/openapi.yaml', [ApiDocsController::class, 'document'])->name('api.openapi');
