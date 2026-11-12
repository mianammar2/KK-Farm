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

/**
 * D-229: the five age stages start on exact days (YKD day 0, KID day 15, GRW day 60) and calendar months (ADT 14,
 * MAT 48); teeth are separate and give the combined code (GRW-T02). Includes the framework's UAT cases UC-01, UC-02,
 * UC-12 and UC-13 for age and teeth.
 */
class AgeStagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_stages_switch_on_the_exact_day_and_combine_with_teeth(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Stage Farm', 'slug' => 'stage-farm'])->id);
        $owner = User::create(['email' => 'o@stage.test', 'name' => 'Owner', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current(), 'active' => true]);
        $breed = Breed::create(['name' => 'Makhi Cheeni']);
        $goat = fn (string $tag, $born, array $more = []) => Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => $born, 'origin' => 'purchased', 'status' => 'active'] + $more);

        $this->assertSame('YKD', AgeStages::codeFor(now()->subDays(14)));
        $this->assertSame('KID', AgeStages::codeFor(now()->subDays(15)), 'switches on day 15');
        $this->assertSame('KID', AgeStages::codeFor(now()->subDays(59)));
        $this->assertSame('GRW', AgeStages::codeFor(now()->subDays(60)), 'switches on day 60');
        $this->assertSame('GRW', AgeStages::codeFor(now()->subMonths(14)->addDay()));
        $this->assertSame('ADT', AgeStages::codeFor(now()->subMonths(14)), 'switches at 14 calendar months');
        $this->assertSame('MAT', AgeStages::codeFor(now()->subMonths(48)));
        $this->assertSame('kid', Classification::ageCategory(now()->subDays(59)));
        $this->assertSame('grower', Classification::ageCategory(now()->subDays(60)));

        // UC-01: female, 8 months: Grower, whatever the weight. UC-02: 16 months with 2 teeth: ADT-T02.
        $this->assertSame('GRW-MLT', $goat('UC01', now()->subMonths(8))->card()['ageCode'], 'D-234: teeth follow age when not checked');
        $this->assertSame('ADT-T02', $goat('UC02', now()->subMonths(16), ['teeth' => 'T02'])->card()['ageCode']);
        // 13 months with 2 permanent teeth stays a Grower: GRW-T02, never forced to Adult.
        $grw = $goat('G13', now()->subMonths(13), ['teeth' => 'T02']);
        $this->assertSame(['GRW-T02', 'grower'], [$grw->card()['ageCode'], $grw->effectiveAge()]);
        // UC-12: 48 months with healthy teeth: Mature Adult, not old or cull.
        $mat = $goat('UC12', now()->subMonths(49), ['teeth' => 'T08']);
        $this->assertSame('MAT-T08', $mat->card()['ageCode']);
        $this->assertNotSame('cull', $mat->effectiveCategory());
        // No teeth check: the teeth for the age (D-234, owner's table).
        $this->assertSame('ADT-T04', $goat('NOEX', now()->subMonths(20))->card()['ageCode']);

        $this->actingAs($owner)->get('/settings/ages')->assertOk()->assertInertia(fn ($p) => $p->has('stages', 5)->where('stages.2.range', '60 days to under 14 months')->where('stages.4.range', '48 months and above'));
        $this->actingAs($owner)->get('/goats/register')->assertOk()->assertInertia(fn ($p) => $p->has('ageStages', 5));

        // Two stages cannot start at the same age; moving Grower's start moves the Kid / Grower boundary.
        $this->actingAs($owner)->post('/settings/stages', ['code' => 'X1', 'label' => 'Adult', 'age_group' => 'adult', 'starts_at' => 14, 'starts_unit' => 'months'])->assertSessionHasErrors('starts_at');
        $grwStage = AgeStage::where('code', 'GRW')->firstOrFail();
        $this->actingAs($owner)->post("/settings/stages/{$grwStage->id}", ['code' => 'GRW', 'label' => 'Grower', 'age_group' => 'grower', 'starts_at' => 3, 'starts_unit' => 'months'])->assertSessionHasNoErrors();
        Tenant::flush();
        Classification::forgetBoundaries();
        $this->assertSame([3, 14], Classification::boundaries());
        $this->assertSame('KID', AgeStages::codeFor(now()->subDays(70)));

        // Care protocols can target one stage; the calendar picks only those goats.
        $this->assertArrayHasKey('stage:MAT', \App\Models\CareProtocol::applies());
        \App\Models\CareProtocol::create(['name' => 'MAT booster', 'kind' => 'vaccination', 'applies_to' => 'stage:MAT', 'active' => true, 'interval_days' => 365, 'created_by' => 'Owner']);
        Tenant::flush();
        $due = collect(\App\Support\CareCalendar::due())->firstWhere('name', 'MAT booster');
        $this->assertSame(['UC12'], collect($due['goats'])->pluck('number')->all());
        $this->assertStringStartsWith('MAT · Mature Adult', $due['appliesTo']);
    }

    public function test_teeth_examinations_are_kept_as_history_and_estimate_a_bought_goats_age(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Exam Farm', 'slug' => 'exam-farm'])->id);
        $vet = User::create(['email' => 'v@exam.test', 'name' => 'Vet', 'password' => 'x', 'role' => 'vet', 'org_id' => Tenant::current(), 'active' => true]);
        // UC-13: bought, no documented birth date, 4 teeth: age estimated, teeth kept.
        $goat = Animal::create(['farm_number' => 'B-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Teddy'])->id, 'origin' => 'purchased', 'status' => 'active']);
        $this->actingAs($vet)->post("/goats/{$goat->id}/teeth", ['teeth' => 'T04'])->assertSessionHasNoErrors();
        $goat->refresh();
        $this->assertTrue($goat->birth_date_is_estimate);
        $this->assertSame(['ADT-T04', 'normal'], [$goat->classCode(), $goat->dental_condition]);

        // A later exam with a condition is a new row; the earlier one stays.
        $this->actingAs($vet)->post("/goats/{$goat->id}/teeth", ['teeth' => 'T08', 'dental_condition' => 'broken'])->assertSessionHasNoErrors();
        $this->actingAs($vet)->post("/goats/{$goat->id}/teeth", ['teeth' => 'T08', 'dental_condition' => 'worn'])->assertSessionHasNoErrors();
        $this->assertSame('worn', $goat->fresh()->dental_condition, 'same teeth, new condition is saved');
        $this->actingAs($vet)->post("/goats/{$goat->id}/teeth", ['teeth' => 'T08', 'dental_condition' => 'broken'])->assertSessionHasNoErrors();
        $this->assertSame([['T08', 'broken'], ['T08', 'worn'], ['T08', 'broken'], ['T04', 'normal']], $goat->fresh()->dentalExams->map(fn ($e) => [$e->dental_code, $e->condition])->all());
        $this->actingAs($vet)->get("/goats/{$goat->id}")->assertInertia(fn ($p) => $p->where('goat.dental.code', 'T08')->where('goat.dental.condition', 'Broken')->has('goat.dental.history', 4));
        $this->actingAs($vet)->get('/')->assertInertia(fn ($p) => $p->where('herdAge.teeth', fn ($t) => collect($t)->firstWhere('code', 'T08')['total'] === 1));
    }
}
