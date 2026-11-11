<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-208: BR-001 permanent Animal ID series; BR-018 / UAT-18 held weights. */
class LivestockIdWeightTest extends TestCase
{
    use RefreshDatabase;

    private Breed $breed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'KK Farms', 'slug' => 'kk-farms-test'])->id);
        $this->breed = Breed::create(['name' => 'Beetal']);
    }

    private function goat(string $tag): Animal
    {
        return Animal::create(['farm_number' => $tag, 'sex' => 'female', 'breed_id' => $this->breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'status' => 'active']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@id.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_every_goat_gets_a_permanent_id_that_never_changes_or_repeats(): void
    {
        $a = $this->goat('BT-P-261010-001');
        $b = $this->goat('BT-P-261010-002');
        $this->assertSame(['KKF-000001', 'KKF-000002'], [$a->animal_code, $b->animal_code]);

        $a->update(['farm_number' => 'BT-B-261010-001', 'status' => 'left_farm']); // tag corrected, goat sold
        $this->assertSame('KKF-000001', $a->fresh()->animal_code);
        $this->assertSame('KKF-000003', $this->goat('BT-P-261111-001')->animal_code); // never reused

        $this->expectException(QueryException::class);
        $b->update(['animal_code' => 'KKF-000099']);
    }

    public function test_impossible_repeated_and_implausible_weights_are_held_until_reviewed(): void
    {
        $g = $this->goat('W-1');
        $w = fn ($kg, $on) => $this->actingAs($this->user('caretaker'))->post('/weights', ['animal_id' => $g->id, 'weight_kg' => $kg, 'measured_on' => $on]);
        $w(30, now()->subDays(20)->toDateString())->assertSessionHasNoErrors();
        $w(30, now()->subDays(20)->toDateString()); // the same reading twice
        $w(31, now()->subDays(10)->toDateString());
        $w(45, now()->toDateString()); // +14 KG in 10 days

        $this->assertSame([30.0, 31.0], $g->weights()->pluck('weight_kg')->all()); // held readings don't count
        $held = WeightRecord::held()->orderBy('id')->get();
        $this->assertSame(['duplicate', 'jump'], $held->pluck('flag')->all());
        $this->assertEquals(31.0, $g->fresh()->latestWeight->weight_kg);

        $this->actingAs($this->user('caretaker'))->post("/weights/review/{$held[1]->id}", ['decision' => 'accepted'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/weights/review/{$held[0]->id}", ['decision' => 'rejected'])->assertSessionHasErrors('note');
        $this->actingAs($this->user('manager'))->post("/weights/review/{$held[0]->id}", ['decision' => 'rejected', 'note' => 'Same reading sent twice'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->post("/weights/review/{$held[1]->id}", ['decision' => 'accepted'])->assertSessionHasNoErrors();
        $this->assertEquals(45.0, $g->fresh()->latestWeight->weight_kg);
        $this->assertSame(3, $g->weights()->count());
        $this->actingAs($this->user('manager'))->get('/weights/review')->assertOk();
    }
}
