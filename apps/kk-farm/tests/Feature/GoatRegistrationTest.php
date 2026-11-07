<?php

namespace Tests\Feature;

use App\Models\Animal;
use App\Models\AnimalPhoto;
use App\Models\Kidding;
use App\Models\Mating;
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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Goats/Register')->where('epc', self::EPC)->has('fields', 27)
                ->where('fields.0.key', 'rfid_epc')->where('fields.1.key', 'breed_id')->where('fields.2.key', 'tag_id')
                ->where('fields', function ($fields) {
                    $by = collect($fields)->keyBy('key');
                    $labels = fn (string $key) => collect($by[$key]['options'])->pluck('label')->all();
                    $this->assertSame(['Female', 'Male'], $labels('sex'));
                    $this->assertSame(['Farm Born', 'Purchased', 'Transferred In'], $labels('origin'));
                    $this->assertSame([
                        'Breeding Male (Buck) (Nasli Bakra)', 'Breeding Female (Doe) (Nasli Bakri)', 'Replacement Breeding Male (Future Breeder Bakra)',
                        'Replacement Breeding Female (Future Breeder Bakri)', 'Male Kid (Nar Bacha)', 'Female Kid (Mada Bachi)', 'Growing Male Goat (Growing Bakra)',
                        'Growing Female Goat (Growing Bakri)', 'Fattening Male Goat (Qurbani / Fattening Bakra)', 'Castrated Male (Wether) (Khassi Bakra)', 'Cull Goat (Sale / Rejected Goat)',
                    ], $labels('animal_class'));
                    $this->assertCount(14, $labels('repro_status'));
                    $this->assertSame(['Kid', 'Grower', 'Adult'], $labels('age_category'));
                    $this->assertSame(['Newborn', 'Suckling', 'Weaned', 'Breeding Selected', 'Sale Selected', 'Orphan'], $labels('kid_stage'));
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
            'rfid_epc' => strtolower(self::EPC), 'from_scan' => true, 'tag_id' => 'KK-501', 'name' => 'Noor', 'animal_class' => 'replacement_female',
            'repro_status' => 'pregnant', 'health_flag' => 'monitor', 'ownership' => 'partner', 'source_farm' => 'Cholistan Farm', 'acquired_on' => now()->subMonth()->toDateString(),
            'sire_id' => $sire->id, 'dam_id' => $dam->id, 'birth_weight_kg' => 3.2, 'latest_weight_kg' => 31.5, 'purchase_cost_pkr' => 18500, 'notes' => 'Calm.',
        ]))->assertSessionHasNoErrors()->assertRedirect();

        $goat = Animal::where('farm_number', 'KK-501')->firstOrFail();
        $this->assertSame('Noor', $goat->name);
        $this->assertSame(['replacement_female', 'pregnant', 'monitor', 'partner', 'Cholistan Farm'], [$goat->animal_class, $goat->repro_status, $goat->health_flag, $goat->ownership, $goat->source_farm]);
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
        $this->assertSame('BB', Breed::create(['name' => 'Barbari', 'default_gestation_days' => 150])->code);
        $this->assertSame('BA', Breed::create(['name' => 'Balochi', 'default_gestation_days' => 150])->code);
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

        foreach ([['male', 'breeding_female'], ['male', 'replacement_female'], ['male', 'female_kid'], ['female', 'wether'], ['female', 'breeding_male'], ['female', 'fattening_male']] as [$sex, $class]) {
            $this->actingAs($caretaker)->post('/goats', $this->payload(['sex' => $sex, 'animal_class' => $class]))->assertSessionHasErrors(['animal_class']);
        }
        $this->assertSame(0, Animal::count());

        foreach ([['male', 'breeding_male'], ['male', 'wether'], ['male', 'male_kid'], ['female', 'breeding_female'], ['female', 'female_kid'], ['female', 'cull']] as [$sex, $class]) {
            $this->actingAs($caretaker)->post('/goats', $this->payload(['sex' => $sex, 'animal_class' => $class]))->assertSessionHasNoErrors();
        }
        $this->assertSame(6, Animal::count());
    }

    private function mating(Animal $dam, ?Animal $sire, int $daysAgo, string $status = 'pregnant'): Mating
    {
        $on = now()->subDays($daysAgo);

        return Mating::create(['dam_id' => $dam->id, 'sire_id' => $sire?->id, 'mated_on' => $on, 'expected_kidding_on' => $on->copy()->addDays(150), 'status' => $status, 'recorded_by' => 'x']);
    }

    public function test_a_farm_born_kid_gets_its_sire_from_the_dams_mating_and_records_the_kidding(): void
    {
        $caretaker = $this->user('caretaker');
        $dam = $this->goat('DAM-1', 'female');
        $sire = $this->goat('SIRE-1', 'male');
        $mating = $this->mating($dam, $sire, 150);

        $kid = ['origin' => 'born_on_farm', 'dam_id' => $dam->id, 'birth_date' => now()->toDateString(), 'animal_class' => 'female_kid'];
        $this->actingAs($caretaker)->post('/goats', $this->payload($kid))->assertSessionHasNoErrors();
        $this->actingAs($caretaker)->post('/goats', $this->payload(['sex' => 'male', 'animal_class' => 'male_kid'] + $kid))->assertSessionHasNoErrors();

        $kids = Animal::whereNotNull('birth_kidding_id')->get();
        $this->assertCount(2, $kids);
        $this->assertSame([$sire->id, $sire->id], $kids->pluck('sire_id')->all(), 'the sire came from the mating');
        $kidding = Kidding::where('mating_id', $mating->id)->sole();
        $this->assertSame(2, $kidding->born_alive, 'twins are counted in one kidding');
        $this->assertSame([$kidding->id], $kids->pluck('birth_kidding_id')->unique()->values()->all());
        $this->assertSame('kidded', $mating->fresh()->status);
        $this->assertSame('lactating', $dam->fresh()->repro_state);
    }

    public function test_no_mating_in_the_gestation_window_means_no_sire_is_guessed(): void
    {
        $dam = $this->goat('DAM-1', 'female');
        $this->mating($dam, $this->goat('SIRE-1', 'male'), 40); // far too recent to be this kid's father

        $this->actingAs($this->user('caretaker'))->post('/goats', $this->payload(['origin' => 'born_on_farm', 'dam_id' => $dam->id, 'birth_date' => now()->toDateString()]))->assertSessionHasNoErrors();

        $kid = Animal::where('dam_id', $dam->id)->sole();
        $this->assertNull($kid->sire_id);
        $this->assertNull($kid->birth_kidding_id);
        $this->assertSame(0, Kidding::count());
    }

    public function test_a_sire_chosen_by_hand_is_kept_and_not_tied_to_another_bucks_mating(): void
    {
        $dam = $this->goat('DAM-1', 'female');
        $this->mating($dam, $this->goat('SIRE-1', 'male'), 150);
        $other = $this->goat('SIRE-2', 'male');

        $this->actingAs($this->user('caretaker'))->post('/goats', $this->payload(['origin' => 'born_on_farm', 'dam_id' => $dam->id, 'sire_id' => $other->id, 'birth_date' => now()->toDateString()]))->assertSessionHasNoErrors();

        $kid = Animal::where('dam_id', $dam->id)->sole();
        $this->assertSame($other->id, $kid->sire_id);
        $this->assertSame(0, Kidding::count());
    }

    public function test_the_register_page_knows_each_does_mating(): void
    {
        $dam = $this->goat('DAM-1', 'female');
        $sire = $this->goat('SIRE-1', 'male');
        $this->mating($dam, $sire, 100);

        $this->actingAs($this->user('caretaker'))->get('/goats/register')
            ->assertInertia(fn (AssertableInertia $page) => $page->where("damMatings.{$dam->id}.sireId", $sire->id)->where("damMatings.{$dam->id}.sire", 'SIRE-1')->where("damMatings.{$dam->id}.status", 'pregnant'));
    }

    public function test_a_profile_picture_taken_at_registration_is_saved_as_the_goats_profile_photo(): void
    {
        Storage::fake();

        $this->actingAs($this->user('caretaker'))->post('/goats', $this->payload(['photo' => UploadedFile::fake()->image('goat.jpg', 800, 600)]))->assertSessionHasNoErrors();

        $photo = AnimalPhoto::sole();
        $this->assertTrue($photo->is_profile);
        Storage::assertExists($photo->path);
        $this->actingAs($this->user('caretaker'))->post('/goats', $this->payload(['photo' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf')]))->assertSessionHasErrors(['photo']);
    }

    public function test_an_unregistered_parent_is_kept_by_tag_and_linked_when_it_is_registered(): void
    {
        $manager = $this->user('manager');
        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'KID-1', 'sire_tag' => 'bt-0099', 'dam_tag' => 'DAM-77']))->assertSessionHasNoErrors();

        $kid = Animal::where('farm_number', 'KID-1')->sole();
        $this->assertSame([null, 'BT-0099', null, 'DAM-77'], [$kid->sire_id, $kid->sire_tag, $kid->dam_id, $kid->dam_tag]);

        // The sire arrives later and is registered with that tag: the kid is linked to it.
        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'BT-0099', 'sex' => 'male']))->assertSessionHasNoErrors();
        $sire = Animal::where('farm_number', 'BT-0099')->sole();
        $kid->refresh();
        $this->assertSame([$sire->id, null], [$kid->sire_id, $kid->sire_tag]);
        $this->assertSame('DAM-77', $kid->dam_tag, 'the dam is still only known by tag');
        $this->assertTrue($kid->events()->where('summary', 'like', 'BT-0099 registered and linked%')->exists());
    }

    public function test_a_typed_parent_tag_of_a_registered_goat_becomes_a_real_link_and_is_checked(): void
    {
        $manager = $this->user('manager');
        $buck = $this->goat('BUCK-1', 'male');
        $doe = $this->goat('DOE-1', 'female');

        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'KID-2', 'sire_tag' => 'buck-1', 'dam_tag' => 'DOE-1']))->assertSessionHasNoErrors();
        $kid = Animal::where('farm_number', 'KID-2')->sole();
        $this->assertSame([$buck->id, null, $doe->id, null], [$kid->sire_id, $kid->sire_tag, $kid->dam_id, $kid->dam_tag]);

        $this->actingAs($manager)->post('/goats', $this->payload(['sire_tag' => 'DOE-1']))->assertSessionHasErrors(['sire_id']);
        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'SELF-1', 'dam_tag' => 'SELF-1']))->assertSessionHasErrors(['dam_id']);
        $this->actingAs($manager)->post('/goats', $this->payload(['sire_tag' => 'bad tag!']))->assertSessionHasErrors(['sire_tag']);
    }

    public function test_a_mating_can_be_recorded_with_an_unrecorded_buck(): void
    {
        $doe = $this->goat('DOE-1', 'female');

        $this->actingAs($this->user('manager'))->post('/breeding/matings', ['dam_id' => $doe->id, 'sire_id' => '', 'mated_on' => now()->subDays(140)->toDateString(), 'method' => 'natural'])->assertSessionHasNoErrors();

        $this->assertNull(Mating::where('dam_id', $doe->id)->sole()->sire_id);
    }

    public function test_the_goat_page_shows_parents_including_those_only_known_by_tag(): void
    {
        $manager = $this->user('manager');
        $buck = $this->goat('BUCK-1', 'male');
        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'KID-3', 'sire_id' => $buck->id, 'dam_tag' => 'DAM-9']));
        $kid = Animal::where('farm_number', 'KID-3')->sole();

        $this->actingAs($manager)->get("/goats/{$kid->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('goat.sire.id', $buck->id)->where('goat.sire.number', 'BUCK-1')
            ->where('goat.dam.id', null)->where('goat.dam.number', 'DAM-9'));
    }

    public function test_age_and_goat_category_follow_from_birth_date_gender_and_purpose_when_left_empty(): void
    {
        $manager = $this->user('manager');
        $cases = [
            ['KID-M', 'male', now()->subMonths(2), null, 'kid', 'male_kid'],
            ['GRW-F', 'female', now()->subMonths(8), 'replacement', 'grower', 'replacement_female'],
            ['ADL-F', 'female', now()->subYears(2), 'breeding', 'adult', 'breeding_female'],
            ['FAT-M', 'male', now()->subMonths(9), 'fattening', 'grower', 'fattening_male'],
        ];
        foreach ($cases as [$tag, $sex, $born, $purpose]) {
            $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => $tag, 'sex' => $sex, 'birth_date' => $born->toDateString(), 'animal_purpose' => $purpose]))->assertSessionHasNoErrors();
        }
        foreach ($cases as [$tag, , , , $age, $category]) {
            $goat = Animal::where('farm_number', $tag)->sole();
            $this->assertSame([$age, $category], [$goat->age_category, $goat->animal_class], $tag);
        }
    }

    public function test_female_status_is_only_for_female_goats(): void
    {
        $this->actingAs($this->user('manager'))->post('/goats', $this->payload(['sex' => 'male', 'repro_status' => 'pregnant']))->assertSessionHasErrors(['repro_status']);
        $this->actingAs($this->user('manager'))->post('/goats', $this->payload(['sex' => 'female', 'repro_status' => 'pregnant']))->assertSessionHasNoErrors();
        $this->assertSame('pregnant', Animal::sole()->repro_state, 'the older pregnancy field stays in step');
    }

    public function test_the_goat_page_shows_the_generated_classification(): void
    {
        $manager = $this->user('manager');
        $mc = Breed::create(['name' => 'Makhi Cheeni (Beetal Variety)', 'default_gestation_days' => 150]);
        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'MC-1', 'breed_id' => $mc->id, 'animal_purpose' => 'breeding', 'animal_class' => 'breeding_female', 'repro_status' => 'pregnant']));
        $goat = Animal::where('farm_number', 'MC-1')->sole();

        $this->actingAs($manager)->get("/goats/{$goat->id}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('goat.classification', 'Breeding Female (Doe) · Makhi Cheeni (Beetal Variety) · Breeding · Pregnant'));
    }

    public function test_breed_purity_is_set_from_the_parents_breeds(): void
    {
        $manager = $this->user('manager');
        $teddy = Breed::create(['name' => 'Teddy', 'default_gestation_days' => 150]);
        $sire = $this->goat('SIRE-1', 'male');
        $dam = Animal::create(['farm_number' => 'DAM-1', 'sex' => 'female', 'breed_id' => $teddy->id, 'birth_date' => now()->subYears(2), 'origin' => 'purchased', 'pen_id' => $this->pen->id]);
        $pureDam = $this->goat('DAM-2', 'female');

        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'X-1', 'sire_id' => $sire->id, 'dam_id' => $dam->id]))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => 'P-1', 'sire_id' => $sire->id, 'dam_id' => $pureDam->id]))->assertSessionHasNoErrors();

        $cross = Animal::where('farm_number', 'X-1')->sole();
        $this->assertSame(['first_cross', 'Beetal × Teddy'], [$cross->breed_purity, $cross->cross_breeds]);
        $this->assertSame('pure', Animal::where('farm_number', 'P-1')->value('breed_purity'));
    }

    public function test_the_dashboard_counts_the_herd_by_ceo_category(): void
    {
        $manager = $this->user('ceo');
        foreach ([['B-1', 'male', 'breeding_male', null], ['B-2', 'female', 'breeding_female', 'pregnant'], ['B-3', 'female', 'breeding_female', 'ready'], ['K-1', 'male', 'male_kid', null]] as [$tag, $sex, $class, $status]) {
            $this->actingAs($manager)->post('/goats', $this->payload(['tag_id' => $tag, 'sex' => $sex, 'animal_class' => $class, 'repro_status' => $status]))->assertSessionHasNoErrors();
        }

        $this->actingAs($manager)->get('/')->assertInertia(fn (AssertableInertia $page) => $page->where('herd', function ($herd) {
            $v = collect($herd)->pluck('value', 'key');
            $this->assertSame([4, 1, 2, 1, 1, 1], [$v['total'], $v['breeding_males'], $v['breeding_females'], $v['pregnant'], $v['ready'], $v['male_kids']]);
            $this->assertCount(12, $herd);

            return true;
        }));
    }

    public function test_the_owner_can_add_a_custom_field_and_it_is_used_on_the_form_and_saved_with_the_goat(): void
    {
        $owner = $this->user('ceo');
        $this->actingAs($owner)->post('/settings/fields', ['label' => 'Horn status', 'type' => 'select', 'options' => "Horned\nPolled", 'section' => 'Custom', 'required' => true])->assertSessionHasNoErrors();

        $field = FieldDefinition::where('key', 'horn_status')->firstOrFail();
        $this->assertFalse($field->builtin);
        $this->assertSame(['horned', 'polled'], array_column($field->options, 'value'));

        $this->actingAs($owner)->get('/goats/register')->assertInertia(fn (AssertableInertia $page) => $page->has('fields', 28)->where('fields.27.key', 'horn_status')->where('fields.27.required', true));

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
        $this->assertSame([...array_keys(\App\Support\Classification::CATEGORIES), 'weaner'], array_column($class->options, 'value'));
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
        $this->actingAs($owner)->get('/goats/register')->assertInertia(fn (AssertableInertia $page) => $page->has('fields', 27));
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

        $this->assertSame(27, FieldDefinition::withoutGlobalScopes()->where('org_id', $other->id)->count(), 'a new organization starts with the standard fields');
        $this->actingAs($outsider)->get('/goats/register')->assertInertia(fn (AssertableInertia $page) => $page->has('fields', 27)->where('fields', fn ($f) => ! collect($f)->contains('key', 'horn_status')));
    }
}
