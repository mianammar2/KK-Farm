<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\DeathReport;
use App\Models\HealthRecord;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\Task;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-260: the vet reports a death, the administrator approves it, the goat page keeps the review; culling is out of breeding only. */
class DeathFlowTest extends TestCase
{
    use RefreshDatabase;

    private Animal $goat;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Death Farm', 'slug' => 'death-farm'])->id);
        \App\Models\Department::seedDefaults((string) Tenant::current());
        $pen = Pen::create(['shed_id' => Shed::create(['code' => 'S1', 'name' => 'S1'])->id, 'name' => 'P01', 'purpose' => 'Does']);
        $this->goat = Animal::create(['farm_number' => 'D-1', 'sex' => 'female', 'breed_id' => Breed::create(['name' => 'Beetal'])->id, 'origin' => 'purchased', 'pen_id' => $pen->id, 'birth_date' => now()->subYears(3), 'animal_class' => 'breeding_female', 'animal_purpose' => 'breeding'])->refresh();
    }

    private function user(string $role): User
    {
        return User::firstOrCreate(['email' => "$role@death.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function report(string $role = 'vet')
    {
        return $this->actingAs($this->user($role))->post("/goats/{$this->goat->id}/death", ['died_at' => now()->subHours(3)->format('Y-m-d H:i'), 'cause' => 'Pneumonia', 'circumstances' => 'Found lying down at 6 am, breathing hard']);
    }

    public function test_only_the_vet_reports_and_record_exit_no_longer_takes_death_or_culling(): void
    {
        foreach (['ceo', 'manager', 'caretaker'] as $role) {
            $this->report($role)->assertForbidden();
        }
        foreach (['died', 'culled'] as $reason) {
            $this->actingAs($this->user('ceo'))->post("/goats/{$this->goat->id}/exit", ['reason' => $reason])->assertSessionHasErrors('reason');
        }
        $this->assertSame('active', $this->goat->fresh()->status);
    }

    public function test_a_reported_death_waits_for_the_administrator_then_the_goat_leaves_with_its_review(): void
    {
        Treatment::create(['animal_id' => $this->goat->id, 'given_on' => now()->subDays(5), 'product' => 'Oxytetracycline', 'dose' => '5 ml', 'route' => 'IM', 'recorded_by' => 'Test vet']);
        $this->report()->assertSessionHasNoErrors();
        $this->assertSame('active', $this->goat->fresh()->status, 'still on the farm until approved');
        $this->assertTrue(Task::where('title', 'Approve death: D-1')->whereIn('status', Task::OPEN)->exists());
        $this->report()->assertStatus(422); // one report at a time

        $r = DeathReport::first();
        $this->actingAs($this->user('vet'))->post("/death-reports/{$r->id}", ['decision' => 'approved'])->assertForbidden();
        $this->actingAs($this->user('ceo'))->post("/death-reports/{$r->id}", ['decision' => 'approved'])->assertSessionHasNoErrors();

        $g = $this->goat->fresh();
        $this->assertSame('died', $g->exit_reason);
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertTrue(HealthRecord::where('animal_id', $g->id)->where('kind', 'postmortem')->where('title', 'Pneumonia')->exists(), 'counts in mortality by cause');
        $this->assertFalse(Task::where('title', 'Approve death: D-1')->whereIn('status', Task::OPEN)->exists());
        $this->actingAs($this->user('vet'))->get("/goats/{$g->id}")->assertInertia(fn ($p) => $p
            ->where('death.report.cause', 'Pneumonia')->where('death.report.decidedBy', 'Test ceo')->where('death.review.treatments.0.product', 'Oxytetracycline'));
    }

    /** D-326: the dashboard's Mortality section counts deaths and slaughter by reason, with who entered and who approved each. */
    public function test_the_dashboard_mortality_section_lists_deaths_and_slaughter_with_who_entered_them(): void
    {
        $this->report()->assertSessionHasNoErrors();
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($p) => $p->where('mortality.waiting', 1)->where('mortality.deaths', 0)
            ->where('mortality.rows.0.status', 'Waiting for approval')->where('mortality.rows.0.reportedBy', 'Test vet'));
        $this->actingAs($this->user('ceo'))->post('/death-reports/'.DeathReport::first()->id, ['decision' => 'approved'])->assertSessionHasNoErrors();

        $other = Animal::create(['farm_number' => 'D-2', 'sex' => 'male', 'breed_id' => $this->goat->breed_id, 'origin' => 'purchased', 'pen_id' => $this->goat->pen_id, 'birth_date' => now()->subYears(2)]);
        $this->actingAs($this->user('vet'))->post("/goats/{$other->id}/slaughter", ['died_at' => now()->toDateString(), 'reason' => 'injury', 'circumstances' => 'Broken leg, not healing'])->assertSessionHasNoErrors();
        $this->actingAs($this->user('ceo'))->post('/death-reports/'.DeathReport::where('kind', 'slaughter')->value('id'), ['decision' => 'approved'])->assertSessionHasNoErrors();

        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($p) => $p
            ->where('mortality.deaths', 1)->where('mortality.slaughtered', 1)->where('mortality.waiting', 0)->where('mortality.ever', 2)->has('mortality.byReason', 2)
            ->where('mortality.rows', fn ($rows) => collect($rows)->contains(fn ($r) => $r['number'] === 'D-1' && $r['kind'] === 'Death' && $r['reason'] === 'Pneumonia' && $r['reportedBy'] === 'Test vet' && $r['approvedBy'] === 'Test ceo' && $r['seen'] === 'Found lying down at 6 am, breathing hard')
                && collect($rows)->contains(fn ($r) => $r['number'] === 'D-2' && $r['kind'] === 'Slaughter' && $r['reason'] === 'Injury or accident' && $r['reportedBy'] === 'Test vet')));
    }

    /** Audit: a goat marked died with no exit row still counts in Veterinary → Mortality, as on the dashboard and Farm Reports. */
    public function test_a_death_without_an_exit_row_counts_in_the_mortality_register(): void
    {
        $this->goat->forceFill(['status' => 'deceased', 'exit_reason' => 'died', 'exit_date' => now()->subDays(3)->toDateString()])->save();
        $this->actingAs($this->user('vet'))->get('/health?tab=mortality')->assertInertia(fn ($p) => $p->has('mortality.rows', 1)->where('mortality.rows.0.number', 'D-1'));
        $this->actingAs($this->user('ceo'))->get('/')->assertInertia(fn ($p) => $p->where('mortality.deaths', 1));
    }

    public function test_the_administrator_can_send_a_report_back_with_a_note(): void
    {
        $this->report();
        $r = DeathReport::first();
        $this->actingAs($this->user('ceo'))->post("/death-reports/{$r->id}", ['decision' => 'rejected'])->assertSessionHasErrors('note');
        $this->actingAs($this->user('ceo'))->post("/death-reports/{$r->id}", ['decision' => 'rejected', 'note' => 'Wrong goat, check the tag'])->assertSessionHasNoErrors();
        $this->assertSame('active', $this->goat->fresh()->status);
        $this->assertTrue(Task::where('title', 'Death report returned: D-1')->where('user_id', $this->user('vet')->id)->exists());
        $this->report()->assertSessionHasNoErrors(); // the vet can report again
    }

    public function test_culling_takes_a_goat_out_of_breeding_but_not_off_the_farm(): void
    {
        foreach (['caretaker', 'ceo', 'manager'] as $role) { // D-269: the vet's by default
            $this->actingAs($this->user($role))->post("/goats/{$this->goat->id}/cull", ['reason' => 'Repeat breeder'])->assertForbidden();
        }
        $this->actingAs($this->user('vet'))->post("/goats/{$this->goat->id}/cull", ['reason' => 'Repeat breeder'])->assertSessionHasNoErrors();
        $g = $this->goat->fresh();
        $this->assertSame('active', $g->status);
        $this->assertSame('cull', $g->animal_class);
        $this->assertSame('CF', \App\Support\Designations::main($g));
        $this->assertSame('not_candidate', \App\Support\Eligibility::check($g)['status']);
    }
}
