<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Disease;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Movements;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-174: disease master and the mortality register with postmortems. */
class MortalityTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Mort Farm', 'slug' => 'mort-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'DEAD-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Teddy'])->id, 'birth_date' => now()->subMonths(10), 'origin' => 'purchased', 'pen_id' => $pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@mort.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_the_vet_keeps_the_disease_master_and_forms_offer_it(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/health/diseases', ['name' => 'CCPP', 'category' => 'respiratory'])->assertForbidden();
        $this->actingAs($this->user('vet'))->post('/health/diseases', ['name' => 'CCPP', 'category' => 'respiratory', 'notifiable' => true, 'zoonotic' => false, 'active' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->post('/health/diseases', ['name' => 'CCPP', 'category' => 'respiratory'])->assertSessionHasErrors('name');
        $this->assertTrue(Disease::where('name', 'CCPP')->value('notifiable'));
        $this->actingAs($this->user('vet'))->get('/health')->assertInertia(fn (AssertableInertia $p) => $p->where('checkups.conditions', ['CCPP']));
    }

    public function test_a_death_gets_a_postmortem_and_counts_by_cause(): void
    {
        $this->actingAs($this->user('vet'))->post('/health/postmortem', ['animal_id' => $this->goat->id, 'cause' => 'Pneumonia', 'examined_on' => now()->toDateString()])->assertStatus(422);
        Movements::exit($this->goat->fresh(), 'death', $this->user('manager'), ['reason' => 'Found dead in the morning']);
        $this->actingAs($this->user('caretaker'))->post('/health/postmortem', ['animal_id' => $this->goat->id, 'cause' => 'Pneumonia', 'examined_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($this->user('vet'))->post('/health/postmortem', ['animal_id' => $this->goat->id, 'cause' => 'Pneumonia', 'findings' => 'Consolidated lungs', 'examined_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->get('/health?tab=mortality')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('mortality.rows.0.number', 'DEAD-1')->where('mortality.rows.0.postmortem.cause', 'Pneumonia')->where('mortality.rows.0.reason', 'Found dead in the morning')
            ->where('mortality.byCause', ['Pneumonia' => 1]));
    }
}
