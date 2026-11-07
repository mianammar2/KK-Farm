<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Cached state that is derived from events (D-038): an animal's pen, a mating's status, a health case's status.
 *
 * The columns exist because the app reads them everywhere, but the events are the truth. drift() lists every
 * row where the cache disagrees with the events; rebuild() makes the cache match again.
 * Not derived yet (still set by hand): animals.health_status, animals.repro_state, animals.status/exit.
 */
final class Projections
{
    public static function refreshMating(int $matingId): void
    {
        DB::update('UPDATE matings SET status = d.status FROM mating_derived_status d WHERE d.mating_id = matings.id AND matings.id = ? AND matings.status <> d.status', [$matingId]);
    }

    public static function refreshHealthRecord(int $recordId): void
    {
        DB::update('UPDATE health_records SET status = d.status FROM health_record_derived_status d WHERE d.health_record_id = health_records.id AND health_records.id = ? AND health_records.status <> d.status', [$recordId]);
    }

    /** @return array{pens: array, matings: array, health_records: array} */
    public static function drift(): array
    {
        return [
            'pens' => DB::select('SELECT a.id AS animal_id, a.pen_id AS stored, cp.pen_id AS derived FROM animals a LEFT JOIN animal_current_pen cp ON cp.animal_id = a.id WHERE a.pen_id IS DISTINCT FROM cp.pen_id'),
            'matings' => DB::select('SELECT m.id, m.status AS stored, d.status AS derived FROM current_matings m JOIN mating_derived_status d ON d.mating_id = m.id WHERE m.status <> d.status'),
            'health_records' => DB::select('SELECT h.id, h.status AS stored, d.status AS derived FROM current_health_records h JOIN health_record_derived_status d ON d.health_record_id = h.id WHERE h.status <> d.status'),
        ];
    }

    /** @return array{pens: int, matings: int, health_records: int} rows repaired */
    public static function rebuild(): array
    {
        return [
            'pens' => DB::update('UPDATE animals a SET pen_id = cp.pen_id FROM (SELECT a2.id, cp2.pen_id FROM animals a2 LEFT JOIN animal_current_pen cp2 ON cp2.animal_id = a2.id) cp WHERE cp.id = a.id AND a.pen_id IS DISTINCT FROM cp.pen_id'),
            'matings' => DB::update('UPDATE matings SET status = d.status FROM mating_derived_status d WHERE d.mating_id = matings.id AND matings.status <> d.status'),
            'health_records' => DB::update('UPDATE health_records SET status = d.status FROM health_record_derived_status d WHERE d.health_record_id = health_records.id AND health_records.status <> d.status'),
        ];
    }
}
