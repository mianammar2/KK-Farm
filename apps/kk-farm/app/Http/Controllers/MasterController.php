<?php

namespace App\Http\Controllers;

use App\Support\Farm;
use App\Support\Masters;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** D-240: Delete on every master list, with one rule (see App\Support\Masters). */
class MasterController extends Controller
{
    public function destroy(Request $request, string $type, string $id): RedirectResponse
    {
        abort_unless(isset(Masters::TYPES[$type]), 404);
        abort_unless(Masters::allowed($request->user(), $type), 403);
        $model = Masters::TYPES[$type][0]::findOrFail($id);
        [$outcome, $message] = Masters::remove($type, $model);
        Farm::log($request->user(), ucfirst($outcome).": {$type} ".($model->{Masters::TYPES[$type][3]} ?? $id), url()->previous());

        return back()->with('success', $message);
    }
}
