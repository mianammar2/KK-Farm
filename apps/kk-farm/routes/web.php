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
    // D-090: the app on each device and its updates
    Route::get('/account/app', function () {
        $file = public_path('downloads/kkfarm.json');
        $info = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return \Inertia\Inertia::render('Account/AppUpdates', ['android' => $info ? ['versionName' => $info['versionName'] ?? '', 'versionCode' => (int) ($info['versionCode'] ?? 0), 'notes' => $info['notes'] ?? ''] : null]);
    });

    Route::get('/', DashboardController::class)->middleware('module:dashboard');

    Route::get('/actions', \App\Http\Controllers\ActionCenterController::class)->middleware('module:dashboard'); // D-077
    Route::get('/activity', \App\Http\Controllers\ActivityController::class)->middleware('module:dashboard'); // D-122
    // D-121: the notification bell loads what needs action in the background, after the page has opened.
    Route::get('/notifications', fn (\Illuminate\Http\Request $r) => response()->json(collect(\App\Support\ActionCenter::items($r->user()))
        ->map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'count' => $i['count'], 'severity' => $i['severity'], 'url' => $i['url'], 'action' => $i['action']])->values()));

    Route::middleware('module:goats')->group(function (): void {
        Route::get('/goats', [GoatController::class, 'index']);
        Route::get('/goats/export', [GoatController::class, 'export']);
        Route::get('/goats/register', [GoatController::class, 'register']);
        Route::post('/goats', [GoatController::class, 'store']);
        Route::get('/goats/{animal}', [GoatController::class, 'show']);
        Route::post('/goats/{animal}/photos', [GoatController::class, 'addPhoto']);
        Route::post('/goats/{animal}/photos/{photo}/profile', [GoatController::class, 'setProfilePhoto']);
        Route::post('/goats/{animal}/exit', [GoatController::class, 'exitFarm']);
        Route::post('/goats/{animal}/birth-date', [GoatController::class, 'correctBirth']); // D-080
        Route::get('/goats/{animal}/edit', [GoatController::class, 'edit']); // D-091
        Route::post('/goats/{animal}/edit', [GoatController::class, 'update']);
        Route::post('/goats/{animal}/removal', [GoatController::class, 'requestRemoval']);
        Route::post('/goats/{animal}/removal/decide', [GoatController::class, 'decideRemoval']);
        Route::post('/goats/{animal}/restore', [GoatController::class, 'restore']);
        Route::post('/goats/{animal}/teeth', [GoatController::class, 'teeth']); // D-106
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
        Route::post('/breeding/matings/{mating}/close', [BreedingController::class, 'close']);
        Route::post('/breeding/weanings', [BreedingController::class, 'wean']);
        Route::post('/breeding/settings', [BreedingController::class, 'settings']);
    });

    Route::middleware('module:health')->group(function (): void {
        Route::get('/health', [HealthController::class, 'index']);
        Route::post('/health/observations', [HealthController::class, 'observe']);
        Route::post('/health/clinical', [HealthController::class, 'clinical']);
        Route::post('/health/records/{record}/close', [HealthController::class, 'close']);
        Route::post('/health/cases', [HealthController::class, 'openCase']);
        Route::post('/health/checkups', [HealthController::class, 'checkup']); // D-095
        Route::post('/health/checkups/{checkup}', [HealthController::class, 'updateCheckup']); // D-099
        Route::post('/health/preventive', [HealthController::class, 'preventive']);
        Route::post('/health/cases/{case}', [HealthController::class, 'updateCase']);
        Route::post('/health/cases/{case}/treatments', [HealthController::class, 'addTreatment']);
        Route::post('/health/medicines', [\App\Http\Controllers\MedicineController::class, 'store']);
        Route::post('/health/medicines/{medicine}', [\App\Http\Controllers\MedicineController::class, 'update']);
        Route::post('/health/medicine-stock', [\App\Http\Controllers\MedicineController::class, 'movement']);
    });

    Route::middleware('module:weights')->group(function (): void {
        Route::get('/weights', [WeightController::class, 'index']);
        Route::post('/weights', [WeightController::class, 'store']);
        Route::post('/weights/{record}', [WeightController::class, 'update']); // D-099
    });

    Route::middleware('module:feeding')->group(function (): void {
        Route::get('/feeding', [FeedingController::class, 'index']);
        Route::post('/feeding/record', [FeedingController::class, 'record']);
        Route::post('/feeding/leftover', [FeedingController::class, 'leftover']);
        Route::post('/feeding/items', [FeedingController::class, 'storeItem']);
        Route::post('/feeding/items/{item}', [FeedingController::class, 'updateItem']);
        Route::post('/feeding/standards', [FeedingController::class, 'storeStandard']); // D-116
        Route::post('/feeding/standards/assign', [FeedingController::class, 'assignStandard']);
        Route::post('/feeding/standards/{standard}', [FeedingController::class, 'updateStandard'])->whereNumber('standard');
        Route::post('/feeding/stock', [FeedingController::class, 'stock']);
        Route::post('/feeding/plans', [FeedingController::class, 'storePlan']);
        Route::post('/feeding/plans/{plan}/approve', [FeedingController::class, 'approve']);
        Route::post('/feeding/settings', [FeedingController::class, 'settings']);
    });

    Route::middleware('module:sheds')->group(function (): void {
        Route::get('/sheds', [ShedController::class, 'index']);
        Route::post('/sheds/transfer', [ShedController::class, 'transfer']);
        Route::get('/movements', [\App\Http\Controllers\MovementController::class, 'index']);
        Route::post('/movements', [\App\Http\Controllers\MovementController::class, 'store']);
        Route::post('/farms', [ShedController::class, 'storeFarm']); // D-128
        Route::patch('/farms/{farm}', [ShedController::class, 'updateFarm']);
        Route::delete('/farms/{farm}', [ShedController::class, 'destroyFarm']); // D-130
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
        Route::post('/tasks/{task}/status', [TaskController::class, 'status']);
    });

    Route::middleware('module:milking')->group(function (): void { // D-117
        Route::get('/milking', [\App\Http\Controllers\MilkingController::class, 'index']);
        Route::post('/milking/records', [\App\Http\Controllers\MilkingController::class, 'record']);
        Route::post('/milking/start', [\App\Http\Controllers\MilkingController::class, 'start']);
        Route::post('/milking/settings', [\App\Http\Controllers\MilkingController::class, 'settings']);
        Route::post('/milking/lactations/{lactation}/dry-off', [\App\Http\Controllers\MilkingController::class, 'dryOff']);
    });
    Route::middleware('module:value')->group(function (): void { // D-113
        Route::get('/value', [\App\Http\Controllers\HerdValueController::class, 'index']);
        Route::post('/value/rates', [\App\Http\Controllers\HerdValueController::class, 'storeRate']);
        Route::post('/value/batch', [\App\Http\Controllers\HerdValueController::class, 'batch']);
        Route::post('/value/feed-price', [\App\Http\Controllers\HerdValueController::class, 'feedPrice']);
    });
    Route::middleware('module:finance')->group(function (): void {
        Route::get('/finance', [FinanceController::class, 'index']);
        Route::post('/finance/entries', [FinanceController::class, 'store']);
        Route::post('/finance/entries/{entry}/reverse', [FinanceController::class, 'reverse']);
        Route::post('/finance/sales', [FinanceController::class, 'sale']);
        Route::post('/finance/entries/{entry}/paid', [FinanceController::class, 'markPaid']);
        // D-083: double-entry books
        Route::get('/finance/books', [\App\Http\Controllers\BooksController::class, 'index']);
        Route::post('/finance/journals', [\App\Http\Controllers\BooksController::class, 'storeJournal']);
        Route::post('/finance/journals/{journal}/reverse', [\App\Http\Controllers\BooksController::class, 'reverseJournal']);
        Route::post('/finance/accounts', [\App\Http\Controllers\BooksController::class, 'storeAccount']);
        Route::post('/finance/accounts/{account}', [\App\Http\Controllers\BooksController::class, 'updateAccount']);
    });

    // D-087: sensors, cameras, scales, gates; open areas
    Route::middleware('module:sensors')->group(function (): void {
        Route::get('/sensors', [\App\Http\Controllers\SensorsController::class, 'index']);
        Route::post('/sensors/devices', [\App\Http\Controllers\SensorsController::class, 'store']);
        Route::post('/sensors/devices/{device}', [\App\Http\Controllers\SensorsController::class, 'update']);
        Route::post('/sensors/devices/{device}/key', [\App\Http\Controllers\SensorsController::class, 'newKey']);
        Route::post('/sensors/devices/{device}/test', [\App\Http\Controllers\SensorsController::class, 'test']);
        Route::post('/sensors/alerts/{alert}', [\App\Http\Controllers\SensorsController::class, 'alert']);
    });
    Route::post('/presence', [\App\Http\Controllers\SensorsController::class, 'presence'])->middleware('module:sheds');

    Route::get('/reports', ReportController::class)->middleware('module:reports');

    Route::middleware('module:settings')->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings/breeds', [SettingsController::class, 'addBreed']);
        Route::post('/settings/breeds/{breed}', [SettingsController::class, 'updateBreed']);
        Route::post('/settings/ages', [SettingsController::class, 'updateAges']);
        Route::get('/settings/users', [UserController::class, 'index']);
        Route::post('/settings/users', [UserController::class, 'store']);
        Route::post('/settings/users/{id}', [UserController::class, 'update'])->whereNumber('id');
        Route::post('/settings/departments', [UserController::class, 'storeDepartment']);
        Route::post('/settings/departments/{department}', [UserController::class, 'updateDepartment']);
        Route::get('/settings/fields', [FieldController::class, 'index']);
        Route::post('/settings/fields', [FieldController::class, 'store']);
        Route::post('/settings/fields/{field}', [FieldController::class, 'update']);
    });
});
