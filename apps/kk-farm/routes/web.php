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
    Route::get('/reports/operations', [\App\Http\Controllers\OperationsLogController::class, 'index']); // D-307/D-308: each app's Operations Log (the controller checks the app)
    Route::get('/lookup/goat', [GoatController::class, 'lookup']); // D-305: an app's scanner opens one goat's card (right "Scan goats"), without the Goats pages
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
    // D-168: the app launcher (Odoo-style home): every module the user may open, as icon tiles. Login lands here.
    Route::get('/apps', fn () => \Inertia\Inertia::render('Apps'));
    Route::get('/apps/{key}', fn () => redirect('/apps'))->where('key', '[a-z]+'); // D-218: master pages removed; old links go to All Apps
    // D-187: App 18 Fattening & Meat Production
    Route::middleware('module:fodder')->group(function (): void { // D-202: App 22 Fodder & Pasture
        $fc = \App\Http\Controllers\FodderController::class;
        Route::get('/fodder', [$fc, 'index']);
        Route::post('/fodder/toggle', [$fc, 'toggle']);
        Route::post('/fodder/fields', [$fc, 'field']);
        Route::post('/fodder/fields/{field}', [$fc, 'field']);
        Route::post('/fodder/crops', [$fc, 'crop']);
        Route::post('/fodder/crops/{crop}/inputs', [$fc, 'input']);
        Route::post('/fodder/crops/{crop}/harvest', [$fc, 'harvest']);
        Route::post('/fodder/lots/{lot}/test', [$fc, 'test']);
        Route::post('/fodder/lots/{lot}/decide', [$fc, 'decide']);
    });
    Route::middleware('module:fattening')->group(function (): void {
        Route::get('/fattening', [\App\Http\Controllers\FatteningController::class, 'index']);
        Route::post('/fattening/batches', [\App\Http\Controllers\FatteningController::class, 'store']);
        Route::post('/fattening/batches/{batch}/add', [\App\Http\Controllers\FatteningController::class, 'add']);
        Route::post('/fattening/batches/{batch}/plan', [\App\Http\Controllers\FatteningController::class, 'plan']);
        Route::post('/fattening/batches/{batch}/weigh', [\App\Http\Controllers\FatteningController::class, 'weigh']);
        Route::post('/fattening/members/{member}/clear', [\App\Http\Controllers\FatteningController::class, 'clear']);
        Route::post('/fattening/members/{member}/reserve', [\App\Http\Controllers\FatteningController::class, 'reserve']);
        Route::post('/fattening/members/{member}/sell', [\App\Http\Controllers\FatteningController::class, 'sell']);
        Route::post('/fattening/selections', [\App\Http\Controllers\FatteningController::class, 'propose']);
        Route::post('/fattening/selections/{selection}/decide', [\App\Http\Controllers\FatteningController::class, 'decide']);
    });
    // D-186: App 20 Water, Climate & Environment
    Route::middleware('module:environment')->group(function (): void {
        Route::get('/environment', [\App\Http\Controllers\EnvironmentController::class, 'index']);
        Route::post('/environment/rounds', [\App\Http\Controllers\EnvironmentController::class, 'round']);
        Route::post('/environment/issues/{issue}/resolve', [\App\Http\Controllers\EnvironmentController::class, 'resolve']);
        Route::post('/environment/issues/{issue}/verify', [\App\Http\Controllers\EnvironmentController::class, 'verify']);
        Route::post('/environment/water-points', [\App\Http\Controllers\EnvironmentController::class, 'waterPoint']);
        Route::post('/environment/water-points/{point}', [\App\Http\Controllers\EnvironmentController::class, 'waterPoint']);
        Route::post('/environment/settings', [\App\Http\Controllers\EnvironmentController::class, 'settings']);
        Route::post('/environment/water/report', [\App\Http\Controllers\EnvironmentController::class, 'report']);
        Route::get('/waste', [\App\Http\Controllers\WasteController::class, 'index']); // D-204
        Route::post('/waste/streams', [\App\Http\Controllers\WasteController::class, 'stream']);
        Route::post('/waste/streams/{stream}', [\App\Http\Controllers\WasteController::class, 'stream']);
        Route::post('/waste/disposals', [\App\Http\Controllers\WasteController::class, 'dispose']);
        Route::post('/waste/disposals/{disposal}/audit', [\App\Http\Controllers\WasteController::class, 'audit']);
        Route::post('/environment/water/tests', [\App\Http\Controllers\EnvironmentController::class, 'test']);
        Route::post('/environment/issues/{issue}/supply', [\App\Http\Controllers\EnvironmentController::class, 'supply']);
        Route::post('/environment/issues/{issue}/approve-supply', [\App\Http\Controllers\EnvironmentController::class, 'approveSupply']);
        Route::post('/environment/issues/{issue}/health-check', [\App\Http\Controllers\EnvironmentController::class, 'healthCheck']);
    });
    // D-194: journey E Sale-to-Cash & Exit
    Route::middleware('module:goats,sales')->group(function (): void { // D-203: sales plans, vet / caretakers check fitness, the crew checks on the road; D-241: finance plans trips without the goat register
        Route::get('/transport', [\App\Http\Controllers\TransportController::class, 'index']); // D-203
        Route::post('/transport/vehicles', [\App\Http\Controllers\TransportController::class, 'vehicle']);
        Route::post('/transport/vehicles/{vehicle}', [\App\Http\Controllers\TransportController::class, 'vehicle']);
        Route::post('/transport/trips', [\App\Http\Controllers\TransportController::class, 'plan']);
        Route::post('/transport/lines/{line}/fitness', [\App\Http\Controllers\TransportController::class, 'fitness']);
        Route::post('/transport/trips/{trip}/check', [\App\Http\Controllers\TransportController::class, 'check']);
        Route::post('/transport/trips/{trip}/deliver', [\App\Http\Controllers\TransportController::class, 'deliver']);
        Route::post('/transport/claims/{claim}', [\App\Http\Controllers\TransportController::class, 'claim']);
    });
    Route::middleware('module:sales')->group(function (): void {
        $so = \App\Http\Controllers\SaleOrderController::class;
        Route::get('/sales/orders', [$so, 'index']);
        Route::post('/sales/orders', [$so, 'quote']);
        Route::post('/sales/orders/{order}/approve', [$so, 'approve']);
        Route::post('/sales/orders/{order}/checklist', [$so, 'checklist']);
        Route::post('/sales/orders/{order}/scan', [$so, 'scan']);
        Route::post('/sales/orders/{order}/dispatch', [$so, 'dispatch']);
        Route::post('/sales/orders/{order}/collect', [$so, 'collect']);
        Route::post('/sales/orders/{order}/cancel', [$so, 'cancel']);
        Route::post('/sales/order-lines/{line}/remove', [$so, 'remove']);
    });

    // D-193: journey D Disease-to-Recovery
    Route::middleware('module:health')->group(function (): void {
        $dc = \App\Http\Controllers\DiseaseController::class;
        Route::get('/disease', [$dc, 'index']);
        Route::post('/disease/triage', [$dc, 'triage']);
        Route::post('/disease/cases/{case}/lab', [$dc, 'lab']);
        Route::post('/disease/cases/{case}/extend', [$dc, 'extend']);
        Route::post('/disease/cases/{case}/clear', [$dc, 'clear']);
        Route::post('/disease/outbreaks/{outbreak}/close', [$dc, 'closeOutbreak']);
        Route::post('/disease/settings', [$dc, 'settings']);
    });

    // D-192: journey C Feed-to-True-Cost
    Route::middleware('module:feed_cost')->group(function (): void {
        $fc = \App\Http\Controllers\FeedCostController::class;
        Route::get('/feed-cost', [$fc, 'index']);
        Route::post('/feed-cost/count', [$fc, 'count']);
        Route::post('/feed-cost/purchase', [$fc, 'purchase']);
        Route::post('/feed-cost/pens/{pen}/review', [$fc, 'review']);
        Route::post('/feed-cost/settings', [$fc, 'settings']);
    });

    // D-191: journey B Birth-to-Breeder: growth check, interventions, breeder / sale selection, growth plan
    Route::middleware('module:weights')->group(function (): void {
        $bb = \App\Http\Controllers\BirthToBreederController::class;
        Route::get('/growth', [$bb, 'index']);
        Route::get('/weights/review', [\App\Http\Controllers\WeightReviewController::class, 'index']); // D-208
        Route::post('/weights/review/{record}', [\App\Http\Controllers\WeightReviewController::class, 'decide'])->where('record', '[0-9]+'); // D-266 F-26: a malformed number is a 404, not a 500
        Route::post('/growth/{animal}/intervene', [$bb, 'intervene']);
        Route::post('/growth/interventions/{intervention}/follow-up', [$bb, 'followUp']);
        Route::post('/growth/{animal}/select', [$bb, 'select']);
        Route::post('/growth/plan', [$bb, 'plan']);
        Route::post('/growth/settings', [$bb, 'settings']);
    });

    // D-189: App 21 Quality, Compliance & Safety
    Route::middleware('module:quality')->group(function (): void {
        $q = \App\Http\Controllers\QualityController::class;
        Route::get('/quality', [$q, 'index']);
        Route::post('/quality/cases', [$q, 'open']);
        Route::post('/quality/cases/{case}/hold', [$q, 'hold']);
        Route::post('/quality/cases/{case}/investigate', [$q, 'investigate']);
        Route::post('/quality/cases/{case}/capa', [$q, 'capa']);
        Route::post('/quality/cases/{case}/close', [$q, 'close']);
        Route::post('/quality/capas/{capa}/approve', [$q, 'approve']);
        Route::post('/quality/capas/{capa}/execute', [$q, 'execute']);
        Route::post('/quality/capas/{capa}/verify', [$q, 'verify']);
        Route::post('/quality/holds/{hold}/release', [$q, 'release']);
        Route::post('/quality/sops', [$q, 'sop']);
        Route::post('/quality/sops/{sop}/revise', [$q, 'revise']);
        Route::post('/quality/sop-revisions/{revision}/approve', [$q, 'approveSop']);
        Route::post('/quality/register', [$q, 'register']);
        Route::post('/quality/register/{item}', [$q, 'register']);
        Route::post('/quality/drills', [$q, 'drill']);
    });

    // D-185: App 19 Animal Welfare & Husbandry
    Route::middleware('module:welfare')->group(function (): void {
        Route::get('/welfare', [\App\Http\Controllers\WelfareController::class, 'index']);
        $eol = \App\Http\Controllers\EndOfLifeController::class; // D-211
        Route::get('/welfare/end-of-life', [$eol, 'index']);
        Route::post('/welfare/end-of-life', [$eol, 'store']);
        Route::post('/welfare/end-of-life/{review}/assess', [$eol, 'assess']);
        Route::post('/welfare/end-of-life/{review}/authorise', [$eol, 'authorise']);
        Route::post('/welfare/end-of-life/{review}/perform', [$eol, 'perform']);
        Route::post('/welfare/end-of-life/{review}/withdraw', [$eol, 'withdraw']);
        Route::post('/welfare/checks', [\App\Http\Controllers\WelfareController::class, 'check']);
        Route::post('/welfare/cases', [\App\Http\Controllers\WelfareController::class, 'open']);
        Route::post('/welfare/cases/{case}/step', [\App\Http\Controllers\WelfareController::class, 'step']);
    });
    // D-182: Maintenance & Assets
    Route::middleware('module:maintenance')->group(function (): void {
        Route::get('/maintenance', [\App\Http\Controllers\MaintenanceController::class, 'index']);
        Route::post('/maintenance/assets', [\App\Http\Controllers\MaintenanceController::class, 'asset']);
        Route::post('/maintenance/assets/{asset}', [\App\Http\Controllers\MaintenanceController::class, 'asset']);
        Route::post('/maintenance/jobs', [\App\Http\Controllers\MaintenanceController::class, 'report']);
        Route::post('/maintenance/jobs/{job}/close', [\App\Http\Controllers\MaintenanceController::class, 'close']);
    });
    // D-180: Farm Operations (checklists, incidents, shift handover)
    Route::middleware('module:tasks')->group(function (): void {
        Route::get('/operations', [\App\Http\Controllers\OperationsController::class, 'index']);
        Route::post('/operations/checklists/{template}/tick', [\App\Http\Controllers\OperationsController::class, 'tick']);
        Route::post('/operations/runs/{run}/verify', [\App\Http\Controllers\OperationsController::class, 'verify']);
        Route::post('/operations/incidents', [\App\Http\Controllers\OperationsController::class, 'incident']);
        Route::post('/operations/incidents/{incident}/resolve', [\App\Http\Controllers\OperationsController::class, 'resolve']);
        Route::post('/operations/incidents/{incident}/escalate', [\App\Http\Controllers\OperationsController::class, 'escalate']); // D-205
        Route::post('/operations/handovers', [\App\Http\Controllers\OperationsController::class, 'handover']);
        Route::post('/operations/templates', [\App\Http\Controllers\OperationsController::class, 'template']);
        Route::post('/operations/templates/{template}', [\App\Http\Controllers\OperationsController::class, 'template']);
    });
    // D-179: the worker app for the RFID gun (pick a job, then scan goat after goat)
    Route::get('/worker', [\App\Http\Controllers\WorkerController::class, 'index']);
    Route::post('/worker/start', [\App\Http\Controllers\WorkerController::class, 'start']);
    Route::post('/worker/stop', [\App\Http\Controllers\WorkerController::class, 'stop']);
    Route::post('/worker/done', [\App\Http\Controllers\WorkerController::class, 'done']); // D-255
    Route::post('/worker/weigh', [\App\Http\Controllers\WorkerController::class, 'weigh']);
    Route::post('/worker/health', [\App\Http\Controllers\WorkerController::class, 'health']);
    Route::post('/worker/move', [\App\Http\Controllers\WorkerController::class, 'move']);
    // D-172: Intake & Quarantine (purchased and transferred goats until veterinary clearance)
    Route::get('/intake', [\App\Http\Controllers\IntakeController::class, 'index'])->middleware('module:goats');
    Route::post('/intake/settings', [\App\Http\Controllers\IntakeController::class, 'settings'])->middleware('module:goats');
    Route::post('/intake/{case}/clear', [\App\Http\Controllers\IntakeController::class, 'clear'])->middleware('module:health');
    Route::post('/intake/{case}/extend', [\App\Http\Controllers\IntakeController::class, 'extend'])->middleware('module:health'); // D-190
    // D-190: journey A Purchase-to-Quarantine: goat purchase lots
    Route::middleware('module:animal_purchase')->group(function (): void {
        $ap = \App\Http\Controllers\AnimalPurchaseController::class;
        Route::get('/purchase/animals', [$ap, 'index']);
        Route::post('/purchase/animals', [$ap, 'request']);
        Route::post('/purchase/animals/{lot}/decide', [$ap, 'decide']);
        Route::post('/purchase/animals/{lot}/cancel', [$ap, 'cancel']);
        Route::post('/purchase/animals/{lot}/receive', [$ap, 'receive']);
        Route::post('/purchase/animals/{lot}/reconcile', [$ap, 'reconcile']);
    });

    Route::get('/actions', \App\Http\Controllers\ActionCenterController::class)->middleware('module:dashboard'); // D-077
    Route::delete('/masters/{type}/{id}', [\App\Http\Controllers\MasterController::class, 'destroy'])->where('type', '[a-z-]+'); // D-240: delete on every master list
    Route::get('/home', \App\Http\Controllers\HomeController::class); // D-241: each role's own home
    Route::get('/dashboard/app/{app}', \App\Http\Controllers\AppDashboardController::class)->where('app', '[a-z_]+'); // D-257
    Route::get('/dashboard/scorecard', \App\Http\Controllers\ScorecardController::class)->middleware('module:dashboard'); // D-233
    Route::get('/dashboard/weight-change', \App\Http\Controllers\WeightChangeController::class)->middleware('module:dashboard'); // D-216
    Route::get('/activity', \App\Http\Controllers\ActivityController::class)->middleware('module:dashboard'); // D-122
    // D-121: the notification bell loads what needs action in the background, after the page has opened.
    Route::get('/notifications', fn (\Illuminate\Http\Request $r) => response()->json(collect(\App\Support\ActionCenter::items($r->user()))
        ->map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'count' => $i['count'], 'severity' => $i['severity'], 'url' => $i['url'], 'action' => $i['action']])->values()));

    Route::middleware('module:goats')->group(function (): void {
        Route::get('/goats', [GoatController::class, 'index']);
        Route::get('/goats/export', [GoatController::class, 'export']);
        Route::get('/goats/register', [GoatController::class, 'register']);
        Route::post('/goats', [GoatController::class, 'store']);
        Route::get('/goats/find', [GoatController::class, 'find']); // D-293: typed Tag ID or scanned RFID → the goat
        Route::get('/goats/{animal}', [GoatController::class, 'show']);
        Route::get('/goats/{animal}/peek', [GoatController::class, 'peek']); // D-248: the card when a goat is tapped in the 3D shed
        Route::post('/goats/{animal}/rfid/arm', [\App\Http\Controllers\RfidAttachController::class, 'arm']); // D-144
        Route::post('/goats/rfid/disarm', [\App\Http\Controllers\RfidAttachController::class, 'disarm']);
        Route::post('/goats/{animal}/rfid', [\App\Http\Controllers\RfidAttachController::class, 'attach']);
        Route::post('/goats/{animal}/photos', [GoatController::class, 'addPhoto']);
        Route::post('/goats/{animal}/photos/{photo}/profile', [GoatController::class, 'setProfilePhoto']);
        Route::post('/goats/{animal}/exit', [GoatController::class, 'exitFarm']);
        Route::post('/goats/{animal}/death', [\App\Http\Controllers\DeathController::class, 'store']); // D-260: the vet reports
        Route::post('/goats/{animal}/height', [GoatController::class, 'height']); // D-262
        Route::get('/goats/{animal}/day/{on}', [GoatController::class, 'day']);
        Route::get('/goats/{animal}/profile', [GoatController::class, 'profile']); // D-300: the full profile card, JSON // D-270: one day's records, JSON
        Route::get('/reports/sheet', [\App\Http\Controllers\HerdSheetController::class, 'index']); // D-288: the Herd Sheet
        Route::get('/reports/records', [\App\Http\Controllers\RecordsReportController::class, 'index']); // D-270
        Route::get('/reports/records/day', [\App\Http\Controllers\RecordsReportController::class, 'day']); // D-270, JSON
        Route::post('/goats/{animal}/cull', [\App\Http\Controllers\DeathController::class, 'cull']); // D-260: out of breeding, not off the farm
        Route::post('/death-reports/{report}', [\App\Http\Controllers\DeathController::class, 'decide']); // D-260: the administrator approves
        Route::post('/goats/{animal}/birth-date', [GoatController::class, 'correctBirth']); // D-080
        Route::get('/goats/{animal}/edit', [GoatController::class, 'edit']); // D-091
        Route::post('/goats/{animal}/edit', [GoatController::class, 'update']);
        Route::post('/goats/{animal}/removal', [GoatController::class, 'requestRemoval']);
        Route::post('/goats/{animal}/removal/decide', [GoatController::class, 'decideRemoval']);
        Route::post('/goats/{animal}/restore', [GoatController::class, 'restore']);
        Route::post('/goats/{animal}/teeth', [GoatController::class, 'teeth']); // D-106
        Route::post('/goats/{animal}/castration', [GoatController::class, 'castration']); // D-230
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
        Route::get('/breeding/eligibility', [\App\Http\Controllers\EligibilityController::class, 'index']); // D-231
        Route::post('/breeding/eligibility/rules', [\App\Http\Controllers\EligibilityController::class, 'saveRule']);
        Route::delete('/breeding/eligibility/rules/{rule}', [\App\Http\Controllers\EligibilityController::class, 'deleteRule']);
        Route::post('/breeding/eligibility/{animal}', [\App\Http\Controllers\EligibilityController::class, 'decide']);
        Route::get('/repro-loss', [\App\Http\Controllers\ReproLossController::class, 'index']); // D-206
        Route::post('/repro-loss/{case}/contain', [\App\Http\Controllers\ReproLossController::class, 'contain']);
        Route::post('/repro-loss/{case}/material', [\App\Http\Controllers\ReproLossController::class, 'material']);
        Route::post('/repro-loss/{case}/sample', [\App\Http\Controllers\ReproLossController::class, 'sample']);
        Route::post('/repro-loss/{case}/diagnose', [\App\Http\Controllers\ReproLossController::class, 'diagnose']);
        Route::post('/repro-loss/{case}/close', [\App\Http\Controllers\ReproLossController::class, 'close']);
        Route::post('/repro-loss/staff/{user}/train', [\App\Http\Controllers\ReproLossController::class, 'train']);
        Route::get('/genetics', [\App\Http\Controllers\GeneticsController::class, 'index']); // D-201
        Route::post('/genetics/decide/{animal}', [\App\Http\Controllers\GeneticsController::class, 'decide']);
        Route::post('/genetics/settings', [\App\Http\Controllers\GeneticsController::class, 'settings']);
        Route::post('/breeding/heat', [BreedingController::class, 'heat']); // D-175
        Route::post('/breeding/kid-care', [BreedingController::class, 'kidCare']); // D-176
        Route::post('/breeding/matings/{mating}/check', [BreedingController::class, 'check']);
        Route::post('/breeding/ultrasound', [BreedingController::class, 'ultrasoundDoe']); // D-298
        Route::post('/breeding/matings/{mating}/kidding', [BreedingController::class, 'kidding']);
        Route::post('/breeding/matings/{mating}/close', [BreedingController::class, 'close']);
        Route::post('/breeding/weanings', [BreedingController::class, 'wean']);
        Route::post('/breeding/settings', [BreedingController::class, 'settings']);
    });

    Route::middleware('module:health')->group(function (): void {
        Route::get('/health', [HealthController::class, 'index']);
        Route::get('/health/antimicrobials', [\App\Http\Controllers\AntimicrobialController::class, 'index']); // D-209
        Route::post('/health/antimicrobials/{medicine}', [\App\Http\Controllers\AntimicrobialController::class, 'mark']);
        Route::post('/health/observations', [HealthController::class, 'observe']);
        Route::post('/health/clinical', [HealthController::class, 'clinical']);
        Route::post('/health/records/{record}/close', [HealthController::class, 'close']);
        Route::post('/health/cases', [HealthController::class, 'openCase']);
        Route::get('/parasites', [\App\Http\Controllers\ParasiteController::class, 'index']); // D-200
        Route::post('/parasites/samples', [\App\Http\Controllers\ParasiteController::class, 'sample']);
        Route::post('/parasites/samples/{sample}/decide', [\App\Http\Controllers\ParasiteController::class, 'decide']);
        Route::post('/parasites/guide', [\App\Http\Controllers\ParasiteController::class, 'guide']);
        Route::post('/health/checkups', [HealthController::class, 'checkup']); // D-095
        Route::post('/health/checkups/{checkup}', [HealthController::class, 'updateCheckup']); // D-099
        Route::post('/health/preventive', [HealthController::class, 'preventive']);
        Route::post('/health/protocols', [HealthController::class, 'protocol']); // D-173
        Route::post('/health/diseases', [HealthController::class, 'disease']); // D-174
        Route::post('/health/diseases/{disease}', [HealthController::class, 'disease']);
        Route::post('/health/symptoms', [HealthController::class, 'symptom']); // D-292
        Route::post('/health/doses/{dose}', [HealthController::class, 'dose']); // D-304
        Route::post('/health/symptoms/{symptom}', [HealthController::class, 'symptom']);
        Route::post('/health/postmortem', [HealthController::class, 'postmortem']);
        Route::post('/health/protocols/{protocol}', [HealthController::class, 'protocol']);
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
        $fl = \App\Http\Controllers\FeedLotController::class; // D-209
        Route::get('/feeding/lots', [$fl, 'index']);
        Route::post('/feeding/lots/limits', [$fl, 'limits']);
        Route::post('/feeding/lots/items/{item}', [$fl, 'item']);
        Route::post('/feeding/lots/{lot}/test', [$fl, 'test']);
        Route::post('/feeding/lots/{lot}/decide', [$fl, 'decide']);
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
        Route::post('/feeding/tmr', [\App\Http\Controllers\TmrController::class, 'store']); // D-177
        Route::post('/feeding/tmr/{formula}', [\App\Http\Controllers\TmrController::class, 'update'])->whereNumber('formula');
        Route::post('/feeding/tmr/{formula}/mix', [\App\Http\Controllers\TmrController::class, 'mix'])->whereNumber('formula');
    });

    Route::middleware('module:sheds')->group(function (): void {
        Route::get('/sheds', [ShedController::class, 'index']);
        Route::get('/housing', [\App\Http\Controllers\HousingController::class, 'index']); // D-207
        Route::post('/housing/pens/{pen}/status', [\App\Http\Controllers\HousingController::class, 'status']);
        Route::post('/housing/pens/{pen}/clean', [\App\Http\Controllers\HousingController::class, 'start']);
        Route::post('/housing/cleanings/{cleaning}/finish', [\App\Http\Controllers\HousingController::class, 'finish']);
        Route::post('/housing/cleanings/{cleaning}/verify', [\App\Http\Controllers\HousingController::class, 'verify']);
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

    // D-178: Inventory, Purchase and Sales apps
    Route::middleware('module:inventory')->group(function (): void {
        Route::get('/inventory', [\App\Http\Controllers\InventoryController::class, 'index']);
        Route::post('/inventory/items', [\App\Http\Controllers\InventoryController::class, 'storeItem']);
        Route::post('/inventory/items/{item}', [\App\Http\Controllers\InventoryController::class, 'updateItem'])->whereNumber('item');
        Route::post('/inventory/moves', [\App\Http\Controllers\InventoryController::class, 'move']);
    });
    Route::middleware('module:purchase')->group(function (): void {
        Route::get('/purchase', [\App\Http\Controllers\PurchaseController::class, 'index']);
        Route::post('/purchase/suppliers', [\App\Http\Controllers\PurchaseController::class, 'storeSupplier']);
        Route::post('/purchase/suppliers/{supplier}', [\App\Http\Controllers\PurchaseController::class, 'updateSupplier'])->whereNumber('supplier');
        Route::post('/purchase/orders', [\App\Http\Controllers\PurchaseController::class, 'storeOrder']);
        Route::post('/purchase/orders/{order}/status', [\App\Http\Controllers\PurchaseController::class, 'status'])->whereNumber('order');
        Route::post('/purchase/orders/{order}/receive', [\App\Http\Controllers\PurchaseController::class, 'receive'])->whereNumber('order');
    });
    Route::middleware('module:sales')->group(function (): void {
        Route::get('/sales', [\App\Http\Controllers\SalesController::class, 'index']);
        Route::get('/sales/readiness', [\App\Http\Controllers\SaleReadinessController::class, 'index']); // D-232
        Route::post('/sales/readiness/weights', [\App\Http\Controllers\SaleReadinessController::class, 'weights']);
        Route::post('/sales/readiness/{animal}', [\App\Http\Controllers\SaleReadinessController::class, 'decide']);
        Route::post('/sales/customers', [\App\Http\Controllers\SalesController::class, 'storeCustomer']);
        Route::post('/sales/customers/{customer}', [\App\Http\Controllers\SalesController::class, 'updateCustomer'])->whereNumber('customer');
        Route::post('/sales/products', [\App\Http\Controllers\SalesController::class, 'product']);
        Route::post('/sales/{sale}/customer', [\App\Http\Controllers\SalesController::class, 'linkCustomer'])->whereNumber('sale');
    });

    Route::middleware('module:employees')->group(function (): void {
        // D-181: attendance, shift roster and leave
        Route::get('/hr', [\App\Http\Controllers\HrController::class, 'index']);
        Route::get('/hr/training', [\App\Http\Controllers\TrainingController::class, 'index']); // D-210
        Route::post('/hr/training', [\App\Http\Controllers\TrainingController::class, 'train']);
        Route::post('/hr/skills', [\App\Http\Controllers\TrainingController::class, 'skill']);
        Route::post('/hr/skills/{skill}/revoke', [\App\Http\Controllers\TrainingController::class, 'revoke']);
        Route::post('/hr/attendance', [\App\Http\Controllers\HrController::class, 'mark']);
        Route::post('/hr/attendance/all-present', [\App\Http\Controllers\HrController::class, 'allPresent']);
        Route::post('/hr/leave', [\App\Http\Controllers\HrController::class, 'requestLeave']);
        Route::post('/hr/leave/{leave}', [\App\Http\Controllers\HrController::class, 'decide']);
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
        // D-188: App 17 Dairy & Milk: sessions, lots, QA, release, dispatch
        Route::get('/dairy', [\App\Http\Controllers\DairyController::class, 'index']);
        Route::get('/dairy/processing', [\App\Http\Controllers\MilkProcessingController::class, 'index']); // D-211
        Route::post('/dairy/processing', [\App\Http\Controllers\MilkProcessingController::class, 'store']);
        Route::post('/dairy/processing/{batch}/decide', [\App\Http\Controllers\MilkProcessingController::class, 'decide']);
        Route::post('/dairy/sessions', [\App\Http\Controllers\DairyController::class, 'close']);
        Route::post('/dairy/lots/{lot}/test', [\App\Http\Controllers\DairyController::class, 'test']);
        Route::post('/dairy/lots/{lot}/release', [\App\Http\Controllers\DairyController::class, 'release']);
        Route::post('/dairy/lots/{lot}/hold', [\App\Http\Controllers\DairyController::class, 'hold']);
        Route::post('/dairy/lots/{lot}/recall', [\App\Http\Controllers\DairyController::class, 'recall']);
        Route::post('/dairy/lots/{lot}/temperature', [\App\Http\Controllers\DairyController::class, 'temperature']); // D-196
        Route::post('/dairy/dispatches/{dispatch}/complaint', [\App\Http\Controllers\DairyController::class, 'complaint']);
        Route::post('/dairy/dispatches/{dispatch}/settle', [\App\Http\Controllers\DairyController::class, 'settle']);
        Route::post('/dairy/lots/{lot}/dispatch', [\App\Http\Controllers\DairyController::class, 'dispatch']);
        Route::post('/dairy/orders', [\App\Http\Controllers\DairyController::class, 'order']); // D-195
        Route::post('/dairy/orders/{order}/cancel', [\App\Http\Controllers\DairyController::class, 'cancelOrder']);
        Route::post('/dairy/dispatches/{dispatch}/collect', [\App\Http\Controllers\DairyController::class, 'collect']);
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
        Route::get('/settings/{section}', [SettingsController::class, 'index'])->whereIn('section', ['breeds', 'ages', 'system']); // D-215
        Route::get('/settings/access', [\App\Http\Controllers\AccessController::class, 'roles']);
        Route::get('/settings/access/tools', [\App\Http\Controllers\AccessToolsController::class, 'index']); // D-266: history, Why?, health
        Route::get('/settings/access/levels/preview', [\App\Http\Controllers\AccessToolsController::class, 'levelPreview']); // D-266 step 5c
        Route::post('/settings/access/levels', [\App\Http\Controllers\AccessToolsController::class, 'setLevel']);
        Route::post('/settings/access/history/{batch}/rollback', [\App\Http\Controllers\AccessToolsController::class, 'rollback']);
        Route::post('/settings/access', [\App\Http\Controllers\AccessController::class, 'saveRoles']);
        Route::get('/settings/users/{id}', [\App\Http\Controllers\AccessController::class, 'user'])->whereNumber('id');
        Route::post('/settings/users/{id}/access', [\App\Http\Controllers\AccessController::class, 'saveUser'])->whereNumber('id');
        Route::post('/settings/breeds', [SettingsController::class, 'addBreed']);
        Route::post('/settings/breeds/{breed}', [SettingsController::class, 'updateBreed']);
        Route::post('/settings/breeds/{breed}/edit', [SettingsController::class, 'editBreed']); // D-219
        Route::delete('/settings/breeds/{breed}', [SettingsController::class, 'deleteBreed']); // D-219
        Route::post('/settings/ages', [SettingsController::class, 'updateAges']);
        Route::get('/settings/automation', [\App\Http\Controllers\AutomationController::class, 'index']); // D-236
        Route::post('/settings/automation/rules', [\App\Http\Controllers\AutomationController::class, 'save']);
        Route::post('/settings/automation/codes', [\App\Http\Controllers\AutomationController::class, 'addCode']); // D-240
        Route::delete('/settings/automation/codes/{code}', [\App\Http\Controllers\AutomationController::class, 'deleteCode'])->where('code', '[A-Z]{2,4}');
        Route::post('/settings/automation/codes/{code}/restore', [\App\Http\Controllers\AutomationController::class, 'restoreCode'])->where('code', '[A-Z]{2,4}');
        Route::post('/settings/automation/codes/{code}', [\App\Http\Controllers\AutomationController::class, 'saveCode'])->where('code', '[A-Z]{2,4}'); // D-239
        Route::delete('/settings/automation/rules/{rule}', [\App\Http\Controllers\AutomationController::class, 'delete']);
        Route::post('/settings/stages', [SettingsController::class, 'addStage']); // D-221
        Route::post('/settings/stages/{stage}', [SettingsController::class, 'editStage']);
        Route::delete('/settings/stages/{stage}', [SettingsController::class, 'deleteStage']);
        Route::post('/settings/teeth', [SettingsController::class, 'addTeeth']); // D-220
        Route::post('/settings/teeth/{stage}', [SettingsController::class, 'editTeeth']);
        Route::delete('/settings/teeth/{stage}', [SettingsController::class, 'deleteTeeth']);
        Route::get('/settings/users', [UserController::class, 'index']);
        Route::post('/settings/users', [UserController::class, 'store']);
        Route::post('/settings/users/{id}', [UserController::class, 'update'])->whereNumber('id');
        Route::get('/settings/users/{id}/details', [UserController::class, 'details'])->whereNumber('id'); // D-244
        Route::get('/settings/users/check-login', [UserController::class, 'checkLogin']);
        Route::post('/settings/users/bulk', [UserController::class, 'bulk']);
        Route::post('/settings/users/{id}/reset-password', [UserController::class, 'resetPassword'])->whereNumber('id');
        Route::post('/settings/users/{id}/sign-out', [UserController::class, 'signOut'])->whereNumber('id');
        Route::delete('/settings/departments/{department}', [UserController::class, 'destroyDepartment']);
        Route::post('/settings/departments', [UserController::class, 'storeDepartment']);
        Route::post('/settings/departments/{department}', [UserController::class, 'updateDepartment']);
        Route::get('/settings/fields', [FieldController::class, 'index']);
        Route::post('/settings/fields', [FieldController::class, 'store']);
        Route::post('/settings/fields/{field}', [FieldController::class, 'update']);
    });
});
