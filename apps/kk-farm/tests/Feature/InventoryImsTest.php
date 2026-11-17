<?php

namespace Tests\Feature;

use App\Models\FeedItem;
use App\Models\FeedStockCount;
use App\Models\FeedStockMovement;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\Organization;
use App\Models\StoreItem;
use App\Models\StoreMove;
use App\Models\User;
use App\Support\Feed;
use App\Support\MedicineStock;
use App\Support\Stores;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-331: the full inventory: every store's items and stock are kept from Inventory, through the same actions the apps use. */
class InventoryImsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'IMS Farm', 'slug' => 'ims-farm'])->id);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@ims.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_feed_and_medicine_added_in_inventory_are_the_same_records_the_apps_use(): void
    {
        $ceo = $this->user('ceo');
        $this->actingAs($ceo)->post('/inventory/feed-items', ['code' => 'conc', 'name' => 'Concentrate', 'category' => 'concentrate', 'cost_per_kg' => 120, 'opening_kg' => 50])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post('/inventory/medicines', ['code' => 'oxy', 'name' => 'Oxytetracycline', 'category' => 'antibiotic', 'unit' => 'ml', 'opening_qty' => 100, 'batch' => 'B1', 'expiry_on' => now()->addDays(10)->toDateString()])->assertSessionHasNoErrors();
        $feed = FeedItem::firstOrFail();
        $med = Medicine::firstOrFail();
        $this->assertSame(50.0, (float) Feed::stock(true)[$feed->id]);
        $this->assertSame(100.0, (float) MedicineStock::quantities()[$med->id]);

        // stock in from Inventory, edit from Inventory: Feeding and Veterinary read the same rows
        $this->actingAs($ceo)->post('/inventory/feed-stock', ['feed_item_id' => $feed->id, 'type' => 'receipt', 'qty_kg' => 25, 'moved_on' => now()->toDateString()])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post("/inventory/medicines/{$med->id}", ['name' => 'Oxytetracycline LA', 'category' => 'antibiotic', 'reorder_level' => 20, 'cost_per_unit' => 5])->assertSessionHasNoErrors();
        $this->assertSame(75.0, (float) Feed::stock(true)[$feed->id]);
        $this->assertSame('Oxytetracycline LA', $med->fresh()->name);
        $this->actingAs($ceo)->get('/feeding?tab=store')->assertOk();

        $this->actingAs($ceo)->get('/inventory')->assertInertia(fn (AssertableInertia $p) => $p->component('Inventory')->has('items', 2)
            ->where('expiry.0.batch', 'B1')->where('expiry.0.state', 'expiring')
            ->where('moves', fn ($m) => collect($m)->contains(fn ($r) => $r['kind'] === 'feed' && $r['typeKey'] === 'receipt' && $r['qty'] == 25)));

        // the item card: its details, batches and every movement
        $card = $this->actingAs($ceo)->getJson("/inventory/card?kind=medicine&id={$med->id}")->assertOk()->json();
        $this->assertSame('Oxytetracycline LA', $card['item']['name']);
        $this->assertSame('B1', $card['batches'][0]['batch']);
        $this->assertCount(1, $card['moves']);
    }

    public function test_each_role_works_only_in_its_own_store(): void
    {
        $this->actingAs($this->user('vet'))->post('/inventory/feed-items', ['code' => 'x', 'name' => 'X', 'category' => 'concentrate', 'cost_per_kg' => 1])->assertForbidden();
        $this->actingAs($this->user('feeding'))->post('/inventory/medicines', ['code' => 'y', 'name' => 'Y', 'category' => 'antibiotic', 'unit' => 'ml'])->assertForbidden();
        $med = Medicine::create(['code' => 'M', 'name' => 'M', 'category' => 'antibiotic', 'unit' => 'ml']);
        $this->actingAs($this->user('feeding'))->getJson("/inventory/card?kind=medicine&id={$med->id}")->assertForbidden();
        $this->actingAs($this->user('feeding'))->post('/inventory/count', ['kind' => 'medicine', 'counted_on' => now()->toDateString(), 'counts' => [['id' => $med->id, 'counted' => 1]]])->assertForbidden();
    }

    public function test_a_stock_count_posts_each_difference_once_in_every_store(): void
    {
        $ceo = $this->user('ceo');
        $feed = FeedItem::create(['code' => 'F', 'name' => 'Straw', 'category' => 'roughage', 'cost_per_kg_minor' => 3000]);
        FeedStockMovement::create(['feed_item_id' => $feed->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty_kg' => 100, 'recorded_by' => 't']);
        $med = Medicine::create(['code' => 'M', 'name' => 'Ivermectin', 'category' => 'dewormer', 'unit' => 'ml', 'cost_per_unit_minor' => 1000]);
        MedicineMovement::create(['medicine_id' => $med->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty' => 50, 'recorded_by' => 't']);
        $gen = StoreItem::create(['code' => 'G', 'name' => 'Lime', 'category' => 'cleaning', 'unit' => 'bag', 'cost_per_unit_minor' => 50000]);
        StoreMove::create(['store_item_id' => $gen->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty' => 10, 'recorded_by' => 't']);
        $on = now()->toDateString();

        $this->actingAs($ceo)->post('/inventory/count', ['kind' => 'feed', 'counted_on' => $on, 'counts' => [['id' => $feed->id, 'counted' => 92]]])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post('/inventory/count', ['kind' => 'medicine', 'counted_on' => $on, 'counts' => [['id' => $med->id, 'counted' => 50]]])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post('/inventory/count', ['kind' => 'store', 'counted_on' => $on, 'counts' => [['id' => $gen->id, 'counted' => 12]], 'notes' => 'Found in shed 2'])->assertSessionHasNoErrors();

        $this->assertSame(92.0, (float) Feed::stock(true)[$feed->id]);
        $this->assertSame(-8.0, FeedStockCount::firstOrFail()->diff_kg); // the feed count record is kept as before (D-192)
        $this->assertSame(0, MedicineMovement::where('type', 'adjustment')->count()); // nothing to post when the count matches
        $this->assertSame(12.0, Stores::generalQty()[$gen->id]);
        $this->assertStringContainsString('Found in shed 2', StoreMove::where('type', 'adjustment')->value('notes'));
        $this->actingAs($ceo)->post('/inventory/count', ['kind' => 'store', 'counted_on' => $on, 'counts' => [['id' => $gen->id, 'counted' => -1]]])->assertSessionHasErrors('counts.0.counted');
    }

    public function test_a_medicine_keeps_its_label_pack_and_vet_check_and_a_held_one_cannot_be_given(): void
    {
        $vet = $this->user('vet');
        $this->actingAs($vet)->post('/inventory/medicines', ['code' => 'dexafer', 'name' => 'Dexafer Injection', 'category' => 'steroid', 'unit' => 'ml', 'pack_unit' => 'vial', 'pack_size' => 50,
            'spec' => 'Possibly dexamethasone 2 mg/mL', 'use_case' => 'Potential steroid use', 'check_status' => 'hold', 'check_note' => 'Identity not established', 'opening_qty' => 100])->assertSessionHasNoErrors();
        $m = Medicine::firstOrFail();
        $this->assertSame(['vial', 50.0, 'hold', 'Possibly dexamethasone 2 mg/mL'], [$m->pack_unit, $m->pack_size, $m->check_status, $m->spec]);
        $this->assertSame('Vet consumables', Medicine::CATEGORIES['consumable']);

        try {
            MedicineStock::issue($m, 2, ['moved_on' => now()->toDateString(), 'recorded_by' => 'vet']);
            $this->fail('A held product was given.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('on hold', $e->errors()['medicine_id'][0]);
        }

        // the vet confirms the label; an edit that leaves out the details keeps them; the stock unit is fixed once stock moved
        $this->actingAs($vet)->post("/inventory/medicines/{$m->id}", ['name' => $m->name, 'category' => 'steroid', 'check_status' => 'confirmed', 'check_note' => null])->assertSessionHasNoErrors();
        $this->assertSame(['confirmed', 'vial', 'Possibly dexamethasone 2 mg/mL'], [$m->fresh()->check_status, $m->fresh()->pack_unit, $m->fresh()->spec]);
        $this->assertSame(0.0, MedicineStock::issue($m->fresh(), 2, ['moved_on' => now()->toDateString(), 'recorded_by' => 'vet']));
        $this->actingAs($vet)->post("/inventory/medicines/{$m->id}", ['name' => $m->name, 'category' => 'steroid', 'unit' => 'vial'])->assertSessionHasErrors('unit');
        $this->actingAs($vet)->getJson("/inventory/card?kind=medicine&id={$m->id}")->assertJsonPath('item.packSize', 50)->assertJsonPath('item.check', 'confirmed');
    }

    public function test_medicines_are_suggested_by_disease_and_care_and_preventive_care_takes_stock(): void
    {
        $vet = $this->user('vet');
        $pneu = \App\Models\Disease::create(['name' => 'Pneumonia', 'category' => 'respiratory']);
        $abx = Medicine::create(['code' => 'A', 'name' => 'Tygent', 'category' => 'antibiotic', 'unit' => 'ml']);
        $vit = Medicine::create(['code' => 'V', 'name' => 'Cyanocob', 'category' => 'vitamin', 'unit' => 'ml']);
        $worm = Medicine::create(['code' => 'W', 'name' => 'Fenzole', 'category' => 'dewormer', 'unit' => 'ml']);
        MedicineMovement::create(['medicine_id' => $worm->id, 'moved_on' => now()->toDateString(), 'type' => 'opening', 'qty' => 100, 'recorded_by' => 't']);

        // the vet sets the medicines used for a disease on the medicine; an edit without the field keeps the links
        $this->actingAs($vet)->post("/inventory/medicines/{$vit->id}", ['name' => 'Cyanocob', 'category' => 'vitamin', 'disease_ids' => [$pneu->id]])->assertSessionHasNoErrors();
        $this->actingAs($vet)->post("/inventory/medicines/{$vit->id}", ['name' => 'Cyanocob 250', 'category' => 'vitamin'])->assertSessionHasNoErrors();
        $this->assertSame([$pneu->id], $vit->fresh()->diseases->pluck('id')->all());

        $data = \App\Support\MedicineSuggest::data();
        $d = collect($data['diseases'])->firstWhere('id', $pneu->id);
        $this->assertSame([$vit->id], $d['set']);
        $this->assertSame('antibiotic', $data['byDisease']['respiratory'][0]); // Tygent fits a respiratory disease by its type
        $this->assertSame(['dewormer', 'ectoparasiticide'], $data['byCare']['deworming']);

        // preventive care with a store product takes the dose from stock
        $breed = \App\Models\Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $goat = \App\Models\Animal::create(['farm_number' => 'B-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased']);
        $this->actingAs($vet)->post('/health/preventive', ['animal_ids' => [$goat->id], 'kind' => 'deworming', 'medicine_id' => $worm->id, 'qty_per_goat' => 8, 'given_on' => now()->toDateString(), 'care_status' => 'administered'])->assertSessionHasNoErrors();
        $this->assertSame(92.0, (float) MedicineStock::quantities()[$worm->id]);
        $this->assertSame('Fenzole', \App\Models\HealthRecord::where('animal_id', $goat->id)->value('title'));
        $this->actingAs($vet)->get('/health')->assertInertia(fn (AssertableInertia $p) => $p->where('suggest.byCare.vaccination', ['vaccine']));
    }

    public function test_a_vet_confirms_a_held_product_with_one_click_and_it_can_then_be_given(): void
    {
        $m = Medicine::create(['code' => 'T', 'name' => 'Trioxyl LA', 'category' => 'antibiotic', 'unit' => 'ml', 'check_status' => 'hold', 'check_note' => 'Product identity to confirm']);
        $this->actingAs($this->user('feeding'))->post("/inventory/medicines/{$m->id}/confirm")->assertForbidden();
        $this->actingAs($this->user('vet'))->post("/inventory/medicines/{$m->id}/confirm")->assertSessionHasNoErrors();
        $m->refresh();
        $this->assertSame('confirmed', $m->check_status);
        $this->assertStringContainsString('Confirmed by Test vet on '.now()->format('d/m/Y').' (was On hold: Product identity to confirm)', $m->check_note);
        $this->assertSame(1.0, MedicineStock::issue($m, 1, ['moved_on' => now()->toDateString(), 'recorded_by' => 'vet'])); // given now (1 ml short: none in stock)
    }
    public function test_a_blank_code_is_made_from_the_name_and_never_clashes(): void // D-335
    {
        $ceo = $this->user('ceo');
        $this->assertSame('ALMOX-LA', \App\Support\ItemCode::fromName('Almox LA Injection'));
        $this->assertSame('ACTIBOL', \App\Support\ItemCode::fromName('Actibol Injection'));
        $this->actingAs($ceo)->post('/inventory/medicines', ['code' => '', 'name' => 'Actibol Injection', 'category' => 'vitamin', 'unit' => 'ml'])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post('/inventory/medicines', ['name' => 'Actibol Injection', 'category' => 'vitamin', 'unit' => 'ml'])->assertSessionHasNoErrors();
        $this->assertSame(['ACTIBOL', 'ACTIBOL-2'], \App\Models\Medicine::orderBy('id')->pluck('code')->all());
        $this->actingAs($ceo)->post('/inventory/feed-items', ['name' => 'Wheat straw', 'category' => 'roughage', 'cost_per_kg' => 30])->assertSessionHasNoErrors();
        $this->assertTrue(\App\Models\FeedItem::where('code', 'WHEAT-STRAW')->exists());
        $this->actingAs($ceo)->post('/inventory/items', ['name' => 'Gloves', 'category' => 'bedding', 'unit' => 'kg'])->assertSessionHasNoErrors();
        $this->assertTrue(\App\Models\StoreItem::where('code', 'GLOVES')->exists());
        $this->actingAs($ceo)->post('/inventory/medicines', ['code' => 'actibol', 'name' => 'Other', 'category' => 'vitamin', 'unit' => 'ml'])->assertSessionHasErrors('code');
    }
}
