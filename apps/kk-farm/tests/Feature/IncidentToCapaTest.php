<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Incident;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\QualityCapa;
use App\Models\QualityCase;
use App\Models\Shed;
use App\Models\Sop;
use App\Models\SopRevision;
use App\Models\User;
use App\Support\Tenant;
use App\Support\WelfareCare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-205: journey P Incident-to-Corrective Action (V3 flow 39). */
class IncidentToCapaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_repeat_incident_goes_through_capa_and_a_revised_sop_and_a_hold_overrides_normal_work(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Capa Farm', 'slug' => 'capa-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $doe = Animal::create(['farm_number' => 'Q-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $u = fn (string $role) => User::firstOrCreate(['email' => "$role@capa.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        [$care, $manager, $vet] = [$u('caretaker'), $u('manager'), $u('vet')];
        $sop = Sop::create(['code' => 'BIO-02', 'title' => 'Night gate and fence check', 'area' => 'biosecurity', 'version' => 1, 'review_on' => now()->addYear()]);
        SopRevision::create(['sop_id' => $sop->id, 'version' => 1, 'body' => 'Check the gate at 9 PM.', 'change_note' => 'First issue', 'written_by' => 'x', 'approved_by' => 'y', 'approved_at' => now()]);

        // A medium dog attack: recorded against SOP v1, stays in the register.
        $report = fn (array $x) => $this->actingAs($care)->post('/operations/incidents', $x + ['occurred_at' => now()->subHours(2)->format('Y-m-d H:i'), 'kind' => 'predator', 'severity' => 'medium', 'pen_id' => $pen->id,
            'description' => 'Stray dog entered P01', 'sop_id' => $sop->id]);
        $report([])->assertSessionHasNoErrors();
        $first = Incident::sole();
        $this->assertSame(['open', 'animal_safety', 1, null], [$first->status, $first->category, $first->sop_version, $first->quality_case_id]);

        // The same at the same pen again: a repeat, sent to a quality case.
        $report(['occurred_at' => now()->subHour()->format('Y-m-d H:i')])->assertSessionHasNoErrors();
        $repeat = Incident::latest('id')->first();
        $this->assertSame([$first->id, 'escalated'], [$repeat->recurrence_of_id, $repeat->status]);
        $case = QualityCase::findOrFail($repeat->quality_case_id);
        $this->assertSame(['incident', $repeat->id], [$case->source, $case->incident_id]);
        $this->actingAs($manager)->post("/operations/incidents/{$repeat->id}/resolve", ['resolution' => 'x', 'root_cause' => 'y'])->assertStatus(422);

        // A critical product-quality incident on a goat is contained at once; the hold overrides normal work.
        $this->actingAs($care)->post('/operations/incidents', ['occurred_at' => now()->format('Y-m-d H:i'), 'kind' => 'product_quality', 'severity' => 'critical', 'animal_id' => $doe->id,
            'description' => 'Wrong drug given', 'containment' => 'isolate'])->assertForbidden();
        $this->actingAs($vet)->post('/operations/incidents', ['occurred_at' => now()->format('Y-m-d H:i'), 'kind' => 'product_quality', 'severity' => 'critical', 'animal_id' => $doe->id, 'description' => 'Wrong drug given'])->assertSessionHasNoErrors();
        try {
            WelfareCare::assertRoutineAllowed($doe->fresh());
            $this->fail('A goat under a quality hold was handled for routine work.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('isolate', $e->getMessage());
        }

        // Root cause → CAPA → independent verification → SOP revised → close: the incidents close with the learning and v2.
        $this->actingAs($manager)->post("/quality/cases/{$case->id}/investigate", ['root_cause' => 'Gate latch broken; night check skipped', 'evidence' => 'Latch photo, check sheet blank'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/quality/cases/{$case->id}/capa", ['kind' => 'preventive', 'action' => 'Replace latch; add 11 PM fence round', 'owner_id' => $u('ceo')->id, 'due_on' => now()->addDays(3)->toDateString()])->assertSessionHasNoErrors();
        $capa = QualityCapa::sole();
        $this->actingAs($manager)->post("/quality/capas/{$capa->id}/approve")->assertForbidden(); // the planner
        $this->actingAs($vet)->post("/quality/capas/{$capa->id}/approve")->assertSessionHasNoErrors();
        $this->actingAs($u('ceo'))->post("/quality/capas/{$capa->id}/execute", ['notes' => 'Latch replaced'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/quality/capas/{$capa->id}/verify", ['pass' => true, 'notes' => 'Latch holds; round signed 3 nights'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/quality/sops/{$sop->id}/revise", ['body' => 'Check the gate at 9 PM and 11 PM; test the latch.', 'change_note' => 'After the dog attacks', 'case_id' => $case->id])->assertSessionHasNoErrors();
        $this->actingAs($vet)->post('/quality/sop-revisions/'.SopRevision::where('version', 2)->value('id').'/approve')->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/quality/cases/{$case->id}/close", ['learning' => 'Two-round night check with latch test'])->assertSessionHasNoErrors();

        $repeat->refresh();
        $this->assertSame(['resolved', 2, 'Gate latch broken; night check skipped'], [$repeat->status, $repeat->sop_version_after, $repeat->root_cause]);
        $this->assertStringContainsString('Two-round night check', $repeat->resolution);
        $this->actingAs($manager)->get('/operations?tab=incidents')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('incidentKpi.capaClosure', 100)->where('incidentKpi.repeats', 1)->where('incidentKpi.escalatedOpen', 1));
    }
}
