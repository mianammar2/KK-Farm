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
        $this->actingAs($care)->post('/worker/start', ['job' => 'move', 'pen_id' => $p2->id]);
        $this->actingAs($care)->post('/worker/move', ['animal_id' => $goat->id])->assertRedirect('/worker');
        $this->assertSame($p2->id, $goat->fresh()->pen_id);

        // Finish: scanning is normal again.
        $this->actingAs($care)->post('/worker/stop');
        $this->actingAs($care)->get('/scan?tag=E280000000000001')->assertInertia(fn (AssertableInertia $p) => $p->component('Scan'));

        // A role without Weight & Growth cannot weigh.
        $feeder = User::create(['name' => 'Feeder', 'email' => 'f@gun.test', 'password' => 'x', 'role' => 'feeding', 'org_id' => Tenant::current()]);
        $this->actingAs($feeder)->post('/worker/start', ['job' => 'weigh'])->assertForbidden();
    }
}
