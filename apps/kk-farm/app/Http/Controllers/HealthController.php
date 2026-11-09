<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\Treatment;
use App\Support\Withdrawal;
use Illuminate\Validation\ValidationException;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class HealthController extends Controller
{
    /** D-078: workbook "Preventive Care" types. */
    public const CARE_TYPES = ['vaccination' => 'Vaccine', 'deworming' => 'Deworming', 'hoof_care' => 'Hoof care', 'examination' => 'Routine examination', 'other_care' => 'Other'];

    public function index(Request $request): Response
    {
        $row = fn (HealthRecord $r) => [
            'id' => $r->id, 'goatId' => $r->animal_id, 'number' => $r->animal->farm_number, 'name' => $r->animal->name, 'coat' => $r->animal->coat, 'sex' => $r->animal->sex,
            'health' => $r->animal->health_status, 'kind' => $r->kind, 'title' => $r->title, 'notes' => $r->notes, 'severity' => $r->severity, 'by' => $r->recorded_by, 'role' => $r->recorded_role,
            'on' => $r->occurred_on->format('j M Y'), 'next' => $r->next_due_on?->format('j M Y'),
        ];
        $care = HealthRecord::with('animal')->whereIn('kind', array_keys(self::CARE_TYPES))->where(fn ($q) => $q->whereNull('care_status')->orWhere('care_status', '!=', 'cancelled'))->whereBetween('next_due_on', [now()->subDays(60), now()->addDays(21)])
            ->whereHas('animal', fn ($q) => $q->active())->orderBy('next_due_on')->limit(30)->get();
        $withdrawal = Withdrawal::active();
        $goatsById = Animal::whereIn('id', $withdrawal->keys())->get()->keyBy('id');
        $cases = HealthCase::with(['animal', 'treatments'])->where(fn ($q) => $q->whereIn('status', HealthCase::ACTIVE)->orWhere('resolved_on', '>=', now()->subDays(30)))
            ->orderByRaw("case status when 'open' then 0 when 'under_treatment' then 1 when 'monitoring' then 2 else 3 end")
            ->orderByRaw("case severity when 'emergency' then 0 when 'high' then 1 when 'moderate' then 2 else 3 end")->orderByDesc('onset_on')->get();
        $today = now()->startOfDay();

        // D-095: routine checkups: every goat with its latest checkup and whether one is due.
        $latest = \App\Support\Checkups::latest();
        $pregnant = \App\Support\Checkups::pregnancies(); // D-100: day of pregnancy is counted from the mating
        $interval = \App\Support\Checkups::interval();
        $since = now()->subDays($interval)->toDateString();
        $checkupGoats = Animal::with('pen.shed')->active()->orderBy('farm_number')->get()->map(fn (Animal $a) => [
            'id' => $a->id, 'number' => $a->farm_number, 'name' => $a->name, 'sex' => $a->sex, 'pen' => $a->pen?->fullName(), 'repro' => $a->repro_status,
            'last' => isset($latest[$a->id]) ? ['on' => $latest[$a->id]->checked_on->format('j M'), 'outcome' => $latest[$a->id]->outcome, 'by' => $latest[$a->id]->examiner] : null,
            'due' => ! isset($latest[$a->id]) || $latest[$a->id]->checked_on->toDateString() <= $since,
            'next' => isset($latest[$a->id]) ? $latest[$a->id]->checked_on->copy()->addDays($interval)->format('j M') : null, // D-099
            'recheck' => isset($latest[$a->id]) && $latest[$a->id]->follow_up_on ? $latest[$a->id]->follow_up_on->format('j M') : null,
            'matedOn' => isset($pregnant[$a->id]) ? $pregnant[$a->id]->mated_on->toDateString() : null,
        ])->values();

        $revisions = \App\Support\Revisions::counts('checkup', \App\Models\HealthCheckup::orderByDesc('checked_on')->orderByDesc('id')->limit(60)->pluck('id'));

        return Inertia::render('Health', [
            'checkups' => [
                'goats' => $checkupGoats, 'interval' => $interval,
                'recent' => ($recent = \App\Models\HealthCheckup::with('animal')->orderByDesc('checked_on')->orderByDesc('id')->limit(60)->get())->map(fn ($c) => [
                    'id' => $c->id, 'on' => $c->checked_on->format('j M Y'), 'goat' => $c->animal->farm_number, 'goatId' => $c->animal_id, 'outcome' => $c->outcome,
                    'temp' => $c->temperature_f, 'score' => $c->body_score, 'weight' => $c->weight_kg,
                    'ultrasound' => $c->ultrasound, 'fetuses' => $c->fetuses, 'flags' => $c->flags(), 'findings' => $c->findings, 'caseId' => $c->health_case_id, 'by' => $c->examiner,
                    // D-099: what a correction form needs, and whether this person may correct it
                    'dateIso' => $c->checked_on->toDateString(), 'days' => $c->days_pregnant, 'followUp' => $c->follow_up_on?->toDateString(), 'followUpNote' => $c->follow_up_note,
                    'sex' => $c->animal->sex, 'canEdit' => $request->user()->passes('health_checkup') && \App\Support\Revisions::canCorrect($request->user(), $c->examiner),
                    'edits' => (int) ($revisions[$c->id] ?? 0),
                ]),
                'normal' => \App\Models\HealthCheckup::NORMAL,
                'sires' => Animal::active()->where('sex', 'male')->orderBy('farm_number')->get(['id', 'farm_number'])->map(fn ($a) => ['id' => $a->id, 'number' => $a->farm_number]),
                'canCheck' => $request->user()->passes('health_checkup'), 'canOpenCase' => $request->user()->passes('open_cases'), 'gestation' => (int) (\App\Support\Tenant::org()?->gestation_days ?: 150), 'conditions' => HealthCase::CONDITIONS,
            ],
            'cases' => $cases->map(fn (HealthCase $c) => $this->caseRow($c, $withdrawal[$c->animal_id] ?? null)),
            // Concerns reported by staff (and older records) that no case covers yet.
            'reports' => HealthRecord::with('animal')->where('status', 'open')->whereNotIn('id', HealthCase::whereNotNull('report_id')->pluck('report_id'))
                ->orderByDesc('occurred_on')->get()->map($row),
            'care' => $care->map(fn (HealthRecord $r) => $row($r) + ['overdue' => $r->next_due_on->isPast(), 'planned' => $r->care_status === 'planned']),
            'withdrawal' => $withdrawal->map(fn ($w, $id) => [
                'goatId' => $id, 'number' => $goatsById[$id]->farm_number ?? '?', 'name' => $goatsById[$id]->name ?? null, 'coat' => $goatsById[$id]->coat ?? 'brown', 'sex' => $goatsById[$id]->sex ?? 'female',
                'meat' => $w['meat'] ? \Illuminate\Support\Carbon::parse($w['meat'])->format('j M Y') : null, 'milk' => $w['milk'] ? \Illuminate\Support\Carbon::parse($w['milk'])->format('j M Y') : null,
            ])->values(),
            'counts' => [
                'active' => $cases->whereIn('status', HealthCase::ACTIVE)->count(),
                'emergency' => $cases->whereIn('status', HealthCase::ACTIVE)->whereIn('severity', ['emergency', 'high'])->count(),
                'isolated' => $cases->whereIn('status', HealthCase::ACTIVE)->where('isolated', true)->count(),
                'doses' => Treatment::whereNotNull('next_dose_on')->where('next_dose_on', '<=', $today->copy()->addDays(2))->whereHas('healthCase', fn ($q) => $q->whereIn('status', HealthCase::ACTIVE))->count(),
                'withdrawal' => $withdrawal->count(),
                'overdue' => HealthRecord::whereIn('kind', ['vaccination', 'deworming'])->where('next_due_on', '<', now())->whereHas('animal', fn ($q) => $q->active())->distinct('animal_id')->count('animal_id'),
            ],
            'goats' => Animal::with('pen.shed')->active()->orderBy('farm_number')->get()->map(fn (Animal $a) => ['id' => $a->id, 'farm_number' => $a->farm_number, 'name' => $a->name, 'pen' => $a->pen?->fullName()]),
            'careTypes' => self::CARE_TYPES,
            'severities' => HealthCase::SEVERITIES, 'statuses' => HealthCase::STATUSES, 'routes' => Treatment::ROUTES,
        ] + $this->medicineStore());
    }

    /** D-074: medicine store for the Health page. */
    private function medicineStore(): array
    {
        $qty = \App\Support\MedicineStock::quantities();
        $batches = \App\Support\MedicineStock::batches();
        $medicines = \App\Models\Medicine::orderBy('name')->get()->map(function (\App\Models\Medicine $m) use ($qty, $batches) {
            $b = $batches[$m->id] ?? collect();
            $q = $qty[$m->id] ?? 0;

            return [
                'id' => $m->id, 'code' => $m->code, 'name' => $m->name, 'category' => $m->category, 'unit' => $m->unit, 'reorder' => $m->reorder_level,
                'costPerUnit' => $m->cost_per_unit_minor / 100, 'storage' => $m->storage, 'supplier' => $m->supplier, 'active' => $m->active, 'qty' => $q,
                'value' => round(max(0, $q) * $m->cost_per_unit_minor / 100), 'status' => \App\Support\MedicineStock::status($q, $m->reorder_level, $b),
                'batches' => $b->map(fn ($x) => ['batch' => $x['batch'], 'expiry' => $x['expiry']?->format('j M Y'), 'expired' => $x['expiry']?->isPast() ?? false, 'qty' => $x['qty']])->values(),
            ];
        });

        return [
            'medicines' => $medicines,
            'medicineMoves' => \App\Models\MedicineMovement::with('medicine')->orderByDesc('moved_on')->orderByDesc('id')->limit(40)->get()->map(fn ($mv) => [
                'id' => $mv->id, 'on' => $mv->moved_on->format('j M'), 'medicine' => $mv->medicine->name, 'unit' => $mv->medicine->unit, 'type' => \App\Models\MedicineMovement::TYPES[$mv->type][0] ?? $mv->type,
                'qty' => $mv->qty, 'batch' => $mv->batch, 'expiry' => $mv->expiry_on?->format('j M Y'), 'by' => $mv->recorded_by, 'party' => $mv->party,
            ]),
            'medicineCategories' => \App\Models\Medicine::CATEGORIES, 'medicineUnits' => \App\Models\Medicine::UNITS,
            'medicineTypes' => collect(\App\Models\MedicineMovement::TYPES)->except(['opening', 'issue'])->map(fn ($t) => ['label' => $t[0], 'direction' => $t[1]]),
            'medicineAlerts' => $medicines->where('active', true)->whereIn('status', ['negative', 'expired', 'reorder', 'expiring'])->count(),
            'canMedicine' => request()->user()->passes('manage_medicine_store'),
        ];
    }

    private function caseRow(HealthCase $c, ?array $withdrawal): array
    {
        $today = now()->startOfDay();
        $next = $c->treatments->whereNotNull('next_dose_on')->sortByDesc('given_on')->first()?->next_dose_on;

        return [
            'id' => $c->id, 'label' => $c->label(), 'goatId' => $c->animal_id, 'number' => $c->animal->farm_number, 'name' => $c->animal->name, 'coat' => $c->animal->coat, 'sex' => $c->animal->sex,
            'onset' => $c->onset_on->format('j M Y'), 'condition' => $c->condition, 'symptoms' => $c->symptoms, 'severity' => $c->severity, 'isolated' => $c->isolated,
            'status' => $c->status, 'active' => in_array($c->status, HealthCase::ACTIVE, true), 'vet' => $c->vet, 'diagnosis' => $c->diagnosis, 'notes' => $c->notes,
            'resolved' => $c->resolved_on?->format('j M Y'), 'age' => $c->ageDays(), 'by' => $c->recorded_by,
            'nextDose' => $next?->format('j M'), 'doseAlert' => $next && in_array($c->status, HealthCase::ACTIVE, true) ? ($next->lessThan($today) ? 'overdue' : ($next->lessThanOrEqualTo($today->copy()->addDays(2)) ? 'soon' : null)) : null,
            'withdrawal' => $withdrawal ? ['meat' => $withdrawal['meat'] ? \Illuminate\Support\Carbon::parse($withdrawal['meat'])->format('j M') : null, 'milk' => $withdrawal['milk'] ? \Illuminate\Support\Carbon::parse($withdrawal['milk'])->format('j M') : null] : null,
            'treatments' => $c->treatments->map(fn (Treatment $t) => [
                'id' => $t->id, 'on' => $t->given_on->format('j M Y'), 'product' => $t->product, 'dose' => $t->dose, 'route' => Treatment::ROUTES[$t->route] ?? $t->route,
                'frequency' => $t->frequency, 'days' => $t->days_prescribed, 'next' => $t->next_dose_on?->format('j M Y'),
                'meat' => $t->meat_clear_on?->format('j M Y'), 'milk' => $t->milk_clear_on?->format('j M Y'), 'cost' => $t->cost_minor !== null ? $t->cost_minor / 100 : null, 'by' => $t->recorded_by, 'notes' => $t->notes,
            ])->values(),
        ];
    }

    /** D-073: the goat's health flag follows its cases: active case = Sick (Quarantine if isolated), monitoring = Recovering. */
    /** D-095: one goat's routine (or any-time) health checkup. */
    public function checkup(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('health_checkup'), 403);
        $data = $request->validate([
            'animal_id' => ['required', 'exists:animals,id'], 'checked_on' => ['required', 'date', 'before_or_equal:today'],
            'temperature_f' => ['nullable', 'numeric', 'between:93,110'], // D-098: heart and breathing rate are not taken
            'body_score' => ['nullable', 'integer', 'between:1,5'], 'weight_kg' => ['nullable', 'numeric', 'between:0.5,150'],
            'ultrasound' => ['nullable', Rule::in(array_keys(\App\Models\HealthCheckup::ULTRASOUND))], 'fetuses' => ['nullable', 'integer', 'between:1,6'],
            'days_pregnant' => ['nullable', 'required_if:ultrasound,pregnant', 'integer', 'between:1,160'], 'sire_id' => ['nullable', 'exists:animals,id'], // D-098: days
            'findings' => ['nullable', 'string', 'max:2000'], 'outcome' => ['required', Rule::in(array_keys(\App\Models\HealthCheckup::OUTCOMES))],
            'open_case' => ['boolean'], 'condition' => ['nullable', 'required_if:open_case,true', 'string', 'max:120'],
            'severity' => ['nullable', Rule::in(array_keys(HealthCase::SEVERITIES))], 'isolated' => ['boolean'],
            'follow_up_on' => ['nullable', 'date', 'after_or_equal:checked_on'], 'follow_up_note' => ['nullable', 'string', 'max:200'], // D-099
        ], ['days_pregnant.required_if' => 'About how many days pregnant?', 'condition.required_if' => 'What did you find? (the condition for the case)']);
        abort_if(! empty($data['open_case']) && ! $request->user()->passes('open_cases'), 403, 'Only a veterinarian or the administrator opens a health case.');
        $goat = Animal::findOrFail($data['animal_id']);
        abort_unless($goat->status === 'active', 422, 'This goat is no longer on the farm.');
        $c = \App\Support\Checkups::record($goat, $data, $request->user());
        Farm::log($request->user(), "Health checkup of {$goat->farm_number}: ".\App\Models\HealthCheckup::OUTCOMES[$c->outcome], "/goats/{$goat->id}");

        return back()->with('success', "{$goat->farm_number} checked: ".\App\Models\HealthCheckup::OUTCOMES[$c->outcome].($c->ultrasound === 'pregnant' ? '. Pregnant: added to Breeding.' : '.').($c->flags() ? ' Outside normal: '.implode(', ', $c->flags()).'.' : ''));
    }

    /** D-099: correct a checkup: the vet who did it, or the administrator. */
    public function updateCheckup(Request $request, \App\Models\HealthCheckup $checkup): RedirectResponse
    {
        abort_unless($request->user()->passes('health_checkup') && \App\Support\Revisions::canCorrect($request->user(), $checkup->examiner), 403, 'Only the person who did this checkup, or the administrator, can correct it.');
        $data = $request->validate([
            'checked_on' => ['required', 'date', 'before_or_equal:today'], 'temperature_f' => ['nullable', 'numeric', 'between:93,110'],
            'body_score' => ['nullable', 'integer', 'between:1,5'], 'weight_kg' => ['nullable', 'numeric', 'between:0.5,150'],
            'ultrasound' => ['nullable', Rule::in(array_keys(\App\Models\HealthCheckup::ULTRASOUND))], 'fetuses' => ['nullable', 'integer', 'between:1,6'],
            'days_pregnant' => ['nullable', 'required_if:ultrasound,pregnant', 'integer', 'between:1,160'], 'sire_id' => ['nullable', 'exists:animals,id'],
            'findings' => ['nullable', 'string', 'max:2000'], 'outcome' => ['required', Rule::in(array_keys(\App\Models\HealthCheckup::OUTCOMES))],
            'follow_up_on' => ['nullable', 'date', 'after_or_equal:checked_on'], 'follow_up_note' => ['nullable', 'string', 'max:200'],
            'reason' => ['nullable', 'string', 'max:300'],
        ], ['days_pregnant.required_if' => 'About how many days pregnant?']);
        $changes = \App\Support\Checkups::correct($checkup->load('animal'), $data, $request->user());

        return back()->with('success', $changes ? 'Checkup corrected: '.implode(', ', array_keys($changes)).'. The old values stay in the history.' : 'Nothing changed.');
    }

    private function syncHealth(Animal $animal): void
    {
        $active = HealthCase::where('animal_id', $animal->id)->whereIn('status', HealthCase::ACTIVE)->get();
        $flag = match (true) {
            $active->contains('isolated', true) => 'quarantine',
            $active->whereIn('status', ['open', 'under_treatment'])->isNotEmpty() => 'sick',
            $active->isNotEmpty() => 'recovering',
            HealthRecord::where('animal_id', $animal->id)->where('status', 'open')->exists() => 'monitor',
            default => 'healthy',
        };
        $animal->update(['health_flag' => $flag]);
    }

    /**
     * D-078: preventive care for one goat or a whole batch in one go: one record per goat, with product, batch/lot,
     * next due date, cost and status. A product from the medicine store is issued for each goat (earliest expiry first).
     */
    public function preventive(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Preventive care is recorded by a veterinarian.');
        $data = $request->validate([
            'animal_ids' => ['required', 'array', 'min:1', 'max:1000'], 'animal_ids.*' => ['uuid', 'distinct', 'exists:animals,id'],
            'kind' => ['required', Rule::in(array_keys(self::CARE_TYPES))], 'title' => ['nullable', 'required_without:medicine_id', 'string', 'max:120'],
            'medicine_id' => ['nullable', 'exists:medicines,id'], 'qty_per_goat' => ['nullable', 'required_with:medicine_id', 'numeric', 'gt:0', 'max:1000'],
            'given_on' => ['required', 'date'], 'next_due_on' => ['nullable', 'date', 'after:given_on'],
            'batch_lot' => ['nullable', 'string', 'max:40'], 'cost_per_goat' => ['nullable', 'numeric', 'min:0', 'max:10000000'],
            'care_status' => ['required', Rule::in(['administered', 'planned', 'cancelled'])], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['animal_ids' => 'goats', 'title' => 'product / vaccine', 'qty_per_goat' => 'quantity per goat']);
        if ($data['care_status'] === 'administered' && Carbon::parse($data['given_on'])->isFuture()) {
            throw ValidationException::withMessages(['given_on' => 'Care given cannot be in the future. Use "Planned" for a future date.']);
        }
        $medicine = ! empty($data['medicine_id']) ? \App\Models\Medicine::findOrFail($data['medicine_id']) : null;
        $title = $data['title'] ?? $medicine->name;
        $cost = isset($data['cost_per_goat']) ? (int) round($data['cost_per_goat'] * 100) : ($medicine && $medicine->cost_per_unit_minor ? (int) round($data['qty_per_goat'] * $medicine->cost_per_unit_minor) : null);
        $goats = Animal::whereIn('id', $data['animal_ids'])->get();
        $short = 0.0;

        DB::transaction(function () use ($goats, $data, $title, $cost, $medicine, $request, &$short) {
            foreach ($goats as $goat) {
                HealthRecord::create([
                    'animal_id' => $goat->id, 'kind' => $data['kind'], 'occurred_on' => $data['given_on'], 'title' => $title, 'notes' => $data['notes'] ?? null,
                    'recorded_by' => $request->user()->name, 'recorded_role' => 'Veterinarian', 'next_due_on' => $data['care_status'] === 'planned' ? $data['given_on'] : ($data['next_due_on'] ?? null),
                    'status' => 'closed', 'batch_lot' => $data['batch_lot'] ?? null, 'cost_minor' => $cost, 'care_status' => $data['care_status'],
                ]);
                if ($data['care_status'] === 'administered') {
                    Farm::event($goat, 'health', self::CARE_TYPES[$data['kind']].": {$title}".(! empty($data['batch_lot']) ? " (lot {$data['batch_lot']})" : ''), $request->user()->name, Carbon::parse($data['given_on']));
                    if ($medicine) {
                        $short += \App\Support\MedicineStock::issue($medicine, (float) $data['qty_per_goat'], ['moved_on' => $data['given_on'], 'reference' => self::CARE_TYPES[$data['kind']], 'recorded_by' => $request->user()->name]);
                    }
                }
            }
        });
        $n = $goats->count();
        Farm::log($request->user(), self::CARE_TYPES[$data['kind']].": {$title} for {$n} goat".($n === 1 ? '' : 's'), '/health');
        $warning = $short > 0 ? " Warning: not enough in-date {$medicine->name} in the store ({$short} {$medicine->unit} short)." : '';

        return back()->with('success', "{$title} recorded for {$n} goat".($n === 1 ? '' : 's').'.'.$warning);
    }

    /** Opens a case (optionally from a reported concern, which it then covers). Veterinarian only. */
    public function openCase(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('open_cases'), 403, 'Only a veterinarian or the administrator opens a health case.'); // D-095
        $data = $request->validate([
            'animal_id' => ['required', 'exists:animals,id'], 'onset_on' => ['required', 'date', 'before_or_equal:today'],
            'condition' => ['required', 'string', 'max:120'], 'symptoms' => ['nullable', 'string', 'max:1000'],
            'severity' => ['required', Rule::in(array_keys(HealthCase::SEVERITIES))], 'isolated' => ['boolean'],
            'diagnosis' => ['nullable', 'string', 'max:1000'], 'notes' => ['nullable', 'string', 'max:1000'],
            'report_id' => ['nullable', 'exists:health_records,id'],
        ]);
        $animal = Animal::findOrFail($data['animal_id']);

        $case = DB::transaction(function () use ($data, $animal, $request) {
            DB::statement('LOCK TABLE health_cases IN SHARE ROW EXCLUSIVE MODE');
            $case = HealthCase::create($data + ['case_no' => (int) HealthCase::max('case_no') + 1, 'vet' => $request->user()->name, 'recorded_by' => $request->user()->name, 'isolated' => (bool) ($data['isolated'] ?? false)]);
            if (! empty($data['report_id'])) {
                HealthRecord::whereKey($data['report_id'])->update(['status' => 'closed']);
            }
            Farm::event($animal, 'health', "Health case {$case->label()} opened: {$case->condition} (".HealthCase::SEVERITIES[$case->severity].($case->isolated ? ', isolated' : '').')', $request->user()->name);
            $this->syncHealth($animal);

            return $case;
        });
        Farm::log($request->user(), "Opened health case {$case->label()} for {$animal->farm_number}", '/health');

        return back()->with('success', "Case {$case->label()} opened for {$animal->farm_number}.");
    }

    public function updateCase(Request $request, HealthCase $case): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian updates a health case.');
        $data = $request->validate([
            'status' => ['required', Rule::in(array_keys(HealthCase::STATUSES))], 'isolated' => ['boolean'],
            'diagnosis' => ['nullable', 'string', 'max:1000'], 'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $resolved = in_array($data['status'], ['recovered', 'closed', 'deceased'], true);

        DB::transaction(function () use ($case, $data, $resolved, $request) {
            $before = $case->status;
            $case->update($data + ['resolved_on' => $resolved ? ($case->resolved_on ?? now()) : null, 'isolated' => (bool) ($data['isolated'] ?? $case->isolated)]);
            if ($before !== $case->status) {
                Farm::event($case->animal, 'health', "Health case {$case->label()}: ".HealthCase::STATUSES[$case->status], $request->user()->name);
            }
            $this->syncHealth($case->animal);
        });
        Farm::log($request->user(), "Updated health case {$case->label()}", '/health');
        $note = $data['status'] === 'deceased' ? ' Record the death on the goat page (Record exit → Died).' : '';

        return back()->with('success', "{$case->label()} saved.{$note}");
    }

    /**
     * A dose given under a case. The meat and milk clear dates are the veterinarian's own (D-006): the vet must type
     * them, or tick that the product has no withdrawal, so a date is never left out by accident.
     */
    public function addTreatment(Request $request, HealthCase $case): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian records a treatment.');
        abort_unless(in_array($case->status, HealthCase::ACTIVE, true), 422, 'This case is closed. Open a new case for a new episode.');
        $data = $request->validate([
            'medicine_id' => ['nullable', 'integer', 'exists:medicines,id'], 'qty_used' => ['nullable', 'required_with:medicine_id', 'numeric', 'gt:0', 'max:100000'],
            'given_on' => ['required', 'date', 'before_or_equal:today'], 'product' => ['nullable', 'required_without:medicine_id', 'string', 'max:120'], 'dose' => ['required', 'string', 'max:40'],
            'route' => ['required', Rule::in(array_keys(Treatment::ROUTES))], 'frequency' => ['nullable', 'string', 'max:60'],
            'days_prescribed' => ['nullable', 'integer', 'between:1,365'], 'next_dose_on' => ['nullable', 'date', 'after_or_equal:given_on'],
            'meat_clear_on' => ['nullable', 'date', 'after_or_equal:given_on'], 'milk_clear_on' => ['nullable', 'date', 'after_or_equal:given_on'],
            'no_withdrawal' => ['boolean'], 'cost' => ['nullable', 'numeric', 'min:0', 'max:10000000'], 'notes' => ['nullable', 'string', 'max:500'],
        ], [], ['meat_clear_on' => 'meat clear date', 'milk_clear_on' => 'milk clear date', 'next_dose_on' => 'next dose date', 'given_on' => 'date given']);
        if (empty($data['meat_clear_on']) && empty($data['milk_clear_on']) && empty($data['no_withdrawal'])) {
            throw ValidationException::withMessages(['meat_clear_on' => 'Enter the meat and milk clear dates from the product label, or tick "No withdrawal for this product".']);
        }

        // D-074: a stock medicine names the product, prices the dose and is issued from the store (earliest expiry first).
        $medicine = ! empty($data['medicine_id']) ? \App\Models\Medicine::findOrFail($data['medicine_id']) : null;
        if ($medicine) {
            $data['product'] = $medicine->name;
            $data['cost'] ??= $medicine->cost_per_unit_minor ? $data['qty_used'] * $medicine->cost_per_unit_minor / 100 : null;
        }
        $short = 0.0;

        DB::transaction(function () use ($case, $data, $request, $medicine, &$short) {
            $treatment = Treatment::create(collect($data)->except(['no_withdrawal', 'cost'])->all() + [
                'health_case_id' => $case->id, 'animal_id' => $case->animal_id, 'cost_minor' => isset($data['cost']) ? (int) round($data['cost'] * 100) : null, 'recorded_by' => $request->user()->name,
            ]);
            if ($medicine) {
                $short = \App\Support\MedicineStock::issue($medicine, (float) $data['qty_used'], [
                    'moved_on' => $data['given_on'], 'treatment_id' => $treatment->id, 'reference' => $case->label(), 'recorded_by' => $request->user()->name,
                ]);
            }
            if ($case->status === 'open') {
                $case->update(['status' => 'under_treatment']);
            }
            $clear = array_filter(['meat clear '.($data['meat_clear_on'] ?? '') => ! empty($data['meat_clear_on']), 'milk clear '.($data['milk_clear_on'] ?? '') => ! empty($data['milk_clear_on'])]);
            Farm::event($case->animal, 'health', "Treatment ({$case->label()}): {$data['product']} {$data['dose']}".($clear ? ' · '.implode(', ', array_keys($clear)) : ' · no withdrawal'), $request->user()->name);
            $this->syncHealth($case->animal);
        });
        Farm::log($request->user(), "Treated {$case->animal->farm_number}: {$data['product']}", '/health');

        $warning = $short > 0 ? " Warning: not enough in-date {$medicine->name} in the store ({$short} {$medicine->unit} short), so stock shows below zero. Record the receipt or check for expired packs." : '';

        return back()->with('success', 'Treatment saved.'.$warning.(! empty($data['meat_clear_on']) ? ' Meat withdrawal until '.\Illuminate\Support\Carbon::parse($data['meat_clear_on'])->format('j M Y').'.' : ''));
    }

    public function observe(Request $request): RedirectResponse
    {
        $data = $request->validate(['animal_id' => ['required', 'exists:animals,id'], 'title' => ['required', 'string', 'max:120'], 'severity' => ['required', Rule::in(['low', 'medium', 'high'])], 'notes' => ['nullable', 'string', 'max:500']]);

        $animal = Animal::findOrFail($data['animal_id']);
        DB::transaction(function () use ($animal, $data, $request): void {
            HealthRecord::create($data + ['kind' => 'observation', 'occurred_on' => now(), 'recorded_by' => $request->user()->name, 'recorded_role' => $request->user()->roleLabel(), 'status' => 'open']);
            if ($animal->health_status === 'healthy') {
                $animal->update(['health_status' => 'watch']);
            }
            Farm::event($animal, 'health', "Concern reported: {$data['title']}", $request->user()->name);
        });
        Farm::log($request->user(), "Reported a concern for {$animal->farm_number}", "/goats/{$animal->id}");

        return back()->with('success', 'Concern reported. A veterinarian will see it on the health board.');
    }

    /** Examinations, diagnoses and treatments are written by a veterinarian only. No override exists. */
    public function clinical(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian can record clinical findings.');
        $data = $request->validate([
            'animal_id' => ['required', 'exists:animals,id'], 'kind' => ['required', Rule::in(['examination', 'treatment', 'vaccination', 'deworming'])],
            'title' => ['required', 'string', 'max:120'], 'notes' => ['nullable', 'string', 'max:500'], 'next_due_on' => ['nullable', 'date', 'after_or_equal:today'],
            'health_status' => ['nullable', Rule::in(['healthy', 'watch', 'treatment', 'critical'])],
        ]);

        $animal = Animal::findOrFail($data['animal_id']);
        DB::transaction(function () use ($animal, $data, $request): void {
            $routine = in_array($data['kind'], ['vaccination', 'deworming'], true);
            HealthRecord::create(collect($data)->except('health_status')->all() + ['occurred_on' => now(), 'recorded_by' => $request->user()->name, 'recorded_role' => 'Veterinarian', 'status' => $routine ? 'closed' : 'open', 'severity' => $routine ? null : 'medium']);
            if (! empty($data['health_status'])) {
                $animal->update(['health_status' => $data['health_status']]);
            }
            Farm::event($animal, 'health', ucfirst($data['kind']).": {$data['title']}", $request->user()->name);
        });
        Farm::log($request->user(), ucfirst($data['kind'])." recorded for {$animal->farm_number}", "/goats/{$animal->id}");

        return back()->with('success', 'Veterinary record saved.');
    }

    public function close(Request $request, HealthRecord $record): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only a veterinarian can close a case.');

        DB::transaction(function () use ($record, $request): void {
            $record->update(['status' => 'closed']);
            $this->syncHealth($record->animal); // D-073: stays Sick while a case is still active
            Farm::event($record->animal, 'health', "Case closed: {$record->title}", $request->user()->name);
        });
        Farm::log($request->user(), "Closed a health case for {$record->animal->farm_number}", "/goats/{$record->animal_id}");

        return back()->with('success', 'Case closed.');
    }
}
