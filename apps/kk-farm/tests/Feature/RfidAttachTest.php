<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidAssignment;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-144: attach an RFID tag to a goat entered by hand, from the registry, by typing or by the gun's next scan. */
class RfidAttachTest extends TestCase
{
    use RefreshDatabase;

    private Pen $pen;

    private Breed $breed;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Test Farm', 'slug' => 'test-farm'])->id);
        $this->breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $this->pen = Pen::create(['shed_id' => Shed::create(['code' => 'SHED-T', 'name' => 'Test Shed'])->id, 'name' => 'P01', 'purpose' => 'Does']);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@rfid.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function goat(string $number): Animal
    {
        return Animal::create(['farm_number' => $number, 'sex' => 'female', 'breed_id' => $this->breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
    }

    private function tagOf(Animal $goat): ?string
    {
        return $goat->fresh()->openAssignment?->tag?->tag_code;
    }

    public function test_typing_a_code_attaches_it_and_a_new_code_replaces_it_keeping_history(): void
    {
        $manager = $this->user('manager');
        $goat = $this->goat('TD141026-01');

        $this->actingAs($manager)->post("/goats/{$goat->id}/rfid", ['tag' => ' e2801170000002aa '])->assertSessionHasNoErrors();
        $this->assertSame('E2801170000002AA', $this->tagOf($goat));

        $this->actingAs($manager)->post("/goats/{$goat->id}/rfid", ['tag' => 'E2801170000002BB'])->assertSessionHasNoErrors();
        $this->assertSame('E2801170000002BB', $this->tagOf($goat));
        $old = RfidAssignment::where('animal_id', $goat->id)->whereNotNull('valid_to')->with('tag')->first();
        $this->assertSame('E2801170000002AA', $old->tag->tag_code, 'the replaced tag stays in the history');
        $this->assertSame('replaced', $old->reason_unassigned);

        // Scanning the tag now opens this goat.
        $this->actingAs($manager)->get('/scan?tag=E2801170000002BB')->assertInertia(fn (AssertableInertia $p) => $p->where('result.goat.number', 'TD141026-01'));
    }

    public function test_a_tag_on_another_goat_moves_only_after_confirming(): void
    {
        $manager = $this->user('manager');
        $first = $this->goat('TD141026-01');
        $second = $this->goat('TD141026-02');
        $this->actingAs($manager)->post("/goats/{$first->id}/rfid", ['tag' => 'E2801170000002AA'])->assertSessionHasNoErrors();

        $this->actingAs($manager)->post("/goats/{$second->id}/rfid", ['tag' => 'E2801170000002AA'])->assertSessionHasErrors(['tag', 'holder']);
        $this->assertSame('E2801170000002AA', $this->tagOf($first));
        $this->assertNull($this->tagOf($second));

        $this->actingAs($manager)->post("/goats/{$second->id}/rfid", ['tag' => 'E2801170000002AA', 'move' => true])->assertSessionHasNoErrors();
        $this->assertNull($this->tagOf($first));
        $this->assertSame('E2801170000002AA', $this->tagOf($second));
    }

    public function test_the_gun_scan_after_scan_rfid_offers_the_tag_to_that_goat(): void
    {
        $manager = $this->user('manager');
        $goat = $this->goat('TD141026-01');

        // Without Scan RFID, an unknown tag starts the goat register as before.
        $this->actingAs($manager)->get('/scan?tag=E2801170000009ZZ')->assertRedirect('/goats/register?epc=E2801170000009ZZ');

        $this->actingAs($manager)->post("/goats/{$goat->id}/rfid/arm")->assertRedirect();
        $this->actingAs($manager)->get('/scan?tag=E2801170000009ZZ')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('attach.code', 'E2801170000009ZZ')->where('attach.goat.number', 'TD141026-01')->where('attach.holder', null));

        $this->actingAs($manager)->post("/goats/{$goat->id}/rfid", ['tag' => 'E2801170000009ZZ', 'from' => 'scan'])->assertRedirect("/goats/{$goat->id}");
        $this->assertSame('E2801170000009ZZ', $this->tagOf($goat));
        // The attach is used up: the next scan opens the goat.
        $this->actingAs($manager)->get('/scan?tag=E2801170000009ZZ')->assertInertia(fn (AssertableInertia $p) => $p->where('attach', null)->where('result.goat.number', 'TD141026-01'));

        // It expires after a few minutes.
        $this->actingAs($manager)->post("/goats/{$goat->id}/rfid/arm");
        $this->travel(4)->minutes();
        $this->actingAs($manager)->get('/scan?tag=E2801170000008YY')->assertRedirect('/goats/register?epc=E2801170000008YY');
    }

    public function test_only_people_who_tag_goats_can_attach(): void
    {
        $goat = $this->goat('TD141026-01');
        $this->actingAs($this->user('finance'))->post("/goats/{$goat->id}/rfid", ['tag' => 'E2801170000002AA'])->assertForbidden();
        $this->actingAs($this->user('manager'))->post("/goats/{$goat->id}/rfid", ['tag' => 'AB'])->assertSessionHasErrors('tag');
        $this->assertNull($this->tagOf($goat));
    }

    public function test_linking_an_rfid_never_changes_the_tag_id(): void
    {
        $manager = $this->user('manager');
        $goat = $this->goat('TD141026-07');
        $before = $goat->fresh()->only(['id', 'farm_number', 'name', 'pen_id']);

        $this->actingAs($manager)->post("/goats/{$goat->id}/rfid", ['tag' => 'E2801170000003AA'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/goats/{$goat->id}/rfid", ['tag' => 'E2801170000003BB'])->assertSessionHasNoErrors();

        $this->assertSame($before, $goat->fresh()->only(['id', 'farm_number', 'name', 'pen_id']), 'the tag ID and the goat stay exactly the same');
        $this->actingAs($manager)->get('/scan?tag=E2801170000003BB')->assertInertia(fn (AssertableInertia $p) => $p->where('result.goat.number', 'TD141026-07'));
    }
}
