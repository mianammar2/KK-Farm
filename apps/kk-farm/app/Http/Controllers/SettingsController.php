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
    public function index(): Response
    {
        return Inertia::render('Settings', [
            'breeds' => Breed::orderBy('name')->get()->map(fn (Breed $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code, 'next' => $b->nextTagId(), 'gestation' => $b->default_gestation_days, 'goats' => Animal::active()->where('breed_id', $b->id)->count()]),
            'ages' => ['growerFrom' => \App\Support\Classification::boundaries()[0], 'adultFrom' => \App\Support\Classification::boundaries()[1]],
            'matrix' => config('kkfarm.modules'),
            'gates' => config('kkfarm.gates'),
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
