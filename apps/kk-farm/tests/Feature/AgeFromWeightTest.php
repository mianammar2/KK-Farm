<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Models\Breed;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\Shed;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** D-110: a goat with no teeth gets its age from its first weighing, by the breed's light weight line. */
class AgeFromWeightTest extends TestCase
{
    use RefreshDatabase;

    private Breed $breed;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Tenant::set(Organization::create(['name' => 'Age Farm', 'slug' => 'age-farm'])->id);
        $this->breed = Breed::create(['name' => 'Makhi Cheeni', 'code' => 'MC', 'default_gestation_days' => 150, 'young_below_kg' => 28]);
        $shed = Shed::create(['code' => 'S1', 'name' => 'KKF-BDU-01']);
        $this->pen = Pen::create(['shed_id' => $shed->id, 'name' => 'P01', 'purpose' => 'Does']);
    }

    private function user(string $role = 'manager'): User
    {
        return User::firstOrCreate(['email' => "$role@age.test"], ['name' => "Test $role", 'password' => 'x', 'role' => $role, 'org_id' => Tenant::current()]);
    }

    private function register(array $extra = []): Animal
    {
        $before = Animal::pluck('id');
        $this->actingAs($this->user())->post('/goats', $extra + [
            'origin' => 'purchased', 'breed_id' => $this->breed->id, 'acquired_on' => now()->toDateString(), 'sex' => 'female', 'pen_id' => $this->pen->id,
        ])->assertSessionHasNoErrors();

        return Animal::whereNotIn('id', $before)->firstOrFail();
    }

    public function test_a_light_goat_is_about_seven_months_and_a_heavier_one_about_ten(): void
    {
        $light = $this->register(['latest_weight_kg' => 24.5]);
        $heavy = $this->register(['latest_weight_kg' => 31]);

        $this->assertSame(now()->subMonths(7)->toDateString(), $light->fresh()->birth_date->toDateString());
        $this->assertTrue($light->fresh()->birth_date_is_estimate);
        $this->assertSame('7 mo (est.)', $light->fresh()->ageLabel());
        $this->assertSame(now()->subMonths(10)->toDateString(), $heavy->fresh()->birth_date->toDateString());
        $this->assertTrue(AnimalEvent::where('animal_id', $light->id)->where('summary', 'like', 'Age estimated from weight: 24.5 KG%under 28 KG for Makhi Cheeni, so about 7 months%')->exists());
    }

    public function test_the_age_counts_from_the_day_of_the_first_weighing_and_later_weighings_do_not_change_it(): void
    {
        $goat = $this->register();
        $this->assertNull($goat->fresh()->birth_date);

        WeightRecord::create(['animal_id' => $goat->id, 'measured_on' => '2026-10-21', 'weight_kg' => 22, 'measured_by' => 'vet']);
        $this->assertSame('2026-03-21', $goat->fresh()->birth_date->toDateString());

        WeightRecord::create(['animal_id' => $goat->id, 'measured_on' => '2026-11-04', 'weight_kg' => 35, 'measured_by' => 'vet']);
        $this->assertSame('2026-03-21', $goat->fresh()->birth_date->toDateString());
    }

    public function test_teeth_win_and_breeds_without_a_line_or_farm_born_goats_are_left_alone(): void
    {
        $withTeeth = $this->register(['teeth' => 'T04', 'birth_date' => now()->subMonths(21)->toDateString(), 'latest_weight_kg' => 20]);
        $this->assertSame(now()->subMonths(21)->toDateString(), $withTeeth->fresh()->birth_date->toDateString());

        $teddy = Breed::create(['name' => 'Teddy', 'code' => 'TD', 'default_gestation_days' => 150]);
        $noLine = $this->register(['breed_id' => $teddy->id, 'latest_weight_kg' => 12]);
        $this->assertNull($noLine->fresh()->birth_date);

        $this->actingAs($this->user())->post("/goats/{$noLine->id}/teeth", ['teeth' => 'T02'])->assertSessionHasNoErrors();
        $this->assertSame(now()->subMonths(15)->toDateString(), $noLine->fresh()->birth_date->toDateString());
    }

    public function test_the_administrator_sets_each_breeds_light_line_in_settings(): void
    {
        $this->actingAs($this->user('ceo'))->post("/settings/breeds/{$this->breed->id}", ['young_below_kg' => 26.5])->assertSessionHasNoErrors();
        $this->assertSame(26.5, (float) $this->breed->fresh()->young_below_kg);

        $this->actingAs($this->user('ceo'))->post("/settings/breeds/{$this->breed->id}", ['young_below_kg' => ''])->assertSessionHasNoErrors();
        $this->assertNull($this->breed->fresh()->young_below_kg);
    }
}
