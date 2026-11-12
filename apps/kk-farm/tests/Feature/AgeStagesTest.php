<?php

namespace Tests\Feature;

use App\Models\AgeStage;
use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\User;
use App\Support\AgeStages;
use App\Support\Classification;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-221: age stages (ERP code, category, age, teeth) follow the goat's age and drive Kid / Grower / Adult. */
class AgeStagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_stages_follow_age_and_settings_keep_them_consistent(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Stage Farm', 'slug' => 'stage-farm'])->id);
        $owner = User::create(['email' => 'o@stage.test', 'name' => 'Owner', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current(), 'active' => true]);
        $breed = Breed::create(['name' => 'Teddy']);
        $goat = fn (string $tag, $born) => Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => $born, 'origin' => 'purchased', 'status' => 'active']);

        $this->assertSame('YKD', $goat('A', now()->subDays(10))->card()['ageCode']);
        $this->assertSame('KID', $goat('B', now()->subDays(30))->card()['ageCode']);
        $this->assertSame('GRW', $goat('C', now()->subMonths(6))->card()['ageCode']);
        $this->assertSame('ADT-T04', $goat('D', now()->subMonths(20))->card()['ageCode']);
        $this->assertSame('ADT-WBT', $goat('E', now()->subMonths(60))->card()['ageCode']);

        $this->actingAs($owner)->get('/settings/ages')->assertOk()->assertInertia(fn ($p) => $p->has('stages', 8));
        $this->actingAs($owner)->get('/goats/register')->assertOk()->assertInertia(fn ($p) => $p->has('ageStages', 8));

        // Overlapping months are refused; moving Grower's start moves the Kid / Grower boundary.
        $this->actingAs($owner)->post('/settings/stages', ['code' => 'X1', 'label' => 'Adult', 'age_group' => 'adult', 'from_months' => 20, 'to_months' => 22])->assertSessionHasErrors('from_months');
        $kid = AgeStage::where('code', 'KID')->firstOrFail();
        $grw = AgeStage::where('code', 'GRW')->firstOrFail();
        $this->actingAs($owner)->post("/settings/stages/{$kid->id}", ['code' => 'KID', 'label' => 'Kid', 'age_group' => 'kid', 'from_months' => 0.5, 'to_months' => 3, 'teeth_key' => 'milk'])->assertSessionHasErrors('from_months');
        $this->actingAs($owner)->post("/settings/stages/{$grw->id}", ['code' => 'GRW', 'label' => 'Grower', 'age_group' => 'grower', 'from_months' => 3, 'to_months' => 14, 'teeth_key' => 'milk'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/settings/stages/{$kid->id}", ['code' => 'KID', 'label' => 'Kid', 'age_group' => 'kid', 'from_months' => 0.5, 'to_months' => 3, 'teeth_key' => 'milk'])->assertSessionHasNoErrors();
        // D-228: care protocols can target one age stage; the calendar picks only those goats.
        $this->assertArrayHasKey('stage:ADT-T04', \App\Models\CareProtocol::applies());
        \App\Models\CareProtocol::create(['name' => 'T04 booster', 'kind' => 'vaccination', 'applies_to' => 'stage:ADT-T04', 'active' => true, 'interval_days' => 365, 'created_by' => 'Owner']);
        Tenant::flush();
        $due = collect(\App\Support\CareCalendar::due())->firstWhere('name', 'T04 booster');
        $this->assertSame(['D'], collect($due['goats'])->pluck('number')->all());
        $this->assertStringStartsWith('ADT-T04 · Adult', $due['appliesTo']);
        Tenant::flush();
        Classification::forgetBoundaries();
        $this->assertSame([3, 14], Classification::boundaries());
        $this->assertSame('KID', AgeStages::forMonths(2.5)->code);
    }
}
