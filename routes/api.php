<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API V1 ROUTES
|--------------------------------------------------------------------------
*/

use App\Http\Controllers\Api\V1\CodeAnalysisController;
Route::prefix('v1')->group(function () {
    Route::post('/analyze', [CodeAnalysisController::class, 'analyze']);
});