<?php

namespace App\Support;

use Closure;
use Illuminate\Http\Request;

/**
 * Where an event came from (D-038). Every history row records `source` and `device_id`.
 *
 * Clients may only claim `manual` or `rfid`. Trusted server code (sensor ingestion, rules, imports) declares its
 * own source with EventContext::as(), so a browser cannot pretend to be a sensor.
 */
final class EventContext
{
    public const CLIENT_SOURCES = ['manual', 'rfid'];

    private static string $source = 'manual';

    private static ?string $device = null;

    /** @var array{reason?: string, event_uuid?: ?string}|null */
    private static ?array $move = null;

    public static function fromRequest(Request $request): void
    {
        $source = $request->header('X-Event-Source');
        self::$source = in_array($source, self::CLIENT_SOURCES, true) ? $source : 'manual';
        $device = preg_replace('/[^A-Za-z0-9._:-]/', '', (string) $request->header('X-Device-Id'));
        self::$device = $device !== '' ? substr($device, 0, 64) : null;
    }

    public static function reset(): void
    {
        self::$source = 'manual';
        self::$device = null;
        self::$move = null;
    }

    public static function source(): string
    {
        return self::$source;
    }

    public static function device(): ?string
    {
        return self::$device;
    }

    /** Runs trusted server code under a given source, then restores the previous one. */
    public static function as(string $source, Closure $work, ?string $device = null): mixed
    {
        [$previousSource, $previousDevice] = [self::$source, self::$device];
        [self::$source, self::$device] = [$source, $device];

        try {
            return $work();
        } finally {
            [self::$source, self::$device] = [$previousSource, $previousDevice];
        }
    }

    /** Lets Farm::move pass its reason and idempotency key to the Animal observer that writes the move. */
    public static function expectMove(string $reason, ?string $eventUuid): void
    {
        self::$move = ['reason' => $reason, 'event_uuid' => $eventUuid];
    }

    /** @return array{reason?: string, event_uuid?: ?string} */
    public static function consumeMove(): array
    {
        $move = self::$move ?? [];
        self::$move = null;

        return $move;
    }
}
