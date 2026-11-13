<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Department;
use App\Models\Device;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidTag;
use App\Models\SensorAlert;
use App\Models\SensorReading;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\ActionCenter;
use App\Support\Farm;
use App\Support\Sensors;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-087: devices, the readings API, rules per device type, alerts, open areas. */
class SensorsTest extends TestCase
{
    use RefreshDatabase;

    private Pen $pen;

    private Pen $area;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Sensor Farm', 'slug' => 'sensor-farm'])->id);
        Department::firstOrCreate(['name' => 'Veterinary']);
        $shed = Shed::create(['code' => 'S1', 'name' => 'KKF-BDU-01']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->area = Pen::create(['shed_id' => $shed->id, 'name' => 'OA01', 'purpose' => 'Grazing', 'kind' => 'open_area']);
        $breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $this->goat = Animal::create(['farm_number' => 'G-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
        $this->goat = $this->goat->fresh();
        Farm::assignTag($this->goat, RfidTag::create(['tag_code' => 'E2801170000002AA', 'status' => 'in_stock']), 'new', $this->user('manager'));
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@sensor.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    /** Add a device through the page; returns [device, key]. */
    private function device(string $type, ?Pen $pen = null, array $settings = []): array
    {
        $this->actingAs($this->user('ceo'))->post('/sensors/devices', ['name' => "Test $type", 'type' => $type, 'pen_id' => $pen?->id, 'settings' => $settings])->assertSessionHasNoErrors();
        $key = session('device_key')['key'];
        auth()->logout();

        return [Device::latest('id')->first(), $key];
    }

    private function send(string $key, array $readings)
    {
        return $this->withHeader('Authorization', "Bearer $key")->postJson('/api/v1/readings', ['readings' => $readings]);
    }

    private function reading(string $metric, ?float $value, array $extra = []): array
    {
        return ['id' => (string) Str::uuid(), 'metric' => $metric, 'value' => $value] + $extra;
    }

    public function test_a_scale_adds_weighings_and_a_weight_drop_alerts_the_vets_with_a_task(): void
    {
        [$scale, $key] = $this->device('weighing_scale', $this->pen);
        $this->assertSame(['SCL-01', 'planned', $this->pen->shed_id], [$scale->code, $scale->status, $scale->shed_id]);
        $this->assertDatabaseMissing('device_keys', ['key_hash' => $key]);

        $first = $this->reading('weight_kg', 40, ['rfid' => 'e2801170000002aa', 'at' => now()->subDays(7)->toIso8601String()]);
        $this->send($key, [$first])->assertOk()->assertJson(['stored' => 1, 'results' => ['weighing']]);
        $this->send($key, [$first])->assertOk()->assertJson(['stored' => 0, 'skipped' => 1], 'a resend is stored once');
        $this->assertSame([40.0, 'sensor', 'Test weighing_scale'], [(float) WeightRecord::sole()->weight_kg, WeightRecord::sole()->source, WeightRecord::sole()->measured_by]);
        $this->assertSame('active', $scale->fresh()->status);

        $this->send($key, [$this->reading('weight_kg', 35, ['rfid' => 'E2801170000002AA'])])->assertOk()->assertJson(['results' => ['weighing + weight loss alert']]);
        $alert = SensorAlert::sole();
        $this->assertSame(['weight_loss', 'warning', $this->goat->id], [$alert->kind, $alert->severity, $alert->animal_id]);
        $task = Task::findOrFail($alert->task_id);
        $this->assertSame(['health', 'high', 'Veterinary'], [$task->type, $task->priority, Department::find($task->department_id)->name]);
        $this->assertSame(['G-1 lost 12.5% of its weight'], array_column(collect(ActionCenter::items($this->user('ceo')))->firstWhere('key', 'sensor_alerts')['items'], 'text')); // D-241: the vet gets the task, not the device feed

        $this->send($key, [$this->reading('weight_kg', 50, ['rfid' => 'FFFF'])])->assertJson(['results' => ['unknown tag']]);
        $this->assertSame(1, SensorAlert::where('kind', 'unknown_tag')->count());
    }

    public function test_only_a_valid_key_is_accepted_and_a_new_key_revokes_the_old_one(): void
    {
        [$scale, $key] = $this->device('weighing_scale');
        $this->send('kkd_wrong', [$this->reading('weight_kg', 40)])->assertStatus(401);
        $this->withHeader('Authorization', "Bearer $key")->getJson('/api/v1/ping')->assertOk()->assertJson(['device' => 'SCL-01']);

        $this->actingAs($this->user('manager'))->post("/sensors/devices/{$scale->id}/key")->assertForbidden(); // D-241: devices and keys are the administrator's
        $this->actingAs($this->user('ceo'))->post("/sensors/devices/{$scale->id}/key")->assertSessionHasNoErrors();
        $newKey = session('device_key')['key'];
        $this->send($key, [$this->reading('weight_kg', 40)])->assertStatus(401);
        $this->send($newKey, [$this->reading('weight_kg', 40)])->assertOk();

        $this->actingAs($this->user('manager'))->post('/sensors/devices', ['name' => 'X', 'type' => 'camera'])->assertForbidden();
        $this->actingAs($this->user('manager'))->get('/sensors')->assertOk(); // D-241: the manager views device status; the vet no longer opens Sensors
        $this->actingAs($this->user('vet'))->get('/sensors')->assertForbidden();
        $this->actingAs($this->user('caretaker'))->get('/sensors')->assertForbidden();
    }

    public function test_an_rfid_gate_records_goats_going_out_and_coming_in_without_changing_their_pen(): void
    {
        [, $key] = $this->device('rfid_gate', $this->area);
        $this->send($key, [$this->reading('passage', 1, ['rfid' => 'E2801170000002AA'])])->assertJson(['results' => ['went out']]);
        $this->assertSame($this->area->id, Sensors::outNow()[$this->goat->id]->area_id);
        $this->assertSame($this->pen->id, $this->goat->fresh()->pen_id, 'home pen unchanged');


        $this->travel(9)->hours();
        $this->assertSame(['G-1'], array_column(collect(ActionCenter::items($this->user('manager')))->firstWhere('key', 'out_too_long')['items'], 'text'));
        $this->send($key, [$this->reading('passage', 0, ['rfid' => 'E2801170000002AA'])])->assertJson(['results' => ['came in']]);
        $this->assertFalse(Sensors::outNow()->has($this->goat->id));

        // By hand, and an open area is never a home pen.
        $this->actingAs($this->user('caretaker'))->post('/presence', ['animal_ids' => [$this->goat->id], 'direction' => 'out', 'area_id' => $this->area->id])->assertSessionHasNoErrors();
        $this->assertTrue(Sensors::outNow()->has($this->goat->id));
        $this->actingAs($this->user('manager'))->post('/movements', ['animal_ids' => [$this->goat->id], 'type' => 'transfer', 'to_pen_id' => $this->area->id, 'moved_on' => now()->toDateString()])->assertSessionHasErrors('to_pen_id');
    }

    public function test_a_camera_count_and_air_readings_are_checked_against_what_is_expected(): void
    {
        [, $cam] = $this->device('camera', $this->pen);
        $this->send($cam, [$this->reading('headcount', 1)])->assertJson(['results' => ['headcount ok']]);
        $this->send($cam, [$this->reading('headcount', 0)])->assertJson(['results' => ['headcount mismatch alert']]);
        $this->assertSame('KKF-BDU-01-P01: camera counts 0, system expects 1', SensorAlert::where('kind', 'headcount')->sole()->title);

        [, $air] = $this->device('environment', $this->pen, ['ammonia_ppm_max' => 20]);
        $this->send($air, [$this->reading('ammonia_ppm', 12), $this->reading('ammonia_ppm', 26), $this->reading('ammonia_ppm', 30), $this->reading('temperature_c', 2)])
            ->assertJson(['results' => ['stored', 'environment alert', 'environment alert already open', 'environment alert']]);
        $this->assertSame(['Ammonia high at KKF-BDU-01-P01: 26 ppm', 'Temperature low at KKF-BDU-01-P01: 2 °C'], SensorAlert::where('kind', 'environment')->orderBy('id')->pluck('title')->all());
    }

    public function test_readings_are_append_only_and_another_farm_sees_none_of_it(): void
    {
        [, $key] = $this->device('environment', $this->pen);
        $this->send($key, [$this->reading('humidity_pct', 50)])->assertOk();
        try {
            DB::transaction(fn () => DB::table('sensor_readings')->update(['value' => 99]));
            $this->fail('an edited reading must be refused');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        Tenant::enter(Organization::create(['name' => 'Other', 'slug' => 'other'])->id);
        try {
            $this->assertSame([0, 0, 0], [Device::count(), SensorReading::count(), SensorAlert::count()]);
        } finally {
            Tenant::leave();
        }
    }
}
