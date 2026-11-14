<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-262: height with its date (vet and administrator), the vet's Teeth and Height jobs, and what was done in the goat's table. */
class HeightTeethTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Height Farm', 'slug' => 'height-farm'])->id);
        \App\Models\Department::seedDefaults((string) Tenant::current());
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'H-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'origin' => 'purchased', 'pen_id' => $pen->id, 'birth_date' => now()->subYears(2)])->refresh();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@height.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_the_vet_and_the_administrator_add_a_height_with_its_date(): void
    {
        $this->actingAs($this->user('caretaker'))->post("/goats/{$this->goat->id}/height", ['height_in' => 28, 'measured_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($this->user('vet'))->post("/goats/{$this->goat->id}/height", ['height_in' => 27, 'measured_on' => now()->subDays(20)->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->user('ceo'))->post("/goats/{$this->goat->id}/height", ['height_in' => 28.5, 'measured_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(72.4, (float) $this->goat->fresh()->height_cm, 0.1, 'the goat follows the latest height');
        $rows = $this->actingAs($this->user('vet'))->get("/goats/{$this->goat->id}")->viewData('page')['props']['records'];
        $this->assertSame([28.5, 27.0], array_map(fn ($r) => $r['height'], $rows));
    }

    public function test_teeth_and_height_are_tasks_jobs_for_the_vet(): void
    {
        foreach (['teeth', 'height'] as $job) {
            $this->assertTrue(\App\Http\Controllers\WorkerController::allowed($this->user('vet'), $job));
            $this->assertTrue(\App\Http\Controllers\WorkerController::allowed($this->user('ceo'), $job));
            $this->assertFalse(\App\Http\Controllers\WorkerController::allowed($this->user('caretaker'), $job));
        }
        $code = array_key_first(\App\Support\Teeth::options());
        $this->actingAs($this->user('vet'))->post("/goats/{$this->goat->id}/teeth", ['teeth' => $code, 'dental_condition' => 'worn', 'examined_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('worn', $this->goat->fresh()->dental_condition);
    }

    public function test_the_table_shows_what_was_found_and_what_was_done(): void
    {
        \App\Models\HealthRecord::create(['animal_id' => $this->goat->id, 'kind' => 'observation', 'occurred_on' => now()->subDays(3), 'title' => 'Checkup: Temperature 104 °F (high)', 'recorded_by' => 'vet', 'recorded_role' => 'Veterinarian', 'status' => 'open']);
        \App\Models\Treatment::create(['animal_id' => $this->goat->id, 'given_on' => now()->subDays(3), 'product' => 'Oxytetracycline', 'dose' => '5 ml', 'route' => 'IM', 'recorded_by' => 'vet']);
        $row = $this->actingAs($this->user('vet'))->get("/goats/{$this->goat->id}")->viewData('page')['props']['records'][0];
        $this->assertSame(['Temperature 104 °F (high)'], $row['care']);
        $this->assertSame(['Treated: Oxytetracycline 5 ml (IM)'], $row['actions']);
    }
}
