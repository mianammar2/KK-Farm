<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\TeethStage;
use App\Models\User;
use App\Support\Teeth;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-220: Settings → Age Groups keeps the farm's teeth stages; the goat screens use them. */
class TeethStagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_teeth_stages_are_added_edited_used_and_kept_while_in_use(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Teeth Farm', 'slug' => 'teeth-farm'])->id);
        $owner = User::create(['email' => 'o@teeth.test', 'name' => 'Owner', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current(), 'active' => true]);
        $goat = Animal::create(['farm_number' => 'T-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Teddy'])->id, 'origin' => 'purchased', 'status' => 'active']);

        $this->actingAs($owner)->get('/settings/ages')->assertOk()->assertInertia(fn ($p) => $p->has('teeth', 6)->has('ages.groups', 3));

        $this->actingAs($owner)->post('/settings/teeth', ['label' => 'Milk, first pair loose', 'from_months' => 10, 'to_months' => 13, 'estimate_months' => 11])->assertSessionHasNoErrors();
        $new = TeethStage::where('label', 'Milk, first pair loose')->firstOrFail();
        $this->actingAs($owner)->post('/settings/teeth', ['label' => 'Bad', 'from_months' => 10, 'to_months' => 12, 'estimate_months' => 20])->assertSessionHasErrors('estimate_months');

        // The new stage is offered on the goat and sets the estimated age from its months.
        $this->actingAs($owner)->post("/goats/{$goat->id}/teeth", ['teeth' => $new->key])->assertSessionHasNoErrors();
        $this->assertSame(11, (int) round($goat->fresh()->birth_date->diffInMonths(now())));

        $two = TeethStage::where('key', '2')->firstOrFail();
        $this->actingAs($owner)->post("/settings/teeth/{$two->id}", ['label' => '2 permanent teeth', 'from_months' => 12, 'to_months' => 18, 'estimate_months' => 14])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame(14, Teeth::months()['2']);

        $this->actingAs($owner)->delete("/settings/teeth/{$new->id}")->assertSessionHasErrors('teeth'); // a goat has it
        $this->actingAs($owner)->delete("/settings/teeth/{$two->id}")->assertSessionHasNoErrors();
        $this->assertNull($two->fresh());
    }
}
