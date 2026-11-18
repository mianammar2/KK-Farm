<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Movements;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-347: a goat that leaves the farm has its open health cases closed, and no new case can be opened for it. */
class ExitClosesCasesTest extends TestCase
{
    use RefreshDatabase;

    public function test_selling_a_goat_closes_its_open_cases_and_blocks_new_ones(): void
    {
        $org = Organization::create(['name' => 'Exit Farm', 'slug' => 'exit-farm']);
        Tenant::set($org->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $goat = Animal::create(['farm_number' => 'MC-9', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Makhi Cheeni'])->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $ceo = User::create(['name' => 'Boss', 'email' => 'b@exit.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => $org->id, 'active' => true]);
        $case = HealthCase::create(['case_no' => 1, 'animal_id' => $goat->id, 'onset_on' => now()->subDays(5)->toDateString(), 'condition' => 'Wound / injury', 'severity' => 'high', 'status' => 'open', 'vet' => 'Vet', 'recorded_by' => 'Vet']);

        $this->actingAs($ceo);
        Movements::exit($goat->fresh(), 'sale', $ceo, ['moved_on' => now()->subDay()->toDateString(), 'destination' => 'Buyer']);

        $case->refresh();
        $this->assertSame('closed', $case->status);
        $this->assertSame(now()->subDay()->toDateString(), $case->resolved_on->toDateString());
        $this->assertStringContainsString('left the farm (sold)', $case->notes);

        $vet = User::create(['name' => 'Vet', 'email' => 'v@exit.test', 'password' => 'x', 'role' => 'vet', 'org_id' => $org->id, 'active' => true]);
        $r = $this->actingAs($vet)->post('/health/cases', ['animal_id' => $goat->id, 'onset_on' => now()->toDateString(), 'condition' => 'Fever', 'severity' => 'low']);
        $r->assertSessionHasErrors('animal_id'); // D-347
    }
}
