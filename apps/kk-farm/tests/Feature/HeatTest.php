<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HeatRecord;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\HeatBoard;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-175: heat detection and the does ready for mating. */
class HeatTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_recorded_heat_makes_the_doe_ready_for_mating_and_predicts_the_next(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Heat Farm', 'slug' => 'heat-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal']);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $mk = fn ($n, $sex, $age) => Animal::create(['farm_number' => $n, 'sex' => $sex, 'breed_id' => $breed->id, 'birth_date' => now()->subDays($age), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $doe = $mk('D-1', 'female', 700);
        $old = $mk('D-2', 'female', 700);
        $preg = $mk('D-3', 'female', 700);
        $kid = $mk('K-1', 'female', 30);
        $buck = $mk('B-1', 'male', 700);
        Mating::create(['dam_id' => $preg->id, 'mated_on' => now()->subDays(60), 'status' => 'pregnant', 'recorded_by' => 'x']);
        HeatRecord::create(['dam_id' => $old->id, 'observed_on' => now()->subDays(19), 'intensity' => 'weak', 'observed_by' => 'x']);
        $user = User::create(['name' => 'Care', 'email' => 'c@heat.test', 'password' => 'x', 'role' => 'caretaker', 'org_id' => Tenant::current()]);

        $this->actingAs($user)->post('/breeding/heat', ['dam_id' => $buck->id, 'observed_on' => now()->toDateString(), 'intensity' => 'strong'])->assertStatus(422);
        $this->actingAs($user)->post('/breeding/heat', ['dam_id' => $doe->id, 'observed_on' => now()->toDateString(), 'intensity' => 'strong', 'signs' => ['Tail flagging', 'Bleating']])->assertSessionHasNoErrors();

        $board = collect(HeatBoard::build())->keyBy('number');
        $this->assertSame(['in_heat', 'due'], [$board['D-1']['status'], $board['D-2']['status']]); // D-2: 19 days since heat, next due in 2 days
        $this->assertSame(now()->addDays(21)->format('d/m'), $board['D-1']['next']);
        $this->assertFalse($board->has('D-3'));   // pregnant
        $this->assertFalse($board->has('K-1'));   // kid
        $this->assertSame('Tail flagging, Bleating', HeatRecord::where('dam_id', $doe->id)->value('signs'));
        $this->actingAs($user)->get('/breeding?tab=heat')->assertInertia(fn (AssertableInertia $p) => $p->where('heat.0.number', 'D-1')->where('heatOptions.cycle', 21));
    }
}
