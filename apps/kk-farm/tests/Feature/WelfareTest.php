<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\HealthRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WelfareCase;
use App\Models\WelfareCheck;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-185: App 19 Animal Welfare along V3 flow 19, with BR-023 and the medical-authorisation control. */
class WelfareTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Welfare Farm', 'slug' => 'welfare-farm'])->id);
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'W-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $pen->id]);
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@welfare.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function step(string $role, array $data)
    {
        return $this->actingAs($this->user($role))->post('/welfare/cases/'.WelfareCase::sole()->id.'/step', $data);
    }

    public function test_a_normal_check_is_marked_normal_with_the_next_inspection(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/welfare/checks', ['animal_id' => $this->goat->id, 'checked_on' => now()->toDateString(), 'bcs' => 3, 'behaviour' => 'normal', 'injury' => 'none', 'lameness' => 0])->assertSessionHasNoErrors();
        $this->assertSame(0, WelfareCase::count());
        $this->assertSame(now()->addDays(7)->toDateString(), WelfareCheck::sole()->next_check_on->toDateString());
    }

    public function test_pain_opens_a_case_that_goes_through_vet_referral_follow_up_and_closure_with_evidence(): void
    {
        $this->actingAs($this->user('caretaker'))->post('/welfare/checks', ['animal_id' => $this->goat->id, 'checked_on' => now()->toDateString(), 'behaviour' => 'dull', 'injury' => 'none', 'lameness' => 2, 'pain' => true])->assertSessionHasNoErrors();
        $case = WelfareCase::sole();
        $this->assertSame(['lameness', 'high', 'open'], [$case->issue, $case->severity, $case->status]);
        $this->assertTrue(Task::where('title', 'like', 'Welfare inspection: WC-0001%')->exists()); // BR-023

        $this->step('caretaker', ['kind' => 'vet_referral', 'notes' => 'Cannot bear weight'])->assertSessionHasNoErrors();
        $this->assertSame('vet_referral', $case->fresh()->status);
        $this->assertSame('open', HealthRecord::find($case->fresh()->health_record_id)->status); // in the vet's Reported Concerns

        $this->step('caretaker', ['kind' => 'close', 'evidence' => 'walking'])->assertStatus(422); // improvement not confirmed yet
        $this->step('caretaker', ['kind' => 'follow_up', 'improved' => false])->assertSessionHasNoErrors();
        $this->assertSame('escalated', $case->fresh()->status);
        $this->assertTrue(Task::where('title', 'like', 'Repeat clinical review: welfare WC-0001%')->exists());

        $this->step('vet', ['kind' => 'follow_up', 'improved' => true])->assertSessionHasNoErrors();
        $this->step('caretaker', ['kind' => 'close', 'evidence' => 'Walking sound'])->assertForbidden(); // under a vet plan
        $this->step('vet', ['kind' => 'close'])->assertSessionHasErrors('evidence');
        $this->step('vet', ['kind' => 'close', 'evidence' => 'Walking sound, eating well'])->assertSessionHasNoErrors();
        $this->assertSame(['closed', 'Test vet'], [$case->fresh()->status, $case->fresh()->closed_by]);
        $this->actingAs($this->user('manager'))->get('/welfare')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->where('kpi.unresolved', 0)->where('cases.0.steps', fn ($s) => count($s) === 4));
    }

    public function test_finance_cannot_open_the_welfare_app(): void
    {
        $this->actingAs($this->user('finance'))->get('/welfare')->assertForbidden();
    }
}
