<?php

namespace App\Support;

use App\Models\Activity;
use App\Models\Animal;
use App\Models\AnimalEvent;
use App\Models\RfidAssignment;
use App\Models\RfidTag;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Business rules shared by controllers, so identity and history rules live in one place. */
class Farm
{
    /** Issues the next farm number from a forward-only sequence. Numbers are never reused. */
    public static function nextFarmNumber(): string
    {
        return sprintf('%s-%04d', config('kkfarm.farm_number_prefix', 'KK'), DB::selectOne("select nextval('animal_number_seq') as number")->number);
    }

    public static function log(User $user, string $action, ?string $url = null, string $audience = 'farm'): void
    {
        Activity::create(['actor' => $user->name, 'role' => $user->roleLabel(), 'action' => $action, 'url' => $url, 'audience' => $audience]);
    }

    public static function event(Animal $animal, string $type, string $summary, string $by, $at = null): void
    {
        AnimalEvent::create(['animal_id' => $animal->id, 'type' => $type, 'summary' => $summary, 'recorded_by' => $by, 'occurred_at' => $at ?? now()]);
    }

    /**
     * Attach a tag to an animal. History stays with each animal: closing an assignment
     * never moves records, and the new animal starts with only its own history.
     */
    public static function assignTag(Animal $animal, RfidTag $tag, string $reason, User $by): RfidAssignment
    {
        return DB::transaction(function () use ($animal, $tag, $reason, $by) {
            $tag = RfidTag::whereKey($tag->id)->lockForUpdate()->first();
            $open = RfidAssignment::where('rfid_tag_id', $tag->id)->whereNull('valid_to')->first();

            if ($animal->status !== 'active') {
                throw ValidationException::withMessages(['animal_id' => 'This goat is no longer on the farm.']);
            }
            if ($open?->animal_id === $animal->id) {
                return $open;
            }
            if ($open) {
                $holder = Animal::find($open->animal_id);
                throw ValidationException::withMessages(['tag' => "This tag is on {$holder->farm_number}. Remove it there first."]);
            }

            self::closeOpenTag($animal, 'replaced', $by);

            $assignment = RfidAssignment::create([
                'animal_id' => $animal->id, 'rfid_tag_id' => $tag->id, 'valid_from' => now(),
                'reason_assigned' => $reason, 'assigned_by' => $by->name,
            ]);
            $tag->update(['status' => 'assigned']);
            self::event($animal, 'tag', "Tag {$tag->tag_code} assigned ($reason)", $by->name);

            return $assignment;
        });
    }

    public static function closeOpenTag(Animal $animal, string $reason, User $by): void
    {
        $open = RfidAssignment::where('animal_id', $animal->id)->whereNull('valid_to')->with('tag')->first();
        if (! $open) {
            return;
        }
        $open->update(['valid_to' => now(), 'reason_unassigned' => $reason]);
        $open->tag->update(['status' => $reason === 'lost' ? 'lost' : 'in_stock']);
        self::event($animal, 'tag', "Tag {$open->tag->tag_code} removed ($reason)", $by->name);
    }

    /** Parents and grandparents as a nested tree; unknown parents stay null. */
    public static function pedigree(?Animal $animal, int $depth = 3): ?array
    {
        if (! $animal) {
            return null;
        }
        $node = ['id' => $animal->id, 'number' => $animal->farm_number, 'name' => $animal->name, 'sex' => $animal->sex, 'coat' => $animal->coat, 'breed' => $animal->breed?->name, 'status' => $animal->status];
        if ($depth > 0) {
            $node['sire'] = self::pedigree($animal->sire, $depth - 1);
            $node['dam'] = self::pedigree($animal->dam, $depth - 1);
        }

        return $node;
    }

    /** Factual pedigree check only: reports shared recorded ancestors and sets no threshold. */
    public static function sharedAncestors(Animal $a, Animal $b, int $depth = 3): array
    {
        $collect = function (?Animal $x, int $d) use (&$collect): array {
            if (! $x || $d === 0) {
                return [];
            }

            return array_merge(array_filter([$x->sire_id, $x->dam_id]), $collect($x->sire, $d - 1), $collect($x->dam, $d - 1));
        };
        $left = array_unique(array_merge([$a->id], $collect($a, $depth)));
        $right = array_unique(array_merge([$b->id], $collect($b, $depth)));

        return array_values(array_intersect($left, $right));
    }

    /** Average daily gain in grams between the two most recent weighings. */
    public static function adg($weights): ?int
    {
        $list = collect($weights)->values();
        if ($list->count() < 2) {
            return null;
        }
        $a = $list[$list->count() - 2];
        $b = $list[$list->count() - 1];
        $days = max(1, (int) $a->measured_on->diffInDays($b->measured_on));

        return (int) round(($b->weight_kg - $a->weight_kg) * 1000 / $days);
    }
}
