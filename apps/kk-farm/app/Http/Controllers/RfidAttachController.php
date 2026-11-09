<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Support\Farm;
use App\Support\GoatRegistration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * D-144: attach an RFID tag to a goat that was entered by hand, from the Goat Registry.
 *
 * "Scan RFID" on a goat arms an attach for that goat for a few minutes (kept in the session). The gun's next scan
 * opens /scan as always; the Scan page sees the armed attach and asks "Attach this tag to TD…?" instead of
 * starting the register. A code can also be typed (or typed by the gun) straight into the panel.
 * The tag code is never the goat's identity (D-004): attaching closes the goat's previous tag as "replaced"
 * and keeps it in its history. A tag on another goat is moved only after the user confirms it.
 */
class RfidAttachController extends Controller
{
    public const SESSION = 'rfid_attach';

    public const MINUTES = 3;

    /** The goat the next scan is for, when an attach was armed in the last few minutes. */
    public static function armed(Request $request): ?Animal
    {
        $armed = $request->session()->get(self::SESSION);
        if (! $armed || now()->timestamp - ($armed['at'] ?? 0) > self::MINUTES * 60) {
            return null;
        }

        return Animal::active()->find($armed['goat'] ?? null);
    }

    public function arm(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('assign_tags'), 403);
        abort_unless($animal->status === 'active', 422);
        $request->session()->put(self::SESSION, ['goat' => $animal->id, 'at' => now()->timestamp]);

        return back();
    }

    public function disarm(Request $request): RedirectResponse
    {
        $request->session()->forget(self::SESSION);

        return back();
    }

    public function attach(Request $request, Animal $animal): RedirectResponse
    {
        abort_unless($request->user()->passes('assign_tags'), 403);
        $request->merge(['tag' => GoatRegistration::normaliseCode((string) $request->input('tag'))]);
        $data = $request->validate([
            'tag' => ['required', 'string', 'min:4', 'max:64', 'regex:/^[A-Z0-9._:-]+$/'],
            'move' => ['sometimes', 'boolean'],
        ], ['tag.regex' => 'That does not look like a tag code. Scan again.', 'tag.min' => 'That code is too short. Scan again.']);

        $user = $request->user();
        $hadTag = $animal->openAssignment()->exists();
        DB::transaction(function () use ($animal, $data, $user, $hadTag) {
            $tag = RfidTag::firstOrCreate(['tag_code' => $data['tag']], ['status' => 'in_stock']);
            $open = RfidAssignment::where('rfid_tag_id', $tag->id)->whereNull('valid_to')->first();
            if ($open && $open->animal_id !== $animal->id) {
                $holder = Animal::find($open->animal_id);
                if (! ($data['move'] ?? false)) {
                    throw ValidationException::withMessages(['tag' => "This RFID is linked to {$holder->farm_number}. Link it to {$animal->farm_number} instead?", 'holder' => $holder->farm_number]);
                }
                Farm::closeOpenTag($holder, 'reassigned', $user); // its history keeps the tag
            }
            Farm::assignTag($animal, $tag, $hadTag ? 'replacement' : 'initial', $user);
        });
        $request->session()->forget(self::SESSION);
        Farm::log($user, "Linked RFID {$data['tag']} to {$animal->farm_number}", "/goats/{$animal->id}");
        $message = "RFID {$data['tag']} is linked to {$animal->farm_number}. Scanning it opens this goat; the tag ID stays {$animal->farm_number}.";

        return $request->input('from') === 'scan'
            ? redirect("/goats/{$animal->id}")->with('success', $message)
            : back()->with('success', $message);
    }
}
