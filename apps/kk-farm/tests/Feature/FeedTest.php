<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FeedingGroup;
use App\Models\FeedItem;
use App\Models\FeedUsage;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RationPlan;
use App\Models\Shed;
use App\Models\User;
use App\Support\Feed;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/** D-054: feed store, rations, feeding per pen and meal, stock and compliance. */
class FeedTest extends TestCase
{
    use RefreshDatabase;

    private Pen $penA;

    private Pen $penB;

    private Breed $breed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // 07:30 in Pakistan (UTC+5): the morning meal (07:00) has started.
        Carbon::setTestNow(Carbon::parse('2026-11-10 02:30:00', 'UTC'));
        Tenant::set(Organization::create(['name' => 'Feed Farm', 'slug' => 'feed-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $shed = Shed::create(['code' => 'SHED-1', 'name' => 'Shed 1']);
        $this->penA = Pen::create(['shed_id' => $shed->id, 'name' => 'Pen A', 'purpose' => 'Does']);
        $this->penB = Pen::create(['shed_id' => $shed->id, 'name' => 'Pen B', 'purpose' => 'Kids']);
        foreach (['D-1', 'D-2'] as $n) {
            $this->goat($n, $this->penA, ['animal_class' => 'breeding_female']);
        }
        $this->goat('K-1', $this->penB, ['animal_class' => 'female_kid', 'birth_date' => now()->subMonth()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function goat(string $number, Pen $pen, array $extra = []): Animal
    {
        return Animal::create($extra + ['farm_number' => $number, 'sex' => 'female', 'breed_id' => $this->breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@feed.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function item(string $code, int $costPkr, float $opening = 0): FeedItem
    {
        $this->actingAs($this->user('manager'))->post('/feeding/items', ['code' => $code, 'name' => "Feed $code", 'category' => 'concentrate', 'cost_per_kg' => $costPkr, 'reorder_level_kg' => 50, 'opening_kg' => $opening])->assertSessionHasNoErrors();

        return FeedItem::where('code', $code)->firstOrFail();
    }

    private function group(string $code): FeedingGroup
    {
        return FeedingGroup::where('code', $code)->firstOrFail();
    }

    /** Draft and approve: breeding does get 1.5 kg straw + 0.5 kg concentrate a day, 40/20/40 over the meals. */
    private function approvedDoeRation(FeedItem $straw, FeedItem $conc): RationPlan
    {
        $this->actingAs($this->user('manager'))->post('/feeding/plans', [
            'feeding_group_id' => $this->group('breeding_does')->id, 'effective_from' => now()->toDateString(),
            'split_morning' => 40, 'split_midday' => 20, 'split_evening' => 40,
            'lines' => [['feed_item_id' => $straw->id, 'kg_per_head' => 1.5], ['feed_item_id' => $conc->id, 'kg_per_head' => 0.5]],
        ])->assertSessionHasNoErrors();
        $plan = RationPlan::latest('id')->firstOrFail();
        $this->actingAs($this->user('vet'))->post("/feeding/plans/{$plan->id}/approve")->assertSessionHasNoErrors();

        return $plan->fresh();
    }

    private function feed(Pen $pen, array $lines, string $meal = 'morning', string $role = 'feeding')
    {
        return $this->actingAs($this->user($role))->post('/feeding/record', ['pen_id' => $pen->id, 'meal' => $meal, 'lines' => $lines]);
    }

    public function test_goats_fall_into_feeding_groups_from_their_classification(): void
    {
        $codes = Animal::orderBy('farm_number')->get()->mapWithKeys(fn ($a) => [$a->farm_number => Feed::groupCode($a)]);
        $this->assertSame(['D-1' => 'breeding_does', 'D-2' => 'breeding_does', 'K-1' => 'kids'], $codes->all());

        Animal::where('farm_number', 'D-1')->update(['repro_status' => 'pregnant']);
        Animal::where('farm_number', 'D-2')->update(['health_flag' => 'quarantine']);
        $this->assertSame('pregnant_does', Feed::groupCode(Animal::where('farm_number', 'D-1')->first()));
        $this->assertSame('quarantine', Feed::groupCode(Animal::where('farm_number', 'D-2')->first()));
    }

    public function test_planned_feed_comes_from_the_goats_in_the_pen_and_only_an_approved_ration(): void
    {
        $straw = $this->item('WS', 30);
        $conc = $this->item('CONC', 120);

        $this->actingAs($this->user('manager'))->post('/feeding/plans', [
            'feeding_group_id' => $this->group('breeding_does')->id, 'effective_from' => now()->toDateString(),
            'split_morning' => 40, 'split_midday' => 20, 'split_evening' => 40, 'lines' => [['feed_item_id' => $straw->id, 'kg_per_head' => 1.5]],
        ]);
        $this->assertSame([], Feed::plannedByPen('morning', now())[$this->penA->id]['lines'], 'a draft ration plans nothing');

        $this->approvedDoeRation($straw, $conc);
        $planned = Feed::plannedByPen('morning', now());
        // 2 does × 1.5 kg × 40% = 1.2 kg straw, 2 × 0.5 × 40% = 0.4 kg concentrate
        $this->assertEquals([$straw->id => 1.2, $conc->id => 0.4], $planned[$this->penA->id]['lines']);
        $this->assertSame(2, $planned[$this->penA->id]['headcount']);
        $this->assertSame([0, 1], [$planned[$this->penB->id]['unplanned'], $planned[$this->penB->id]['onMilk']], 'the kid drinks milk: not counted as missing a ration (D-069)');

        // Moving a goat changes both pens' plans: headcount is never typed.
        Animal::where('farm_number', 'D-2')->update(['pen_id' => $this->penB->id]);
        $planned = Feed::plannedByPen('morning', now());
        $this->assertEquals([$straw->id => 0.6, $conc->id => 0.2], $planned[$this->penA->id]['lines']);
        $this->assertEquals([$straw->id => 0.6, $conc->id => 0.2], $planned[$this->penB->id]['lines']);
    }

    public function test_feeding_reduces_stock_and_a_retry_or_correction_never_counts_twice(): void
    {
        $straw = $this->item('WS', 30, 100);
        $conc = $this->item('CONC', 120, 20);
        $this->approvedDoeRation($straw, $conc);

        $line = ['id' => (string) Str::uuid(), 'feed_item_id' => $straw->id, 'offered_kg' => 1.4];
        $this->feed($this->penA, [$line, ['id' => (string) Str::uuid(), 'feed_item_id' => $conc->id, 'offered_kg' => 0.4]])->assertSessionHasNoErrors();
        $this->feed($this->penA, [$line])->assertSessionHasNoErrors(); // the same entry sent again
        $this->assertSame(2, FeedUsage::count());
        $this->assertEqualsWithDelta(98.6, Feed::stock()[$straw->id], 0.001);

        $usage = FeedUsage::where('feed_item_id', $straw->id)->firstOrFail();
        $this->assertEqualsWithDelta(1.2, $usage->planned_kg, 0.001);
        $this->assertSame(2, $usage->headcount);
        $this->assertSame(3000, $usage->unit_cost_minor);

        // Correction: a new row supersedes the old one, the old row is untouched.
        $this->feed($this->penA, [['id' => (string) Str::uuid(), 'feed_item_id' => $straw->id, 'offered_kg' => 1.0]])->assertSessionHasNoErrors();
        $this->assertSame(3, FeedUsage::count());
        $this->assertEqualsWithDelta(1.4, $usage->fresh()->offered_kg, 0.001);
        $this->assertEqualsWithDelta(99.0, Feed::stock()[$straw->id], 0.001);
        $this->assertSame(2, FeedUsage::current()->count());
    }

    public function test_leftover_is_a_correction_and_cannot_exceed_what_was_given(): void
    {
        $straw = $this->item('WS', 30, 100);
        $this->feed($this->penA, [['id' => (string) Str::uuid(), 'feed_item_id' => $straw->id, 'offered_kg' => 2]])->assertSessionHasNoErrors();
        $usage = FeedUsage::firstOrFail();

        $this->actingAs($this->user('feeding'))->post('/feeding/leftover', ['lines' => [['id' => (string) Str::uuid(), 'usage_id' => $usage->id, 'refused_kg' => 3]]])->assertSessionHasErrors('lines.0.refused_kg');
        $this->actingAs($this->user('feeding'))->post('/feeding/leftover', ['lines' => [['id' => (string) Str::uuid(), 'usage_id' => $usage->id, 'refused_kg' => 0.5]]])->assertSessionHasNoErrors();

        $current = FeedUsage::current()->sole();
        $this->assertSame($usage->id, $current->supersedes_id);
        $this->assertEqualsWithDelta(1.5, $current->consumedKg(), 0.001);
        $this->assertTrue($current->fed_at->equalTo($usage->fed_at), 'the meal time stays the time it was fed');
        $this->assertEqualsWithDelta(98.0, Feed::stock()[$straw->id], 0.001, 'refused feed still left the store');
    }

    public function test_a_purchase_adds_stock_records_the_expense_and_sets_the_cost(): void
    {
        $straw = $this->item('WS', 30, 10);
        $this->actingAs($this->user('feeding'))->post('/feeding/stock', ['feed_item_id' => $straw->id, 'type' => 'purchase', 'qty_kg' => 500, 'rate' => 32.5, 'moved_on' => now()->toDateString(), 'party' => 'Mandi', 'reference' => 'INV-9'])->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(510, Feed::stock()[$straw->id], 0.001);
        $this->assertSame(3250, $straw->fresh()->cost_per_kg_minor);
        $expense = LedgerEntry::sole();
        $this->assertSame(['out', 'Feed Purchase', 16250.0], [$expense->direction, $expense->category, (float) $expense->amount]);

        $this->actingAs($this->user('feeding'))->post('/feeding/stock', ['feed_item_id' => $straw->id, 'type' => 'adjustment', 'qty_kg' => 5, 'moved_on' => now()->toDateString()])->assertSessionHasErrors('direction');
        $this->actingAs($this->user('feeding'))->post('/feeding/stock', ['feed_item_id' => $straw->id, 'type' => 'write_off', 'qty_kg' => 20, 'moved_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(490, Feed::stock()[$straw->id], 0.001);
        $this->assertSame(1, LedgerEntry::count(), 'only purchases create an expense');
    }

    public function test_rations_need_meals_totalling_100_and_a_vet_to_approve(): void
    {
        $straw = $this->item('WS', 30);
        $payload = ['feeding_group_id' => $this->group('kids')->id, 'effective_from' => now()->toDateString(), 'split_morning' => 50, 'split_midday' => 0, 'split_evening' => 40, 'lines' => [['feed_item_id' => $straw->id, 'kg_per_head' => 0.3]]];

        $this->actingAs($this->user('manager'))->post('/feeding/plans', $payload)->assertSessionHasErrors('split_morning');
        $this->actingAs($this->user('caretaker'))->post('/feeding/plans', ['split_evening' => 50] + $payload)->assertForbidden();
        $this->actingAs($this->user('manager'))->post('/feeding/plans', ['split_evening' => 50] + $payload)->assertSessionHasNoErrors();
        $plan = RationPlan::sole();

        $this->actingAs($this->user('manager'))->post("/feeding/plans/{$plan->id}/approve")->assertForbidden();
        $this->actingAs($this->user('vet'))->post("/feeding/plans/{$plan->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('approved', $plan->fresh()->status);
    }

    public function test_only_the_feed_store_roles_change_feeds_and_stock(): void
    {
        $straw = $this->item('WS', 30, 100);
        $caretaker = $this->user('caretaker');

        $this->actingAs($caretaker)->post('/feeding/items', ['code' => 'X', 'name' => 'X', 'category' => 'other', 'cost_per_kg' => 1])->assertForbidden();
        $this->actingAs($caretaker)->post('/feeding/stock', ['feed_item_id' => $straw->id, 'type' => 'receipt', 'qty_kg' => 1, 'moved_on' => now()->toDateString()])->assertForbidden();
        $this->feed($this->penA, [['id' => (string) Str::uuid(), 'feed_item_id' => $straw->id, 'offered_kg' => 1]], 'morning', 'caretaker')->assertSessionHasNoErrors();
        $this->actingAs($this->user('manager'))->post('/feeding/items', ['code' => 'ws', 'name' => 'Dup', 'category' => 'other', 'cost_per_kg' => 1])->assertSessionHasErrors('code');
    }

    public function test_meals_are_done_late_or_missed_from_the_time_feed_was_recorded(): void
    {
        $straw = $this->item('WS', 30, 100);
        $conc = $this->item('CONC', 120, 100);
        $this->approvedDoeRation($straw, $conc);
        $kids = $this->group('kids');
        $this->actingAs($this->user('manager'))->post('/feeding/plans', ['feeding_group_id' => $kids->id, 'effective_from' => now()->toDateString(), 'split_morning' => 50, 'split_midday' => 0, 'split_evening' => 50, 'lines' => [['feed_item_id' => $conc->id, 'kg_per_head' => 0.2]]]);
        $this->actingAs($this->user('vet'))->post('/feeding/plans/'.RationPlan::where('status', 'draft')->value('id').'/approve');
        $org = Feed::org();

        $this->feed($this->penA, [['id' => (string) Str::uuid(), 'feed_item_id' => $straw->id, 'offered_kg' => 1.2]]); // 07:30, on time
        $board = Feed::board('2026-11-10', $org);
        $this->assertSame('done', $board[$this->penA->id]['morning']['status']);
        $this->assertSame('due', $board[$this->penB->id]['morning']['status']);
        $this->assertSame('upcoming', $board[$this->penA->id]['midday']['status']);
        $this->assertSame('none', $board[$this->penB->id]['midday']['status'], 'kids get 0% at midday');

        Carbon::setTestNow(Carbon::parse('2026-11-10 05:30:00', 'UTC')); // 10:30 local: morning window (180 min) is over
        $this->assertSame('missed', Feed::board('2026-11-10', $org)[$this->penB->id]['morning']['status']);
        $this->feed($this->penB, [['id' => (string) Str::uuid(), 'feed_item_id' => $conc->id, 'offered_kg' => 0.1]]);
        $this->assertSame('late', Feed::board('2026-11-10', $org)[$this->penB->id]['morning']['status']);
        $this->assertSame(['expected' => 2, 'done' => 1, 'late' => 1, 'missed' => 0], Feed::todayCompliance($org));

        $this->actingAs($this->user('ceo'))->get('/feeding')->assertOk();
        $this->actingAs($this->user('ceo'))->get('/')->assertOk();
    }

    public function test_a_farm_cannot_feed_into_another_farms_pen_or_feed(): void
    {
        $mine = Tenant::current();
        Tenant::set(Organization::create(['name' => 'Other', 'slug' => 'other'])->id);
        $otherPen = Pen::create(['shed_id' => Shed::create(['code' => 'S', 'name' => 'S'])->id, 'name' => 'Theirs', 'purpose' => 'x']);
        $otherItem = FeedItem::create(['code' => 'WS', 'name' => 'Their straw', 'category' => 'roughage']);
        Tenant::set($mine);
        $straw = $this->item('WS', 30);

        $this->feed($otherPen, [['id' => (string) Str::uuid(), 'feed_item_id' => $straw->id, 'offered_kg' => 1]])->assertSessionHasErrors('pen_id');
        $this->feed($this->penA, [['id' => (string) Str::uuid(), 'feed_item_id' => $otherItem->id, 'offered_kg' => 1]])->assertSessionHasErrors('lines.0.feed_item_id');
        $this->assertSame(0, FeedUsage::count());
    }

    public function test_age_bands_are_kid_under_2_months_grower_to_14_and_adult_after_and_move_on_by_themselves(): void
    {
        $at = fn (int $months) => (new Animal(['birth_date' => now()->subMonths($months)->subDay()]))->effectiveAge();
        $this->assertSame(['kid', 'grower', 'grower', 'adult'], [$at(1), $at(2), $at(13), $at(14)]);

        // A category chosen by hand is ignored once the birth date is known: the goat ages automatically.
        $this->assertSame('adult', (new Animal(['birth_date' => now()->subMonths(20), 'age_category' => 'kid']))->effectiveAge());
        $this->assertSame('kid', (new Animal(['birth_date' => null, 'age_category' => 'kid']))->effectiveAge());
    }

    public function test_a_body_weight_ration_feeds_growers_3_to_4_percent_and_adults_2_to_3_percent(): void
    {
        $straw = $this->item('WS', 30, 100);
        $conc = $this->item('CONC', 120);
        $grower = $this->goat('G-1', $this->penA, ['animal_class' => 'breeding_female', 'birth_date' => now()->subMonths(6)]);
        $adult = Animal::where('farm_number', 'D-1')->first(); // 2 years old
        \App\Models\WeightRecord::create(['animal_id' => $grower->id, 'measured_on' => now(), 'weight_kg' => 20, 'measured_by' => 'x']);
        \App\Models\WeightRecord::create(['animal_id' => $adult->id, 'measured_on' => now()->subMonth(), 'weight_kg' => 35, 'measured_by' => 'x']);
        \App\Models\WeightRecord::create(['animal_id' => $adult->id, 'measured_on' => now(), 'weight_kg' => 40, 'measured_by' => 'x']); // the latest weight counts

        $this->actingAs($this->user('manager'))->post('/feeding/plans', [
            'feeding_group_id' => $this->group('breeding_does')->id, 'effective_from' => now()->toDateString(), 'basis' => 'body_weight',
            'split_morning' => 50, 'split_midday' => 0, 'split_evening' => 50,
            'lines' => [['feed_item_id' => $straw->id, 'share_pct' => 75], ['feed_item_id' => $conc->id, 'share_pct' => 20]],
        ])->assertSessionHasErrors('lines');
        $this->actingAs($this->user('manager'))->post('/feeding/plans', [
            'feeding_group_id' => $this->group('breeding_does')->id, 'effective_from' => now()->toDateString(), 'basis' => 'body_weight',
            'split_morning' => 50, 'split_midday' => 0, 'split_evening' => 50,
            'lines' => [['feed_item_id' => $straw->id, 'share_pct' => 75], ['feed_item_id' => $conc->id, 'share_pct' => 25]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->post('/feeding/plans/'.RationPlan::sole()->id.'/approve');

        $pen = Feed::plannedByPen('morning', now())[$this->penA->id];
        // D-069: grower 20 kg × 3–4% = 0.6–0.8 kg/day, adult 40 kg × 2–3% = 0.8–1.2 kg/day, planned at the middle (0.7 + 1.0 = 1.7).
        // D-2 has no weight. Morning gets 50%: 0.85 kg (0.7–1.0), straw 75%, concentrate 25%.
        $this->assertEqualsWithDelta(0.64, $pen['lines'][$straw->id], 0.006);
        $this->assertEqualsWithDelta(0.21, $pen['lines'][$conc->id], 0.006);
        $this->assertEqualsWithDelta(0.525, $pen['min'][$straw->id], 0.006);
        $this->assertEqualsWithDelta(0.75, $pen['max'][$straw->id], 0.006);
        $this->assertSame([1, 1], [$pen['unplanned'], $pen['noWeight']]);

        // Feeding within the range is on plan; outside it is flagged.
        $this->feed($this->penA, [['id' => (string) Str::uuid(), 'feed_item_id' => $straw->id, 'offered_kg' => 0.9]])->assertSessionHasNoErrors();
        $this->actingAs($this->user('ceo'))->get('/feeding')->assertInertia(fn ($page) => $page->where('history.0.off', true));
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page->where('feeding.pens.0.name', 'Shed 1-Pen A')->where('feeding.pens.0.meals.morning.given', 0.9)->has('feeding.pens', 2));
    }

    public function test_the_dashboard_shows_each_goats_requirement_by_shed_and_pen(): void
    {
        $straw = $this->item('WS', 30);
        $d1 = Animal::where('farm_number', 'D-1')->first();
        \App\Models\WeightRecord::create(['animal_id' => $d1->id, 'measured_on' => now(), 'weight_kg' => 40, 'measured_by' => 'x']);
        $this->actingAs($this->user('manager'))->post('/feeding/plans', ['feeding_group_id' => $this->group('breeding_does')->id, 'effective_from' => now()->toDateString(), 'basis' => 'body_weight',
            'split_morning' => 50, 'split_midday' => 0, 'split_evening' => 50, 'lines' => [['feed_item_id' => $straw->id, 'share_pct' => 100]]])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->post('/feeding/plans/'.RationPlan::sole()->id.'/approve');

        $req = Feed::perGoat(Animal::active()->get(), now());
        $this->assertSame(['planned', 1.0, 0.8, 1.2], [$req[$d1->id]['state'], $req[$d1->id]['kgDay'], $req[$d1->id]['min'], $req[$d1->id]['max']], '40 kg adult × 2–3%, planned at 2.5%');
        $this->assertSame('no_weight', $req[Animal::where('farm_number', 'D-2')->value('id')]['state']);
        $this->assertSame('milk', $req[Animal::where('farm_number', 'K-1')->value('id')]['state']);
        $this->assertEqualsWithDelta(Feed::plannedByPen('morning', now())[$this->penA->id]['lines'][$straw->id] * 2, $req[$d1->id]['kgDay'], 0.01, 'per goat adds up to the pen plan');

        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page
            ->where('requirement.kgDay', 1)->where('requirement.cost', 30)->where('requirement.milk', 1)->where('requirement.missing', 1)
            ->where('requirement.sheds.0.name', 'Shed 1')->where('requirement.sheds.0.pens.0.name', 'Shed 1-Pen A')
            ->where('requirement.sheds.0.pens.0.goats.0.number', 'D-1')->where('requirement.sheds.0.pens.0.goats.0.feeds', 'Feed WS 1'));
    }

    public function test_without_a_ration_the_plan_is_automatic_from_weight_and_feed_given_cannot_exceed_stock(): void
    {
        $tmr = $this->item('TMR', 60, 2);
        foreach (['D-1' => 50, 'D-2' => 30] as $n => $kg) {
            \App\Models\WeightRecord::create(['animal_id' => Animal::where('farm_number', $n)->value('id'), 'measured_on' => now(), 'weight_kg' => $kg, 'measured_by' => 'x']);
        }

        // 80 KG of adults × 2.5% = 2 KG a day; morning is 40% = 0.8 KG on the feed in store.
        $plan = Feed::plannedByPen('morning', now())[$this->penA->id];
        $this->assertSame([2, 0, [$tmr->id => 0.8]], [$plan['auto'], $plan['unplanned'], $plan['lines']]);
        $this->assertSame([$tmr->id => 0.64], $plan['min'], '2% of 80 KG × 40%');

        $care = $this->user('feeding');
        $send = fn (float $kg) => $this->actingAs($care)->post('/feeding/record', ['pen_id' => $this->penA->id, 'meal' => 'morning', 'lines' => [['id' => (string) Str::uuid(), 'feed_item_id' => $tmr->id, 'offered_kg' => $kg]]]);
        $send(2.5)->assertSessionHasErrors(['lines.0.offered_kg' => 'Only 2 KG of Feed TMR available. Record a purchase in Feed store first.']);
        $this->assertSame(0, FeedUsage::count());

        $send(1.5)->assertSessionHasNoErrors(); // a custom amount above the 0.8 KG plan, within stock
        $this->assertEqualsWithDelta(0.5, Feed::stock()[$tmr->id], 0.001);
        $send(2.5)->assertSessionHasErrors('lines.0.offered_kg');
        $send(2.0)->assertSessionHasNoErrors(); // the correction only needs the extra 0.5 KG
        $this->assertEqualsWithDelta(0.0, Feed::stock()[$tmr->id], 0.001);
    }

    public function test_moving_a_goat_after_feeding_shows_the_pen_overfed_everywhere(): void
    {
        $tmr = $this->item('TMR', 60, 100);
        foreach (['D-1' => 50, 'D-2' => 50] as $n => $kg) {
            \App\Models\WeightRecord::create(['animal_id' => Animal::where('farm_number', $n)->value('id'), 'measured_on' => now(), 'weight_kg' => $kg, 'measured_by' => 'x']);
        }
        // Morning plan for pen A: 100 KG × 2.5% × 40% = 1 KG (range 0.8–1.2). Given exactly that.
        $this->actingAs($this->user('feeding'))->post('/feeding/record', ['pen_id' => $this->penA->id, 'meal' => 'morning', 'lines' => [['id' => (string) Str::uuid(), 'feed_item_id' => $tmr->id, 'offered_kg' => 1]]])->assertSessionHasNoErrors();
        $this->assertSame('ok', Feed::board(now()->toDateString(), Feed::org())[$this->penA->id]['morning']['balance']['state']);

        // D-2 moves to pen B: pen A now needs 0.5 KG (0.4–0.6) and was given 1 KG.
        $this->actingAs($this->user('manager'))->post('/movements', ['animal_ids' => [Animal::where('farm_number', 'D-2')->value('id')], 'type' => 'transfer', 'to_pen_id' => $this->penB->id, 'moved_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $morning = Feed::board(now()->toDateString(), Feed::org())[$this->penA->id]['morning'];
        $this->assertSame([0.5, 'over', 0.5], [$morning['plannedKg'], $morning['balance']['state'], $morning['balance']['diff']]);

        $this->actingAs($this->user('ceo'))->get('/feeding')->assertInertia(fn ($page) => $page->where('board.0.balance', 'over')->where('board.0.diff', 0.5));
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page->where('feeding.overPens', 1)->where('feeding.pens.0.meals.morning.balance', 'over')
            ->where('requirement.sheds.0.pens.0.givenToday', 1)->where('requirement.sheds.0.pens.0.kgDay', 1.3));
        $over = collect(\App\Support\ActionCenter::items($this->user('ceo')))->firstWhere('key', 'feed_over');
        $this->assertSame(['Shed 1-Pen A'], array_column($over['items'], 'text'));
        $this->assertSame(['ok', 'short'], [Feed::balance(1, 0.8, 1.2, 1.1, 10)['state'], Feed::balance(1, 0.8, 1.2, 0.5, 10)['state']]);
    }

    public function test_the_requirement_shows_this_month_and_the_shortage_to_month_end(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-21 06:00', 'UTC'));
        $adult = Animal::where('farm_number', 'D-1')->first();
        \App\Models\WeightRecord::create(['animal_id' => $adult->id, 'measured_on' => now(), 'weight_kg' => 40, 'measured_by' => 'x']);
        $this->item('WS', 30, 5);

        // 40 kg × 2.5% = 1 kg a day: November has 30 days, 10 are left including today, the store holds 5 kg.
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page
            ->where('requirement.supply.month', 'November')->where('requirement.supply.needMonth', 30)
            ->where('requirement.supply.daysLeft', 10)->where('requirement.supply.needLeft', 10)->where('requirement.supply.shortMonth', 5)
            ->where('requirement.kgMonth', 30)->where('requirement.sheds.0.kgMonth', 30)->where('requirement.sheds.0.pens.0.goats.0.kgMonth', 30));
    }

    public function test_the_requirement_comes_from_weight_and_intake_even_without_a_ration_and_is_checked_against_the_store(): void
    {
        $adult = Animal::where('farm_number', 'D-1')->first();
        $grower = $this->goat('G-1', $this->penA, ['animal_class' => 'growing_female', 'birth_date' => now()->subMonths(6)]);
        // A purchase date entered as the birth date: the category says Breeding Male, so it is fed as an adult and flagged.
        $buck = $this->goat('B-1', $this->penB, ['sex' => 'male', 'animal_class' => 'breeding_male', 'birth_date' => now()->subDay()]);
        foreach ([[$adult, 50], [$grower, 20], [$buck, 88]] as [$g, $kg]) {
            \App\Models\WeightRecord::create(['animal_id' => $g->id, 'measured_on' => now(), 'weight_kg' => $kg, 'measured_by' => 'x']);
        }

        $req = Feed::perGoat(Animal::active()->get(), now());
        $this->assertSame([1.25, 'planned', false], [$req[$adult->id]['kgDay'], $req[$adult->id]['state'], $req[$adult->id]['split']], '50 kg adult × 2.5% with no ration at all');
        $this->assertSame(0.7, $req[$grower->id]['kgDay'], '20 kg grower × 3.5%');
        $this->assertSame([2.2, 'adult'], [$req[$buck->id]['kgDay'], $req[$buck->id]['age']], 'not a kid on milk despite the wrong date');
        $this->assertContains('age_conflict', \App\Support\HerdStats::tags($buck->fresh(), true));

        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page
            ->where('requirement.supply.needDay', 4.2)->where('requirement.supply.inStore', 0)->where('requirement.supply.days', 0)->where('requirement.supply.unsplit', 4.2));
        $short = collect(\App\Support\ActionCenter::items($this->user('ceo')))->firstWhere('key', 'feed_short');
        $this->assertSame(['Not enough feed for today', 'critical'], [$short['label'], $short['severity']]);

        $straw = $this->item('WS', 30, 30);
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page->where('requirement.supply.days', 7));
        $this->assertNull(collect(\App\Support\ActionCenter::items($this->user('ceo')))->firstWhere('key', 'feed_short'), '30 kg lasts 7 days at 4.2 kg a day');

        // The owner corrects the birth date; the old one stays in the lifecycle.
        $this->actingAs($this->user('ceo'))->post("/goats/{$buck->id}/birth-date", ['age_months' => 30, 'reason' => 'purchase date was entered'])->assertSessionHasNoErrors();
        $this->assertSame('adult', $buck->fresh()->effectiveAge());
        $this->assertNotContains('age_conflict', \App\Support\HerdStats::tags($buck->fresh(), true));
        $this->actingAs($this->user('caretaker'))->post("/goats/{$buck->id}/birth-date", ['age_months' => 30, 'reason' => 'x'])->assertForbidden();
    }

    /** D-107: with feeding not recorded, only the requirement shows: no meal, missed, store or shortage alerts. */
    public function test_without_recording_feeding_only_the_requirement_shows(): void
    {
        \App\Models\WeightRecord::create(['animal_id' => Animal::where('farm_number', 'D-1')->value('id'), 'measured_on' => now(), 'weight_kg' => 40, 'measured_by' => 'x']);
        Carbon::setTestNow(Carbon::parse('2026-11-10 12:00:00', 'UTC')); // well past the morning meal: it would be "missed"
        $keys = fn () => array_column(\App\Support\ActionCenter::items($this->user('ceo')), 'key');
        $this->assertContains('feed_short', $keys(), 'recording on: the empty store is a shortage');

        $this->actingAs($this->user('ceo'))->post('/feeding/settings', ['morning' => '07:00', 'midday' => '12:00', 'evening' => '17:00', 'late' => 60, 'window' => 180, 'tolerance' => 10,
            'grower_min' => 3, 'grower_max' => 4, 'adult_min' => 2, 'adult_max' => 3, 'tracking' => false])->assertSessionHasNoErrors();
        \App\Support\Tenant::flush();
        $this->assertNotContains('feeding_missed', $keys());
        $this->assertNotContains('feed_short', $keys());
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page->where('feeding', null)->where('feedTracking', false)
            ->where('requirement.tracking', false)->where('requirement.supply.needDay', 1));
    }

    /** D-111: CP % per age in Feeding Settings; the dashboard shows the protein the feed should carry. */
    public function test_crude_protein_percent_per_age_gives_the_protein_needed(): void
    {
        $d1 = Animal::where('farm_number', 'D-1')->first();
        \App\Models\WeightRecord::create(['animal_id' => $d1->id, 'measured_on' => now(), 'weight_kg' => 40, 'measured_by' => 'x']);
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page->where('requirement.supply.cpDay', null));

        $this->actingAs($this->user('ceo'))->post('/feeding/settings', ['morning' => '07:00', 'midday' => '12:00', 'evening' => '17:00', 'late' => 60, 'window' => 180, 'tolerance' => 10,
            'grower_min' => 3, 'grower_max' => 4, 'adult_min' => 2, 'adult_max' => 3, 'tracking' => false, 'grower_cp' => 16, 'adult_cp' => 12])->assertSessionHasNoErrors();
        \App\Support\Tenant::flush();
        $this->assertSame(12.0, (float) Organization::find(Tenant::current())->adult_cp_pct);

        // 40 KG adult × 2.5% = 1 KG of feed a day; at 12% CP that is 0.12 KG of protein.
        $req = Feed::perGoat(Animal::active()->get(), now());
        $this->assertSame([12.0, 0.12], [$req[$d1->id]['cp'], $req[$d1->id]['cpKg']]);
        $this->assertNull($req[Animal::where('farm_number', 'K-1')->value('id')]['cp'], 'kid on milk: no CP');
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($page) => $page->where('requirement.supply.cpDay', 0.12)
            ->where('requirement.sheds.0.cpDay', 0.12)->where('requirement.sheds.0.pens.0.goats.0.cpKg', 0.12));
        $this->actingAs($this->user('ceo'))->get('/feeding')->assertInertia(fn ($page) => $page->where('settings.cp.adult', 12)->where('settings.cp.kid', null));

        $this->actingAs($this->user('ceo'))->post('/feeding/settings', ['morning' => '07:00', 'midday' => '12:00', 'evening' => '17:00', 'late' => 60, 'window' => 180, 'tolerance' => 10,
            'grower_min' => 3, 'grower_max' => 4, 'adult_min' => 2, 'adult_max' => 3, 'adult_cp' => 55])->assertSessionHasErrors('adult_cp');
    }
}
