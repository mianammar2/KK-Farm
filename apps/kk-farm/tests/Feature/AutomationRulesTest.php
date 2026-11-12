<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\ClassificationRule;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-236: Settings → Automation Rules: the category and starting female status follow the farm's table. */
class AutomationRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_owner_changes_a_rule_and_goats_follow_it(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Auto Farm', 'slug' => 'auto-farm'])->id);
        $owner = User::create(['email' => 'o@auto.test', 'name' => 'Owner', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current(), 'active' => true]);
        $breed = Breed::create(['name' => 'Teddy']);
        $doe = Animal::create(['farm_number' => 'A1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subMonths(20), 'origin' => 'purchased', 'status' => 'active', 'animal_purpose' => 'sale']);
        $this->assertSame('growing_female', $doe->effectiveCategory(), 'default: same as the earlier rules');

        $this->actingAs($owner)->get('/settings/automation')->assertOk()->assertInertia(fn ($p) => $p->component('Settings/Automation')->has('picks', 16)->where('rules', fn ($r) => count($r) > 10));
        $this->actingAs($owner)->post('/settings/automation/rules', ['sex' => 'female', 'stage' => 'ADT', 'purpose' => 'sale', 'class' => 'breeding_male'])->assertSessionHasErrors('class');
        $this->actingAs($owner)->post('/settings/automation/rules', ['sex' => 'female', 'stage' => 'ADT', 'purpose' => 'sale', 'class' => 'cull', 'status' => 'retired'])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame(['cull', ['CF']], [$doe->fresh()->effectiveCategory(), \App\Support\Designations::codes($doe->fresh())]);

        // The starting female status comes from the rule when the goat is registered.
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P1', 'purpose' => 'breeding', 'kind' => 'pen']);
        $this->actingAs($owner)->post('/goats', ['origin' => 'purchased', 'breed_id' => $breed->id, 'pen_id' => $pen->id, 'sex' => 'female', 'animal_purpose' => 'sale', 'tag_id' => 'A2', 'birth_date' => now()->subMonths(20)->toDateString()])->assertSessionHasNoErrors();
        $this->assertSame('retired', Animal::where('farm_number', 'A2')->value('repro_status'));

        // D-239: a gender code's settings can be changed; new registrations use them.
        $this->actingAs($owner)->post('/settings/automation/codes/FF', ['label' => 'Qurbani Doe', 'purpose' => 'fattening', 'class' => 'breeding_male', 'stage' => 'ADT'])->assertSessionHasErrors('class');
        $this->actingAs($owner)->post('/settings/automation/codes/FF', ['label' => 'Qurbani Doe', 'purpose' => 'meat', 'class' => 'growing_female', 'stage' => 'ADT'])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->actingAs($owner)->get('/goats/register')->assertInertia(fn ($p) => $p->where('roleOptions', fn ($o) => ($ff = collect($o)->firstWhere('value', 'FF')) && $ff['label'] === 'FF · Qurbani Doe' && $ff['purpose'] === 'meat' && $ff['stage'] === 'ADT'));
        $this->actingAs($owner)->post('/goats', ['origin' => 'purchased', 'breed_id' => $breed->id, 'pen_id' => $pen->id, 'sex' => 'female', 'role_code' => 'FF', 'tag_id' => 'A3'])->assertSessionHasNoErrors();
        $ff = Animal::where('farm_number', 'A3')->sole();
        $this->assertSame(['meat', 'ADT'], [$ff->animal_purpose, $ff->ageStageCode()]);
        // D-240: the farm adds its own code, uses it, and deletes codes (used ones leave the Gender field, goats keep them).
        $this->actingAs($owner)->post('/settings/automation/codes', ['code' => 'qb', 'sex' => 'male', 'label' => 'Qurbani Buck', 'purpose' => 'fattening', 'class' => 'fattening_male', 'stage' => 'GRW'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/settings/automation/codes', ['code' => 'BK', 'sex' => 'male', 'label' => 'Dup', 'class' => 'male_kid'])->assertSessionHasErrors('code');
        Tenant::flush();
        $this->actingAs($owner)->post('/goats', ['origin' => 'purchased', 'breed_id' => $breed->id, 'pen_id' => $pen->id, 'sex' => 'male', 'role_code' => 'QB', 'tag_id' => 'Q1'])->assertSessionHasNoErrors();
        $q1 = Animal::where('farm_number', 'Q1')->sole();
        $this->assertSame(['QB', 'fattening', 'GRW'], [\App\Support\Designations::main($q1), $q1->animal_purpose, $q1->ageStageCode()]);
        $this->actingAs($owner)->delete('/settings/automation/codes/QB')->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertArrayNotHasKey('QB', \App\Support\Designations::picks());
        $this->assertSame('QB', \App\Support\Designations::main($q1->fresh()), 'the goat keeps its code');
        $this->actingAs($owner)->delete('/settings/automation/codes/CM')->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertArrayNotHasKey('CM', \App\Support\Designations::picks());
        $this->actingAs($owner)->post('/settings/automation/codes/CM/restore')->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertArrayHasKey('CM', \App\Support\Designations::picks());
        $rule = ClassificationRule::where('purpose', 'sale')->firstOrFail();
        $this->actingAs($owner)->delete("/settings/automation/rules/{$rule->id}")->assertSessionHasNoErrors();
        $base = ClassificationRule::where('purpose', '*')->firstOrFail();
        $this->actingAs($owner)->delete("/settings/automation/rules/{$base->id}")->assertStatus(422);
        $this->actingAs(User::create(['email' => 'c@auto.test', 'name' => 'Care', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current(), 'active' => true]))->get('/settings/automation')->assertForbidden();
    }
}
