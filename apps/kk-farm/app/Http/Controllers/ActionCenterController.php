<?php

namespace App\Http\Controllers;

use App\Support\ActionCenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** D-077: the morning check (workbook "Action Center"). */
class ActionCenterController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Actions', ['items' => ActionCenter::items($request->user()), 'checkedAt' => now()->setTimezone(\App\Support\Feed::now()->timezone)->format('g:i A')]);
    }
}
