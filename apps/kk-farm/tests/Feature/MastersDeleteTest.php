<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Customer;
use App\Models\Disease;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-240: Delete on every master list: unused entries go, used ones are switched off or explained, never lost. */
class MastersDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_delete_removes_unused_entries_and_keeps_used_ones(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Master Farm', 'slug' => 'master-farm'])->id);
        $owner = User::create(['email' => 'o@m.test', 'name' => 'Owner', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current(), 'active' => true]);
        $care = User::create(['email' => 'c@m.test', 'name' => 'Care', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current(), 'active' => true]);

        $unused = Customer::create(['name' => 'Walk-in Ali', 'active' => true]);
        $this->actingAs($owner)->delete("/masters/customer/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertNull(Customer::find($unused->id));

        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P1', 'purpose' => 'breeding', 'kind' => 'pen']);
        Animal::create(['farm_number' => 'G1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Teddy'])->id, 'origin' => 'purchased', 'status' => 'active', 'pen_id' => $pen->id]);
        $this->actingAs($owner)->delete("/masters/pen/{$pen->id}")->assertSessionHasErrors('delete'); // goats live there
        $this->actingAs($owner)->delete("/masters/shed/{$shed->id}")->assertSessionHasErrors('delete');
        $this->assertNotNull(Pen::find($pen->id));

        $this->actingAs($care)->delete("/masters/shed/{$shed->id}")->assertForbidden();
        $this->actingAs($owner)->delete('/masters/nothing/1')->assertNotFound();
    }
}
