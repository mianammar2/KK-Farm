<?php

namespace App\Models\Concerns;

use App\Support\EventContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * History rows are facts (D-038): never edited, never deleted. A correction is a new row that supersedes the old one.
 *
 * The database enforces this with a trigger; this trait gives a clear error earlier and the helpers:
 *  - the global scope `current` hides superseded rows (use withSuperseded() for the full history),
 *  - correct() appends a superseding row,
 *  - appendOnce() makes a write idempotent through a client-generated event_uuid,
 *  - creating fills the envelope (source, device) from EventContext.
 *
 * A model may declare projectionColumns(): cached state that can be rebuilt from events (see Support\Projections).
 */
trait AppendOnly
{
    /** @return list<string> */
    public static function projectionColumns(): array
    {
        return [];
    }

    public static function bootAppendOnly(): void
    {
        static::addGlobalScope('current', function (Builder $query): void {
            $table = $query->getModel()->getTable();
            $query->whereNotExists(fn ($sub) => $sub->from("$table as superseding")->whereColumn('superseding.supersedes_id', "$table.id")->selectRaw('1'));
        });

        static::creating(function (Model $model): void {
            $model->source ??= EventContext::source();
            $model->device_id ??= EventContext::device();
        });

        static::updating(function (Model $model): void {
            $illegal = array_diff(array_keys($model->getDirty()), static::projectionColumns(), ['updated_at']);
            if ($illegal) {
                throw new LogicException(class_basename($model).' is history and cannot be edited ('.implode(', ', $illegal).'). Use correct().');
            }
        });

        static::deleting(function (Model $model): void {
            throw new LogicException(class_basename($model).' is history and cannot be deleted. Use correct().');
        });
    }

    /** Every row ever written, including superseded ones. */
    public static function withSuperseded(): Builder
    {
        return static::withoutGlobalScope('current');
    }

    /** Appends a row that replaces this one. Only the latest version of a fact can be corrected. */
    public function correct(array $changes): static
    {
        if (static::withSuperseded()->where('supersedes_id', $this->getKey())->exists()) {
            throw new LogicException('This record was already corrected. Correct the latest version.');
        }

        $copy = $this->replicate(['recorded_at', 'event_uuid', 'created_at', 'updated_at', 'source', 'device_id', 'supersedes_id']);
        $copy->fill($changes);
        $copy->supersedes_id = $this->getKey();
        $copy->save();

        return $copy->refresh();
    }

    /** Creates the row unless a row with this client event id exists; replays return the original. */
    public static function appendOnce(string $eventUuid, array $attributes): static
    {
        if ($existing = static::withSuperseded()->where('event_uuid', $eventUuid)->first()) {
            return $existing;
        }

        try {
            return DB::transaction(fn () => static::create($attributes + ['event_uuid' => $eventUuid]));
        } catch (UniqueConstraintViolationException) {
            return static::withSuperseded()->where('event_uuid', $eventUuid)->firstOrFail();
        }
    }
}
