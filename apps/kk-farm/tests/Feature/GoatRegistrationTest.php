<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\Breed;
use App\Models\FieldDefinition;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Models\Shed;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Farm;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** D-040: a scan starts the goat register when there is nothing to show; the form is the KK Farms Goat Register sheet. */
class GoatRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const EPC = 'E2806894000050123456789A';

    private Organization $org;

    private Breed $breed;

    private Pen $pen;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->org = Organization::create(['name' => 'Test Farm', 'slug' => 'test-farm']);
        Tenant::set($this->org->id);
        $this->breed = Breed::create(['name' => 'Beetal', 'default_gestation_days' => 150]);
        $this->pen = Pen::create(['shed_id' => Shed::create(['code' => 'SHED-T', 'name' => 'Test Shed'])->id, 'name' => 'Pen T', 'purpose' => 'Kids']);
    }

    private function user(string $role, ?Organization $org = null): User
    {
        return User::firstOrCreate(['email' => "$role-".($org?->slug ?? 'a').'@test.local'], ['name' => "Test $role", 'password' => 'unused', 'role' => $role, 'org_id' => ($org ?? $this->org)->id]);
    }

    private function payload(array $override = []): array
    {
        return $override + ['sex' => 'female', 'breed_id' => $this->breed->id, 'pen_id' => $this->pen->id, 'birth_date' => now()->subYear()->toDateString(), 'origin' => 'purchased'];
    }

    private function goat(string $number = 'T-0001', string $sex = 'female'): Animal
    {
        return Animal::create(['farm_number' => $number, 'sex' => $sex, 'breed_id' => $this->breed->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id])->refresh();
    }

    public function test_scanning_a_tag_the_farm_has_never_seen_starts_the_goat_register(): void
    {
        $this->actingAs($this->user('caretaker'))->get('/scan?tag='.strtolower(self::EPC))
            ->assertRedirect('/goats/register?epc='.self::EPC);
    }

    public function test_scanning_a_tag_that_was_never_used_on_a_goat_starts_the_goat_register(): void
    {
        RfidTag::create(['tag_code' => 'NEW-1', 'status' => 'in_stock']);

        $this->actingAs($this->user('caretaker'))->get('/scan?tag=NEW-1')->assertRedirect('/goats/register?epc=NEW-1');
    }

    public function test_scanning_a_tag_on_a_goat_shows_that_goat_even_when_typed_in_lower_case(): void
    {
        $caretaker = $this->user('caretaker');
        $goat = $this->goat();
        Farm::assignTag($goat, RfidTag::create(['tag_code' => self::EPC]), 'initial', $caretaker);

        $this->actingAs($caretaker)->get('/scan?tag='.strtolower(self::EPC))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Scan')->where('result.state', 'assigned')->where('result.goat.id', $goat->id));
    }

    public function test_a_tag_released_by_a_goat_still_shows_its_history_instead_of_a_blank_form(): void
    {
        $caretaker = $this->user('caretaker');
        $goat = $this->goat();
        Farm::assignTag($goat, RfidTag::create(['tag_code' => 'USED-1']), 'initial', $caretaker);
        Farm::closeOpenTag($goat, 'removed', $caretaker);

        $this->actingAs($caretaker)->get('/scan?tag=USED-1')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Scan')->where('result.state', 'free')->has('result.history', 1));
    }

    public function test_the_register_form_has_the_workbook_fields_in_order_with_their_dropdown_options(): void
    {
        $this->actingAs($this->user('caretaker'))->get('/goats/register?epc='.self::EPC)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Goats/Register')->where('epc', self::EPC)->has('fields', 22)
                ->where('fields.0.key', 'rfid_epc')->where('fields.1.key', 'breed_id')->where('fields.2.key', 'tag_id')
                ->where('fields', function ($fields) {
                    $by = collect($fields)->keyBy('key');
                    $labels = fn (string $key) => collect($by[$key]['options'])->pluck('label')->all();
                    $this->assertSame(['Female', 'Male'], $labels('sex'));
                    $this->assertSame(['Farm Born', 'Purchased', 'Transferred In'], $labels('origin'));
                    $this->assertSame(['Kid', 'Replacement Doe', 'Breeding Doe', 'Breeding Buck', 'Wether', 'Other'], $labels('animal_class'));
                    $this->assertSame(['N/A', 'Open', 'In Heat', 'Bred', 'Pregnant', 'Lactating', 'Dry', 'Postpartum'], $labels('repro_status'));
                    $this->assertSame(['Active', 'Sold', 'Dead', 'Culled', 'Transferred'], $labels('lifecycle_status'));
                    $this->assertSame(['Healthy', 'Monitor', 'Sick', 'Quarantine', 'Recovering'], $labels('health_flag'));
                    $this->assertSame(['KK Farms', 'Partner', 'Consignment', 'Other'], $labels('ownership'));
                    $this->assertTrue($by['sex']['required'] && $by['breed_id']['required'] && $by['pen_id']['required'] && $by['birth_date']['required']);

                    return true;
                }));
    }

    public function test_registering_from_a_scan_creates_the_goat_assigns_the_tag_and_saves_the_first_weight(): void
    {
        $dam = $this->goat('DAM-1', 'female');
        $sire = $this->goat('SIRE-1', 'male');

        $this->actingAs($this->user('caretaker'))->post('/goats', $this->payload([
            'rfid_epc' => strtolower(self::EPC), 'from_scan' => true, 'tag_id' => 'KK-501', 'name' => 'Noor', 'animal_class' => 'replacement_doe',
            'repro_status' => 'pregnant', 'health_flag' => 'monitor', 'ownership' => 'partner', 'source_farm' => 'Cholistan Farm', 'acquired_on' => now()->subMonth()->toDateString(),
            'sire_id' => $sire->id, 'dam_id' => $dam->id, 'birth_weight_kg' => 3.2, 'latest_weight_kg' => 31.5, 'purchase_cost_pkr' => 18500, 'notes' => 'Calm.',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $goat = Animal::where('farm_number', 'KK-501')->firstOrFail();
        $this->assertSame('Noor', $goat->name);
        $this->assertSame(['replacement_doe', 'pregnant', 'monitor', 'partner', 'Cholistan Farm'], [$goat->animal_class, $goat->repro_status, $goat->health_flag, $goat->ownership, $goat->source_farm]);
        $this->assertSame('watch', $goat->health_status, 'Monitor maps to the existing watch state');
        $this->assertSame('pregnant', $goat->repro_state);
        $this->assertSame(1850000, $goat->purchase_cost_minor, 'Money is stored in paisa');
        $this->assertSame([$sire->id, $dam->id], [$goat->sire_id, $goat->dam_id]);
        $this->assertSame(self::EPC, RfidAssignment::where('animal_id', $goat->id)->whereNull('valid_to')->with('tag')->firstOrFail()->tag->tag_code, 'the scanned code is stored upper-case');
        $this->assertEquals(31.5, WeightRecord::where('animal_id', $goat->id)->where('context', 'registration')->value('weight_kg'));
        $this->assertTrue($goat->events()->where('summary', 'like', '%RFID scan%')->exists());
    }

    public function test_a_blank_tag_id_is_generated_from_the_breed_with_a_counter_per_breed(): void
    {
        $manager = $this->user('manager');
        $teddy = Breed::create(['name' => 'Teddy', 'default_gestation_days' => 150]);
        $boer = Breed::create(['name' => 'Kalahari Red', 'default_gestation_days' => 150]);

        foreach ([$this->breed, $this->breed, $teddy, $this->breed, $boer] as $breed) {
            $this->actingAs($manager)->post('/goats', $this->payload(['breed_id' => $breed->id]))->assertSessionHasNoErrors();
        }

        $this->assertSame(['BT-0001', 'BT-0002', 'BT-0003', 'KR-0001', 'TD-0001'], Animal::orderBy('farm_number')->pluck('farm_number')->all());
        $this->assertSame('BT-0004', $this->breed->fresh()->nextTagId());
    }

    public function test_a_typed_tag_id_is_kept_and_generated_ids_skip_numbers_already_in_use(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'bt-0001']))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/goats', $this->payload())->assertSessionHasNoErrors();

        $this->assertSame(['BT-0001', 'BT-0002'], Animal::orderBy('farm_number')->pluck('farm_number')->all(), 'BT-0001 was typed by hand, so the generator skips it');
    }

    public function test_breed_codes_come_from_the_workbook_or_initials_and_never_repeat(): void
    {
        $this->assertSame('BT', $this->breed->code);
        $this->assertSame('DDP', Breed::create(['name' => 'Dera Din Panah', 'default_gestation_days' => 150])->code);
        $this->assertSame('BA', Breed::create(['name' => 'Barbari', 'default_gestation_days' => 150])->code);
        $this->assertSame('BA2', Breed::create(['name' => 'Bannu', 'default_gestation_days' => 150])->code, 'BA is taken, so the next free code is used');
    }

    public function test_the_register_page_shows_the_tag_id_each_breed_will_give(): void
    {
        $this->actingAs($this->user('caretaker'))->get('/goats/register')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('lookups.breed_id.0.label', 'Beetal')->where('lookups.breed_id.0.next', 'BT-0001'));
    }

    public function test_the_owner_can_add_a_breed_with_its_own_tag_code(): void
    {
        $this->actingAs($this->user('ceo'))->post('/settings/breeds', ['name' => 'Kamori Pure', 'code' => 'kp'])->assertSessionHasNoErrors();
        $this->assertSame('KP', Breed::where('name', 'Kamori Pure')->value('code'));

        $this->actingAs($this->user('ceo'))->post('/settings/breeds', ['name' => 'Another', 'code' => 'BT'])->assertSessionHasErrors(['code']);
    }

    public function test_a_tag_id_or_a_tag_already_in_use_is_refused_and_no_goat_is_created(): void
    {
        $manager = $this->user('manager');
        $holder = $this->goat('T-0001');
        Farm::assignTag($holder, RfidTag::create(['tag_code' => self::EPC]), 'initial', $manager);

        $this->actingAs($manager)->post('/goats', $this->payload(['rfid_epc' => self::EPC]))->assertSessionHasErrors(['rfid_epc']);
        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'T-0001']))->assertSessionHasErrors(['tag_id']);
        $this->assertSame(1, Animal::count());
    }

    public function test_only_roles_that_may_assign_tags_can_register_with_a_tag(): void
    {
        $this->actingAs($this->user('vet'))->post('/goats', $this->payload(['rfid_epc' => self::EPC]))->assertSessionHasErrors(['rfid_epc']);
        $this->assertSame(0, Animal::count());
        $this->assertSame(0, RfidTag::count());

        $this->actingAs($this->user('vet'))->post('/goats', $this->payload())->assertSessionHasNoErrors();
        $this->assertSame(1, Animal::count());
    }

    public function test_required_fields_dropdown_values_and_parent_sex_are_checked(): void
    {
        $doe = $this->goat('DOE-1', 'female');
        $caretaker = $this->user('caretaker');

        $this->actingAs($caretaker)->post('/goats', [])->assertSessionHasErrors(['sex', 'breed_id', 'pen_id', 'birth_date', 'origin']);
        $this->actingAs($caretaker)->post('/goats', $this->payload(['animal_class' => 'banana']))->assertSessionHasErrors(['animal_class']);
        $this->actingAs($caretaker)->post('/goats', $this->payload(['sire_id' => $doe->id]))->assertSessionHasErrors(['sire_id']);
        $this->actingAs($caretaker)->post('/goats', $this->payload(['birth_date' => now()->addDay()->toDateString()]))->assertSessionHasErrors(['birth_date']);
        $this->assertSame(1, Animal::count());
    }

    public function test_the_animal_class_must_fit_the_sex(): void
    {
        $caretaker = $this->user('caretaker');

        $this->actingAs($caretaker)->post('/goats', $this->payload(['sex' => 'male', 'animal_class' => 'breeding_doe']))->assertSessionHasErrors(['animal_class']);
        $this->actingAs($caretaker)->post('/goats', $this->payload(['sex' => 'male', 'animal_class' => 'replacement_doe']))->assertSessionHasErrors(['animal_class']);
        $this->actingAs($caretaker)->post('/goats', $this->payload(['sex' => 'female', 'animal_class' => 'wether']))->assertSessionHasErrors(['animal_class']);
        $this->actingAs($caretaker)->post('/goats', $this->payload(['sex' => 'female', 'animal_class' => 'breeding_buck']))->assertSessionHasErrors(['animal_class']);
        $this->assertSame(0, Animal::count());

        foreach ([['male', 'breeding_buck'], ['male', 'wether'], ['male', 'kid'], ['female', 'breeding_doe'], ['female', 'kid'], ['female', 'other']] as [$sex, $class]) {
            $this->actingAs($caretaker)->post('/goats', $this->payload(['sex' => $sex, 'animal_class' => $class]))->assertSessionHasNoErrors();
        }
        $this->assertSame(6, Animal::count());
    }

    public function test_the_owner_can_add_a_custom_field_and_it_is_used_on_the_form_and_saved_with_the_goat(): void
    {
        $owner = $this->user('ceo');
        $this->actingAs($owner)->post('/settings/fields', ['label' => 'Horn status', 'type' => 'select', 'options' => "Horned\nPolled", 'section' => 'Custom', 'required' => true])->assertSessionHasNoErrors();

        $field = FieldDefinition::where('key', 'horn_status')->firstOrFail();
        $this->assertFalse($field->builtin);
        $this->assertSame(['horned', 'polled'], array_column($field->options, 'value'));

        $this->actingAs($owner)->get('/goats/register')->assertInertia(fn (AssertableInertia $page) => $page->has('fields', 23)->where('fields.22.key', 'horn_status')->where('fields.22.required', true));

        $this->actingAs($owner)->post('/goats', $this->payload())->assertSessionHasErrors(['custom_fields.horn_status']);
        $this->actingAs($owner)->post('/goats', $this->payload(['custom_fields' => ['horn_status' => 'bogus']]))->assertSessionHasErrors(['custom_fields.horn_status']);
        $this->actingAs($owner)->post('/goats', $this->payload(['custom_fields' => ['horn_status' => 'polled']]))->assertSessionHasNoErrors();

        $this->assertSame(['horn_status' => 'polled'], Animal::firstOrFail()->custom_fields);
    }

    public function test_editing_dropdown_options_keeps_the_values_of_goats_already_registered(): void
    {
        $owner = $this->user('ceo');
        $class = FieldDefinition::where('key', 'animal_class')->firstOrFail();
        $labels = collect($class->options)->pluck('label')->push('Weaner')->implode("\n");

        $this->actingAs($owner)->post("/settings/fields/{$class->id}", ['label' => 'Class', 'options' => $labels])->assertSessionHasNoErrors();

        $class->refresh();
        $this->assertSame('Class', $class->label);
        $this->assertSame(['kid', 'replacement_doe', 'breeding_doe', 'breeding_buck', 'wether', 'other', 'weaner'], array_column($class->options, 'value'));
    }

    public function test_the_fields_needed_to_register_a_goat_cannot_be_switched_off_and_custom_fields_can(): void
    {
        $owner = $this->user('ceo');
        $sex = FieldDefinition::where('key', 'sex')->firstOrFail();
        $this->actingAs($owner)->post("/settings/fields/{$sex->id}", ['label' => 'Sex', 'options' => "Female\nMale", 'active' => false])->assertStatus(422);

        $this->actingAs($owner)->post('/settings/fields', ['label' => 'Coat note', 'type' => 'text', 'section' => 'Custom', 'required' => true]);
        $note = FieldDefinition::where('key', 'coat_note')->firstOrFail();
        $this->actingAs($owner)->post("/settings/fields/{$note->id}", ['label' => 'Coat note', 'active' => false, 'required' => true])->assertSessionHasNoErrors();

        // Switched off means hidden and no longer required.
        $this->actingAs($owner)->get('/goats/register')->assertInertia(fn (AssertableInertia $page) => $page->has('fields', 22));
        $this->actingAs($owner)->post('/goats', $this->payload())->assertSessionHasNoErrors();
    }

    public function test_only_the_owner_manages_register_fields(): void
    {
        $this->actingAs($this->user('manager'))->get('/settings/fields')->assertForbidden();
        $this->actingAs($this->user('caretaker'))->post('/settings/fields', ['label' => 'X', 'type' => 'text', 'section' => 'Custom'])->assertForbidden();
        $this->actingAs($this->user('ceo'))->get('/settings/fields')->assertOk();
    }

    public function test_another_organizations_custom_fields_and_goats_never_appear(): void
    {
        $this->actingAs($this->user('ceo'))->post('/settings/fields', ['label' => 'Horn status', 'type' => 'text', 'section' => 'Custom']);
        $other = Organization::create(['name' => 'Other Farm', 'slug' => 'other-farm']);
        $outsider = $this->user('ceo', $other);

        $this->assertSame(22, FieldDefinition::withoutGlobalScopes()->where('org_id', $other->id)->count(), 'a new organization starts with the standard fields');
        $this->actingAs($outsider)->get('/goats/register')->assertInertia(fn (AssertableInertia $page) => $page->has('fields', 22)->where('fields', fn ($f) => ! collect($f)->contains('key', 'horn_status')));
    }
}
