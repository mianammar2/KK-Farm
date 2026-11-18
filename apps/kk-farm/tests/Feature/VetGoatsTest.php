<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\HealthCheckup;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-348: the Veterinary app opens on the goats that need the vet, with the reasons, and the goat page opens from it. */
class VetGoatsTest extends TestCase
{
    use RefreshDatabase;

    public function test_goats_to_check_lists_reasons_and_the_goat_page_opens_for_the_vet(): void
    {
        $org = Organization::create(['name' => 'Vet Farm', 'slug' => 'vet-farm']);
        Tenant::set($org->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Makhi Cheeni']);
        $mk = fn ($n) => Animal::create(['farm_number' => $n, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        [$hot, $fine] = [$mk('MC-1'), $mk('MC-2')];
        HealthCheckup::create(['animal_id' => $hot->id, 'checked_on' => now()->subDay(), 'temperature_f' => 105.1, 'outcome' => 'monitor', 'examiner' => 'Vet']);
        HealthCase::create(['case_no' => 1, 'animal_id' => $hot->id, 'onset_on' => now()->subDays(2)->toDateString(), 'condition' => 'Pneumonia', 'severity' => 'high', 'status' => 'open', 'vet' => 'Vet', 'recorded_by' => 'Vet']);
        $vet = User::create(['name' => 'Vet', 'email' => 'v@vet.test', 'password' => 'x', 'role' => 'vet', 'org_id' => $org->id, 'active' => true]);

        $this->actingAs($vet)->get('/health/goats')->assertInertia(fn ($p) => $p->component('Health/VetGoats')->has('goats', 2)
            ->where('goats.0.number', 'MC-1')->where('goats.0.issues.0', ['crit', '105.1 °F high', 'temp_high'])
            ->where('goats.0.issues.1', ['crit', 'HC-0001 Pneumonia', 'case'])
            ->where('goats.1.issues', []));
        $this->actingAs($vet)->get("/goats/{$hot->id}")->assertOk()->assertInertia(fn ($p) => $p->component('Goats/Show')->has('issues', 2));
    }
}
