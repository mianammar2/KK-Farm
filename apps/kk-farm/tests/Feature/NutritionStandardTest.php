<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FeedItem;
use App\Models\Mating;
use App\Models\NutritionStandard;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RationPlan;
use App\Models\Shed;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Feed;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** D-116: Nutrition Standards from the KK Farms CP chart. */
class NutritionStandardTest extends TestCase
{
    use RefreshDatabase;

    private Breed $mc;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow(Carbon::parse('2026-11-10 07:00:00', 'UTC'));
        Tenant::set(Organization::create(['name' => 'Nutrition Farm', 'slug' => 'nutrition-farm'])->id);
        $this->mc = Breed::create(['name' => 'Makhi Cheeni', 'code' => 'MC', 'default_gestation_days' => 150]);
        $shed = Shed::create(['code' => 'S1', 'name' => 'KKF-GDU-01']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Growers']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@nutrition.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function goat(string $tag, string $sex, int $months, array $extra = [], ?float $kg = null): Animal
    {
        $g = Animal::create($extra + ['farm_number' => $tag, 'sex' => $sex, 'breed_id' => $this->mc->id, 'birth_date' => now()->subMonths($months)->toDateString(), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
        if ($kg) {
            WeightRecord::create(['animal_id' => $g->id, 'measured_on' => now()->toDateString(), 'weight_kg' => $kg, 'measured_by' => 'x']);
        }

        return $g;
    }

    public function test_every_farm_starts_with_the_twelve_groups_of_the_cp_chart(): void
    {
        $this->assertSame(12, NutritionStandard::count());
        $late = NutritionStandard::where('category', 'late_pregnant')->first();
        $this->assertSame(['Late Pregnant Female', '12–16%', 14.0], [$late->name, $late->range(), $late->target()]);
        $this->assertNull(NutritionStandard::where('name', 'High-Producing Lactating Female')->value('category'), 'put on by hand only');
    }

    public function test_each_goat_is_matched_to_its_group(): void
    {
        $doe = $this->goat('D-LATE', 'female', 30, ['repro_status' => 'pregnant']);
        Mating::create(['dam_id' => $doe->id, 'mated_on' => now()->subDays(110)->toDateString(), 'expected_kidding_on' => now()->addDays(40)->toDateString(), 'status' => 'pregnant', 'method' => 'natural', 'recorded_by' => 'x']);
        $early = $this->goat('D-EARLY', 'female', 30, ['repro_status' => 'pregnant']);
        Mating::create(['dam_id' => $early->id, 'mated_on' => now()->subDays(30)->toDateString(), 'expected_kidding_on' => now()->addDays(120)->toDateString(), 'status' => 'pregnant', 'method' => 'natural', 'recorded_by' => 'x']);
        $cases = [
            'young_kid' => $this->goat('K-1', 'female', 1),
            'weaned_kid' => $this->goat('K-2', 'male', 1, ['kid_stage' => 'weaned']),
            'late_pregnant' => $doe,
            'early_pregnant' => $early,
            'lactating' => $this->goat('D-LAC', 'female', 30, ['repro_status' => 'lactating']),
            'ready_female' => $this->goat('D-RDY', 'female', 20, ['repro_status' => 'ready']),
            'fattening' => $this->goat('F-1', 'male', 8, ['animal_purpose' => 'meat']),
            'growing_male' => $this->goat('G-1', 'male', 8),
            'growing_female' => $this->goat('G-2', 'female', 8),
            'adult_male' => $this->goat('B-1', 'male', 30),
            'adult_female' => $this->goat('D-1', 'female', 30),
        ];
        Tenant::flush();
        foreach ($cases as $category => $goat) {
            $this->assertSame($category, Feed::nutritionCategory($goat->fresh()), $goat->farm_number);
        }
    }

    public function test_the_chart_example_a_30_kg_grower_at_3_percent_and_14_percent_cp_needs_126_g(): void
    {
        $this->actingAs($this->user('ceo'))->post('/feeding/settings', ['morning' => '07:00', 'midday' => '12:00', 'evening' => '17:00', 'late' => 60, 'window' => 180, 'tolerance' => 10,
            'grower_min' => 3, 'grower_max' => 3, 'adult_min' => 2, 'adult_max' => 3])->assertSessionHasNoErrors();
        // A Makhi Cheeni growing male of 20–35 KG on 14% (preferred over the any-breed 12–14% standard).
        $this->actingAs($this->user('vet'))->post('/feeding/standards', ['name' => 'Growing Male (Makhi Cheeni)', 'category' => 'growing_male', 'breed_id' => $this->mc->id,
            'min_weight_kg' => 20, 'max_weight_kg' => 35, 'cp_min_pct' => 14, 'cp_max_pct' => 14, 'tdn_min_pct' => 65, 'tdn_max_pct' => 70, 'feeding_method' => 'tmr', 'meals_per_day' => 3,
            'feed_formula' => 'TMR-GR-01', 'reviewed_on' => '2026-11-09', 'active' => true])->assertSessionHasNoErrors();
        $g = $this->goat('G-1', 'male', 8, [], 30);
        $light = $this->goat('G-2', 'male', 8, [], 15);
        Tenant::flush();

        $req = Feed::perGoat(Animal::active()->get(), now());
        $this->assertSame([0.9, 14.0, 0.126, 'Growing Male (Makhi Cheeni)'], [$req[$g->id]['kgDay'], $req[$g->id]['cp'], $req[$g->id]['cpKg'], $req[$g->id]['standard']]);
        $this->assertSame('Growing Male Goat', $req[$light->id]['standard'], '15 KG is outside 20–35 KG');

        $this->actingAs($this->user('ceo'))->get('/feeding?tab=nutrition')->assertInertia(fn ($page) => $page
            ->where('standards', fn ($all) => ($s = collect($all)->firstWhere('name', 'Growing Male (Makhi Cheeni)')) && $s['goats'] === 1 && $s['formula'] === 'TMR-GR-01'
                && $s['reviewedBy'] === 'Test vet' && $s['pens'] === ['KKF-GDU-01-P01' => 1]));
        $this->actingAs($this->user('caretaker'))->post('/feeding/standards', ['name' => 'X', 'cp_min_pct' => 10, 'cp_max_pct' => 12])->assertForbidden();
    }

    public function test_a_goat_can_be_put_on_a_standard_by_hand_and_back(): void
    {
        $doe = $this->goat('MC181026-02', 'female', 30, ['repro_status' => 'lactating'], 40);
        $high = NutritionStandard::where('name', 'High-Producing Lactating Female')->first();
        $this->actingAs($this->user('vet'))->post('/feeding/standards/assign', ['standard_id' => $high->id, 'tags' => 'mc181026-02'])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame('High-Producing Lactating Female', Feed::standardFor($doe->fresh(), 40)->name);
        $this->actingAs($this->user('vet'))->post('/feeding/standards/assign', ['standard_id' => $high->id, 'tags' => 'MC181026-02, NOPE-1'])->assertSessionHasErrors(['tags' => 'Not found: NOPE-1']);

        $this->actingAs($this->user('vet'))->post('/feeding/standards/assign', ['standard_id' => null, 'tags' => 'MC181026-02'])->assertSessionHasNoErrors();
        Tenant::flush();
        $this->assertSame('Lactating Female', Feed::standardFor($doe->fresh(), 40)->name);
    }

    public function test_mix_cp_is_on_dry_matter_when_every_feed_has_a_dry_matter_percent(): void
    {
        $m = $this->user('manager');
        $this->actingAs($m)->post('/feeding/items', ['code' => 'C32', 'name' => 'Concentrate', 'category' => 'concentrate', 'cost_per_kg' => 120, 'cp_pct' => 32, 'dm_pct' => 90])->assertSessionHasNoErrors();
        $this->actingAs($m)->post('/feeding/items', ['code' => 'WS', 'name' => 'Wheat straw', 'category' => 'concentrate', 'cost_per_kg' => 30, 'cp_pct' => 4])->assertSessionHasNoErrors();
        $group = \App\Models\FeedingGroup::where('code', 'growers')->first();
        $this->actingAs($m)->post('/feeding/plans', ['feeding_group_id' => $group->id, 'effective_from' => now()->toDateString(), 'basis' => 'body_weight', 'split_morning' => 50, 'split_midday' => 0, 'split_evening' => 50,
            'lines' => [['feed_item_id' => FeedItem::where('code', 'C32')->value('id'), 'share_pct' => 25], ['feed_item_id' => FeedItem::where('code', 'WS')->value('id'), 'share_pct' => 75]]])->assertSessionHasNoErrors();
        $plan = RationPlan::sole()->load('lines.item');
        $this->assertSame(['cp' => 11.0, 'basis' => 'as_fed'], Feed::mix($plan), 'straw has no dry matter yet');

        $straw = FeedItem::where('code', 'WS')->first();
        $this->actingAs($m)->post("/feeding/items/{$straw->id}", ['name' => 'Wheat straw', 'category' => 'concentrate', 'cost_per_kg' => 30, 'cp_pct' => 4, 'dm_pct' => 80])->assertSessionHasNoErrors();
        // (25 × 32 + 75 × 4) ÷ (25 × 90 + 75 × 80) = 1,100 ÷ 8,250 = 13.3% of the dry matter.
        $this->assertSame(['cp' => 13.3, 'basis' => 'dry_matter'], Feed::mix($plan->fresh()->load('lines.item')));
    }
}
