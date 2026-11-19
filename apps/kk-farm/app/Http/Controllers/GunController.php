<?php

namespace App\Http\Controllers;

use App\Models\GunCommand;
use App\Models\GunDevice;
use App\Support\Farm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

    public function report(Request $request): JsonResponse
    {
        $data = $request->validate([
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
        ]);
        $gun = GunDevice::firstOrNew(['install_id' => $data['install_id']]);
        if (! $gun->exists) {
            $gun->label = 'Gun '.(GunDevice::count() + 1);
        }
        $gun->fill([
            'app_version' => $data['version'] ?? $gun->app_version,
            'app_code' => $data['code'] ?? $gun->app_code,
            'reader' => $data['reader'] ?? $gun->reader,
            'state' => $data['state'] ?? $gun->state,
            'last_user' => $request->user()->name,
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
        $busy = GunCommand::where('gun_device_id', $gun->id)->where('created_at', '>', now()->subMinutes(5))->exists();

        return response()->json([
            'commands' => $waiting->map(fn (GunCommand $c) => ['id' => $c->id, 'command' => $c->command])->values(),
            'every' => $busy ? 10 : 60, // seconds to the next report
        ]);
    }

    public function index(): Response
    {
        $guns = GunDevice::with(['commands' => fn ($q) => $q->latest('id')->limit(15)])->orderBy('label')->get();

        return Inertia::render('Settings/Guns', [
            'guns' => $guns->map(fn (GunDevice $g) => [
                'id' => $g->id, 'label' => $g->label, 'version' => $g->app_version, 'code' => $g->app_code, 'reader' => $g->reader,
                'state' => $g->state ?? [], 'user' => $g->last_user,
                'seen' => $g->last_seen_at?->format('d/m/Y H:i:s'), 'online' => $g->last_seen_at?->gt(now()->subMinutes(3)) ?? false,
                'logsAt' => $g->logs_at?->format('d/m/Y H:i:s'), 'logLines' => $g->logs ? substr_count($g->logs, "\n") + 1 : 0,
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
        $data = $request->validate(['command' => ['required', Rule::in(array_keys(GunCommand::COMMANDS))]]);
        GunCommand::create(['gun_device_id' => $gun->id, 'command' => $data['command'], 'requested_by' => $request->user()->name]);
        Farm::log($request->user(), "Asked {$gun->label} to: ".GunCommand::COMMANDS[$data['command']], '/settings/guns');

        return back()->with('success', "Sent to {$gun->label}. It answers with its next report (within a minute while the app is open).");
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
