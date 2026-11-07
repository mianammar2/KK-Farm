<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Models\Breed;
use App\Models\Employee;
use App\Models\FeedingGroup;
use App\Models\FeedingLog;
use App\Models\HealthRecord;
use App\Models\Kidding;
use App\Models\LedgerEntry;
use App\Models\Mating;
use App\Models\Organization;
use App\Models\Pen;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Models\Shed;
use App\Models\Task;
use App\Models\User;
use App\Models\WeightRecord;
use App\Support\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Run with: php artisan migrate:fresh --seed --seeder=DemoSeeder
 *
 * Entirely synthetic demonstration farm. Every person, animal, tag and amount is invented.
 * Nothing here describes the real KK Farm; shed layout, feeding groups and schedules are placeholders
 * until the farm visit confirms them.
 */
class DemoSeeder extends Seeder
{
    private const FEMALE = ['Noor', 'Rani', 'Zoya', 'Heer', 'Sitara', 'Gul', 'Mehak', 'Chandni', 'Laila', 'Sohni', 'Roshni', 'Pari', 'Husna', 'Sana', 'Maya', 'Anmol', 'Bano', 'Dua', 'Farah', 'Ghazal', 'Hina', 'Iqra', 'Jia', 'Kiran', 'Lubna', 'Mahi', 'Nimra', 'Rabia', 'Saba', 'Tania', 'Uzma', 'Warda', 'Yumna', 'Zara', 'Aiza', 'Bisma', 'Dania', 'Eman', 'Fiza', 'Hoorain'];

    private const MALE = ['Sher', 'Badshah', 'Raja', 'Toofan', 'Moti', 'Kalu', 'Bahadur', 'Sikandar', 'Rustam', 'Heera', 'Jugnu', 'Shahzada', 'Sultan', 'Tiger', 'Lala', 'Babar'];

    private int $sequence = 0;

    private int $tagSequence = 0;

    public function run(): void
    {
        mt_srand(20261107);

        // Everything below belongs to one synthetic organization; org_id fills itself from the bound tenant (D-037).
        Tenant::set(Organization::create(['name' => 'KK Farm (synthetic demo)', 'slug' => 'kk-farm-demo'])->id);

        $users = collect(config('kkfarm.roles'))->mapWithKeys(fn (string $label, string $role) => [$role => User::create([
            'name' => $role === 'vet' ? 'Dr. Demo Vet' : "Demo {$label}",
            'email' => "$role@demo.kkfarm.local",
            'password' => Hash::make(Str::random(40)),
            'role' => $role,
        ])]);

        $breeds = collect([
            'Beetal' => ['black', 'spotted', 'brown'], 'Teddy' => ['black', 'spotted', 'white'], 'Kamori' => ['brown', 'spotted'],
            'Dera Din Panah' => ['black'], 'Nachi' => ['black', 'tan'], 'Boer' => ['boer'], 'Crossbred' => ['tan', 'spotted', 'brown', 'white'],
        ])->map(fn (array $coats, string $name) => ['model' => Breed::create(['name' => $name, 'default_gestation_days' => config('kkfarm.demo_gestation_days')]), 'coats' => $coats]);

        $shed = Shed::create(['code' => 'SHED-1', 'name' => 'Shed 1', 'status' => 'active']);
        foreach (range(2, 6) as $n) {
            Shed::create(['code' => "SHED-$n", 'name' => "Shed $n", 'status' => 'planned']);
        }
        $pens = collect([
            'does' => ['Pen A', 'Breeding does', 44], 'pregnant' => ['Pen B', 'Pregnant does', 22], 'kids' => ['Pen C', 'Kids', 30],
            'growers' => ['Pen D', 'Growers', 34], 'bucks' => ['Pen E', 'Bucks', 16], 'isolation' => ['Pen F', 'Isolation', 6],
        ])->map(fn (array $p) => Pen::create(['shed_id' => $shed->id, 'name' => $p[0], 'purpose' => $p[1], 'capacity' => $p[2]]));

        $groups = collect([
            'does' => ['Breeding does', 'Adult does, not pregnant', [['Green fodder', 3.0], ['Concentrate', 0.3]], 'approved'],
            'pregnant' => ['Pregnant does', 'Confirmed pregnant', [['Green fodder', 3.5], ['Concentrate', 0.5], ['Mineral mix', 0.02]], 'approved'],
            'kids' => ['Kids', 'Under 6 months', [['Starter feed', 0.2], ['Green fodder', 0.8]], 'approved'],
            'growers' => ['Growers', '6 to 18 months', [['Green fodder', 2.5], ['Concentrate', 0.35]], 'approved'],
            'bucks' => ['Bucks', 'Breeding males', [['Green fodder', 4.0], ['Concentrate', 0.5]], 'pending_approval'],
            'isolation' => ['Special care', 'Under veterinary care', [['Soft fodder', 2.0]], 'approved'],
        ])->map(fn (array $g) => FeedingGroup::create([
            'name' => $g[0], 'criteria' => $g[1], 'plan_status' => $g[3],
            'ration' => array_map(fn (array $r) => ['item' => $r[0], 'kg' => $r[1]], $g[2]),
            'approved_by' => $g[3] === 'approved' ? 'Dr. Demo Vet' : null, 'approved_at' => $g[3] === 'approved' ? now()->subDays(20) : null,
        ]));

        $founders = collect();
        foreach (['Beetal', 'Beetal', 'Teddy', 'Kamori', 'Dera Din Panah', 'Boer'] as $breedName) {
            $founders->push($this->goat($breeds, 'male', mt_rand(36, 72), null, null, 'purchased', $breedName));
        }
        foreach (range(1, 30) as $i) {
            $founders->push($this->goat($breeds, 'female', mt_rand(36, 84), null, null, 'purchased'));
        }
        $bucks = $founders->where('sex', 'male')->values();
        $does = $founders->where('sex', 'female')->values();

        $second = collect();
        foreach (range(1, 40) as $i) {
            $dam = $does->random();
            $second->push($this->goat($breeds, mt_rand(0, 3) ? 'female' : 'male', mt_rand(18, 34), $this->pickSire($bucks, $dam), $dam, 'born_on_farm'));
        }
        $dams = $second->where('sex', 'female')->merge($does)->values();
        $third = collect();
        foreach (range(1, 50) as $i) {
            $dam = $dams->random();
            $sire = $this->pickSire($bucks, $dam);
            $third->push($this->goat($breeds, mt_rand(0, 1) ? 'female' : 'male', mt_rand(1, 16), $sire, $dam, 'born_on_farm'));
        }
        $all = $founders->merge($second)->merge($third);
        DB::statement("select setval('animal_number_seq', {$this->sequence})");

        $exits = [['left_farm', 'sold'], ['left_farm', 'sold'], ['left_farm', 'sold'], ['left_farm', 'sold'], ['left_farm', 'gifted'], ['left_farm', 'sacrificed'], ['deceased', 'died'], ['deceased', 'died']];
        $exited = $second->merge($third)->filter(fn (Animal $a) => $a->birth_date < now()->subMonths(6))->shuffle()->take(count($exits))->values();
        foreach ($exited as $i => $animal) {
            $animal->update(['status' => $exits[$i][0], 'exit_reason' => $exits[$i][1], 'exit_date' => now()->subDays(mt_rand(15, 200))]);
        }
        $active = $all->where('status', 'active')->values();

        $this->assignPensAndHealth($active, $pens, $groups, $users);
        $this->seedTags($all, $users['manager']);
        $this->seedWeights($all);
        $this->seedPreventiveCare($active);
        $this->seedBreeding($active, $third, $bucks, $pens, $groups);
        $this->seedLifecycleEvents($all);
        $this->seedOperations($pens, $groups);
        $this->seedLedger($shed, $exited);
        $this->seedActivity();
    }

    private function goat(Collection $breeds, string $sex, int $ageMonths, ?Animal $sire, ?Animal $dam, string $origin, ?string $fixedBreed = null): Animal
    {
        $this->sequence++;
        $names = $sex === 'female' ? self::FEMALE : self::MALE;
        $breedName = $fixedBreed ?? ($sire && $dam && $sire->breed_id === $dam->breed_id
            ? Breed::find($sire->breed_id)->name
            : ($sire ? 'Crossbred' : $this->pickBreed()));
        $breed = $breeds[$breedName];

        return Animal::create([
            'farm_number' => sprintf('DEMO-%04d', $this->sequence),
            'name' => mt_rand(0, 2) ? $names[$this->sequence % count($names)] : null,
            'sex' => $sex,
            'breed_id' => $breed['model']->id,
            'birth_date' => now()->subMonths($ageMonths)->subDays(mt_rand(0, 27))->toDateString(),
            'birth_date_is_estimate' => $origin === 'purchased',
            'origin' => $origin,
            'sire_id' => $sire?->id,
            'dam_id' => $dam?->id,
            'coat' => $breed['coats'][array_rand($breed['coats'])],
        ])->refresh();
    }

    /** Mostly same-breed pairings, never a doe's own sire, with some deliberate crossbreeding. */
    private function pickSire(Collection $bucks, Animal $dam): Animal
    {
        $eligible = $bucks->filter(fn (Animal $b) => $b->id !== $dam->sire_id);
        $sameBreed = $eligible->where('breed_id', $dam->breed_id);

        return $sameBreed->isNotEmpty() && mt_rand(0, 4) ? $sameBreed->random() : $eligible->random();
    }

    private function pickBreed(): string
    {
        $pool = ['Beetal', 'Beetal', 'Beetal', 'Beetal', 'Teddy', 'Teddy', 'Kamori', 'Kamori', 'Dera Din Panah', 'Nachi', 'Boer'];

        return $pool[array_rand($pool)];
    }

    private function assignPensAndHealth(Collection $active, Collection $pens, Collection $groups, Collection $users): void
    {
        foreach ($active as $animal) {
            $months = $animal->birth_date->diffInMonths(now());
            $key = match (true) {
                $months < 6 => 'kids',
                $months < 18 => 'growers',
                $animal->sex === 'male' => 'bucks',
                default => 'does',
            };
            $animal->update(['pen_id' => $pens[$key]->id, 'feeding_group_id' => $groups[$key]->id]);
        }

        $unwell = $active->shuffle()->take(12)->values();
        $cases = [
            ['critical', 'high', 'Off feed, not standing well'], ['treatment', 'high', 'Respiratory signs'], ['treatment', 'medium', 'Lameness, left foreleg'],
            ['treatment', 'medium', 'Scouring'], ['treatment', 'medium', 'Skin lesions'],
        ];
        foreach ($unwell as $i => $animal) {
            [$status, $severity, $title] = $cases[$i] ?? ['watch', 'low', ['Reduced appetite', 'Slow weight gain', 'Mild cough', 'Dull coat'][$i % 4]];
            $animal->update(['health_status' => $status] + ($status === 'watch' ? [] : ['pen_id' => $pens['isolation']->id, 'feeding_group_id' => $groups['isolation']->id]));
            HealthRecord::create([
                'animal_id' => $animal->id, 'kind' => $status === 'watch' ? 'observation' : 'examination', 'occurred_on' => now()->subDays(mt_rand(0, 6)),
                'title' => $title, 'notes' => 'Synthetic record for demonstration.', 'severity' => $severity,
                'recorded_by' => $status === 'watch' ? $users['caretaker']->name : $users['vet']->name,
                'recorded_role' => $status === 'watch' ? 'Caretaker' : 'Veterinarian', 'status' => 'open',
                'next_due_on' => $status === 'watch' ? null : now()->addDays(mt_rand(1, 5)),
            ]);
        }
    }

    private function seedTags(Collection $all, User $by): void
    {
        foreach ($all as $animal) {
            $tag = RfidTag::create(['tag_code' => $this->tagCode(), 'status' => 'assigned']);
            $from = $animal->origin === 'purchased' ? now()->subMonths(30) : $animal->birth_date->copy()->addDays(10);
            if ($animal->status === 'active') {
                RfidAssignment::create(['animal_id' => $animal->id, 'rfid_tag_id' => $tag->id, 'valid_from' => $from, 'reason_assigned' => 'initial', 'assigned_by' => $by->name]);
            } else {
                RfidAssignment::create(['animal_id' => $animal->id, 'rfid_tag_id' => $tag->id, 'valid_from' => $from, 'valid_to' => $animal->exit_date, 'reason_assigned' => 'initial', 'reason_unassigned' => $animal->exit_reason, 'assigned_by' => $by->name]);
                $tag->update(['status' => 'in_stock']);
            }
        }

        // One goat lost its first tag, showing a replaced tag in the identity history.
        $animal = $all->where('status', 'active')->values()->get(10);
        $open = RfidAssignment::where('animal_id', $animal->id)->whereNull('valid_to')->first();
        $open->update(['valid_to' => now()->subMonths(2), 'reason_unassigned' => 'lost']);
        RfidTag::whereKey($open->rfid_tag_id)->update(['status' => 'lost']);
        $replacement = RfidTag::create(['tag_code' => $this->tagCode(), 'status' => 'assigned']);
        RfidAssignment::create(['animal_id' => $animal->id, 'rfid_tag_id' => $replacement->id, 'valid_from' => now()->subMonths(2), 'reason_assigned' => 'replacement', 'assigned_by' => $by->name]);

        foreach (range(1, 12) as $i) {
            RfidTag::create(['tag_code' => $this->tagCode(), 'status' => 'in_stock']);
        }
    }

    private function tagCode(): string
    {
        return sprintf('SIM-982-%06d', 100000 + (++$this->tagSequence) * 37);
    }

    private function seedWeights(Collection $all): void
    {
        $rows = [];
        foreach ($all as $animal) {
            $end = $animal->exit_date ?? now();
            $age = (int) $animal->birth_date->diffInMonths($end);
            $mature = ($animal->sex === 'male' ? 62 : 44) * [0.9, 1.0, 1.08][mt_rand(0, 2)];
            foreach (range(min($age, 13), 0) as $back) {
                $on = $end->copy()->subMonths($back)->subDays(mt_rand(0, 3));
                if ($on < $animal->birth_date) {
                    continue;
                }
                $months = max(0, $animal->birth_date->diffInDays($on) / 30.4);
                $kg = 3.2 + $mature * (1 - exp(-$months / 13)) + mt_rand(-40, 40) / 100;
                if ($back === 0 && $animal->health_status !== 'healthy') {
                    $kg *= 0.93;
                }
                $rows[] = ['animal_id' => $animal->id, 'measured_on' => $on->toDateString(), 'weight_kg' => round($kg, 1), 'context' => 'routine', 'measured_by' => 'Demo Caretaker', 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            WeightRecord::insert($chunk);
        }
    }

    private function seedPreventiveCare(Collection $active): void
    {
        foreach ($active as $i => $animal) {
            $vaccinated = now()->subDays(mt_rand(30, 200));
            HealthRecord::create(['animal_id' => $animal->id, 'kind' => 'vaccination', 'occurred_on' => $vaccinated, 'title' => $i % 2 ? 'PPR vaccine' : 'Enterotoxaemia vaccine', 'recorded_by' => 'Dr. Demo Vet', 'recorded_role' => 'Veterinarian', 'next_due_on' => $vaccinated->copy()->addDays(180)]);
            $wormed = now()->subDays(mt_rand(10, 125));
            HealthRecord::create(['animal_id' => $animal->id, 'kind' => 'deworming', 'occurred_on' => $wormed, 'title' => 'Routine deworming', 'recorded_by' => 'Dr. Demo Vet', 'recorded_role' => 'Veterinarian', 'next_due_on' => $wormed->copy()->addDays(120)]);
        }
    }

    private function seedBreeding(Collection $active, Collection $third, Collection $bucks, Collection $pens, Collection $groups): void
    {
        foreach ($third->groupBy('dam_id') as $damId => $kids) {
            $born = $kids->first()->birth_date;
            $mating = Mating::create(['dam_id' => $damId, 'sire_id' => $kids->first()->sire_id, 'mated_on' => $born->copy()->subDays(config('kkfarm.demo_gestation_days')), 'expected_kidding_on' => $born, 'status' => 'kidded', 'recorded_by' => 'Demo Farm Manager']);
            Kidding::create(['dam_id' => $damId, 'mating_id' => $mating->id, 'kidded_on' => $born, 'born_alive' => $kids->count(), 'stillborn' => mt_rand(0, 9) === 0 ? 1 : 0, 'recorded_by' => 'Dr. Demo Vet']);
            if ($born > now()->subMonths(3)) {
                Animal::whereKey($damId)->where('status', 'active')->update(['repro_state' => 'lactating']);
            }
        }

        $candidates = Animal::active()->get()->filter(fn (Animal $a) => $a->sex === 'female' && $a->health_status === 'healthy' && $a->repro_state === null && $a->birth_date < now()->subMonths(16))->shuffle()->values();
        foreach ($candidates->take(14) as $doe) {
            $on = now()->subDays(mt_rand(35, 142));
            Mating::create(['dam_id' => $doe->id, 'sire_id' => $bucks->filter(fn (Animal $b) => $b->id !== $doe->sire_id)->random()->id, 'mated_on' => $on, 'expected_kidding_on' => $on->copy()->addDays(config('kkfarm.demo_gestation_days')), 'status' => 'pregnant', 'recorded_by' => 'Demo Farm Manager']);
            $doe->update(['repro_state' => 'pregnant', 'pen_id' => $pens['pregnant']->id, 'feeding_group_id' => $groups['pregnant']->id]);
        }
        foreach ($candidates->slice(14, 5) as $doe) {
            $on = now()->subDays(mt_rand(3, 25));
            Mating::create(['dam_id' => $doe->id, 'sire_id' => $bucks->filter(fn (Animal $b) => $b->id !== $doe->sire_id)->random()->id, 'mated_on' => $on, 'expected_kidding_on' => $on->copy()->addDays(config('kkfarm.demo_gestation_days')), 'status' => 'open', 'recorded_by' => 'Demo Farm Manager']);
        }
    }

    private function seedLifecycleEvents(Collection $all): void
    {
        $rows = [];
        $push = function (Animal $animal, string $type, string $summary, $at, string $by = 'Demo Farm Manager') use (&$rows): void {
            $rows[] = ['animal_id' => $animal->id, 'type' => $type, 'summary' => $summary, 'occurred_at' => $at, 'recorded_by' => $by, 'created_at' => now(), 'updated_at' => now()];
        };
        foreach ($all as $animal) {
            $animal->refresh();
            $push($animal, 'origin', $animal->origin === 'purchased' ? 'Purchased and registered' : 'Born on the farm', $animal->origin === 'purchased' ? now()->subMonths(30) : $animal->birth_date);
            foreach (RfidAssignment::where('animal_id', $animal->id)->with('tag')->get() as $assignment) {
                $push($animal, 'tag', "Tag {$assignment->tag->tag_code} assigned ({$assignment->reason_assigned})", $assignment->valid_from);
                if ($assignment->valid_to) {
                    $push($animal, 'tag', "Tag {$assignment->tag->tag_code} removed ({$assignment->reason_unassigned})", $assignment->valid_to);
                }
            }
            foreach (HealthRecord::where('animal_id', $animal->id)->get() as $record) {
                $push($animal, 'health', $record->title, $record->occurred_on, $record->recorded_by);
            }
            foreach (WeightRecord::where('animal_id', $animal->id)->orderBy('measured_on')->get()->filter(fn (WeightRecord $w, int $i) => $i % 4 === 0) as $weight) {
                $push($animal, 'weight', "Weighed {$weight->weight_kg} kg", $weight->measured_on, $weight->measured_by);
            }
            if ($animal->exit_date) {
                $push($animal, 'exit', 'Left the farm: '.$animal->exit_reason, $animal->exit_date);
            }
        }
        foreach (Mating::whereIn('status', ['pregnant', 'open'])->get() as $mating) {
            $push(Animal::find($mating->dam_id), 'breeding', 'Mating recorded', $mating->mated_on);
        }
        foreach (Kidding::all() as $kidding) {
            $push(Animal::find($kidding->dam_id), 'breeding', "Kidded: {$kidding->born_alive} born alive", $kidding->kidded_on, 'Dr. Demo Vet');
        }
        foreach (array_chunk($rows, 300) as $chunk) {
            AnimalEvent::insert($chunk);
        }
    }

    private function seedOperations(Collection $pens, Collection $groups): void
    {
        $staff = collect([
            ['EMP-01', 'Demo Manager', 'Farm Manager', 'Day'], ['VET-1', 'Dr. Demo Vet', 'Veterinarian', 'Day'], ['VET-2', 'Dr. Demo Vet 2', 'Veterinarian', 'On call'],
            ['EMP-02', 'Demo Caretaker 1', 'Caretaker', 'Day'], ['EMP-03', 'Demo Caretaker 2', 'Caretaker', 'Day'], ['EMP-04', 'Demo Caretaker 3', 'Caretaker', 'Night'],
            ['EMP-05', 'Demo Feeder 1', 'Feeding Employee', 'Morning'], ['EMP-06', 'Demo Feeder 2', 'Feeding Employee', 'Evening'],
            ['EMP-07', 'Demo Cleaner 1', 'Cleaning Employee', 'Morning'], ['EMP-08', 'Demo Cleaner 2', 'Cleaning Employee', 'Evening'], ['EMP-09', 'Demo Accounts', 'Finance Employee', 'Day'],
        ])->map(fn (array $e) => Employee::create(['code' => $e[0], 'name' => $e[1], 'role' => $e[2], 'shift' => $e[3]]));

        $today = [
            ['Morning feed, Pen A', 'feeding', 'does', 'EMP-05', 'high', true], ['Morning feed, Pen C', 'feeding', 'kids', 'EMP-05', 'high', true],
            ['Evening feed, all pens', 'feeding', null, 'EMP-06', 'high', false], ['Clean Pen B', 'cleaning', 'pregnant', 'EMP-07', 'normal', true],
            ['Clean Pen D', 'cleaning', 'growers', 'EMP-07', 'normal', false], ['Disinfect Pen F', 'cleaning', 'isolation', 'EMP-08', 'high', false],
            ['Health round, Pen F', 'observation', 'isolation', 'VET-1', 'high', true], ['Check pregnant does', 'observation', 'pregnant', 'VET-1', 'normal', false],
            ['Monthly weighing, Pen D', 'weighing', 'growers', 'EMP-02', 'normal', false], ['Refill water troughs', 'observation', null, 'EMP-03', 'normal', true],
        ];
        foreach ($today as $t) {
            Task::create([
                'title' => $t[0], 'type' => $t[1], 'pen_id' => $t[2] ? $pens[$t[2]]->id : null, 'employee_id' => $staff->firstWhere('code', $t[3])->id,
                'due_on' => now()->toDateString(), 'priority' => $t[4], 'status' => $t[5] ? 'done' : 'open',
                'completed_at' => $t[5] ? now()->subHours(mt_rand(1, 5)) : null, 'completed_by' => $t[5] ? $staff->firstWhere('code', $t[3])->name : null,
            ]);
        }

        foreach ($groups as $group) {
            foreach (range(0, 13) as $back) {
                foreach (['morning', 'evening'] as $session) {
                    if ($back === 0 && $session === 'evening') {
                        continue;
                    }
                    FeedingLog::create(['feeding_group_id' => $group->id, 'fed_on' => now()->subDays($back)->toDateString(), 'session' => $session, 'status' => mt_rand(0, 24) === 0 ? 'partial' : 'done', 'fed_by' => 'Demo Feeder 1']);
                }
            }
        }
    }

    private function seedLedger(Shed $shed, Collection $exited): void
    {
        $out = [
            ['Feed', 'SUP-01 Fodder Supplier', 180000, 260000], ['Feed', 'SUP-02 Concentrate Supplier', 90000, 140000], ['Medicine', 'SUP-03 Vet Pharmacy', 18000, 45000],
            ['Veterinary', 'Visiting specialist', 8000, 20000], ['Salaries', 'Staff payroll', 310000, 310000], ['Utilities', 'Electricity and water', 26000, 42000], ['Maintenance', 'Shed repairs', 6000, 38000],
        ];
        foreach (range(11, 0) as $back) {
            $month = now()->startOfMonth()->subMonths($back);
            foreach ($out as $o) {
                $on = $month->copy()->addDays(mt_rand(0, $back === 0 ? max(0, now()->day - 1) : 26));
                LedgerEntry::create(['direction' => 'out', 'category' => $o[0], 'amount' => mt_rand($o[2] / 100, $o[3] / 100) * 100, 'occurred_on' => $on, 'counterparty' => $o[1], 'description' => "{$o[0]} (synthetic)", 'shed_id' => $shed->id, 'recorded_by' => 'Demo CEO / Owner']);
            }
            if ($back % 3 === 1) {
                LedgerEntry::create(['direction' => 'out', 'category' => 'Animal purchase', 'amount' => mt_rand(900, 1800) * 100, 'occurred_on' => $month->copy()->addDays(10), 'counterparty' => 'SUP-04 Livestock Seller', 'description' => 'Breeding stock (synthetic)', 'shed_id' => $shed->id, 'recorded_by' => 'Demo CEO / Owner']);
            }
            LedgerEntry::create(['direction' => 'in', 'category' => 'Manure and other', 'amount' => mt_rand(150, 400) * 100, 'occurred_on' => $month->copy()->addDays(20)->min(now()), 'counterparty' => 'Local buyer', 'description' => 'Other income (synthetic)', 'recorded_by' => 'Demo CEO / Owner']);
            foreach (range(1, mt_rand(2, 5)) as $i) {
                LedgerEntry::create(['direction' => 'in', 'category' => 'Animal sale', 'amount' => mt_rand(600, 1900) * 100, 'occurred_on' => $month->copy()->addDays(mt_rand(1, 22))->min(now()), 'counterparty' => 'Buyer (synthetic)', 'description' => 'Goat sale (synthetic)', 'recorded_by' => 'Demo CEO / Owner']);
            }
        }
        foreach ($exited->where('exit_reason', 'sold') as $animal) {
            LedgerEntry::create(['direction' => 'in', 'category' => 'Animal sale', 'amount' => mt_rand(700, 1900) * 100, 'occurred_on' => $animal->exit_date, 'counterparty' => 'Buyer (synthetic)', 'description' => "Sale of {$animal->farm_number}", 'animal_id' => $animal->id, 'recorded_by' => 'Demo CEO / Owner']);
        }
    }

    private function seedActivity(): void
    {
        $items = [
            ['Dr. Demo Vet', 'Veterinarian', 'Completed the Pen F health round'], ['Demo Feeding Employee', 'Feeding Employee', 'Confirmed morning feed for Breeding does'],
            ['Demo Caretaker', 'Caretaker', 'Reported reduced appetite in Pen D'], ['Demo Cleaning Employee', 'Cleaning Employee', 'Finished cleaning Pen B'],
            ['Demo Farm Manager', 'Farm Manager', 'Moved a goat to Pen F for observation'], ['Dr. Demo Vet', 'Veterinarian', 'Scheduled a follow-up examination'],
        ];
        foreach ($items as $i => $item) {
            $at = Carbon::now()->subMinutes(($i + 1) * 23);
            DB::table('activities')->insert(['actor' => $item[0], 'role' => $item[1], 'action' => $item[2], 'audience' => 'farm', 'created_at' => $at, 'updated_at' => $at]);
        }
        Activity::query()->count();
    }
}
