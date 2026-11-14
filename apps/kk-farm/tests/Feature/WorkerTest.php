<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidTag;
use App\Models\Shed;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-179: the worker app for the RFID gun: pick a job, then each scan opens that goat in the job. */
class WorkerTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_job_routes_every_scan_into_its_quick_form(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Gun Farm', 'slug' => 'gun-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal']);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $p1 = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $p2 = Pen::create(['shed_id' => $shed->id, 'name' => 'P02', 'purpose' => 'Does']);
        $goat = Animal::create(['farm_number' => 'TD-01', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $p1->id])->fresh();
        $care = User::create(['name' => 'Care', 'email' => 'c@gun.test', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current()]);
        Farm::assignTag($goat, RfidTag::create(['tag_code' => 'E280000000000001']), 'initial', $care);

        // Without a job, a scan opens the Scan page as before.
        $this->actingAs($care)->get('/scan?tag=E280000000000001')->assertInertia(fn (AssertableInertia $p) => $p->component('Scan'));
        // D-252: the health round is the vet's unless the administrator gives it to a person
        $this->actingAs($care)->get('/worker')->assertInertia(fn (AssertableInertia $p) => $p->component('Worker')->missing('jobs.health'));
        $this->actingAs($care)->post('/worker/start', ['job' => 'health'])->assertForbidden();
        $care->forceFill(['gate_access' => ['report_concern' => true]])->save();
        Tenant::flush();
        $care = $care->fresh();
        $this->actingAs($care)->get('/worker')->assertInertia(fn (AssertableInertia $p) => $p->component('Worker')->where('job', null)->has('jobs.weigh')->has('jobs.health')->has('jobs.move'));

        // Weigh: the scan goes straight to the goat; saving counts it and waits for the next scan.
        $this->actingAs($care)->post('/worker/start', ['job' => 'weigh'])->assertRedirect('/worker');
        $this->actingAs($care)->get('/scan?tag=E280000000000001')->assertRedirect('/worker?goat='.$goat->id);
        $this->actingAs($care)->get('/scan?tag=E2800000000000FF')->assertRedirect('/worker?unknown=E2800000000000FF');
        $this->actingAs($care)->get('/worker?goat='.$goat->id)->assertInertia(fn (AssertableInertia $p) => $p->where('goat.number', 'TD-01')->where('job.job', 'weigh'));
        $this->actingAs($care)->post('/worker/weigh', ['animal_id' => $goat->id, 'weight_kg' => 41.5, 'body_score' => 3])->assertRedirect('/worker');
        $this->assertEqualsWithDelta(41.5, (float) WeightRecord::where('animal_id', $goat->id)->value('weight_kg'), 0.01);
        $this->actingAs($care)->get('/worker')->assertInertia(fn (AssertableInertia $p) => $p->where('job.done', 1));

        // Health: a concern goes to the vet and puts the goat on Watch.
        $this->actingAs($care)->post('/worker/start', ['job' => 'health']);
        $this->actingAs($care)->post('/worker/health', ['animal_id' => $goat->id, 'fine' => false])->assertSessionHasErrors('title');
        $this->actingAs($care)->post('/worker/health', ['animal_id' => $goat->id, 'fine' => false, 'title' => 'Limping', 'severity' => 'medium'])->assertRedirect('/worker');
        $this->assertSame('Limping', HealthRecord::where('animal_id', $goat->id)->value('title'));
        $this->assertSame('watch', $goat->fresh()->health_status);

        // Move: the pen is chosen once; each scanned goat moves there.
        $this->actingAs($care)->post('/worker/start', ['job' => 'move'])->assertSessionHasErrors('pen_id');
        $this->actingAs($care)->post('/worker/start', ['job' => 'move', 'pen_id' => $p2->id])->assertSessionHasErrors(['reason' => 'Say why the goats are moved.']); // D-255
        $this->actingAs($care)->post('/worker/start', ['job' => 'move', 'pen_id' => $p2->id, 'reason' => 'Weaning', 'on' => now()->subDay()->toDateString()]);
        $this->actingAs($care)->post('/worker/move', ['animal_id' => $goat->id])->assertRedirect('/worker');
        $this->assertSame($p2->id, $goat->fresh()->pen_id);
        $m = \App\Models\AnimalMovement::where('animal_id', $goat->id)->latest('id')->first();
        $this->assertSame(['Weaning', now()->subDay()->toDateString()], [$m->reason, $m->moved_on->toDateString()], 'the round\'s reason and date');

        // D-242: the goat's number typed in the box finds the goat too (any case); an unknown number says so.
        $this->actingAs($care)->get('/scan?tag='.strtolower($goat->farm_number))->assertRedirect('/worker?goat='.$goat->id);
        $this->actingAs($care)->get('/scan?tag=NO-SUCH-GOAT')->assertRedirect('/worker?unknown=NO-SUCH-GOAT');

        // Finish: scanning is normal again.
        $this->actingAs($care)->post('/worker/stop');
        $this->actingAs($care)->get('/scan?tag=E280000000000001')->assertInertia(fn (AssertableInertia $p) => $p->component('Scan'));

        // A role without Weight & Growth cannot weigh.
        $feeder = User::create(['name' => 'Feeder', 'email' => 'f@gun.test', 'password' => 'x', 'role' => 'feeding', 'org_id' => Tenant::current()]);
        $this->actingAs($feeder)->post('/worker/start', ['job' => 'weigh'])->assertForbidden();
    }

    /** D-255: the new field jobs: each needs its right, asks its date first, saves through the app's own records and counts the goat. */
    public function test_new_field_jobs_follow_their_rights_and_the_rounds_date(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Jobs Farm', 'slug' => 'jobs-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal']);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $doe = Animal::create(['farm_number' => 'D-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $buck = Animal::create(['farm_number' => 'B-1', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $mk = fn ($role) => User::create(['name' => "T $role", 'email' => "$role@jobs.test", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        [$care, $vet] = [$mk('caretaker'), $mk('vet')];

        $jobs = fn ($u) => array_keys($this->actingAs($u)->get('/worker')->viewData('page')['props']['jobs']);
        $this->assertEqualsCanonicalizing(['weigh', 'heat', 'mating', 'milk', 'move', 'lookup'], $jobs($care));
        $this->assertEqualsCanonicalizing(['temp', 'health', 'heat', 'mating', 'care', 'teeth', 'height', 'move', 'lookup'], $jobs($vet)); // D-262: teeth and height
        $this->actingAs($care)->post('/worker/start', ['job' => 'care', 'care_kind' => 'vaccination', 'care_title' => 'PPR'])->assertForbidden();
        $this->actingAs($care)->post('/breeding/heat', ['dam_id' => $doe->id, 'observed_on' => now()->toDateString(), 'intensity' => 'strong'])->assertSessionHasNoErrors();

        // the vet's mating round, yesterday, with this buck
        $day = now()->subDay()->toDateString();
        $this->actingAs($vet)->post('/worker/start', ['job' => 'mating', 'method' => 'natural', 'sire_id' => $buck->id, 'on' => $day])->assertRedirect('/worker');
        $this->actingAs($vet)->get('/worker')->assertInertia(fn ($p) => $p->where('job.opts.sire', 'B-1')->where('job.opts.on', $day));
        $this->actingAs($vet)->post('/breeding/matings', ['dam_id' => $doe->id, 'sire_id' => $buck->id, 'mated_on' => $day, 'method' => 'natural'])->assertSessionHasNoErrors();
        $this->actingAs($vet)->post('/worker/done')->assertRedirect('/worker');
        $this->actingAs($vet)->get('/worker')->assertInertia(fn ($p) => $p->where('job.done', 1));
        $this->assertSame($day, \App\Models\Mating::where('dam_id', $doe->id)->value('mated_on')->toDateString());

        // a temp check round: the vet only
        $this->actingAs($vet)->post('/worker/start', ['job' => 'temp', 'on' => now()->toDateString()])->assertRedirect('/worker');
        $this->actingAs($care)->post('/worker/start', ['job' => 'temp'])->assertForbidden();
        $this->actingAs($vet)->post('/worker/start', ['job' => 'weigh', 'on' => now()->addDay()->toDateString()])->assertSessionHasErrors('on'); // not in the future
    }

    /** D-256: the Administrator has every job by default. */
    public function test_the_administrator_has_every_job(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Admin Farm', 'slug' => 'admin-farm'])->id);
        $owner = User::create(['name' => 'Owner', 'email' => 'o@admin-jobs.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => Tenant::current()]);
        $jobs = array_keys($this->actingAs($owner)->get('/worker')->viewData('page')['props']['jobs']);
        $this->assertEqualsCanonicalizing(array_keys(\App\Http\Controllers\WorkerController::JOBS), $jobs);
    }
}
