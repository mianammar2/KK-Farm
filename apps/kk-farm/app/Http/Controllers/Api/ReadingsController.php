<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Support\Sensors;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * D-087: the one ingestion API for every device. A device signs with its own key (Authorization: Bearer kkd_…),
 * which picks its farm; from then on Row-Level Security applies as for a person.
 */
class ReadingsController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return $this->asDevice($request, function (Device $device) use ($request) {
            $data = $request->validate([
                'readings' => ['required', 'array', 'min:1', 'max:500'],
                'readings.*.id' => ['required', 'uuid'],
                'readings.*.at' => ['nullable', 'date'],
                'readings.*.metric' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
                'readings.*.value' => ['nullable', 'numeric', 'between:-1000000,1000000'],
                'readings.*.unit' => ['nullable', 'string', 'max:16'],
                'readings.*.rfid' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
                'readings.*.data' => ['nullable', 'array'],
            ]);

            return response()->json(Sensors::ingest($device, $data['readings'], $device->name));
        });
    }

    /** A device checks in (heartbeat) and learns its own name and place. */
    public function ping(Request $request): JsonResponse
    {
        return $this->asDevice($request, function (Device $device) {
            $device->forceFill(['last_seen_at' => now(), 'status' => in_array($device->status, ['planned', 'offline'], true) ? 'active' : $device->status])->save();

            return response()->json(['device' => $device->code, 'name' => $device->name, 'type' => $device->type, 'place' => $device->placeName(), 'time' => now()->toIso8601String()]);
        });
    }

    private function asDevice(Request $request, \Closure $then): JsonResponse
    {
        $found = $request->bearerToken() ? Sensors::deviceForKey($request->bearerToken()) : null;
        if (! $found) {
            return response()->json(['message' => 'Unknown or revoked device key.'], 401);
        }
        Tenant::enter($found['org']);
        try {
            $device = Device::with(['pen.shed', 'shed'])->find($found['device']);
            if (! $device || $device->status === 'retired') {
                return response()->json(['message' => 'This device is retired.'], 403);
            }

            return $then($device);
        } finally {
            Tenant::leave();
        }
    }
}
