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
use Tests\TestCase;

/** D-336: an RFID tag is linked on the goat's own page, and every scanner then finds the goat by it. */
class GoatRfidLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tag_linked_on_the_goat_page_opens_the_goat_when_scanned_and_can_be_replaced_or_removed(): void
    {
        $org = Organization::create(['name' => 'Tag Farm', 'slug' => 'tag-farm']);
        Tenant::set($org->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Makhi Cheeni']);
        $goat = Animal::create(['farm_number' => 'MC-071126P-01', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $other = Animal::create(['farm_number' => 'MC-071126P-02', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $ceo = User::create(['name' => 'Boss', 'email' => 'b@tag.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => $org->id, 'active' => true]);
        $feeder = User::create(['name' => 'Feeder', 'email' => 'f@tag.test', 'password' => 'x', 'role' => 'feeding', 'org_id' => $org->id, 'active' => true]);

        $this->actingAs($ceo)->get("/goats/{$goat->id}/edit")->assertInertia(fn ($p) => $p->where('rfid', null)->where('canAssignTags', true));
        $this->actingAs($feeder)->post("/goats/{$goat->id}/tag", ['tag' => 'E200AAA1'])->assertForbidden();
        $this->actingAs($ceo)->post("/goats/{$goat->id}/tag", ['tag' => ' e200aaa1 '])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->get("/goats/{$goat->id}/edit")->assertInertia(fn ($p) => $p->where('rfid', 'E200AAA1'));
        $this->actingAs($ceo)->getJson('/lookup/goat?code=E200AAA1')->assertOk()->assertSee('MC-071126P-01');

        // the same tag cannot go on a second goat
        $this->actingAs($ceo)->post("/goats/{$other->id}/tag", ['tag' => 'E200AAA1'])->assertSessionHasErrors('tag');

        // replacing closes the old tag in the history
        $this->actingAs($ceo)->post("/goats/{$goat->id}/tag", ['tag' => 'E200BBB2'])->assertSessionHasNoErrors();
        $this->assertSame(['E200BBB2'], RfidAssignment::with('tag')->where('animal_id', $goat->id)->whereNull('valid_to')->get()->pluck('tag.tag_code')->all());
        $this->assertSame(2, RfidAssignment::where('animal_id', $goat->id)->count());

        $this->actingAs($ceo)->post("/goats/{$goat->id}/tag/remove", ['reason' => 'lost'])->assertSessionHasNoErrors();
        $this->assertSame(0, RfidAssignment::where('animal_id', $goat->id)->whereNull('valid_to')->count());
        $this->actingAs($ceo)->getJson('/lookup/goat?code=E200BBB2')->assertNotFound();

        // D-337: a touch tag written with the goat's Tag ID on the gun is linked under that Tag ID and opens the goat
        $this->actingAs($ceo)->post("/goats/{$goat->id}/tag", ['tag' => 'MC-071126P-01', 'chip' => '04a1b2c3d4'])->assertSessionHasNoErrors();
        $this->assertSame(['MC-071126P-01'], RfidAssignment::with('tag')->where('animal_id', $goat->id)->whereNull('valid_to')->get()->pluck('tag.tag_code')->all());
        $this->actingAs($ceo)->getJson('/lookup/goat?code=mc-071126p-01')->assertOk()->assertSee('MC-071126P-01');
        $this->actingAs($ceo)->post("/goats/{$goat->id}/tag", ['tag' => 'MC-071126P-01', 'chip' => 'not hex'])->assertSessionHasErrors('chip');
    }

    public function test_goats_whose_tags_still_carry_factory_numbers_are_listed_for_writing(): void // D-361
    {
        $org = Organization::create(['name' => 'Write Farm', 'slug' => 'write-farm']);
        Tenant::set($org->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Makhi Cheeni']);
        $old = Animal::create(['farm_number' => 'MC-101026P-05', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $done = Animal::create(['farm_number' => 'MC-101026P-06', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $ceo = User::create(['name' => 'Boss', 'email' => 'b@write.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => $org->id, 'active' => true]);
        $feeder = User::create(['name' => 'Feeder', 'email' => 'f@write.test', 'password' => 'x', 'role' => 'feeding', 'org_id' => $org->id, 'active' => true]);
        $this->actingAs($ceo)->post("/goats/{$old->id}/tag", ['tag' => 'E2000016651501581200A119'])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->post("/goats/{$done->id}/tag", ['tag' => 'MC-101026P-06'])->assertSessionHasNoErrors();

        $this->actingAs($feeder)->getJson('/goats/factory-tags')->assertForbidden();
        $this->actingAs($ceo)->getJson('/goats/factory-tags')->assertOk()->assertExactJson([['id' => $old->id, 'number' => 'MC-101026P-05', 'tag' => 'E2000016651501581200A119']]);

        // written on the gun: the link moves to the Tag ID and the goat leaves the list
        $this->actingAs($ceo)->post("/goats/{$old->id}/tag", ['tag' => 'MC-101026P-05', 'chip' => 'E2000016651501581200A119'])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->getJson('/goats/factory-tags')->assertOk()->assertExactJson([]);
        $this->actingAs($ceo)->getJson('/lookup/goat?code=C6D58C9001C1042199900040')->assertNotFound(); // another goat's packed ID
        $this->actingAs($ceo)->getJson('/goats/find?code=MC-101026P-05')->assertOk()->assertJson(['number' => 'MC-101026P-05']);
    }

    public function test_a_uhf_tag_written_with_the_tag_id_reads_as_that_tag_id(): void
    {
        $this->assertSame('MC-181026P-17', \App\Support\GoatRegistration::normaliseCode(bin2hex('MC-181026P-17')."00"));
        $this->assertSame('MC-TEST-0001', \App\Support\GoatRegistration::normaliseCode('4d432d544553542d30303031'));
        $this->assertSame('E20000166515015616107331', \App\Support\GoatRegistration::normaliseCode('e20000166515015616107331'));
        $this->assertSame('300833B2DDD9014000000000', \App\Support\GoatRegistration::normaliseCode('300833B2DDD9014000000000'));
    }

    public function test_a_tag_id_packed_into_96_bits_reads_as_that_tag_id(): void // D-359
    {
        $this->assertSame('MC-071126P-01', \App\Support\GoatRegistration::normaliseCode('C6D58C9001C1042199900040'));
        $this->assertSame('TD-141026P-25', \App\Support\GoatRegistration::normaliseCode('c6d74d901101002199902140'));
        $this->assertSame('C6D58C9001C1042199900041', \App\Support\GoatRegistration::normaliseCode('C6D58C9001C1042199900041')); // stray bits: not ours
    }

    public function test_only_the_administrator_unlinks_a_tag_linked_to_the_wrong_goat(): void // D-382
    {
        $org = Organization::create(['name' => 'Wrong Farm', 'slug' => 'wrong-farm']);
        Tenant::set($org->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Makhi Cheeni']);
        $goat = Animal::create(['farm_number' => 'MC-071126P-01', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $right = Animal::create(['farm_number' => 'MC-071126P-02', 'sex' => 'male', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $ceo = User::create(['name' => 'Boss', 'email' => 'b@wrong.test', 'password' => 'x', 'role' => 'ceo', 'org_id' => $org->id, 'active' => true]);
        $manager = User::create(['name' => 'Man', 'email' => 'm@wrong.test', 'password' => 'x', 'role' => 'manager', 'org_id' => $org->id, 'active' => true]);

        $this->actingAs($ceo)->post("/goats/{$goat->id}/tag", ['tag' => 'E200CCC3'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post("/goats/{$goat->id}/tag/remove", ['reason' => 'wrong'])->assertForbidden();
        $this->assertSame(1, RfidAssignment::where('animal_id', $goat->id)->whereNull('valid_to')->count());

        $this->actingAs($ceo)->post("/goats/{$goat->id}/tag/remove", ['reason' => 'wrong'])->assertSessionHasNoErrors();
        $this->assertSame('wrong', RfidAssignment::where('animal_id', $goat->id)->value('reason_unassigned'));
        $this->assertSame(0, RfidAssignment::where('animal_id', $goat->id)->whereNull('valid_to')->count());
        // the tag is free: it links to the right goat with no move
        $this->actingAs($ceo)->post("/goats/{$right->id}/tag", ['tag' => 'E200CCC3'])->assertSessionHasNoErrors();
        $this->actingAs($ceo)->getJson('/lookup/goat?code=E200CCC3')->assertOk()->assertSee('MC-071126P-02');
    }
}
