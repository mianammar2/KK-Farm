<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthCase;
use App\Models\LedgerEntry;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\ReproLossCase;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Support\Biosecurity;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-206: journey Q Reproductive Loss Investigation (V3 flow 40). */
class ReproLossTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_abortion_is_contained_handled_by_trained_staff_diagnosed_by_the_vet_and_holds_breeding(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Loss Farm', 'slug' => 'loss-farm'])->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $iso = Pen::create(['shed_id' => $shed->id, 'name' => 'ISO', 'purpose' => 'Isolation']);
        $breed = Breed::create(['name' => 'Teddy', 'default_gestation_days' => 150]);
        $buck = Animal::create(['farm_number' => 'B-1', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $does = [];
        foreach (['L-1', 'L-2'] as $tag) {
            $does[$tag] = Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        }
        $u = fn (string $role) => User::firstOrCreate(['email' => "$role@loss.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        [$care, $manager, $vet] = [$u('caretaker'), $u('manager'), $u('vet')];
        $abort = function (Animal $doe, int $daysAgo) use ($buck, $vet) {
            $m = Mating::create(['dam_id' => $doe->id, 'sire_id' => $buck->id, 'mated_on' => now()->subDays(100 + $daysAgo), 'expected_kidding_on' => now()->addDays(50), 'status' => 'pregnant', 'recorded_by' => 'Test']);
            $this->actingAs($vet)->post("/breeding/matings/{$m->id}/kidding", ['outcome' => 'ABT', 'kidded_on' => now()->subDays($daysAgo)->toDateString(), 'kids' => [], 'still_male' => 0, 'still_female' => 0])->assertSessionHasNoErrors();
        };

        // An abortion opens an investigation; a second in the same pen within 30 days is a cluster.
        $abort($does['L-1'], 10);
        $case = ReproLossCase::sole();
        $this->assertSame(['abortion', $pen->id, $buck->id, 'open', false], [$case->loss_type, $case->pen_id, $case->sire_id, $case->status, $case->cluster]);
        $abort($does['L-2'], 0);
        $this->assertTrue($case->fresh()->cluster);
        $this->assertTrue(Task::where('title', 'like', 'Reproductive loss cluster%')->where('priority', 'critical')->exists());

        // She cannot be mated while her loss is under investigation.
        $this->actingAs($manager)->post('/breeding/matings', ['dam_id' => $does['L-1']->id, 'sire_id' => $buck->id, 'mated_on' => now()->toDateString(), 'method' => 'natural'])->assertSessionHasErrors('dam_id');

        // Containment: isolate. Material: only the vet or trained staff, with protection.
        $this->actingAs($vet)->post("/repro-loss/{$case->id}/contain", ['pen_id' => $iso->id])->assertSessionHasNoErrors(); // D-266 F-05: containment is for the vet or trained staff
        $this->assertSame($iso->id, $does['L-1']->fresh()->pen_id);
        $this->actingAs($care)->post("/repro-loss/{$case->id}/material", ['ppe_used' => true, 'note' => 'x'])->assertForbidden();
        $this->actingAs($care)->post("/repro-loss/{$case->id}/sample", ['samples' => 'x', 'lab' => 'x', 'sampled_on' => now()->toDateString()])->assertForbidden();
        $this->actingAs($manager)->post("/repro-loss/staff/{$care->id}/train", ['trained' => true])->assertSessionHasNoErrors();
        $care->refresh();
        $this->actingAs($care)->post("/repro-loss/{$case->id}/material", ['ppe_used' => false, 'note' => 'Fetus bagged'])->assertSessionHasErrors('ppe_used');
        $this->actingAs($care)->post("/repro-loss/{$case->id}/material", ['ppe_used' => true, 'note' => 'Fetus and placenta double-bagged, kept cold; bedding burnt'])->assertSessionHasNoErrors();
        $this->actingAs($care)->post("/repro-loss/{$case->id}/sample", ['samples' => 'Fetus, placenta, blood', 'lab' => 'UVAS Lahore', 'sampled_on' => now()->toDateString(), 'cost' => 4500])->assertSessionHasNoErrors();
        $this->assertSame(4500.0, (float) LedgerEntry::where('category', 'Veterinary')->sole()->amount);

        // Only the vet diagnoses. A suspected zoonotic cause restricts the pen through a contagious vet case.
        $this->actingAs($care)->post("/repro-loss/{$case->id}/diagnose", ['diagnosis' => 'Brucella'])->assertForbidden();
        $this->actingAs($vet)->post("/repro-loss/{$case->id}/diagnose", ['lab_result' => 'Brucella antibodies positive', 'diagnosis' => 'Suspected brucellosis', 'zoonotic_suspected' => true])->assertSessionHasNoErrors();
        $hc = HealthCase::where('condition', 'Suspected infectious abortion')->sole();
        $this->assertSame(['suspected', $pen->id], [$hc->contagion, $hc->restricted_pen_id]);
        $this->assertArrayHasKey($pen->id, Biosecurity::restrictedPens());
        $this->assertTrue(Task::where('title', 'like', 'Zoonotic risk%')->exists());

        // The vet's breeding decision: do not breed; mating stays refused after closing.
        $this->actingAs($vet)->post("/repro-loss/{$case->id}/close", ['breeding' => 'do_not_breed', 'prevention' => 'Test the herd; cull positives; vaccinate replacements'])->assertSessionHasNoErrors();
        $this->assertSame('closed', $case->fresh()->status);
        $this->actingAs($manager)->post('/breeding/matings', ['dam_id' => $does['L-1']->id, 'sire_id' => $buck->id, 'mated_on' => now()->toDateString(), 'method' => 'natural'])->assertSessionHasErrors('dam_id');

        $this->actingAs($manager)->get('/repro-loss')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('kpi.incidence', 100)->where('kpi.clusters', 1)->where('kpi.open', 1)->where('kpi.bySire.0.losses', 2));
    }
}
