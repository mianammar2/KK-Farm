<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalMovement;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\PenCleaning;
use App\Models\Shed;
use App\Models\User;
use App\Support\Movements;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** D-207: App 07 Housing: pen status, BR-004 overcapacity block with authorised override, care moves exempt, cleaning verified by someone else. */
class HousingTest extends TestCase
{
    use RefreshDatabase;

    private Pen $home;

    private Pen $small;

    private array $g = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Pen Farm', 'slug' => 'pen-farm'])->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $this->home = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Herd']);
        $this->small = Pen::create(['shed_id' => $shed->id, 'name' => 'P02', 'purpose' => 'Herd', 'capacity' => 1]);
        $breed = Breed::create(['name' => 'Beetal']);
        foreach (['H-1', 'H-2', 'H-3'] as $tag) {
            $this->g[$tag] = Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $this->home->id, 'status' => 'active']);
        }
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@pen.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function move(string $role, string $tag, array $extra = [])
    {
        return $this->actingAs($this->user($role))->post('/movements', $extra + ['animal_ids' => [$this->g[$tag]->id], 'type' => 'transfer', 'moved_on' => now()->toDateString(), 'to_pen_id' => $this->small->id]);
    }

    public function test_a_full_pen_needs_an_owner_or_manager_reason_and_care_moves_pass(): void
    {
        $this->move('manager', 'H-1')->assertSessionHasNoErrors();
        $this->move('manager', 'H-2')->assertSessionHasErrors('to_pen_id'); // full
        $this->move('caretaker', 'H-2', ['capacity_override' => 'Short stay'])->assertSessionHasErrors('to_pen_id');
        $this->move('manager', 'H-2', ['capacity_override' => 'Two days while P03 is repaired'])->assertSessionHasNoErrors();
        $this->assertStringContainsString('authorised by Test manager: Two days', AnimalMovement::where('animal_id', $this->g['H-2']->id)->sole()->capacity_override);

        Movements::transfer($this->g['H-3']->fresh(), $this->small, $this->user('vet'), ['care' => true, 'reason' => 'Isolation']); // never stopped by capacity
        $this->assertSame($this->small->id, $this->g['H-3']->fresh()->pen_id);
        $this->actingAs($this->user('manager'))->get('/housing?tab=overrides')->assertOk();
    }

    public function test_a_closed_pen_takes_no_goats_and_cannot_close_with_goats_in_it(): void
    {
        $this->actingAs($this->user('manager'))->post("/housing/pens/{$this->home->id}/status", ['status' => 'closed', 'reason' => 'Roof'])->assertSessionHasErrors('status');
        $this->actingAs($this->user('caretaker'))->post("/housing/pens/{$this->small->id}/status", ['status' => 'maintenance', 'reason' => 'Feeder'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/housing/pens/{$this->small->id}/status", ['status' => 'maintenance'])->assertSessionHasErrors('reason');
        $this->actingAs($this->user('manager'))->post("/housing/pens/{$this->small->id}/status", ['status' => 'maintenance', 'reason' => 'Broken feeder'])->assertSessionHasNoErrors();
        $this->expectException(ValidationException::class);
        Movements::transfer($this->g['H-1']->fresh(), $this->small->fresh(), $this->user('vet'), ['care' => true]);
    }

    public function test_disinfection_needs_an_empty_pen_closes_it_and_is_verified_by_someone_else(): void
    {
        $this->actingAs($this->user('cleaning'))->post("/housing/pens/{$this->home->id}/clean", ['kind' => 'disinfection'])->assertSessionHasErrors('kind'); // goats inside
        $this->actingAs($this->user('cleaning'))->post("/housing/pens/{$this->small->id}/clean", ['kind' => 'routine'])->assertSessionHasNoErrors();
        $this->assertSame('active', $this->small->fresh()->status);

        $this->actingAs($this->user('cleaning'))->post("/housing/pens/{$this->small->id}/clean", ['kind' => 'disinfection'])->assertSessionHasNoErrors();
        $this->assertSame('cleaning', $this->small->fresh()->status);
        $this->move('manager', 'H-1')->assertSessionHasErrors('to_pen_id'); // being cleaned

        $c = PenCleaning::where('kind', 'disinfection')->sole();
        $this->actingAs($this->user('cleaning'))->post("/housing/cleanings/{$c->id}/finish", ['disinfectant' => 'Virkon S'])->assertSessionHasErrors('disinfectant'); // contact time
        $this->actingAs($this->user('cleaning'))->post("/housing/cleanings/{$c->id}/finish", ['disinfectant' => 'Virkon S', 'contact_minutes' => 10])->assertSessionHasNoErrors();
        $this->actingAs($this->user('cleaning'))->post("/housing/cleanings/{$c->id}/verify")->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/housing/cleanings/{$c->id}/verify")->assertSessionHasNoErrors();
        $this->assertSame(['active', 'Test manager'], [$this->small->fresh()->status, $c->fresh()->verified_by]);
        $this->move('manager', 'H-1')->assertSessionHasNoErrors();
    }
}
