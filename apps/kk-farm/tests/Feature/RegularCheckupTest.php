<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCheckup;
use App\Models\HeightRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-344: one Regular checkup saves temperature, weight and height (each optional) with the time it was taken. */
class RegularCheckupTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_regular_checkup_saves_what_was_taken_with_its_time_and_logs_it_all(): void
    {
        $org = Organization::create(['name' => 'Check Farm', 'slug' => 'check-farm']);
        Tenant::set($org->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $goat = Animal::create(['farm_number' => 'MC-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Makhi Cheeni'])->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $ceo = User::create(['name' => 'Boss', 'email' => 'b@check.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => $org->id, 'active' => true]);
        $on = now()->subDay()->toDateString();

        $this->actingAs($ceo)->post("/goats/{$goat->id}/regular-checkup", ['checked_on' => $on, 'checked_time' => '07:30', 'outcome' => 'healthy'])->assertSessionHasErrors('temperature_f');
        $this->actingAs($ceo)->post("/goats/{$goat->id}/regular-checkup", ['checked_on' => $on, 'checked_time' => '07:30', 'temperature_f' => '104.2', 'weight_kg' => '31.5', 'height_in' => '24', 'outcome' => 'healthy'])->assertSessionHasNoErrors();

        $c = HealthCheckup::sole();
        $this->assertSame(['07:30:00', 'monitor'], [substr((string) $c->checked_time, 0, 8), $c->outcome]); // a high temperature cannot stay Healthy
        $this->assertSame('07:30:00', substr((string) WeightRecord::sole()->measured_time, 0, 8));
        $this->assertSame('07:30:00', substr((string) HeightRecord::sole()->measured_time, 0, 8));
        $this->assertEqualsWithDelta(61.0, (float) $goat->fresh()->height_cm, 0.1);
        $this->assertDatabaseHas('activities', ['action' => 'Regular checkup of MC-1 on '.now()->subDay()->format('d/m/Y').' at 7:30 AM: 104.2 °F (high), 31.5 KG, result watch, 24 in']);

        // only a weight, no time
        $this->actingAs($ceo)->post("/goats/{$goat->id}/regular-checkup", ['checked_on' => $on, 'weight_kg' => '32', 'outcome' => 'healthy'])->assertSessionHasNoErrors();
        $this->assertSame(2, WeightRecord::count());
        $this->assertNull(HealthCheckup::orderByDesc('id')->first()->checked_time);
    }
}
