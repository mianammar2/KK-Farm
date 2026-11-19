<?php

namespace App\Http\Controllers;

use App\Models\GunCommand;
use App\Models\GunDevice;
use App\Support\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Support\Tenant;
use Inertia\Inertia;
use Inertia\Response;

/**
 * D-354: guns report to the server by themselves, and the Administrator tests them from Settings → Guns.
 * The gun app posts a report while it is open (every minute, every 10 s while a command was sent lately), using the
 * signed-in person's session; the answer carries the commands waiting for it.
 */
class GunController extends Controller
{
    public const LOG_LIMIT = 200_000; // characters kept of a gun's log

    /** Signed in on the gun (the first report): finds or adds the gun and gives it its own key. */
    public function report(Request $request): JsonResponse
    {
        $data = $this->validReport($request);
        $gun = GunDevice::firstOrNew(['install_id' => $data['install_id']]);
        if (! $gun->exists) {
            $gun->label = 'Gun '.(GunDevice::count() + 1);
        }
        $gun->last_user = $request->user()->name;
        $key = null;
        if (! $gun->key_hash) {
            $key = 'kkg_'.Str::random(40);
            $gun->key_hash = hash('sha256', $key);
        }

        return $this->handle($gun, $data, $key);
    }

    /** D-355: with the gun's own key (api/v1/gun/report), so it reports when nobody is signed in or only the scan service runs. */
    public function reportByKey(Request $request): JsonResponse
    {
        return $this->asGun($request, function (GunDevice $gun) use ($request) {
            $data = $this->validReport($request);
            abort_unless($data['install_id'] === $gun->install_id, 409, 'This key belongs to another gun.');

            return $this->handle($gun, $data, null);
        });
    }

    public function screenByKey(Request $request): JsonResponse
    {
        return $this->asGun($request, fn (GunDevice $gun) => $this->storeScreen($request, $gun));
    }

    private function asGun(Request $request, \Closure $then): JsonResponse
    {
        $row = $request->bearerToken() ? DB::selectOne('select org_id, gun_id from gun_for_key(?)', [hash('sha256', $request->bearerToken())]) : null;
        if (! $row) {
            return response()->json(['message' => 'Unknown gun key. Sign in on the gun once.'], 401);
        }
        Tenant::enter($row->org_id);
        try {
            return $then(GunDevice::findOrFail($row->gun_id));
        } finally {
            Tenant::leave();
        }
    }

    /** @return array<string, mixed> */
    private function validReport(Request $request): array
    {
        return $request->validate([
            'install_id' => ['required', 'uuid'],
            'version' => ['nullable', 'string', 'max:20'],
            'code' => ['nullable', 'integer', 'min:0'],
            'reader' => ['nullable', 'string', 'max:20'],
            'state' => ['nullable', 'array'],
            'logs' => ['nullable', 'string'],
            'results' => ['nullable', 'array', 'max:20'],
            'results.*.id' => ['required', 'integer'],
            'results.*.ok' => ['required', 'boolean'],
            'results.*.text' => ['nullable', 'string', 'max:4000'],
            'can' => ['nullable', 'array'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function handle(GunDevice $gun, array $data, ?string $key): JsonResponse
    {
        $gun->fill([
            'app_version' => $data['version'] ?? $gun->app_version,
            'app_code' => $data['code'] ?? $gun->app_code,
            'reader' => $data['reader'] ?? $gun->reader,
            'state' => $data['state'] ?? $gun->state,
            'can' => $data['can'] ?? $gun->can,
            'last_seen_at' => now(),
        ]);
        if (isset($data['logs'])) {
            $gun->logs = mb_substr($data['logs'], -self::LOG_LIMIT);
            $gun->logs_at = now();
        }
        $gun->save();

        foreach ($data['results'] ?? [] as $r) {
            GunCommand::where('gun_device_id', $gun->id)->whereKey($r['id'])->whereIn('status', ['pending', 'sent'])
                ->update(['status' => $r['ok'] ? 'done' : 'failed', 'result' => $r['text'] ?? null, 'done_at' => now()]);
        }

        $waiting = GunCommand::where('gun_device_id', $gun->id)->where('status', 'pending')->orderBy('id')->get();
        GunCommand::whereIn('id', $waiting->pluck('id'))->update(['status' => 'sent', 'sent_at' => now()]);
        $last = GunCommand::where('gun_device_id', $gun->id)->max('created_at');
        $every = ! $last ? 60 : (now()->diffInSeconds($last, true) < 120 ? 2 : (now()->diffInSeconds($last, true) < 300 ? 10 : 60)); // D-355: 2 s while someone works the remote screen

        return response()->json([
            'commands' => $waiting->map(fn (GunCommand $c) => ['id' => $c->id, 'command' => $c->command, 'args' => $c->args ?? (object) []])->values(),
            'every' => $every, // seconds to the next report
        ] + ($key ? ['key' => $key] : []));
    }

    /** D-355: the gun sends its screen (JPEG, base64) after a screenshot, tap, swipe or key. */
    public function screen(Request $request): JsonResponse
    {
        return $this->storeScreen($request, GunDevice::where('install_id', (string) $request->input('install_id'))->firstOrFail());
    }

    private function storeScreen(Request $request, GunDevice $gun): JsonResponse
    {
        $data = $request->validate([
            'install_id' => ['required', 'uuid'],
            'image' => ['required', 'string', 'max:3000000'],
            'w' => ['required', 'integer', 'min:1', 'max:10000'],
            'h' => ['required', 'integer', 'min:1', 'max:10000'],
        ]);
        abort_unless($data['install_id'] === $gun->install_id, 409, 'This key belongs to another gun.');
        $jpeg = base64_decode($data['image'], true);
        abort_unless($jpeg !== false && str_starts_with($jpeg, "\xFF\xD8"), 422, 'Not a JPEG');
        $gun->update(['screen' => $data['image'], 'screen_w' => $data['w'], 'screen_h' => $data['h'], 'screen_at' => now(), 'last_seen_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function index(): Response
    {
        $guns = GunDevice::select(['id', 'org_id', 'install_id', 'label', 'app_version', 'app_code', 'reader', 'state', 'can', 'last_user', 'last_seen_at', 'logs_at', 'screen_at', 'created_at', 'updated_at'])
            ->selectRaw("coalesce(length(logs) - length(replace(logs, chr(10), '')) + 1, 0) as log_lines")->with(['commands' => fn ($q) => $q->latest('id')->limit(15)])->orderBy('label')->get();

        return Inertia::render('Settings/Guns', [
            'guns' => $guns->map(fn (GunDevice $g) => [
                'id' => $g->id, 'label' => $g->label, 'version' => $g->app_version, 'code' => $g->app_code, 'reader' => $g->reader,
                'state' => $g->state ?? [], 'user' => $g->last_user,
                'seen' => $g->last_seen_at?->format('d/m/Y H:i:s'), 'online' => $g->last_seen_at?->gt(now()->subMinutes(3)) ?? false,
                'logsAt' => $g->logs_at?->format('d/m/Y H:i:s'), 'can' => $g->can ?? [],
                'screenAt' => $g->screen_at?->format('d/m/Y H:i:s'), 'screenStamp' => $g->screen_at?->getTimestamp(), 'logLines' => (int) $g->log_lines,
                'commands' => $g->commands->map(fn (GunCommand $c) => [
                    'id' => $c->id, 'label' => GunCommand::COMMANDS[$c->command] ?? $c->command, 'status' => $c->status, 'result' => $c->result,
                    'by' => $c->requested_by, 'at' => $c->created_at->format('d/m/Y H:i:s'),
                ]),
            ]),
            'commands' => GunCommand::COMMANDS,
            'latest' => $this->latestApp(),
        ]);
    }

    public function logs(GunDevice $gun): \Symfony\Component\HttpFoundation\Response
    {
        return response($gun->logs ?? '', 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Content-Disposition' => 'inline; filename="'.str_replace(' ', '-', $gun->label).'-log.txt"']);
    }

    public function command(Request $request, GunDevice $gun): RedirectResponse
    {
        $data = $request->validate(['command' => ['required', Rule::in([...array_keys(GunCommand::COMMANDS), ...GunCommand::REMOTE])]]);
        $args = match ($data['command']) {
            'tap' => $request->validate(['x' => ['required', 'numeric', 'between:0,1'], 'y' => ['required', 'numeric', 'between:0,1']]),
            'swipe' => $request->validate(['x' => ['required', 'numeric', 'between:0,1'], 'y' => ['required', 'numeric', 'between:0,1'], 'x2' => ['required', 'numeric', 'between:0,1'], 'y2' => ['required', 'numeric', 'between:0,1']]),
            'key' => $request->validate(['key' => ['required', Rule::in(GunCommand::KEYS)]]),
            'text' => $request->validate(['text' => ['required', 'string', 'max:200']]),
            default => null,
        };
        GunCommand::create(['gun_device_id' => $gun->id, 'command' => $data['command'], 'args' => $args, 'requested_by' => $request->user()->name]);
        if (isset(GunCommand::COMMANDS[$data['command']])) {
            Farm::log($request->user(), "Asked {$gun->label} to: ".GunCommand::COMMANDS[$data['command']], '/settings/guns');
        } elseif ($data['command'] === 'screenshot' && ! GunCommand::where('gun_device_id', $gun->id)->where('command', 'screenshot')->where('created_at', '>', now()->subMinutes(10))->skip(1)->exists()) {
            Farm::log($request->user(), "Opened the remote screen of {$gun->label}", '/settings/guns'); // once per session, not every tap
        }

        return back()->with(isset(GunCommand::COMMANDS[$data['command']]) ? ['success' => "Sent to {$gun->label}. It answers within seconds while it is on."] : []);
    }

    public function screenImage(GunDevice $gun): \Symfony\Component\HttpFoundation\Response
    {
        $jpeg = GunDevice::whereKey($gun->id)->value('screen');
        abort_unless($jpeg, 404);

        return response(base64_decode($jpeg), 200, ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'no-store']);
    }

    public function update(Request $request, GunDevice $gun): RedirectResponse
    {
        $data = $request->validate(['label' => ['required', 'string', 'max:60']]);
        $old = $gun->label;
        $gun->update(['label' => trim($data['label'])]);
        Farm::log($request->user(), "Renamed gun {$old} to {$gun->label}", '/settings/guns');

        return back()->with('success', 'Saved.');
    }

    public function destroy(Request $request, GunDevice $gun): RedirectResponse
    {
        $gun->delete();
        Farm::log($request->user(), "Removed gun {$gun->label} from Settings → Guns", '/settings/guns');

        return back()->with('success', "{$gun->label} removed. It comes back by itself if its app reports again.");
    }

    /** @return array{versionName: string, versionCode: int}|null */
    private function latestApp(): ?array
    {
        $file = public_path('downloads/kkfarm.json');
        $info = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return $info ? ['versionName' => (string) ($info['versionName'] ?? ''), 'versionCode' => (int) ($info['versionCode'] ?? 0)] : null;
    }
}
