<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\BreederSelection;
use App\Models\Breed;
use App\Models\Kidding;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\Weaning;
use App\Models\WelfareCase;
use App\Support\Genetics;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-201: journey L Genetic Selection-to-Replacement (V3 flow 35). */
class GeneticSelectionTest extends TestCase
{
    use RefreshDatabase;

    private Pen $pen;

    private function goat(string $tag, string $sex, array $extra = []): Animal
    {
        return Animal::create($extra + ['farm_number' => $tag, 'sex' => $sex, 'breed_id' => Breed::firstOrCreate(['name' => 'Beetal'])->id, 'birth_date' => now()->subYears(3), 'origin' => 'purchased',
            'pen_id' => $this->pen->id, 'animal_purpose' => 'breeding'])->fresh();
    }

    /** A litter of kids from a mating, weaned at 84 days at the given weight (null: the kid died young). */
    private function litter(Animal $sire, Animal $dam, array $weanKg, string $prefix): void
    {
        $born = now()->subDays(150);
        $m = Mating::create(['dam_id' => $dam->id, 'sire_id' => $sire->id, 'mated_on' => $born->copy()->subDays(150), 'method' => 'natural', 'status' => 'kidded', 'recorded_by' => 'x']);
        Kidding::create(['dam_id' => $dam->id, 'mating_id' => $m->id, 'kidded_on' => $born, 'born_alive' => count($weanKg), 'stillborn' => 0, 'recorded_by' => 'x']);
        foreach ($weanKg as $i => $kg) {
            $kid = Animal::create(['farm_number' => "{$prefix}-{$i}", 'sex' => 'male', 'breed_id' => $dam->breed_id, 'birth_date' => $born, 'origin' => 'born_on_farm', 'sire_id' => $sire->id, 'dam_id' => $dam->id,
                'birth_weight_kg' => 3, 'pen_id' => $this->pen->id] + ($kg === null ? ['status' => 'deceased', 'exit_reason' => 'died', 'exit_date' => $born->copy()->addDays(10)] : []));
            if ($kg !== null) {
                Weaning::create(['kid_id' => $kid->id, 'dam_id' => $dam->id, 'status' => 'completed', 'weaned_on' => $born->copy()->addDays(84), 'weight_kg' => $kg, 'handler' => 'x', 'method' => 'gradual']);
            }
        }
    }

    public function test_merit_from_progeny_inbreeding_alerts_and_a_replacement_with_its_planned_buck(): void
    {
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Gene Farm', 'slug' => 'gene-farm'])->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $replacementPen = Pen::create(['shed_id' => $shed->id, 'name' => 'R1', 'purpose' => 'Replacements']);
        $u = fn (string $role) => User::firstOrCreate(['email' => "$role@gene.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
        [$care, $manager] = [$u('caretaker'), $u('manager')];

        // Two bucks with measured kids: S's kids all survive and grow fast; T loses one and grows slower.
        $s = $this->goat('S-1', 'male');
        $t = $this->goat('T-1', 'male');
        $d1 = $this->goat('D-1', 'female');
        $d2 = $this->goat('D-2', 'female');
        $this->litter($s, $d1, [18, 17, 19], 'SK');
        $this->litter($t, $d2, [12, 13, null], 'TK');

        // Half-siblings by S: their kid would be 12.5 % inbred; an unrelated pairing 0 %.
        $k1 = $this->goat('K-1', 'female', ['sire_id' => $s->id, 'dam_id' => $d1->id, 'birth_date' => now()->subDays(200), 'origin' => 'born_on_farm', 'animal_purpose' => null]);
        $k2 = $this->goat('K-2', 'male', ['sire_id' => $s->id, 'dam_id' => $d2->id, 'birth_date' => now()->subDays(400), 'origin' => 'born_on_farm']);
        Genetics::flush();
        $this->assertSame(12.5, Genetics::coi($k2->id, $k1->id));
        $this->assertSame(0.0, Genetics::coi($t->id, $k1->id));

        $prog = Genetics::progeny()['parents']->keyBy('number');
        $this->assertTrue($prog['S-1']['measured']);
        $this->assertGreaterThan($prog['T-1']['merit'], $prog['S-1']['merit']);
        $this->assertSame([100.0, 66.7], [$prog['S-1']['survival'], $prog['T-1']['survival']]);

        // Mating half-siblings: an inbreeding alert; only the owner, manager or vet may go ahead, with a reason.
        $mate = ['dam_id' => $k1->id, 'sire_id' => $k2->id, 'mated_on' => now()->toDateString(), 'method' => 'natural'];
        $this->actingAs($manager)->post('/breeding/matings', $mate)->assertSessionHasErrors('sire_id');
        $this->actingAs($care)->post('/breeding/matings', $mate + ['coi_override_reason' => 'x'])->assertForbidden();

        // Replacement review: K-1 has no weight and a welfare case, so the checks fail; pedigree is information only.
        WelfareCase::create(['case_no' => 1, 'animal_id' => $k1->id, 'opened_on' => now(), 'issue' => 'injury', 'severity' => 'low', 'status' => 'open', 'opened_by' => 'x']);
        $this->actingAs($manager)->get('/genetics')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('candidates.0.number', 'K-1')->where('candidates.0.eligible', false)->where('candidates.0.sire.number', 'S-1')
            ->where('candidates.0.checks', fn ($checks) => collect($checks)->firstWhere('key', 'welfare')['ok'] === false && collect($checks)->firstWhere('key', 'pedigree')['info'] === true));

        $this->actingAs($care)->post("/genetics/decide/{$k1->id}", ['decision' => 'replacement'])->assertForbidden();
        $this->actingAs($manager)->post("/genetics/decide/{$k1->id}", ['decision' => 'replacement', 'planned_sire_id' => $k2->id])->assertSessionHasErrors('reason');
        $this->actingAs($manager)->post("/genetics/decide/{$k1->id}", ['decision' => 'replacement', 'planned_sire_id' => $t->id, 'pen_id' => $replacementPen->id,
            'reason' => 'Best dam line; minor injury healing'])->assertSessionHasNoErrors();
        $sel = BreederSelection::sole();
        $this->assertSame([true, $t->id, 0.0], [$sel->override, $sel->planned_sire_id, $sel->planned_coi_pct]);
        $this->assertSame(['replacement', $replacementPen->id], [$k1->fresh()->animal_purpose, $k1->fresh()->pen_id]);
        $this->assertTrue(Task::where('title', 'Breeding plan: K-1 with T-1')->exists());

        // Going ahead with the half-sibling mating is counted as an inbreeding alert.
        WelfareCase::query()->update(['status' => 'closed', 'closed_at' => now()]);
        $this->actingAs($manager)->post('/breeding/matings', $mate + ['coi_override_reason' => 'Trial, vet agreed'])->assertSessionHasNoErrors();
        $this->assertSame(12.5, (float) Mating::where('dam_id', $k1->id)->value('coi_pct'));
        $this->actingAs($manager)->get('/genetics')->assertInertia(fn (AssertableInertia $p) => $p->where('kpi.inbreedingAlerts', 1)->where('kpi.selected', 1));
        $this->actingAs($care)->post('/genetics/settings', ['inbreeding_limit_pct' => 20])->assertForbidden();
    }
}
