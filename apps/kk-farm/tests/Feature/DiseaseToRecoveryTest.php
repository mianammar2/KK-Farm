<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Disease;
use App\Models\HealthCase;
use App\Models\HealthRecord;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Outbreak;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Movements;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-193: V3 journey D Disease-to-Recovery: triage, isolation and alert, pen restriction until cleared, lab, clearance, case cost, outbreak. */
class DiseaseToRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Pen $pen;

    private Pen $iso;

    private Pen $other;

    private Animal $sick;

    private Animal $mate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Vet Farm', 'slug' => 'vet-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal']);
        $this->pen = Pen::create(['shed_id' => Shed::create(['code' => 'GDU1', 'name' => 'GDU', 'type' => 'GDU'])->id, 'name' => 'P01', 'purpose' => 'Growers']);
        $this->other = Pen::create(['shed_id' => $this->pen->shed_id, 'name' => 'P02', 'purpose' => 'Growers']);
        $this->iso = Pen::create(['shed_id' => Shed::create(['code' => 'VCU1', 'name' => 'VCU', 'type' => 'VCU'])->id, 'name' => 'P01', 'purpose' => 'Isolation']);
        $mk = fn ($tag) => Animal::create(['farm_number' => $tag, 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'born_on_farm', 'pen_id' => $this->pen->id, 'status' => 'active']);
        $this->sick = $mk('D-1');
        $this->mate = $mk('D-2');
        Disease::create(['name' => 'PPR (Peste des petits ruminants)', 'category' => 'infectious', 'contagious' => true, 'notifiable' => true]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@vet.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_high_risk_triage_isolates_alerts_and_restricts_the_pen_until_the_vet_clears_it(): void
    {
        $report = HealthRecord::create(['animal_id' => $this->sick->id, 'kind' => 'observation', 'occurred_on' => now(), 'title' => 'Nasal discharge, fever', 'severity' => 'high', 'recorded_by' => 'Ali', 'recorded_role' => 'Caretaker', 'status' => 'open']);
        $triage = ['animal_id' => $this->sick->id, 'report_id' => $report->id, 'condition' => 'PPR (Peste des petits ruminants)', 'severity' => 'high', 'contagion' => 'suspected',
            'isolate_pen_id' => $this->iso->id, 'restrict_pen' => true, 'declare_outbreak' => true];
        $this->actingAs($this->user('caretaker'))->post('/disease/triage', $triage)->assertForbidden();
        $this->actingAs($this->user('vet'))->post('/disease/triage', $triage)->assertSessionHasNoErrors();

        $case = HealthCase::sole();
        $this->assertSame([true, $this->pen->id, $this->iso->id, 'closed'], [$case->emergency, $case->restricted_pen_id, $this->sick->fresh()->pen_id, $report->fresh()->status]);
        $this->assertSame(2, Task::where('title', 'like', 'EMERGENCY HC-0001%')->where('priority', 'critical')->count());
        $this->assertTrue(Task::where('title', 'like', 'Notify the Livestock Department%')->exists());
        $this->assertSame('OB-'.now()->format('Y').'-01', Outbreak::sole()->code);

        // Restricted lot: pen mates neither move nor sell; the vet still can move them.
        foreach ([fn () => Movements::transfer($this->mate, $this->other, $this->user('manager')), fn () => Movements::exit($this->mate, 'sale', $this->user('ceo'), ['amount' => 40000])] as $try) {
            try {
                $try();
                $this->fail('A goat of a restricted pen moved.');
            } catch (ValidationException $e) {
                $this->assertStringContainsString('restriction', $e->getMessage());
            }
        }

        $this->actingAs($this->user('vet'))->post("/disease/cases/{$case->id}/lab", ['lab_test' => 'PPR PCR', 'lab_sampled_on' => now()->toDateString(), 'lab_cost' => 3500, 'contagion' => 'confirmed'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->post("/disease/cases/{$case->id}/lab", ['lab_test' => 'PPR PCR', 'lab_sampled_on' => now()->toDateString(), 'lab_result' => 'Positive'])->assertSessionHasNoErrors();
        $this->assertSame(1, LedgerEntry::where('category', 'Veterinary')->count()); // posted once
        Treatment::create(['health_case_id' => $case->id, 'animal_id' => $this->sick->id, 'given_on' => now(), 'product' => 'Oxytet', 'dose' => '5 ml', 'route' => 'injectable', 'cost_minor' => 120000, 'meat_clear_on' => now()->addDays(28), 'recorded_by' => 'x']);

        $this->actingAs($this->user('vet'))->post("/disease/cases/{$case->id}/extend", ['review_on' => now()->addDays(4)->toDateString(), 'notes' => 'Add supportive fluids'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->post("/disease/cases/{$case->id}/clear", ['to_pen_id' => $this->pen->id, 'labour_hours' => 6])->assertSessionHasNoErrors();
        $this->assertSame(['recovered', $this->pen->id], [$case->fresh()->status, $this->sick->fresh()->pen_id]);
        Movements::transfer($this->mate, $this->other, $this->user('manager')); // restriction lifted
        $this->assertSame($this->other->id, $this->mate->fresh()->pen_id);

        $this->assertSame(['medicine' => 120000, 'lab' => 350000, 'labour' => 150000, 'total' => 620000], $case->fresh()->load('treatments')->costMinor(25000));
        $this->actingAs($this->user('vet'))->get('/disease')->assertInertia(fn (AssertableInertia $p) => $p->component('Disease')->where('kpi.caseCost', 6200)->where('outbreaks.0.cases', 1)->where('outbreaks.0.recovered', 1));
        $this->actingAs($this->user('vet'))->post('/disease/outbreaks/'.Outbreak::sole()->id.'/close', ['report' => 'Single case from a market visitor; vaccinated the herd.'])->assertSessionHasNoErrors();
        $this->assertSame('closed', Outbreak::sole()->status);
    }

    public function test_routine_triage_opens_a_case_without_isolation_or_restriction(): void
    {
        $this->actingAs($this->user('vet'))->post('/disease/triage', ['animal_id' => $this->sick->id, 'condition' => 'Foot rot / lameness', 'severity' => 'moderate', 'contagion' => 'none'])->assertSessionHasNoErrors();
        $case = HealthCase::sole();
        $this->assertSame([false, null, null], [$case->emergency, $case->restricted_pen_id, $case->outbreak_id]);
        Movements::transfer($this->mate, $this->other, $this->user('manager'));
        $this->assertSame($this->other->id, $this->mate->fresh()->pen_id);
    }
}
