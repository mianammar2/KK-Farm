<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Breed;
use App\Support\Farm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    /** D-215: the Settings master page (a card per settings page) or one settings page on its own. */
    public function index(Request $request, ?string $section = null): Response
    {
        abort_unless(in_array($section, [null, 'breeds', 'ages', 'system'], true), 404);
        $org = $request->user()->org_id;

        return Inertia::render('Settings', [
            'section' => $section,
            'breeds' => Breed::orderBy('name')->get()->map(fn (Breed $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'next' => $b->nextTagId(), 'gestation' => $b->default_gestation_days, 'lightBelow' => $b->young_below_kg !== null ? (float) $b->young_below_kg : null, 'goats' => Animal::active()->where('breed_id', $b->id)->count(), 'used' => self::breedUses($b)]),
            'ages' => ['growerFrom' => \App\Support\Classification::boundaries()[0], 'adultFrom' => \App\Support\Classification::boundaries()[1]],
            'counts' => ['users' => \App\Models\User::where('org_id', $org)->where('active', true)->count(), 'fields' => \App\Models\FieldDefinition::count(),
                'overrides' => \App\Models\User::where('org_id', $org)->get()->filter(fn ($u) => ! empty($u->module_access) || ! empty($u->gate_access))->count()],
            'environment' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'database' => 'PostgreSQL '.explode(' ', \Illuminate\Support\Facades\DB::selectOne('show server_version')->server_version)[0]],
        ]);
    }

    public function addBreed(Request $request): RedirectResponse
    {
        $request->merge(['code' => $request->filled('code') ? Breed::normaliseCode((string) $request->input('code')) : null]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'unique:breeds,name'],
            'code' => ['nullable', 'string', 'min:1', 'max:8', 'unique:breeds,code'], // Tag IDs of this breed start with it (D-041)
        ]);

        Breed::create(array_filter($data) + ['default_gestation_days' => config('kkfarm.demo_gestation_days')]);
        Farm::log($request->user(), "Added breed {$data['name']}", '/settings');

        return back()->with('success', 'Breed added.');
    }

    /** D-110: the breed's "light" weight line, used to estimate the age of goats with no teeth recorded. */
    public function updateBreed(Request $request, Breed $breed): RedirectResponse
    {
        $data = $request->validate(['young_below_kg' => ['nullable', 'numeric', 'between:1,150']]);
        $breed->update(['young_below_kg' => $data['young_below_kg'] ?? null]);
        Farm::log($request->user(), "Set {$breed->name} light weight line to ".($data['young_below_kg'] ?? 'none').' KG', '/settings');

        return back()->with('success', "{$breed->name}: saved. Goats with no teeth get their age from their first weighing.");
    }

    /** D-219: rename a breed or change its tag code. Tags already given keep their ID; new tags use the new code. */
    public function editBreed(Request $request, Breed $breed): RedirectResponse
    {
        $request->merge(['code' => Breed::normaliseCode((string) $request->input('code'))]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', \Illuminate\Validation\Rule::unique('breeds', 'name')->ignore($breed->id)],
            'code' => ['required', 'string', 'min:1', 'max:8', \Illuminate\Validation\Rule::unique('breeds', 'code')->ignore($breed->id)],
        ]);
        $was = "{$breed->name} ({$breed->code})";
        $breed->update($data);
        Farm::log($request->user(), "Changed breed {$was} to {$data['name']} ({$data['code']})", '/settings/breeds');

        return back()->with('success', "{$data['name']}: saved. Tags already given keep their ID; new tags start with {$data['code']}.");
    }

    /** D-219: delete a breed nothing uses. A breed on any goat (also sold or dead ones) or record stays, so history is kept. */
    public function deleteBreed(Request $request, Breed $breed): RedirectResponse
    {
        $used = self::breedUses($breed);
        if ($used) {
            throw \Illuminate\Validation\ValidationException::withMessages(['breed' => "{$breed->name} cannot be deleted: {$used} goats or records use it. Change their breed first."]);
        }
        $breed->delete();
        Farm::log($request->user(), "Deleted breed {$breed->name} ({$breed->code})", '/settings/breeds');

        return back()->with('success', "{$breed->name} deleted.");
    }

    /** How many rows of any table (goats in every status, purchase lots, plans, standards) point at this breed. */
    public static function breedUses(Breed $breed): int
    {
        $tables = \App\Support\Tenant::memo('breed_id_tables', fn () => collect(\Illuminate\Support\Facades\DB::select("select table_name from information_schema.columns where table_schema = current_schema() and column_name = 'breed_id' and table_name <> 'breeds'"))->pluck('table_name')->all());

        return collect($tables)->sum(fn ($t) => \Illuminate\Support\Facades\DB::table($t)->where('breed_id', $breed->id)->count());
    }

    /** Age category boundaries for this farm (D-047): Kid below the first, Adult from the second, Grower between. */
    public function updateAges(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'grower_from_months' => ['required', 'integer', 'min:1', 'max:24'],
            'adult_from_months' => ['required', 'integer', 'max:60', 'gt:grower_from_months'],
        ]);

        \App\Models\Organization::whereKey($request->user()->org_id)->update($data);
        \App\Support\Classification::forgetBoundaries();
        Farm::log($request->user(), "Set age categories: kid under {$data['grower_from_months']} months, adult from {$data['adult_from_months']} months", '/settings');

        return back()->with('success', 'Age categories saved. Every goat is reclassified from its birth date.');
    }
}
