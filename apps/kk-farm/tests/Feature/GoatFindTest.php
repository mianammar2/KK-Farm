<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidTag;
use App\Models\Shed;
use App\Models\User;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-293: the goat fields of every form find a goat by a typed Tag ID or a scanned RFID chip. */
class GoatFindTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_tag_id_or_an_rfid_chip_finds_the_goat_and_only_on_this_farm(): void
    {
        $org = Organization::create(['name' => 'Find Farm', 'slug' => 'find-farm']);
        Tenant::set($org->id);
        $shed = Shed::create(['code' => 'S1', 'name' => 'S1']);
        $pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
        $breed = Breed::create(['name' => 'Makhi Cheeni']);
        $goat = Animal::create(['farm_number' => 'MC-101026P-01', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYear(), 'origin' => 'purchased', 'pen_id' => $pen->id])->fresh();
        $care = User::create(['name' => 'Care', 'email' => 'care@find.test', 'password' => 'x', 'role' => 'caretaker', 'org_id' => $org->id, 'active' => true]);
        Farm::assignTag($goat, RfidTag::create(['tag_code' => 'E2801170000002080000ABCD']), 'initial', $care);

        $this->actingAs($care)->getJson('/goats/find?code=mc-101026p-01')->assertOk()->assertJson(['id' => $goat->id, 'number' => 'MC-101026P-01']);
        $this->actingAs($care)->getJson('/goats/find?code=E2801170000002080000ABCD')->assertOk()->assertJson(['id' => $goat->id]);
        $this->actingAs($care)->getJson('/goats/find?code=NO-SUCH-GOAT')->assertNotFound();

        // another farm business sees nothing of it
        $other = Organization::create(['name' => 'Other', 'slug' => 'other']);
        $stranger = User::create(['name' => 'Stranger', 'email' => 's@other.test', 'password' => 'x', 'role' => 'caretaker', 'org_id' => $other->id, 'active' => true]);
        Tenant::flush();
        $this->actingAs($stranger)->getJson('/goats/find?code=MC-101026P-01')->assertNotFound();
    }
}
