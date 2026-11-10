<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\KidCareRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Support\KidCare;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-176: newborn kid care: colostrum first, nursing checks, a kid not suckling alerts the vet, fostering. */
class KidCareTest extends TestCase
{
    use RefreshDatabase;

    public function test_newborns_need_colostrum_and_a_kid_not_suckling_raises_a_critical_task(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Kid Farm', 'slug' => 'kid-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal']);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Kids']);
        $mk = fn ($n, $sex, $days, $extra = []) => Animal::create($extra + ['farm_number' => $n, 'sex' => $sex, 'breed_id' => $breed->id, 'birth_date' => now()->subDays($days)->toDateString(), 'origin' => 'farm_born', 'pen_id' => $pen->id]);
        $dam = $mk('D-1', 'female', 900, ['origin' => 'purchased']);
        $foster = $mk('D-2', 'female', 900, ['origin' => 'purchased']);
        $kid = $mk('K-1', 'female', 1, ['dam_id' => $dam->id]);
        $mk('K-OLD', 'male', 30, ['dam_id' => $dam->id]);
        $vet = User::create(['name' => 'Vet', 'email' => 'v@kid.test', 'password' => 'x', 'role' => 'vet', 'org_id' => Tenant::current()]);

        $board = collect(KidCare::board())->keyBy('number');
        $this->assertTrue($board['K-1']['colostrumDue']);
        $this->assertFalse($board->has('K-OLD'), 'only kids up to 14 days old');

        $this->actingAs($vet)->post('/breeding/kid-care', ['kid_id' => $kid->id, 'kind' => 'colostrum', 'given_at' => now()->subHour()->format('Y-m-d H:i'), 'amount_ml' => 200, 'source' => 'dam'])->assertSessionHasNoErrors();
        $board = collect(KidCare::board())->keyBy('number');
        $this->assertFalse($board['K-1']['colostrumDue']);
        $this->assertNotNull($board['K-1']['colostrum']);

        $this->actingAs($vet)->post('/breeding/kid-care', ['kid_id' => $kid->id, 'kind' => 'nursing_check', 'given_at' => now()->format('Y-m-d H:i')])->assertSessionHasErrors('suckling');
        $this->actingAs($vet)->post('/breeding/kid-care', ['kid_id' => $kid->id, 'kind' => 'nursing_check', 'given_at' => now()->format('Y-m-d H:i'), 'suckling' => 'none'])->assertSessionHasNoErrors();
        $this->assertTrue(collect(KidCare::board())->keyBy('number')['K-1']['weak']);
        $this->assertSame('critical', Task::where('animal_id', $kid->id)->value('priority'));

        $this->actingAs($vet)->post('/breeding/kid-care', ['kid_id' => $kid->id, 'kind' => 'fostered', 'given_at' => now()->format('Y-m-d H:i'), 'foster_dam_id' => $foster->id])->assertSessionHasNoErrors();
        $this->assertSame('orphan', $kid->fresh()->kid_stage);
        $this->assertSame('D-2', collect(KidCare::board())->keyBy('number')['K-1']['fosteredTo']);
        $this->assertSame(3, KidCareRecord::where('kid_id', $kid->id)->count());

        $this->actingAs($vet)->get('/breeding?tab=kidcare')->assertInertia(fn (AssertableInertia $p) => $p->where('kidCare.0.number', 'K-1')->has('kidCareOptions.kinds'));
        $this->actingAs($vet)->post('/breeding/kid-care', ['kid_id' => $kid->id, 'kind' => 'colostrum', 'given_at' => now()->addDay()->format('Y-m-d H:i')])->assertSessionHasErrors('given_at');
    }
}
