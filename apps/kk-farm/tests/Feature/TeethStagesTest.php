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

/** D-220 / D-229: Settings → Age Groups keeps the farm's dental codes; the goat screens use them. */
class TeethStagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dental_codes_are_added_edited_used_and_kept_while_in_use(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Teeth Farm', 'slug' => 'teeth-farm'])->id);
        $owner = User::create(['email' => 'o@teeth.test', 'name' => 'Owner', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current(), 'active' => true]);
        $goat = Animal::create(['farm_number' => 'T-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Teddy'])->id, 'origin' => 'purchased', 'status' => 'active']);

        $this->actingAs($owner)->get('/settings/ages')->assertOk()->assertInertia(fn ($p) => $p->has('teeth', 5)->where('teeth.1.key', 'T02')->where('teeth.1.local', 'Donda'));

        // Teeth follow age, so a new code needs months of its own: overlapping is refused.
        $this->actingAs($owner)->post('/settings/teeth', ['key' => 'X9', 'label' => 'Overlap', 'from_months' => 10, 'to_months' => 13, 'estimate_months' => 11])->assertSessionHasErrors('from_months');
        $this->actingAs($owner)->post('/settings/teeth', ['key' => 'UNK', 'label' => 'Bad code', 'from_months' => 60, 'estimate_months' => 62])->assertSessionHasErrors('key');
        $t08 = TeethStage::where('key', 'T08')->firstOrFail();
        $this->actingAs($owner)->post("/settings/teeth/{$t08->id}", ['label' => '8 Permanent Incisors', 'local_term' => 'Aath Dant', 'from_months' => 30, 'to_months' => 48, 'estimate_months' => 39])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/settings/teeth', ['key' => 'wbt', 'label' => 'Worn / Broken Mouth', 'from_months' => 48, 'estimate_months' => 54])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame('WBT', Teeth::forMonths(60));

        // A code is offered on the goat and sets the estimated age from its months.
        $this->actingAs($owner)->post("/goats/{$goat->id}/teeth", ['teeth' => 'WBT'])->assertSessionHasNoErrors();
        $this->assertSame(54, (int) round($goat->fresh()->birth_date->diffInMonths(now())));
        $new = TeethStage::where('key', 'WBT')->firstOrFail();
        $this->actingAs($owner)->delete("/settings/teeth/{$new->id}")->assertSessionHasErrors('teeth'); // a goat has it
        $two = TeethStage::where('key', 'T02')->firstOrFail();
        $this->actingAs($owner)->delete("/settings/teeth/{$two->id}")->assertSessionHasNoErrors();
        $this->assertNull($two->fresh());
    }
}
