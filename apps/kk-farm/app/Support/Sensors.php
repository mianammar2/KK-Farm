<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\AnimalPresence;
use App\Models\Department;
use App\Models\Device;
use App\Models\DeviceKey;
use App\Models\Pen;
use App\Models\RfidAssignment;
use App\Models\SensorAlert;
use App\Models\SensorReading;
use App\Models\Task;
use App\Models\WeightRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * D-087: the device-agnostic sensor layer. Every device type sends readings in one format; one small rule per
 * metric turns a reading into the farm record a person would make (a weighing, a goat going out) or an alert.
 * Thresholds are per-device settings with farm defaults. Alerts recommend; vets and managers decide.
 */
final class Sensors
{
    /** type => [label, metrics (metric => [label, unit]), what it does] */
    public const TYPES = [
        'weighing_scale' => ['Weighing scale', ['weight_kg' => ['Weight', 'KG']], 'Weighs a goat identified by its RFID tag; adds a weighing and watches for weight loss.'],
        'rfid_gate' => ['RFID gate / reader', ['passage' => ['Passage (1 = out, 0 = in)', '']], 'Reads tags at the door to an open area: records goats going out and coming in.'],
        'camera' => ['Camera', ['headcount' => ['Goats counted', ''], 'motion' => ['Motion', '']], 'Counts goats in a pen or open area and compares with what the system expects.'],
        'environment' => ['Air / climate sensor', ['ammonia_ppm' => ['Ammonia', 'ppm'], 'temperature_c' => ['Temperature', '°C'], 'humidity_pct' => ['Humidity', '%']], 'Watches ammonia, temperature and humidity in a shed.'],
        'water_meter' => ['Water meter', ['water_l' => ['Water', 'L']], 'Water used by a pen or shed.'],
        'feed_scale' => ['Feed scale / bin', ['feed_kg' => ['Feed', 'KG']], 'Feed in a bin or trough.'],
        'energy_meter' => ['Energy meter', ['kwh' => ['Energy', 'kWh']], 'Electricity used.'],
        'other' => ['Other sensor', [], 'Any other reading; stored for later.'],
    ];

    /** Farm defaults for device settings. */
    public const DEFAULTS = [
        'weight_drop_pct' => 10, 'ammonia_ppm_max' => 25, 'temperature_c_max' => 35, 'temperature_c_min' => 5, 'humidity_pct_max' => 85,
        'offline_hours' => 2, 'out_hours_max' => 8,
    ];

    public static function newKey(Device $device): string
    {
        $key = 'kkd_'.Str::random(40);
        DeviceKey::where('device_id', $device->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        DeviceKey::create(['device_id' => $device->id, 'key_hash' => hash('sha256', $key), 'hint' => substr($key, -4)]);

        return $key;
    }

    /** @return array{org: string, device: int}|null */
    public static function deviceForKey(string $key): ?array
    {
        $row = DB::selectOne('select org_id, device_id from device_for_key(?)', [hash('sha256', $key)]);

        return $row ? ['org' => $row->org_id, 'device' => (int) $row->device_id] : null;
    }

    /**
     * Store and process readings. A reading id seen before is skipped, so a device can safely resend.
     *
     * @param  array<int, array{id: string, at?: ?string, metric: string, value?: float|int|null, unit?: ?string, rfid?: ?string, data?: ?array}>  $readings
     * @return array{stored: int, skipped: int, results: array<int, string>}
     */
    public static function ingest(Device $device, array $readings, string $by): array
    {
        $out = ['stored' => 0, 'skipped' => 0, 'results' => []];
        foreach ($readings as $r) {
            DB::transaction(function () use ($device, $r, $by, &$out) {
                if (SensorReading::where('device_id', $device->id)->where('reading_id', $r['id'])->exists()) {
                    $out['skipped']++;
                    $out['results'][] = 'duplicate';

                    return;
                }
                $epc = isset($r['rfid']) ? strtoupper(trim($r['rfid'])) : null;
                $reading = SensorReading::create([
                    'device_id' => $device->id, 'reading_id' => $r['id'], 'read_at' => self::readAt($r['at'] ?? null), 'metric' => $r['metric'],
                    'value' => $r['value'] ?? null, 'unit' => $r['unit'] ?? (self::TYPES[$device->type][1][$r['metric']][1] ?? null), 'rfid_epc' => $epc,
                    'animal_id' => $epc ? self::animalForTag($epc)?->id : null, 'payload' => $r['data'] ?? null,
                ]);
                $result = self::process($device, $reading, $by);
                DB::table('sensor_readings')->where('id', $reading->id)->update(['result' => mb_substr($result, 0, 60)]);
                $out['stored']++;
                $out['results'][] = $result;
            });
        }
        $device->forceFill(['last_seen_at' => now(), 'status' => in_array($device->status, ['planned', 'offline'], true) ? 'active' : $device->status])->save();

        return $out;
    }

    /** The device's time in the app's time zone (stored like every other timestamp); a clock ahead of ours counts as now. */
    private static function readAt(?string $at): Carbon
    {
        $t = $at ? Carbon::parse($at)->setTimezone(config('app.timezone')) : now();

        return $t->gt(now()) ? now() : $t;
    }

    public static function animalForTag(string $epc): ?Animal
    {
        return RfidAssignment::with('animal')->whereNull('valid_to')->whereHas('tag', fn ($q) => $q->whereRaw('upper(tag_code) = ?', [$epc]))->first()?->animal;
    }

    /** One rule per metric. Returns what the reading made. */
    private static function process(Device $d, SensorReading $r, string $by): string
    {
        $goat = $r->animal_id ? Animal::find($r->animal_id) : null;
        $tagged = in_array($r->metric, ['weight_kg', 'passage'], true);
        if ($tagged && $r->rfid_epc && ! $goat) {
            self::alert($d, $r, 'unknown_tag', 'info', "Tag {$r->rfid_epc} is not on any goat", "Read by {$d->name} at {$d->placeName()}. Assign the tag on the Scan page.", dedupeKey: $r->rfid_epc);

            return 'unknown tag';
        }

        return match ($r->metric) {
            'weight_kg' => $goat && $r->value > 0 ? self::weighing($d, $r, $goat) : 'stored',
            'passage' => $goat ? self::passage($d, $r, $goat, $by) : 'stored',
            'headcount' => $r->value !== null && $d->pen_id ? self::headcount($d, $r) : 'stored',
            'ammonia_ppm', 'temperature_c', 'humidity_pct' => self::environment($d, $r),
            default => 'stored',
        };
    }

    private static function weighing(Device $d, SensorReading $r, Animal $goat): string
    {
        $on = $r->read_at->copy()->setTimezone(Feed::org()->timezone ?? 'UTC')->toDateString();
        $previous = WeightRecord::where('animal_id', $goat->id)->where('measured_on', '>=', Carbon::parse($on)->subDays(60))->orderByDesc('measured_on')->orderByDesc('id')->first();
        WeightRecord::create(['animal_id' => $goat->id, 'measured_on' => $on, 'weight_kg' => round($r->value, 2), 'context' => 'routine', 'measured_by' => $d->name, 'source' => 'sensor', 'device_id' => $d->id]);
        Farm::event($goat, 'weight', 'Weighed '.round($r->value, 2)." KG by {$d->name}", $d->name, $r->read_at);
        if ($previous && $previous->weight_kg > 0) {
            $drop = ($previous->weight_kg - $r->value) / $previous->weight_kg * 100;
            if ($drop >= $d->setting('weight_drop_pct', self::DEFAULTS['weight_drop_pct'])) {
                self::alert($d, $r, 'weight_loss', $drop >= 20 ? 'critical' : 'warning', "{$goat->farm_number} lost ".round($drop, 1).'% of its weight',
                    'From '.round($previous->weight_kg, 1).' KG on '.Carbon::parse($previous->measured_on)->format('d/m').' to '.round($r->value, 1).' KG. A vet should examine the goat.', $goat, vetTask: true);

                return 'weighing + weight loss alert';
            }
        }

        return 'weighing';
    }

    private static function passage(Device $d, SensorReading $r, Animal $goat, string $by): string
    {
        $out = (int) round($r->value ?? 1) === 1;
        $area = $d->pen?->kind === 'open_area' ? $d->pen : Pen::where('kind', 'open_area')->where('shed_id', $d->shed_id ?? $goat->pen?->shed_id)->orderBy('name')->first();
        self::setPresence(collect([$goat]), $out ? 'out' : 'in', $out ? $area : null, 'rfid', $d->name, $d, $r->read_at);

        return $out ? 'went out' : 'came in';
    }

    private static function headcount(Device $d, SensorReading $r): string
    {
        $pen = $d->pen;
        $out = self::outNow();
        $expected = $pen->kind === 'open_area'
            ? $out->where('area_id', $pen->id)->count()
            : Animal::active()->where('pen_id', $pen->id)->get()->reject(fn ($g) => $out->has($g->id))->count();
        $seen = (int) round($r->value);
        if ($seen !== $expected) {
            self::alert($d, $r, 'headcount', 'warning', "{$pen->fullName()}: camera counts {$seen}, system expects {$expected}",
                $seen < $expected ? 'A goat may be missing, hidden or out without being recorded.' : 'A goat may be in the wrong pen or not registered.', pen: $pen, dedupeKey: (string) $pen->id);

            return 'headcount mismatch alert';
        }

        return 'headcount ok';
    }

    private static function environment(Device $d, SensorReading $r): string
    {
        [$label, $unit] = self::TYPES['environment'][1][$r->metric];
        $max = $d->setting("{$r->metric}_max", self::DEFAULTS["{$r->metric}_max"] ?? INF);
        $min = $d->setting("{$r->metric}_min", self::DEFAULTS["{$r->metric}_min"] ?? -INF);
        if ($r->value === null || ($r->value <= $max && $r->value >= $min)) {
            return 'stored';
        }
        $high = $r->value > $max;
        $made = self::alert($d, $r, 'environment', $high && $r->value >= $max * 2 ? 'critical' : 'warning', "{$label} ".($high ? 'high' : 'low')." at {$d->placeName()}: ".round($r->value, 1)." {$unit}",
            ($high ? 'Above ' : 'Below ').($high ? $max : $min)." {$unit}. Check ventilation, bedding and water.", pen: $d->pen, dedupeKey: $r->metric);

        return $made ? 'environment alert' : 'environment alert already open';
    }

    /** An alert, at most one open per device, kind and subject within an hour; a vet alert opens a task for the vets. */
    public static function alert(?Device $d, ?SensorReading $r, string $kind, string $severity, string $title, ?string $detail = null, ?Animal $goat = null, ?Pen $pen = null, bool $vetTask = false, ?string $dedupeKey = null): ?SensorAlert
    {
        $subject = mb_substr($dedupeKey ?? $goat?->id ?? (string) $pen?->id, 0, 64) ?: null;
        $recent = SensorAlert::where('kind', $kind)->where('status', 'open')->where('created_at', '>=', now()->subHour())
            ->where('device_id', $d?->id)->where('subject', $subject)->exists();
        if ($recent && $kind !== 'weight_loss') {
            return null;
        }
        $task = null;
        if ($vetTask) {
            $task = Task::create([
                'title' => mb_substr("Examine: {$title}", 0, 190), 'type' => 'health', 'priority' => $severity === 'critical' ? 'critical' : 'high', 'status' => 'open',
                'due_on' => now()->toDateString(), 'animal_id' => $goat?->id, 'pen_id' => $goat?->pen_id ?? $pen?->id,
                'department_id' => Department::where('name', 'Veterinary')->value('id'), 'notes' => mb_substr("Raised by {$d?->name}. {$detail}", 0, 500),
            ]);
        }

        return SensorAlert::create([
            'device_id' => $d?->id, 'reading_id' => $r?->id, 'animal_id' => $goat?->id, 'pen_id' => $pen?->id ?? $goat?->pen_id, 'kind' => $kind, 'subject' => $subject, 'severity' => $severity,
            'title' => mb_substr($title, 0, 160), 'detail' => $detail ? mb_substr($detail, 0, 500) : null, 'task_id' => $task?->id,
        ]);
    }

    /** @return Collection<string, AnimalPresence> goats out in an open area now, by goat id (their latest presence row is "out") */
    public static function outNow(): Collection
    {
        return AnimalPresence::with('area.shed')->whereIn('id', DB::table('animal_presence')->selectRaw('max(id)')->groupBy('animal_id'))
            ->where('direction', 'out')->whereHas('animal', fn ($q) => $q->active())->get()->keyBy('animal_id');
    }

    /** Goats go out to an open area, or come back in. Already out/in goats are skipped. */
    public static function setPresence(Collection $goats, string $direction, ?Pen $area, string $source, string $by, ?Device $device = null, ?Carbon $at = null): int
    {
        $now = self::outNow();
        $n = 0;
        foreach ($goats as $goat) {
            $isOut = $now->has($goat->id);
            if (($direction === 'out' && $isOut && (int) $now[$goat->id]->area_id === (int) $area?->id) || ($direction === 'in' && ! $isOut)) {
                continue;
            }
            AnimalPresence::create(['animal_id' => $goat->id, 'area_id' => $direction === 'out' ? $area?->id : $now[$goat->id]?->area_id, 'direction' => $direction,
                'at' => $at ?? now(), 'device_id' => $device?->id, 'source' => $source, 'recorded_by' => $by]);
            Farm::event($goat, 'move', $direction === 'out' ? 'Went out to '.($area?->fullName() ?? 'the open area') : 'Came back in to '.($goat->pen?->fullName() ?? 'its pen'), $by, $at);
            $n++;
        }

        return $n;
    }
}
