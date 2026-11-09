<?php

namespace App\Support;

use App\Models\Animal;
use App\Models\RecordRevision;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * D-099: who may correct a record, and the trail of corrections.
 * The person who recorded it may correct it (their own work); the administrator may correct anyone's.
 */
final class Revisions
{
    public static function canCorrect(User $user, ?string $recordedBy): bool
    {
        return $user->role === 'ceo' || ($recordedBy !== null && $recordedBy === $user->name);
    }

    /** @param array<string, array{0: mixed, 1: mixed}> $changes field => [old, new] */
    public static function log(string $type, int $id, ?Animal $animal, array $changes, User $by, ?string $reason): ?RecordRevision
    {
        if (! $changes) {
            return null;
        }
        $revision = RecordRevision::create([
            'record_type' => $type, 'record_id' => $id, 'animal_id' => $animal?->id, 'changes' => $changes,
            'reason' => $reason, 'changed_by' => $by->name, 'changed_role' => $by->roleLabel(),
        ]);
        if ($animal) {
            $text = collect($changes)->map(fn ($c, $f) => "$f: ".self::show($c[0]).' → '.self::show($c[1]))->implode('; ');
            Farm::event($animal, 'edit', ucfirst($type)." corrected by {$by->name}: $text".($reason ? " ($reason)" : ''), $by->name);
        }

        return $revision;
    }

    /** @return Collection<int, int> number of corrections per record id */
    public static function counts(string $type, Collection $ids): Collection
    {
        return RecordRevision::where('record_type', $type)->whereIn('record_id', $ids)->selectRaw('record_id, count(*) as n')->groupBy('record_id')->pluck('n', 'record_id');
    }

    private static function show(mixed $v): string
    {
        return $v === null || $v === '' ? 'empty' : (string) $v;
    }
}
