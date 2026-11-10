<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\CareProtocol;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Support\CareCalendar;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-173: the vet's vaccine / deworming protocols and the calendar worked out from them. */
class CareCalendarTest extends TestCase
{
    use RefreshDatabase;

    private Animal $kid;

    private Animal $doe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Care Farm', 'slug' => 'care-farm'])->id);
        $breed = Breed::create(['name' => 'Beetal']);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->kid = Animal::create(['farm_number' => 'KID-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subDays(40), 'origin' => 'purchased', 'pen_id' => $pen->id]);
        $this->doe = Animal::create(['farm_number' => 'DOE-1', 'sex' => 'female', 'breed_id' => $breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@care.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    public function test_the_vet_sets_protocols_and_the_calendar_lists_who_is_due(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/health/protocols', ['name' => 'PPR', 'kind' => 'vaccination', 'applies_to' => 'all'])->assertForbidden();
        $this->actingAs($this->user('vet'))->post('/health/protocols', ['name' => 'Kid deworming', 'kind' => 'deworming', 'applies_to' => 'kids', 'first_age_days' => 30, 'interval_days' => 60])->assertSessionHasNoErrors();
        $this->actingAs($this->user('vet'))->post('/health/protocols', ['name' => 'PPR', 'kind' => 'vaccination', 'applies_to' => 'all', 'interval_days' => 365])->assertSessionHasNoErrors();

        $cal = collect(CareCalendar::due())->keyBy('name');
        $this->assertSame(['KID-1'], collect($cal['Kid deworming']['goats'])->pluck('number')->all()); // 40 days old: due since day 30
        $this->assertTrue($cal['Kid deworming']['goats'][0]['overdue']);
        $this->assertEqualsCanonicalizing(['KID-1', 'DOE-1'], collect($cal['PPR']['goats'])->pluck('number')->all()); // never given: due now

        $ppr = CareProtocol::where('name', 'PPR')->sole();
        $this->actingAs($this->user('vet'))->post('/health/preventive', [
            'animal_ids' => [$this->kid->id, $this->doe->id], 'kind' => 'vaccination', 'title' => 'PPR', 'given_on' => now()->toDateString(),
            'next_due_on' => now()->addDays(365)->toDateString(), 'care_status' => 'administered', 'protocol_id' => $ppr->id,
        ])->assertSessionHasNoErrors();
        $this->assertSame([], collect(CareCalendar::due())->firstWhere('name', 'PPR')['goats']); // next due in a year, outside 30 days

        $this->actingAs($this->user('vet'))->get('/health?tab=calendar')->assertInertia(fn (AssertableInertia $p) => $p->has('calendar', 2)->has('protocols', 2)->has('applies', 7));
    }
}
