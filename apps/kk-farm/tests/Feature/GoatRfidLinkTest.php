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

    public function test_a_uhf_tag_written_with_the_tag_id_reads_as_that_tag_id(): void
    {
        $this->assertSame('MC-181026P-17', \App\Support\GoatRegistration::normaliseCode(bin2hex('MC-181026P-17')."00"));
        $this->assertSame('MC-TEST-0001', \App\Support\GoatRegistration::normaliseCode('4d432d544553542d30303031'));
        $this->assertSame('E20000166515015616107331', \App\Support\GoatRegistration::normaliseCode('e20000166515015616107331'));
        $this->assertSame('300833B2DDD9014000000000', \App\Support\GoatRegistration::normaliseCode('300833B2DDD9014000000000'));
    }
}
