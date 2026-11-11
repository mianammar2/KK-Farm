<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FeedItem;
use App\Models\FeedLot;
use App\Models\HealthCase;
use App\Models\Medicine;
use App\Models\MedicineMovement;
use App\Models\Organization;
use App\Models\TmrFormula;
use App\Models\TmrFormulaLine;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Feed;
use App\Support\Tenant;
use App\Support\Tmr;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-209: antimicrobial use report; feed safety lots held until a second person releases them (BR-016, UAT-17). */
class AmuFeedLotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Safe Farm', 'slug' => 'safe-farm'])->id);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@safe.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_antibiotic_use_is_reported_with_untraced_doses_and_critically_important_drugs(): void
    {
        $g = Animal::create(['farm_number' => 'A-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'status' => 'active']);
        $oxy = Medicine::create(['code' => 'OXY', 'name' => 'Oxytetracycline', 'category' => 'antibiotic', 'unit' => 'ml']);
        $vit = Medicine::create(['code' => 'VIT', 'name' => 'Vitamin AD3E', 'category' => 'vitamin', 'unit' => 'ml']);
        $case = HealthCase::create(['case_no' => 1, 'animal_id' => $g->id, 'onset_on' => now(), 'condition' => 'Pneumonia', 'severity' => 'moderate', 'status' => 'under_treatment', 'recorded_by' => 'x']);
        $treat = fn (Medicine $m, ?string $batch) => tap(Treatment::create(['health_case_id' => $case->id, 'animal_id' => $g->id, 'given_on' => now(), 'product' => $m->name, 'dose' => '5 ml', 'route' => 'injectable', 'medicine_id' => $m->id, 'qty_used' => 5, 'recorded_by' => 'Vet']),
            fn ($t) => MedicineMovement::create(['medicine_id' => $m->id, 'moved_on' => now(), 'type' => 'issue', 'qty' => -5, 'batch' => $batch, 'treatment_id' => $t->id, 'recorded_by' => 'Vet']));
        $treat($oxy, 'OX-22');
        $treat($oxy, null);
        $treat($vit, 'V1');

        $this->actingAs($this->user('caretaker'))->post("/health/antimicrobials/{$oxy->id}", ['critically_important' => true])->assertForbidden();
        $this->actingAs($this->user('vet'))->post("/health/antimicrobials/{$oxy->id}", ['critically_important' => true])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->get('/health/antimicrobials')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('kpi.courses', 2)->where('kpi.goats', 1)->where('kpi.cia', 2)->where('kpi.noBatch', 1)->where('kpi.per100', 200)
            ->where('byMedicine.0.name', 'Oxytetracycline')->where('byCondition.0.name', 'Pneumonia'));
    }

    public function test_a_lot_needing_a_safety_test_cannot_be_fed_or_mixed_until_a_second_person_releases_it(): void
    {
        $maize = FeedItem::create(['code' => 'MZ', 'name' => 'Maize grain', 'category' => 'concentrate', 'cost_per_kg_minor' => 8000]);
        $this->actingAs($this->user('manager'))->post("/feeding/lots/items/{$maize->id}", ['safety_test' => true])->assertSessionHasNoErrors();
        $buy = fn () => $this->actingAs($this->user('manager'))->post('/feeding/stock', ['feed_item_id' => $maize->id, 'type' => 'purchase', 'qty_kg' => 100, 'rate' => 80, 'moved_on' => now()->toDateString(), 'party' => 'Mandi']);
        $buy()->assertSessionHasNoErrors();
        $lot = FeedLot::sole();
        $this->assertSame(['quarantined', 0.0], [$lot->status, Feed::usable(true)[$maize->id]]);

        $formula = TmrFormula::create(['feed_item_id' => FeedItem::create(['code' => 'TMR', 'name' => 'TMR', 'category' => 'tmr'])->id, 'name' => 'TMR', 'active' => true, 'created_by' => 'x']);
        TmrFormulaLine::create(['tmr_formula_id' => $formula->id, 'feed_item_id' => $maize->id, 'pct' => 100]);
        try {
            Tmr::mix($formula, 50, now()->toDateString(), $this->user('feeding'));
            $this->fail('A quarantined lot was mixed.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($lot->lot_no, $e->getMessage().json_encode($e->errors()));
        }

        $decide = fn ($role, $d, $note = null) => $this->actingAs($this->user($role))->post("/feeding/lots/{$lot->id}/decide", ['decision' => $d, 'note' => $note]);
        $decide('vet', 'released')->assertSessionHasErrors('decision'); // not tested yet
        $this->actingAs($this->user('feeding'))->post("/feeding/lots/{$lot->id}/test", ['moisture_pct' => 16, 'aflatoxin_ppb' => 5])->assertSessionHasNoErrors();
        $decide('vet', 'released')->assertSessionHasErrors('decision'); // moisture above 14 %
        $this->actingAs($this->user('feeding'))->post("/feeding/lots/{$lot->id}/test", ['moisture_pct' => 12, 'aflatoxin_ppb' => 5])->assertSessionHasNoErrors();
        $decide('manager', 'released')->assertSessionHasErrors('decision'); // the receiver cannot release
        $decide('feeding', 'released')->assertForbidden();
        $decide('vet', 'released')->assertSessionHasNoErrors();
        Tmr::mix($formula, 50, now()->toDateString(), $this->user('feeding'));

        $buy()->assertSessionHasNoErrors();
        $second = FeedLot::where('status', 'quarantined')->sole();
        $this->actingAs($this->user('vet'))->post("/feeding/lots/{$second->id}/decide", ['decision' => 'rejected', 'note' => 'Mouldy, smells musty'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $second->fresh()->status);
        $this->assertEquals(50.0, Feed::stock(true)[$maize->id]); // 100 + 100 - 50 mixed - 100 written off
        $this->actingAs($this->user('manager'))->get('/feeding/lots')->assertOk();
    }
}
