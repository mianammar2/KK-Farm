<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\LedgerEntry;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WasteDisposal;
use App\Models\WasteStream;
use App\Support\Movements;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-204: journey O Waste-to-Safe-Disposal (V3 flow 38). */
class WasteDisposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_routes_need_an_adviser_biowaste_needs_controls_carcasses_are_tracked_and_disposals_audited(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Clean Farm', 'slug' => 'clean-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $goat = Animal::create(['farm_number' => 'C-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'birth_date' => now()->subYears(4), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $u = fn (string $role) => User::firstOrCreate(['email' => "$role@clean.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        [$care, $manager, $vet] = [$u('caretaker'), $u('manager'), $u('vet')];

        // A death raises a carcass disposal task.
        Movements::exit($goat, 'death', $manager, ['reason' => 'Pneumonia']);
        $this->assertTrue(Task::where('title', 'Dispose carcass: C-1')->where('status', 'open')->exists());

        // Routes: only the owner, manager or vet records them; without an adviser a route cannot be used.
        $this->actingAs($care)->post('/waste/streams', ['kind' => 'carcass', 'name' => 'Carcass pit', 'method' => 'burial'])->assertForbidden();
        $this->actingAs($vet)->post('/waste/streams', ['kind' => 'carcass', 'name' => 'Carcass pit', 'method' => 'burial'])->assertSessionHasNoErrors();
        $pit = WasteStream::sole();
        $carcass = ['stream_id' => $pit->id, 'disposed_on' => now()->toDateString(), 'method' => 'burial', 'animal_id' => $goat->id, 'qty' => 1, 'unit' => 'count'];
        $this->actingAs($care)->post('/waste/disposals', $carcass + ['ppe_used' => true, 'area_disinfected' => true])->assertSessionHasErrors('stream_id');
        $this->actingAs($vet)->post("/waste/streams/{$pit->id}", ['kind' => 'carcass', 'name' => 'Carcass pit', 'method' => 'burial', 'adviser' => 'Dr. Saima', 'adviser_role' => 'District livestock officer',
            'advised_on' => now()->subMonth()->toDateString(), 'instructions' => 'Deep pit, quicklime, 1 m soil cover, away from water'])->assertSessionHasNoErrors();

        // Biological waste needs protection and disinfection; a different method needs a reason.
        $this->actingAs($care)->post('/waste/disposals', $carcass)->assertSessionHasErrors('ppe_used');
        $this->actingAs($care)->post('/waste/disposals', ['method' => 'incineration'] + $carcass + ['ppe_used' => true, 'area_disinfected' => true])->assertSessionHasErrors('deviation_reason');
        $this->actingAs($care)->post('/waste/disposals', $carcass + ['ppe_used' => true, 'area_disinfected' => true, 'evidence_ref' => 'PIT-3'])->assertSessionHasNoErrors();
        $this->actingAs($care)->post('/waste/disposals', $carcass + ['ppe_used' => true, 'area_disinfected' => true])->assertSessionHasErrors('animal_id'); // only once
        $this->assertSame('completed', Task::where('title', 'Dispose carcass: C-1')->value('status'));

        // A contractor route with a weekly pickup: manifest required, cost posted once, gaps count as missed pickups.
        $this->actingAs($manager)->post('/waste/streams', ['kind' => 'sharps', 'name' => 'Sharps', 'method' => 'sharps_box', 'contractor' => 'MediWaste Pvt', 'licence_no' => 'EPA-77',
            'pickup_every_days' => 7, 'adviser' => 'Dr. Saima', 'adviser_role' => 'District livestock officer', 'advised_on' => now()->subMonth()->toDateString()])->assertSessionHasNoErrors();
        $sharps = WasteStream::where('kind', 'sharps')->sole();
        $box = ['stream_id' => $sharps->id, 'method' => 'sharps_box', 'ppe_used' => true, 'area_disinfected' => true, 'qty' => 1, 'unit' => 'count'];
        $this->actingAs($care)->post('/waste/disposals', $box + ['disposed_on' => now()->subDays(30)->toDateString()])->assertSessionHasErrors('evidence_ref');
        $this->actingAs($care)->post('/waste/disposals', $box + ['disposed_on' => now()->subDays(30)->toDateString(), 'evidence_ref' => 'MW-101', 'cost' => 1500])->assertSessionHasNoErrors();
        $this->actingAs($care)->post('/waste/disposals', $box + ['disposed_on' => now()->subDays(9)->toDateString(), 'evidence_ref' => 'MW-130', 'cost' => 1500])->assertSessionHasNoErrors();
        $this->assertSame(3000.0, (float) LedgerEntry::where('category', 'Waste Disposal')->sum('amount'));

        // Audit by someone other than the recorder.
        $d = WasteDisposal::where('evidence_ref', 'PIT-3')->sole();
        $this->actingAs($care)->post("/waste/disposals/{$d->id}/audit")->assertForbidden();
        $this->actingAs($manager)->post("/waste/disposals/{$d->id}/audit", ['note' => 'Pit checked'])->assertSessionHasNoErrors();

        // 30 → 9 days ago is 2 missed weekly pickups; 9 days since the last is 1 more, and the route is overdue.
        $this->actingAs($manager)->get('/waste')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('kpi.missedPickups', 3)->where('kpi.carcassesPending', 0)->where('kpi.audited', 33)->where('streams.1.overdue', true));
    }
}
