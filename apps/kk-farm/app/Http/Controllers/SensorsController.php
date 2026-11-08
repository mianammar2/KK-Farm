<?php

namespace App\Http\Controllers;

use App\Models\Animal;
use App\Models\Device;
use App\Models\Pen;
use App\Models\SensorAlert;
use App\Models\SensorReading;
use App\Models\Shed;
use App\Support\Farm;
use App\Support\Sensors;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** D-087: devices, their keys, readings and sensor alerts; goats going out to open areas and back in. */
class SensorsController extends Controller
{
    public function index(Request $request): Response
    {
        $offlineAfter = now()->subHours(Sensors::DEFAULTS['offline_hours']);

        return Inertia::render('Sensors', [
            'devices' => Device::with(['pen.shed', 'shed', 'keys' => fn ($q) => $q->whereNull('revoked_at')])->orderBy('code')->get()->map(fn (Device $d) => [
                'id' => $d->id, 'code' => $d->code, 'name' => $d->name, 'type' => $d->type, 'typeLabel' => Sensors::TYPES[$d->type][0] ?? $d->type,
                'vendor' => $d->vendor, 'model' => $d->model, 'place' => $d->placeName(), 'shedId' => $d->shed_id, 'penId' => $d->pen_id,
                'status' => $d->status === 'active' && $d->last_seen_at && $d->last_seen_at->lt($offlineAfter) ? 'offline' : $d->status,
                'lastSeen' => $d->last_seen_at?->diffForHumans(short: true), 'keyHint' => $d->keys->first()?->hint, 'settings' => $d->settings ?? [], 'notes' => $d->notes,
            ]),
            'alerts' => SensorAlert::with(['device', 'animal', 'pen.shed'])->orderByRaw("status = 'open' desc")->orderByDesc('id')->limit(100)->get()->map(fn (SensorAlert $a) => [
                'id' => $a->id, 'kind' => SensorAlert::KINDS[$a->kind] ?? $a->kind, 'severity' => $a->severity, 'title' => $a->title, 'detail' => $a->detail, 'status' => $a->status,
                'device' => $a->device?->name, 'goat' => $a->animal?->farm_number, 'goatId' => $a->animal_id, 'pen' => $a->pen?->fullName(), 'taskId' => $a->task_id,
                'on' => $a->created_at->format('j M g:i A'), 'by' => $a->resolved_by ?? $a->acknowledged_by,
            ]),
            'readings' => SensorReading::with(['device', 'animal'])->orderByDesc('read_at')->orderByDesc('id')->limit(100)->get()->map(fn (SensorReading $r) => [
                'id' => $r->id, 'at' => $r->read_at->format('j M g:i:s A'), 'device' => $r->device->name, 'metric' => $r->metric, 'value' => $r->value, 'unit' => $r->unit,
                'rfid' => $r->rfid_epc, 'goat' => $r->animal?->farm_number, 'goatId' => $r->animal_id, 'result' => $r->result,
            ]),
            'out' => Sensors::outNow()->map(fn ($p) => ['goatId' => $p->animal_id, 'goat' => $p->animal->farm_number, 'area' => $p->area?->fullName(), 'since' => $p->at->diffForHumans(short: true), 'hours' => round($p->at->diffInMinutes(now()) / 60, 1)])->values(),
            'types' => collect(Sensors::TYPES)->map(fn ($t, $k) => ['key' => $k, 'label' => $t[0], 'metrics' => collect($t[1])->map(fn ($m, $mk) => ['key' => $mk, 'label' => $m[0], 'unit' => $m[1]])->values(), 'help' => $t[2]])->values(),
            'defaults' => Sensors::DEFAULTS,
            'places' => Shed::with(['pens' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get()->map(fn (Shed $s) => ['id' => $s->id, 'name' => $s->name, 'pens' => $s->pens->map(fn (Pen $p) => ['id' => $p->id, 'name' => $p->fullName(), 'kind' => $p->kind])]),
            'endpoint' => url('/api/v1/readings'),
            'newKey' => $request->session()->get('device_key'),
            'can' => ['manage' => $request->user()->passes('manage_devices')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_devices'), 403);
        $data = $this->validated($request);
        $data['code'] ??= $this->nextCode($data['type']);
        $device = Device::create($data);
        $key = Sensors::newKey($device);
        Farm::log($request->user(), "Added device {$device->code} {$device->name}", '/sensors');

        return back()->with('success', "{$device->name} added. Copy its key now: it is shown only once.")->with('device_key', ['device' => $device->code, 'key' => $key]);
    }

    public function update(Request $request, Device $device): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_devices'), 403);
        $device->update($this->validated($request, $device));

        return back()->with('success', "{$device->name} saved.");
    }

    public function newKey(Request $request, Device $device): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_devices'), 403);
        $key = Sensors::newKey($device);
        Farm::log($request->user(), "Issued a new key for device {$device->code} (old key revoked)", '/sensors');

        return back()->with('success', 'New key issued; the old one no longer works. Copy it now.')->with('device_key', ['device' => $device->code, 'key' => $key]);
    }

    /** Send a reading by hand through the same rules a real device uses: to try a setup before the hardware arrives. */
    public function test(Request $request, Device $device): RedirectResponse
    {
        abort_unless($request->user()->passes('manage_devices'), 403);
        $data = $request->validate(['metric' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'], 'value' => ['nullable', 'numeric'], 'rfid' => ['nullable', 'string', 'max:64']]);
        $r = Sensors::ingest($device, [['id' => (string) Str::uuid(), 'metric' => $data['metric'], 'value' => $data['value'] ?? null, 'rfid' => $data['rfid'] ?? null, 'data' => ['test' => true, 'by' => $request->user()->name]]], $request->user()->name);

        return back()->with('success', "Test reading: {$r['results'][0]}.");
    }

    public function alert(Request $request, SensorAlert $alert): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['acknowledged', 'resolved'])]]);
        $alert->update($data['status'] === 'resolved' ? ['status' => 'resolved', 'resolved_by' => $request->user()->name, 'resolved_at' => now()] : ['status' => 'acknowledged', 'acknowledged_by' => $request->user()->name]);

        return back()->with('success', 'Alert '.($data['status'] === 'resolved' ? 'resolved' : 'acknowledged').'.');
    }

    /** Goats go out to an open area or come back in (by hand; RFID gates and cameras do the same automatically). */
    public function presence(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'animal_ids' => ['required', 'array', 'min:1', 'max:500'], 'animal_ids.*' => ['uuid', 'exists:animals,id'],
            'direction' => ['required', Rule::in(['out', 'in'])],
            'area_id' => ['nullable', 'required_if:direction,out', Rule::exists('pens', 'id')->where('kind', 'open_area')],
        ], ['area_id.required_if' => 'Choose the open area.']);
        $area = isset($data['area_id']) ? Pen::with('shed')->find($data['area_id']) : null;
        $n = Sensors::setPresence(Animal::with('pen.shed')->active()->whereIn('id', $data['animal_ids'])->get(), $data['direction'], $area, 'manual', $request->user()->name);
        Farm::log($request->user(), ($data['direction'] === 'out' ? "Sent {$n} goat(s) out to ".$area->fullName() : "Brought {$n} goat(s) back in"), '/');

        return back()->with('success', $data['direction'] === 'out' ? "{$n} goat".($n === 1 ? '' : 's')." out in {$area->fullName()}." : "{$n} goat".($n === 1 ? '' : 's').' back in their pens.');
    }

    private function validated(Request $request, ?Device $device = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'type' => ['required', Rule::in(array_keys(Sensors::TYPES))],
            'code' => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('devices', 'code')->where('org_id', \App\Support\Tenant::current())->ignore($device?->id)],
            'vendor' => ['nullable', 'string', 'max:60'], 'model' => ['nullable', 'string', 'max:60'],
            'shed_id' => ['nullable', 'exists:sheds,id'], 'pen_id' => ['nullable', 'exists:pens,id'],
            'status' => ['nullable', Rule::in(array_keys(Device::STATUSES))],
            'settings' => ['nullable', 'array'], 'settings.*' => ['nullable', 'numeric', 'between:-1000,100000'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);
        if (! empty($data['pen_id'])) {
            $data['shed_id'] = Pen::find($data['pen_id'])->shed_id; // a device in a pen is in that pen's shed
        }
        $data['settings'] = array_filter(array_intersect_key($data['settings'] ?? [], Sensors::DEFAULTS), fn ($v) => $v !== null && $v !== '');
        $data['status'] ??= $device?->status ?? 'planned';
        if (! $device) {
            $data['code'] = isset($data['code']) ? strtoupper($data['code']) : null;
        } else {
            unset($data['code']);
        }

        return $data;
    }

    private function nextCode(string $type): string
    {
        $prefix = ['weighing_scale' => 'SCL', 'rfid_gate' => 'GATE', 'camera' => 'CAM', 'environment' => 'AIR', 'water_meter' => 'WTR', 'feed_scale' => 'FEED', 'energy_meter' => 'NRG'][$type] ?? 'DEV';
        $n = Device::where('code', 'like', "$prefix-%")->count() + 1;
        while (Device::where('code', sprintf('%s-%02d', $prefix, $n))->exists()) {
            $n++;
        }

        return sprintf('%s-%02d', $prefix, $n);
    }
}
