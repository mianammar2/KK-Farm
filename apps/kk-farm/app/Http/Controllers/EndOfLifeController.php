<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\EndOfLifeReview;
use App\Models\WelfareCase;
use App\Support\Farm;
use App\Support\Movements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-211: App 19 Humane End of Life. Anyone caring for a goat can ask for a review; the vet assesses it (supports it or
 * not); the owner or manager authorises it (not the person who asked, except the owner); only the vet performs it and
 * records the method and disposal; the death is then recorded through the normal exit, so the goat's history, tag and
 * counts close the same way. The system records the decision trail; it never decides.
 */
class EndOfLifeController extends Controller
{
    public function index(Request $request): Response
    {
        $rows = EndOfLifeReview::with(['animal.pen.shed', 'welfareCase'])->orderByRaw("status in ('requested','assessed','approved') desc")->orderByDesc('id')->limit(100)->get();
        $role = $request->user()->role;

        return Inertia::render('EndOfLife', [
            'reviews' => $rows->map(fn (EndOfLifeReview $r) => ['id' => $r->id, 'no' => $r->label(), 'goatId' => $r->animal_id, 'goat' => $r->animal?->farm_number, 'animalId' => $r->animal?->animal_code,
                'pen' => $r->animal?->pen?->fullName(), 'reason' => EndOfLifeReview::REASONS[$r->reason] ?? $r->reason, 'details' => $r->details, 'requestedBy' => $r->requested_by,
                'on' => $r->created_at->format('d/m/Y'), 'welfare' => $r->welfareCase?->label(), 'assessment' => $r->vet_assessment, 'recommends' => $r->vet_recommends, 'vetBy' => $r->vet_by,
                'approvedBy' => $r->approved_by, 'status' => $r->status, 'statusLabel' => EndOfLifeReview::STATUS[$r->status] ?? $r->status, 'method' => $r->method, 'disposition' => $r->disposition,
                'performedBy' => $r->performed_by, 'performedAt' => $r->performed_at?->format('d/m/Y g:i A')])->values(),
            'goats' => Animal::active()->orderBy('farm_number')->get(['id', 'farm_number'])->map(fn ($a) => ['id' => $a->id, 'number' => $a->farm_number])->values(),
            'cases' => WelfareCase::whereNotIn('status', ['closed'])->with('animal')->get()->map(fn ($c) => ['id' => $c->id, 'label' => $c->label(), 'goatId' => $c->animal_id])->values(),
            'reasons' => EndOfLifeReview::REASONS,
            'isVet' => $role === 'vet', 'canAuthorise' => in_array($role, ['ceo', 'manager'], true), 'me' => $request->user()->name,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('request_end_of_life'), 403, 'Only the vet or the farm manager asks for a humane end-of-life review.'); // D-264 F-04
        $data = $request->validate(['animal_id' => ['required', 'uuid', 'exists:animals,id'], 'welfare_case_id' => ['nullable', 'exists:welfare_cases,id'],
            'reason' => ['required', Rule::in(array_keys(EndOfLifeReview::REASONS))], 'details' => ['required', 'string', 'min:10', 'max:1000']], [], ['animal_id' => 'goat']);
        $a = Animal::findOrFail($data['animal_id']);
        abort_unless($a->status === 'active', 422, 'This goat is no longer on the farm.');
        if (EndOfLifeReview::where('animal_id', $a->id)->whereIn('status', ['requested', 'assessed', 'approved'])->exists()) {
            throw ValidationException::withMessages(['animal_id' => "{$a->farm_number} already has an open end-of-life review."]);
        }
        $r = EndOfLifeReview::create($data + ['requested_by' => $request->user()->name]);
        \App\Models\Task::create(['title' => mb_substr("Vet: assess {$a->farm_number} for humane end of life ({$r->label()})", 0, 190), 'type' => 'health', 'priority' => 'high', 'status' => 'open',
            'due_on' => now()->toDateString(), 'pen_id' => $a->pen_id, 'department_id' => \App\Models\Department::where('name', 'Veterinary')->value('id'), 'notes' => mb_substr($data['details'], 0, 500)]);
        Farm::event($a, 'health', "End-of-life review {$r->label()} requested: ".EndOfLifeReview::REASONS[$data['reason']], $request->user()->name);

        return back()->with('success', "{$r->label()} sent to the vet for assessment.");
    }

    public function assess(Request $request, EndOfLifeReview $review): RedirectResponse
    {
        abort_unless($request->user()->passes('clinical'), 403, 'Only the vet assesses.'); // D-264 F-16: the vet-locked action, so a person the administrator grants it can act
        abort_unless($review->status === 'requested', 422, 'Already assessed.');
        $data = $request->validate(['recommends' => ['required', 'boolean'], 'assessment' => ['required', 'string', 'min:10', 'max:1000']]);
        $review->update(['vet_recommends' => $data['recommends'], 'vet_assessment' => $data['assessment'], 'vet_by' => $request->user()->name, 'vet_at' => now(), 'status' => $data['recommends'] ? 'assessed' : 'declined']);
        Farm::event($review->animal, 'health', "End-of-life review {$review->label()}: vet ".($data['recommends'] ? 'supports' : 'does not support').' it', $request->user()->name);

        return back()->with('success', $data['recommends'] ? 'Assessment saved. The owner or manager authorises.' : 'Not supported: care continues under the vet.');
    }

    public function authorise(Request $request, EndOfLifeReview $review): RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['ceo', 'manager'], true), 403, 'The owner or manager authorises.');
        abort_unless($review->status === 'assessed' && $review->vet_recommends, 422, 'The vet must support it first.');
        if ($review->requested_by === $user->name && $user->role !== 'ceo') {
            throw ValidationException::withMessages(['decision' => 'Someone other than the person who asked authorises.']);
        }
        $data = $request->validate(['decision' => ['required', Rule::in(['approved', 'declined'])]]);
        $review->update(['status' => $data['decision'], 'approved_by' => $user->name, 'approved_at' => now()]);

        return back()->with('success', $data['decision'] === 'approved' ? 'Authorised. Only the vet performs it.' : 'Declined.');
    }

    public function perform(Request $request, EndOfLifeReview $review): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->passes('clinical'), 403, 'Only the vet performs humane euthanasia.'); // D-264 F-16
        abort_unless($review->status === 'approved', 422, 'It must be authorised first.');
        $data = $request->validate(['method' => ['required', 'string', 'max:200'], 'disposition' => ['required', 'string', 'max:120']]);
        DB::transaction(function () use ($review, $data, $user) {
            $m = Movements::exit($review->animal, 'death', $user, ['reason' => "Humane euthanasia {$review->label()}: ".EndOfLifeReview::REASONS[$review->reason], 'notes' => mb_substr("Method: {$data['method']}. Disposal: {$data['disposition']}", 0, 500), 'authorized_by' => $review->approved_by]);
            $review->update($data + ['status' => 'done', 'performed_by' => $user->name, 'performed_at' => now(), 'movement_id' => $m->id]);
        });
        Farm::log($user, "Humane end of life {$review->label()} for {$review->animal->farm_number}", '/welfare/end-of-life');

        return back()->with('success', 'Recorded. The death is on the goat record and its tag is released.');
    }

    public function withdraw(Request $request, EndOfLifeReview $review): RedirectResponse
    {
        abort_unless(in_array($review->status, ['requested', 'assessed', 'approved'], true), 422);
        abort_unless($review->requested_by === $request->user()->name || in_array($request->user()->role, ['ceo', 'manager', 'vet'], true), 403);
        $review->update(['status' => 'withdrawn']);

        return back()->with('success', 'Withdrawn.');
    }
}
