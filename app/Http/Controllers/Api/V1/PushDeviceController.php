<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Listeners\UpsertPushDeviceRequest;
use App\Http\Responses\ApiResponse;
use App\Models\DeviceSession;
use App\Models\PushDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PushDeviceController extends Controller
{
    public function upsert(UpsertPushDeviceRequest $request, string $deviceSession): JsonResponse
    {
        $session = DeviceSession::query()->where('public_id', $deviceSession)->where('user_id', $request->user()->getKey())
            ->where('client_type', 'mobile')->whereNull('revoked_at')->where('absolute_expires_at', '>', now())->firstOrFail();
        $token = $request->string('push_token')->toString();
        $hash = hash('sha256', $token);
        $device = DB::transaction(function () use ($request, $session, $token, $hash): PushDevice {
            $device = PushDevice::query()->where('token_hash', $hash)->lockForUpdate()->first() ?? new PushDevice;
            $device->fill([
                'user_id' => $request->user()->getKey(), 'device_session_id' => $session->getKey(),
                'platform' => $request->string('platform')->toString(), 'token_hash' => $hash, 'push_token' => $token,
                'enabled_at' => now(), 'disabled_at' => null, 'last_seen_at' => now(),
            ])->save();

            return $device;
        }, attempts: 3);

        return ApiResponse::success($this->resource($device), 201);
    }

    public function destroy(Request $request, string $pushDevice): JsonResponse
    {
        $device = PushDevice::query()->where('public_id', $pushDevice)->where('user_id', $request->user()->getKey())->firstOrFail();
        $device->update(['disabled_at' => now()]);

        return ApiResponse::success(['id' => $device->public_id, 'enabled' => false]);
    }

    private function resource(PushDevice $device): array
    {
        return ['id' => $device->public_id, 'platform' => $device->platform, 'enabled' => $device->disabled_at === null, 'last_seen_at' => $device->last_seen_at->utc()->toISOString()];
    }
}
