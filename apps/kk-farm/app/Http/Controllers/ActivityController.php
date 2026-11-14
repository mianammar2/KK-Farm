<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** D-122: the Activity Log: who did what and when, newest first (moved off the dashboard). */
class ActivityController extends Controller
{
    public const PER_PAGE = 50;

    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $q = trim((string) $request->query('q', ''));
        $who = (string) $request->query('who', '');
        $base = Activity::query()->when(! $user->canModule('finance'), fn ($x) => $x->where('audience', 'farm'))
            ->when($user->role !== 'ceo', fn ($x) => $x->where('audience', '!=', 'security')); // D-241: refused-access entries are the administrator's
        $rows = (clone $base)
            ->when($q !== '', fn ($x) => $x->where('action', 'ilike', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%'))
            ->when($who !== '', fn ($x) => $x->where('actor', $who))
            ->latest()->limit(((int) $request->query('pages', 1)) * self::PER_PAGE + 1)->get();
        $more = $rows->count() > ((int) $request->query('pages', 1)) * self::PER_PAGE;

        return Inertia::render('Activity', [
            'activity' => $rows->take(((int) $request->query('pages', 1)) * self::PER_PAGE)->map(fn (Activity $a) => [
                'id' => $a->id, 'actor' => $a->actor, 'role' => $a->role, 'action' => $a->action, 'url' => $a->url,
                'at' => $a->created_at->setTimezone(\App\Support\Tenant::org()?->timezone ?: 'Asia/Karachi')->format('d/m/Y g:i A'), 'ago' => $a->created_at->diffForHumans(short: true),
                'day' => $a->created_at->setTimezone(\App\Support\Tenant::org()?->timezone ?: 'Asia/Karachi')->format('d/m/Y'),
            ])->values(),
            'more' => $more,
            'people' => (clone $base)->distinct()->orderBy('actor')->pluck('actor'),
            'filters' => ['q' => $q, 'who' => $who, 'pages' => (int) $request->query('pages', 1)],
        ]);
    }
}
