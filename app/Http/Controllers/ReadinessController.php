<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ReadinessController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('select 1');
            $key = 'readiness:'.bin2hex(random_bytes(8));
            Cache::put($key, 'ok', 10);
            $cacheReady = Cache::get($key) === 'ok';
            Cache::forget($key);

            if (! $cacheReady) {
                throw new \RuntimeException('Cache write/read check failed.');
            }

            $disk = (string) config('filesystems.evidence', 'local');
            $probe = 'health/readiness-'.bin2hex(random_bytes(8));
            try {
                Storage::disk($disk)->put($probe, 'ok');
                $storageReady = Storage::disk($disk)->get($probe) === 'ok';
            } finally {
                Storage::disk($disk)->delete($probe);
            }
            if (! $storageReady) {
                throw new \RuntimeException('Evidence storage check failed.');
            }

            if ((bool) config('health.require_background_heartbeats')) {
                $minimum = now()->subSeconds((int) config('health.heartbeat_max_age_seconds'))->timestamp;
                if ((int) Cache::get('health:scheduler:last_seen', 0) < $minimum || (int) Cache::get('health:queue:last_seen', 0) < $minimum) {
                    throw new \RuntimeException('Background worker check failed.');
                }
            }
        } catch (Throwable) {
            return response()->json(['status' => 'unavailable'], 503);
        }

        return response()->json(['status' => 'ready']);
    }
}
