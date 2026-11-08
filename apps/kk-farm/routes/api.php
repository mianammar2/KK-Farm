<?php

use App\Http\Controllers\Api\ReadingsController;
use Illuminate\Support\Facades\Route;

// D-087: devices (scales, RFID gates, cameras, sensors) send readings here with their own key.
Route::middleware('throttle:600,1')->prefix('v1')->group(function (): void {
    Route::post('/readings', [ReadingsController::class, 'store']);
    Route::get('/ping', [ReadingsController::class, 'ping']);
});
