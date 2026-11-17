<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\GoatCount;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidTag;
use App\Models\Shed;
use App\Models\User;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-338: a goat count by scanning keeps expected vs counted, by breed, with missing, extra and unknown tags. */
class GoatCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_pen_count_saves_totals_by_breed_and_what_was_missing_or_from_elsewhere(): void
    {
        $org = Organization::create(['name' => 'Count Farm', 'slug' => 'count-farm']);
        Tenant::set($org->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $p1 = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $p2 = Pen::create(['shed_id' => $shed->id, 'name' => 'P02', 'purpose' => 'Bucks']);
        $mc = Breed::create(['name' => 'Makhi Cheeni']);
        $bt = Breed::create(['name' => 'Beetal']);
        $mk = fn ($n, $b, $pen, $sex = 'female') => Animal::create(['farm_number' => $n, 'sex' => $sex, 'breed_id' => $b->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $a = $mk('MC-1', $mc, $p1); $b = $mk('MC-2', $mc, $p1); $c = $mk('BT-1', $bt, $p1); $d = $mk('BT-2', $bt, $p2, 'male');
        $ceo = User::create(['name' => 'Boss', 'email' => 'b@count.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => $org->id, 'active' => true]);
        Farm::assignTag($a, RfidTag::create(['tag_code' => 'E200AA']), 'initial', $ceo);

        $this->actingAs($ceo)->get('/goats/count')->assertInertia(fn ($p) => $p->component('GoatCount')->has('goats', 4)->where('goats.0.tags', [])->has('sheds.0.pens', 2));
        $this->actingAs($ceo)->post('/goats/count', ['scope' => 'pen', 'pen_id' => $p1->id, 'counted_ids' => [$a->id, $c->id, $d->id], 'unknown' => ['E2FFFF']])->assertSessionHasNoErrors();

        $n = GoatCount::sole();
        $this->assertSame([3, 3], [$n->expected, $n->counted]);
        $this->assertSame(['MC-2'], $n->missing);
        $this->assertSame(['BT-2'], $n->extra);
        $this->assertSame(['E2FFFF'], $n->unknown);
        $this->assertEquals(['Beetal' => ['expected' => 1, 'counted' => 2], 'Makhi Cheeni' => ['expected' => 2, 'counted' => 1]], $n->by_breed);
        $this->actingAs($ceo)->post('/goats/count', ['scope' => 'pen', 'counted_ids' => []])->assertSessionHasErrors('pen_id');
    }
}
