<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Support\Farm;
use App\Support\GoatRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Simulated reader. It looks up the current assignment; the tag code is never the goat's identity. */
class ScanController extends Controller
{
    public function index(Request $request): Response|RedirectResponse
    {
        $code = GoatRegistration::normaliseCode($request->query('tag', ''));
        $result = null;

        if ($code !== '') {
            $tag = RfidTag::where('tag_code', $code)->first();
            $open = $tag ? RfidAssignment::with('animal.breed', 'animal.pen', 'animal.latestWeight', 'animal.photos')->where('rfid_tag_id', $tag->id)->whereNull('valid_to')->first() : null;
            $past = $tag && ! $open ? RfidAssignment::with('animal')->where('rfid_tag_id', $tag->id)->orderByDesc('valid_to')->get() : collect();

            // D-144: "Scan RFID" was pressed on a goat in the registry: this scan is for that goat.
            if ($armed = RfidAttachController::armed($request)) {
                return Inertia::render('Scan', $this->pageProps() + ['result' => null, 'attach' => [
                    'code' => $code, 'goat' => ['id' => $armed->id, 'number' => $armed->farm_number, 'name' => $armed->name],
                    'current' => $armed->openAssignment?->tag?->tag_code,
                    'holder' => $open && $open->animal_id !== $armed->id ? $open->animal->farm_number : null,
                    'same' => $open?->animal_id === $armed->id,
                ]]);
            }

            // D-179: a worker job is running on this device: the scanned goat goes straight into that job.
            if (WorkerController::job($request)) {
                return $open ? redirect('/worker?goat='.$open->animal_id) : redirect('/worker?unknown='.urlencode($code));
            }

            // The scan starts the goat register when there is nothing to show (D-040): a tag the farm has never seen,
            // or one that was never used on any goat.
            if ((! $tag || (! $open && $past->isEmpty() && $tag->status !== 'lost')) && \App\Support\Pages::allows($request->user(), '/goats/register')) { // D-241: only roles that register goats
                return redirect('/goats/register?epc='.urlencode($code));
            }

            $result = [
                'code' => $code,
                'state' => ! $tag ? 'unknown' : ($open ? 'assigned' : ($tag->status === 'lost' ? 'lost' : 'free')),
                'goat' => $open ? $open->animal->card() + ['withdrawal' => \App\Support\Withdrawal::summary($open->animal),
                    // D-102: what the scan pop-up offers, and her day of pregnancy
                    'pregnancyDay' => \App\Support\Checkups::dayOfPregnancy($open->animal, now()->toDateString()),
                    'can' => ['health' => $request->user()->canModule('health'), 'check' => $request->user()->passes('health_checkup'), 'weigh' => $request->user()->passes('record_weights'), 'move' => $request->user()->canModule('sheds')]] : null,
                'since' => $open?->valid_from->format('j M Y'),
                'history' => $past->map(fn (RfidAssignment $a) => ['id' => $a->animal_id, 'number' => $a->animal->farm_number, 'to' => $a->valid_to?->format('j M Y'), 'reason' => $a->reason_unassigned])->values(),
            ];
        }

        return Inertia::render('Scan', $this->pageProps() + ['result' => $result, 'attach' => null]);
    }

    private function pageProps(): array
    {
        return [
            'samples' => [
                'assigned' => RfidAssignment::with('tag')->whereNull('valid_to')->orderBy('id')->limit(4)->get()->pluck('tag.tag_code'),
                'free' => RfidTag::where('status', 'in_stock')->whereHas('assignments')->limit(2)->pluck('tag_code'),
                'new' => RfidTag::where('status', 'in_stock')->whereDoesntHave('assignments')->limit(2)->pluck('tag_code'),
            ],
            'untagged' => Animal::active()->whereDoesntHave('openAssignment')->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
            'goats' => Animal::active()->orderBy('farm_number')->get(['id', 'farm_number', 'name']),
        ];
    }

    public function assign(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('assign_tags'), 403);
        $request->merge(['tag' => GoatRegistration::normaliseCode($request->input('tag'))]);
        $data = $request->validate(['tag' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9._:-]+$/'], 'animal_id' => ['required', 'exists:animals,id'], 'reason' => ['required', Rule::in(['initial', 'replacement', 'reassigned'])]]);

        $animal = Animal::findOrFail($data['animal_id']);
        // A tag the farm has never seen is added to the tag register here, then assigned (D-040).
        Farm::assignTag($animal, RfidTag::firstOrCreate(['tag_code' => $data['tag']], ['status' => 'in_stock']), $data['reason'], $request->user());
        Farm::log($request->user(), "Assigned tag {$data['tag']} to {$animal->farm_number}", "/goats/{$animal->id}");

        return redirect('/scan?tag='.urlencode($data['tag']))->with('success', "Tag assigned to {$animal->farm_number}.");
    }

    public function remove(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('assign_tags'), 403);
        $data = $request->validate(['animal_id' => ['required', 'exists:animals,id'], 'reason' => ['required', Rule::in(['lost', 'damaged', 'removed'])]]);

        $animal = Animal::findOrFail($data['animal_id']);
        Farm::closeOpenTag($animal, $data['reason'], $request->user());
        Farm::log($request->user(), "Removed the tag from {$animal->farm_number} ({$data['reason']})", "/goats/{$animal->id}");

        return back()->with('success', 'Tag removed. The goat keeps its identity and full history.');
    }
}
