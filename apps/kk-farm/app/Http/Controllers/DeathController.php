<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\DeathReport;
use App\Models\HealthRecord;
use App\Models\Task;
use App\Support\Farm;
use App\Support\Movements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * D-260: a death is a veterinary finding. The vet reports it with the time, the cause and what was seen; the
 * administrator approves it and only then does the goat leave the farm (or rejects it with a note back to the vet).
 */
class DeathController extends Controller
{
    public const TASK = 'Approve death: ';

    public function store(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('report_death'), 403, 'Reporting a death is the vet\'s.');
        abort_unless($animal->status === 'active', 422, 'This goat is no longer on the farm.');
        abort_if(DeathReport::where('animal_id', $animal->id)->where('status', 'pending')->exists(), 422, 'A death is already reported for this goat and waits for approval.');
        $data = $request->validate([
            'died_at' => ['required', 'date', 'before_or_equal:now'], 'cause' => ['required', 'string', 'max:120'],
            'circumstances' => ['required', 'string', 'min:5', 'max:2000'], 'postmortem' => ['nullable', 'string', 'max:2000'],
        ], ['circumstances.required' => 'Say what was seen (signs, when, where).'], ['died_at' => 'time of death']);
        $u = $request->user();
        DB::transaction(function () use ($animal, $data, $u) {
            $r = DeathReport::create($data + ['animal_id' => $animal->id, 'reported_by' => $u->name, 'reported_by_user_id' => $u->id, 'status' => 'pending']);
            Task::create([
                'title' => mb_substr(self::TASK.$animal->farm_number, 0, 190), 'type' => 'health', 'priority' => 'high', 'status' => 'open', 'due_on' => now()->toDateString(),
                'animal_id' => $animal->id, 'pen_id' => $animal->pen_id, 'department_id' => \App\Models\Department::where('name', 'Management')->value('id'),
                'notes' => mb_substr("Reported by {$u->name}: died ".Carbon::parse($data['died_at'])->format('d/m/Y H:i')." · {$data['cause']}. Approve to record the exit.", 0, 500),
            ]);
            Farm::event($animal, 'health', "Death reported by {$u->name}: {$data['cause']} (waiting for approval)", $u->name, Carbon::parse($data['died_at']));
        });
        Farm::log($u, "Reported the death of {$animal->farm_number}: {$data['cause']}", "/goats/{$animal->id}");

        return back()->with('success', 'Death reported. The administrator approves it before the goat leaves the farm.');
    }

    /**
     * D-260: culling takes a goat out of breeding (bucks, does and replacements): it becomes a Cull goat for sale. It
     * stays on the farm with its whole record; it leaves only when it is sold (or another exit).
     */
    public function cull(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('cull_breeding'), 403);
        abort_unless($animal->status === 'active', 422, 'This goat is no longer on the farm.');
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']], ['reason.required' => 'Say why it leaves breeding.']);
        $u = $request->user();
        $was = \App\Support\Classification::CATEGORIES[$animal->effectiveCategory()][0] ?? 'Goat';
        DB::transaction(function () use ($animal, $data, $u, $was) {
            $animal->update(['animal_class' => 'cull', 'animal_purpose' => 'sale', 'registered_role' => null]);
            Farm::event($animal, 'breeding', "Culled from breeding by {$u->name} (was {$was}): {$data['reason']}", $u->name);
            Task::where('animal_id', $animal->id)->whereIn('status', Task::OPEN)->where('title', 'like', \App\Support\Eligibility::TASK_PREFIX.'%')
                ->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $u->name, 'notes' => 'Culled from breeding.']);
        });
        \App\Support\Tenant::flush();
        Farm::log($u, "Culled {$animal->farm_number} from breeding: {$data['reason']}", "/goats/{$animal->id}");

        return back()->with('success', "{$animal->farm_number} is out of breeding (Cull goat, for sale). It stays on the farm and on record.");
    }

    public function decide(Request $request, DeathReport $report): RedirectResponse
    {
        abort_unless($request->user()->passes('approve_death'), 403, 'Approving a death is the administrator\'s.');
        abort_unless($report->status === 'pending', 422, 'This report was already decided.');
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])], 'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:300']],
            ['note.required_if' => 'Say why, so the vet can correct it.']);
        $u = $request->user();
        $animal = $report->animal;
        DB::transaction(function () use ($report, $data, $u, $animal) {
            if ($data['decision'] === 'approved') {
                abort_unless($animal->status === 'active', 422, 'This goat is no longer on the farm.');
                try {
                    $m = Movements::exit($animal, 'death', $u, ['moved_on' => $report->died_at->toDateString(), 'reason' => "Died {$report->died_at->format('d/m/Y H:i')}: {$report->cause}",
                        'notes' => mb_substr($report->circumstances ?? '', 0, 500), 'authorized_by' => $u->name]);
                } catch (ValidationException $e) {
                    throw ValidationException::withMessages(['decision' => collect($e->errors())->flatten()->first()]);
                }
                // the cause counts in Mortality & Postmortem and Mortality by Cause
                HealthRecord::create(['animal_id' => $animal->id, 'kind' => 'postmortem', 'occurred_on' => $report->died_at->toDateString(), 'title' => $report->cause,
                    'notes' => trim(($report->postmortem ? "Postmortem: {$report->postmortem}\n" : '')."Seen: {$report->circumstances}"), 'recorded_by' => $report->reported_by, 'recorded_role' => 'Veterinarian', 'status' => 'closed']);
                $report->update(['status' => 'approved', 'decided_by' => $u->name, 'decided_at' => now(), 'decision_note' => $data['note'] ?? null, 'movement_id' => $m?->id]);
            } else {
                $report->update(['status' => 'rejected', 'decided_by' => $u->name, 'decided_at' => now(), 'decision_note' => $data['note']]);
                Farm::event($animal, 'health', "Death report not approved by {$u->name}: {$data['note']}", $u->name);
                if ($report->reported_by_user_id) {
                    Task::create(['title' => mb_substr("Death report returned: {$animal->farm_number}", 0, 190), 'type' => 'health', 'priority' => 'high', 'status' => 'open', 'due_on' => now()->toDateString(),
                        'animal_id' => $animal->id, 'user_id' => $report->reported_by_user_id, 'notes' => mb_substr("{$u->name}: {$data['note']}", 0, 500)]);
                }
            }
            Task::where('animal_id', $animal->id)->where('title', 'like', self::TASK.'%')->whereIn('status', Task::OPEN)
                ->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $u->name]);
        });
        Farm::log($u, ($data['decision'] === 'approved' ? 'Approved' : 'Returned')." the death report of {$animal->farm_number}", "/goats/{$animal->id}", 'security');

        return back()->with('success', $data['decision'] === 'approved' ? "{$animal->farm_number} is recorded as dead. The death review is on its page." : 'Report returned to the vet.');
    }
}
