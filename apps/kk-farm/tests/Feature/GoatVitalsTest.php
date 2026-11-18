<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCheckup;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-340: the registry shows weight gain or loss, low or high weight against similar goats, and the last temperature in °F. */
class GoatVitalsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_registry_shows_weight_change_weight_band_and_temperature(): void
    {
        $org = Organization::create(['name' => 'Vitals Farm', 'slug' => 'vitals-farm']);
        Tenant::set($org->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $mc = Breed::create(['name' => 'Makhi Cheeni']);
        $mk = fn ($n) => Animal::create(['farm_number' => $n, 'sex' => 'female', 'breed_id' => $mc->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $w = fn ($a, $kg, $days) => WeightRecord::create(['animal_id' => $a->id, 'measured_on' => now()->subDays($days), 'weight_kg' => $kg, 'measured_by' => 'Vet', 'review_status' => 'accepted']);
        $t = fn ($a, $f) => HealthCheckup::create(['animal_id' => $a->id, 'checked_on' => now()->subDay(), 'temperature_f' => $f, 'outcome' => 'healthy', 'examiner' => 'Vet']);
        [$a, $b, $c, $d] = [$mk('MC-1'), $mk('MC-2'), $mk('MC-3'), $mk('MC-4')];
        $w($a, 30, 20); $w($a, 32.5, 5); // gaining, average-ish
        $w($b, 36, 20); $w($b, 34.8, 5); // losing
        $w($c, 34, 5);
        $w($d, 22, 5); // far under the group average: Low
        $t($a, 102.2); $t($b, 105.0); $t($d, 100.0);
        HealthCheckup::create(['animal_id' => $b->id, 'checked_on' => now()->subDays(6), 'temperature_f' => 103.4, 'outcome' => 'healthy', 'examiner' => 'Vet']); // the reading before
        $ceo = User::create(['name' => 'Boss', 'email' => 'b@vitals.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => $org->id, 'active' => true]);

        $this->actingAs($ceo)->get('/goats')->assertInertia(fn ($p) => $p->component('Goats/Index')
            ->where('goats.0.number', 'MC-1')->where('goats.0.weightChange', 2.5)->where('goats.0.tempF', 102.2)->where('goats.0.tempState', 'normal')
            ->where('goats.1.weightChange', -1.2)->where('goats.1.tempF', 105)->where('goats.1.tempChange', 1.6)->where('goats.0.tempChange', null)->where('goats.1.tempState', 'high')
            ->where('goats.2.weightChange', null)->where('goats.2.tempF', null)
            ->where('goats.3.weightBand', 'low')->where('goats.3.tempState', 'low')->where('goats.3.tempF', 100)
            ->where('goats.0.tempRange', '101.3–103.5 °F'));
    }
}
