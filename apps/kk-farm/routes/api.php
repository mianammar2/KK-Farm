<?php

use App\Http\Controllers\Api\ReadingsController;
use Illuminate\Support\Facades\Route;

// D-087: devices (scales, RFID gates, cameras, sensors) send readings here with their own key.
Route::middleware('throttle:600,1')->prefix('v1')->group(function (): void {
    Route::post('/readings', [ReadingsController::class, 'store']);
    Route::get('/ping', [ReadingsController::class, 'ping']);
    // D-355: the KK Farm gun app with its own key (given on its first report while someone was signed in)
    Route::post('/gun/report', [\App\Http\Controllers\GunController::class, 'reportByKey']);
    Route::post('/gun/screen', [\App\Http\Controllers\GunController::class, 'screenByKey']);
});
