<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\StaffSkill;
use App\Models\StaffTraining;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-210: App 14 Training & Skills. Training records by topic with expiry, the training each job needs, and skills /
 * authorisations (vet licence, medicine administration, milk release, payment approval…) with evidence, assessor and
 * expiry. Compliance = every required topic current. Records are kept: a refresher is a new record, a skill is revoked.
 */
class TrainingController extends Controller
{
    private function manager(Request $request): bool
    {
        return in_array($request->user()->role, ['ceo', 'manager'], true);
    }

    public function index(Request $request): Response
    {
        $people = Employee::where('is_active', true)->orderBy('name')->get();
        $trainings = StaffTraining::with('employee')->whereIn('employee_id', $people->pluck('id'))->orderByDesc('trained_on')->get();
        $skills = StaffSkill::with('employee')->whereIn('employee_id', $people->pluck('id'))->orderByDesc('assessed_on')->get();
        $soon = today()->addDays(30);
        $matrix = $people->map(function (Employee $e) use ($trainings, $skills, $soon) {
            $mine = $trainings->where('employee_id', $e->id);
            $need = StaffTraining::requiredFor($e);
            $cells = collect($need)->mapWithKeys(function ($topic) use ($mine, $soon) {
                $last = $mine->where('topic', $topic)->sortByDesc('trained_on')->first();
                $state = match (true) {
                    ! $last => 'missing',
                    $last->expires_on && $last->expires_on->lt(today()) => 'expired',
                    $last->expires_on && $last->expires_on->lte($soon) => 'due',
                    default => 'ok',
                };

                return [$topic => ['state' => $state, 'on' => $last?->trained_on->format('d/m/Y'), 'expires' => $last?->expires_on?->format('d/m/Y')]];
            });

            return ['id' => $e->id, 'name' => $e->name, 'code' => $e->code, 'role' => $e->role, 'cells' => $cells, 'compliant' => $cells->every(fn ($c) => in_array($c['state'], ['ok', 'due'], true)),
                'skills' => $skills->where('employee_id', $e->id)->filter->valid()->map(fn ($s) => StaffSkill::SKILLS[$s->skill] ?? $s->skill)->values()];
        });

        return Inertia::render('Training', [
            'matrix' => $matrix->values(),
            'trainings' => $trainings->take(200)->map(fn (StaffTraining $t) => ['id' => $t->id, 'person' => $t->employee?->name, 'topic' => StaffTraining::TOPICS[$t->topic] ?? $t->topic, 'title' => $t->title,
                'on' => $t->trained_on->format('d/m/Y'), 'expires' => $t->expires_on?->format('d/m/Y'), 'expired' => (bool) $t->expires_on?->lt(today()), 'trainer' => $t->trainer, 'certificate' => $t->certificate_ref, 'by' => $t->recorded_by])->values(),
            'skills' => $skills->map(fn (StaffSkill $s) => ['id' => $s->id, 'person' => $s->employee?->name, 'skill' => StaffSkill::SKILLS[$s->skill] ?? $s->skill, 'authorisation' => in_array($s->skill, StaffSkill::AUTHORISATIONS, true),
                'level' => StaffSkill::LEVELS[$s->level] ?? $s->level, 'evidence' => $s->evidence, 'on' => $s->assessed_on->format('d/m/Y'), 'until' => $s->valid_until?->format('d/m/Y'), 'valid' => $s->valid(),
                'status' => $s->status, 'by' => $s->assessed_by, 'revokedBy' => $s->revoked_by, 'revokeReason' => $s->revoke_reason])->values(),
            'kpi' => ['people' => $people->count(), 'compliance' => $people->count() ? (int) round($matrix->where('compliant', true)->count() * 100 / $people->count()) : null,
                'expired' => $matrix->sum(fn ($m) => collect($m['cells'])->whereIn('state', ['missing', 'expired'])->count()), 'due' => $matrix->sum(fn ($m) => collect($m['cells'])->where('state', 'due')->count()),
                'authorised' => $skills->filter(fn ($s) => $s->valid() && in_array($s->skill, StaffSkill::AUTHORISATIONS, true))->count()],
            'people' => $people->map(fn ($e) => ['id' => $e->id, 'name' => $e->name])->values(),
            'options' => ['topics' => StaffTraining::TOPICS, 'skills' => StaffSkill::SKILLS, 'levels' => StaffSkill::LEVELS],
            'canEdit' => $this->manager($request),
        ]);
    }

    public function train(Request $request): RedirectResponse
    {
        abort_unless($this->manager($request), 403);
        $data = $request->validate(['employee_ids' => ['required', 'array', 'min:1', 'max:200'], 'employee_ids.*' => ['integer', 'exists:employees,id'], 'topic' => ['required', Rule::in(array_keys(StaffTraining::TOPICS))],
            'title' => ['nullable', 'string', 'max:160'], 'trained_on' => ['required', 'date', 'before_or_equal:today'], 'trainer' => ['nullable', 'string', 'max:120'],
            'expires_on' => ['nullable', 'date', 'after:trained_on'], 'certificate_ref' => ['nullable', 'string', 'max:80']], [], ['employee_ids' => 'people']);
        foreach (array_unique($data['employee_ids']) as $id) {
            StaffTraining::create(['employee_id' => $id] + collect($data)->except('employee_ids')->all() + ['recorded_by' => $request->user()->name]);
        }
        Farm::log($request->user(), StaffTraining::TOPICS[$data['topic']].' training recorded for '.count($data['employee_ids']).' people', '/hr/training');

        return back()->with('success', 'Training recorded.');
    }

    public function skill(Request $request): RedirectResponse
    {
        abort_unless($this->manager($request), 403);
        $data = $request->validate(['employee_id' => ['required', 'integer', 'exists:employees,id'], 'skill' => ['required', Rule::in(array_keys(StaffSkill::SKILLS))], 'level' => ['required', Rule::in(array_keys(StaffSkill::LEVELS))],
            'evidence' => ['nullable', 'string', 'max:200'], 'assessed_on' => ['required', 'date', 'before_or_equal:today'], 'valid_until' => ['nullable', 'date', 'after:assessed_on']]);
        if (in_array($data['skill'], StaffSkill::AUTHORISATIONS, true) && empty($data['evidence'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['evidence' => 'An authorisation needs its evidence (licence number, assessment or approval).']);
        }
        StaffSkill::create($data + ['assessed_by' => $request->user()->name]);
        Farm::log($request->user(), (StaffSkill::SKILLS[$data['skill']]).' recorded for '.Employee::find($data['employee_id'])?->name, '/hr/training?tab=skills');

        return back()->with('success', 'Skill recorded.');
    }

    public function revoke(Request $request, StaffSkill $skill): RedirectResponse
    {
        abort_unless($this->manager($request), 403);
        abort_unless($skill->status === 'active', 422);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $skill->update(['status' => 'revoked', 'revoked_by' => $request->user()->name, 'revoke_reason' => $data['reason']]);

        return back()->with('success', 'Revoked. The record stays.');
    }
}
