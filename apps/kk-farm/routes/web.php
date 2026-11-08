<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BreedingController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FieldController;
use App\Http\Controllers\FeedingController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GoatController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ShedController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WeightController;
use Illuminate\Support\Facades\Route;

// Short link to install the KK Farm Android app on the RFID gun (D-048). Public: the app itself needs a sign-in.
Route::get('/app', fn () => redirect('/downloads/kkfarm.apk'));

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);
});

Route::middleware('auth')->group(function (): void {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/switch-role', [AuthController::class, 'login']); // demo login only (D-044)
    Route::get('/account/password', [AuthController::class, 'editPassword']);
    Route::post('/account/password', [AuthController::class, 'updatePassword']);

    Route::get('/', DashboardController::class)->middleware('module:dashboard');

    Route::middleware('module:goats')->group(function (): void {
        Route::get('/goats', [GoatController::class, 'index']);
        Route::get('/goats/export', [GoatController::class, 'export']);
        Route::get('/goats/register', [GoatController::class, 'register']);
        Route::post('/goats', [GoatController::class, 'store']);
        Route::get('/goats/{animal}', [GoatController::class, 'show']);
        Route::post('/goats/{animal}/photos', [GoatController::class, 'addPhoto']);
        Route::post('/goats/{animal}/photos/{photo}/profile', [GoatController::class, 'setProfilePhoto']);
        Route::post('/goats/{animal}/exit', [GoatController::class, 'exitFarm']);
        Route::get('/media/{photo}', [GoatController::class, 'media']);
    });

    Route::middleware('module:scan')->group(function (): void {
        Route::get('/scan', [ScanController::class, 'index']);
        Route::post('/scan/assign', [ScanController::class, 'assign']);
        Route::post('/scan/remove', [ScanController::class, 'remove']);
    });

    Route::middleware('module:breeding')->group(function (): void {
        Route::get('/breeding', [BreedingController::class, 'index']);
        Route::post('/breeding/matings', [BreedingController::class, 'store']);
        Route::post('/breeding/matings/{mating}/check', [BreedingController::class, 'check']);
        Route::post('/breeding/matings/{mating}/kidding', [BreedingController::class, 'kidding']);
    });

    Route::middleware('module:health')->group(function (): void {
        Route::get('/health', [HealthController::class, 'index']);
        Route::post('/health/observations', [HealthController::class, 'observe']);
        Route::post('/health/clinical', [HealthController::class, 'clinical']);
        Route::post('/health/records/{record}/close', [HealthController::class, 'close']);
    });

    Route::middleware('module:weights')->group(function (): void {
        Route::get('/weights', [WeightController::class, 'index']);
        Route::post('/weights', [WeightController::class, 'store']);
    });

    Route::middleware('module:feeding')->group(function (): void {
        Route::get('/feeding', [FeedingController::class, 'index']);
        Route::post('/feeding/log', [FeedingController::class, 'log']);
        Route::post('/feeding/groups/{group}/approve', [FeedingController::class, 'approve']);
    });

    Route::middleware('module:sheds')->group(function (): void {
        Route::get('/sheds', [ShedController::class, 'index']);
        Route::post('/sheds/transfer', [ShedController::class, 'transfer']);
        Route::post('/sheds', [ShedController::class, 'store']);
        Route::patch('/sheds/{shed}', [ShedController::class, 'update']);
        Route::post('/sheds/{shed}/pens', [ShedController::class, 'storePen']);
        Route::patch('/pens/{pen}', [ShedController::class, 'updatePen']);
    });

    Route::middleware('module:employees')->group(function (): void {
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
    });

    Route::middleware('module:tasks')->group(function (): void {
        Route::get('/tasks', [TaskController::class, 'index']);
        Route::post('/tasks', [TaskController::class, 'store']);
        Route::post('/tasks/{task}/toggle', [TaskController::class, 'toggle']);
    });

    Route::middleware('module:finance')->group(function (): void {
        Route::get('/finance', [FinanceController::class, 'index']);
        Route::post('/finance/entries', [FinanceController::class, 'store']);
        Route::post('/finance/entries/{entry}/reverse', [FinanceController::class, 'reverse']);
    });

    Route::get('/reports', ReportController::class)->middleware('module:reports');

    Route::middleware('module:settings')->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings/breeds', [SettingsController::class, 'addBreed']);
        Route::post('/settings/ages', [SettingsController::class, 'updateAges']);
        Route::get('/settings/users', [UserController::class, 'index']);
        Route::post('/settings/users', [UserController::class, 'store']);
        Route::post('/settings/users/{id}', [UserController::class, 'update'])->whereNumber('id');
        Route::get('/settings/fields', [FieldController::class, 'index']);
        Route::post('/settings/fields', [FieldController::class, 'store']);
        Route::post('/settings/fields/{field}', [FieldController::class, 'update']);
    });
});
