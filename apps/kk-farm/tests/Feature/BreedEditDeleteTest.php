<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-219: breeds can be renamed, given a new tag code, and deleted only when nothing uses them. */
class BreedEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_edits_and_deletes_unused_breeds_only(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Breed Farm', 'slug' => 'breed-farm'])->id);
        $owner = User::create(['email' => 'o@breed.test', 'name' => 'Owner', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current(), 'active' => true]);
        $used = Breed::create(['name' => 'Teddy']);
        $unused = Breed::create(['name' => 'Barbari']);
        Animal::create(['farm_number' => 'TD-1', 'sex' => 'female', 'breed_id' => $used->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'status' => 'sold']);

        $this->actingAs($owner)->get('/settings/breeds')->assertOk();
        $this->actingAs($owner)->post("/settings/breeds/{$unused->id}/edit", ['name' => 'Renamed', 'code' => 'rn'])->assertSessionHasNoErrors();
        $this->assertSame(['Renamed', 'RN'], [$unused->fresh()->name, $unused->fresh()->code]);
        $this->actingAs($owner)->post("/settings/breeds/{$unused->id}/edit", ['name' => 'Teddy', 'code' => 'ZZ'])->assertSessionHasErrors('name');

        $this->actingAs($owner)->delete("/settings/breeds/{$used->id}")->assertSessionHasErrors('breed'); // a sold goat still uses it
        $this->assertNotNull($used->fresh());
        $this->actingAs($owner)->delete("/settings/breeds/{$unused->id}")->assertSessionHasNoErrors();
        $this->assertNull($unused->fresh());
    }
}
