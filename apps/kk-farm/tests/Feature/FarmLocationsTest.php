<?php

namespace Tests\Feature;

use App\Models\FarmLocation;
use App\Models\Organization;
use App\Models\Shed;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-128: Organization → Farm (location) → Shed → Pen. A farm's code starts its shed names. */
class FarmLocationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Test Farm', 'slug' => 'test-farm'])->id);
    }

    private function user(string $role): User
    {
        return User::create(['name' => "Test $role", 'email' => "$role@test.local", 'password' => 'unused', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_a_shed_without_a_farm_goes_to_the_first_farm(): void
    {
        $shed = Shed::create(['code' => 'SHED-T', 'name' => 'Test Shed']);

        $this->assertNotNull($shed->farm_id);
        $this->assertSame('KKF', $shed->farm->code);
        $this->assertSame(1, FarmLocation::count());
    }

    public function test_a_manager_adds_a_farm_with_its_location_and_its_sheds_take_its_code(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($manager)->post('/sheds', ['type' => 'BDU', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('KKF-BDU-01', Shed::first()->name);

        $this->actingAs($manager)->post('/farms', [
            'name' => 'North Farm', 'code' => 'nf', 'address' => 'Chak 12', 'city' => 'Bahawalpur', 'district' => 'Bahawalpur', 'province' => 'Punjab',
            'country' => 'Pakistan', 'latitude' => '29.3957', 'longitude' => '71.6833',
        ])->assertSessionHasNoErrors();
        $north = FarmLocation::where('code', 'NF')->firstOrFail();
        $this->assertEqualsWithDelta(29.3957, $north->latitude, 0.0001);
        $this->assertSame('Bahawalpur, Punjab, Pakistan', $north->place());

        $this->actingAs($manager)->post('/sheds', ['farm_id' => $north->id, 'type' => 'BDU', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertTrue(Shed::where('name', 'NF-BDU-01')->where('farm_id', $north->id)->exists());

        // Moving a shed to another farm gives it that farm's code; its pens stay.
        $first = Shed::where('name', 'KKF-BDU-01')->firstOrFail();
        $this->actingAs($manager)->patch("/sheds/{$first->id}", ['farm_id' => $north->id, 'type' => 'BDU', 'status' => 'active'])->assertSessionHasNoErrors();
        $this->assertSame('NF-BDU-02', $first->fresh()->name);

        $this->actingAs($manager)->get('/sheds')->assertInertia(fn (AssertableInertia $page) => $page->has('farms', 2)->where('farms.1.code', 'NF')->where('farms.1.sheds', 2));
    }

    public function test_farm_codes_are_checked_and_fixed_once_sheds_use_them(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($manager)->post('/sheds', ['type' => 'BDU', 'status' => 'active'])->assertSessionHasNoErrors();
        $farm = FarmLocation::firstOrFail();

        $this->actingAs($manager)->post('/farms', ['name' => 'Copy', 'code' => 'kkf'])->assertSessionHasErrors('code');
        $this->actingAs($manager)->post('/farms', ['name' => 'Bad', 'code' => 'K1'])->assertSessionHasErrors('code');
        $this->actingAs($manager)->post('/farms', ['name' => 'Half GPS', 'code' => 'HG', 'latitude' => '29.1'])->assertSessionHasErrors('longitude');
        $this->actingAs($manager)->patch("/farms/{$farm->id}", ['name' => $farm->name, 'code' => 'NEW'])->assertSessionHasErrors('code');
        $this->actingAs($manager)->patch("/farms/{$farm->id}", ['name' => 'Main Farm', 'code' => 'KKF', 'city' => 'Lahore'])->assertSessionHasNoErrors();
        $this->assertSame('Lahore', $farm->fresh()->city);
    }

    public function test_only_managers_and_the_owner_manage_farms(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/farms', ['name' => 'X', 'code' => 'XX'])->assertForbidden();
        $this->assertSame(0, FarmLocation::count());
    }
}
