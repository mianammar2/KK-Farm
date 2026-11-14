<?php

namespace App\Http\Controllers;

use App\Models\WeightRecord;
use App\Support\AgeFromWeight;
use App\Support\Farm;
use App\Support\WeightCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** D-208 / BR-018: held weight readings, accepted or rejected by the owner, manager or vet. */
class WeightReviewController extends Controller
{
    private function reviewer(Request $request): bool
    {
        return $request->user()->passes('review_weights');
    }

    public function index(Request $request): Response
    {
        $row = fn (WeightRecord $w) => ['id' => $w->id, 'goatId' => $w->animal_id, 'goat' => $w->animal?->farm_number, 'animalId' => $w->animal?->animal_code, 'on' => $w->measured_on->format('d/m/Y'),
            'kg' => (float) $w->weight_kg, 'flag' => WeightCheck::FLAGS[$w->flag] ?? $w->flag, 'note' => $w->flag_note, 'by' => $w->measured_by, 'context' => $w->context,
            'status' => $w->review_status, 'reviewedBy' => $w->reviewed_by, 'reviewNote' => $w->review_note];
        $base = fn () => WeightRecord::withoutGlobalScope('counted')->with('animal')->whereNotNull('flag');

        return Inertia::render('WeightReview', [
            'held' => $base()->where('review_status', 'held')->orderByDesc('measured_on')->orderByDesc('id')->get()->map($row),
            'done' => $base()->whereIn('review_status', ['accepted', 'rejected'])->orderByDesc('reviewed_at')->limit(50)->get()->map($row),
            'canReview' => $this->reviewer($request),
        ]);
    }

    public function decide(Request $request, int $record): RedirectResponse
    {
        abort_unless($this->reviewer($request), 403, 'The owner, manager or vet reviews held weights.');
        $w = WeightRecord::held()->findOrFail($record);
        $data = $request->validate(['decision' => ['required', Rule::in(['accepted', 'rejected'])], 'note' => ['nullable', 'required_if:decision,rejected', 'string', 'max:300']], [], ['note' => 'reason']);
        $w->update(['review_status' => $data['decision'], 'reviewed_by' => $request->user()->name, 'reviewed_at' => now(), 'review_note' => $data['note'] ?? null]);
        if ($data['decision'] === 'accepted') {
            AgeFromWeight::fromRecord($w);
        }
        Farm::log($request->user(), ($data['decision'] === 'accepted' ? 'Accepted' : 'Rejected')." held weight {$w->weight_kg} KG of {$w->animal?->farm_number}", '/weights/review');

        return back()->with('success', $data['decision'] === 'accepted' ? 'Accepted: the weight now counts.' : 'Rejected: kept on record, never counted.');
    }
}
