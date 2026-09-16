<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreHeartbeatRequest;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    /**
     * Record a deployment heartbeat and answer with stub licence terms.
     */
    public function __invoke(StoreHeartbeatRequest $request): JsonResponse
    {
        $deployment = $request->deployment();
        $validated = $request->validated();

        $deployment->update([
            'app_version' => $validated['app_version'],
            'last_seen_at' => now(),
        ]);

        $deployment->heartbeats()->create([
            'app_version' => $validated['app_version'],
            'students' => $validated['counts']['students'],
            'teachers' => $validated['counts']['teachers'],
            'users' => $validated['counts']['users'],
            'modules_in_use' => $validated['modules_in_use'] ?? [],
        ]);

        activity('deployments')
            ->performedOn($deployment)
            ->causedBy($deployment)
            ->withProperties([
                'app_version' => $validated['app_version'],
                'counts' => $validated['counts'],
                'modules_in_use' => $validated['modules_in_use'] ?? [],
            ])
            ->log('heartbeat.received');

        return response()->json([
            'licence' => [
                'tier' => 'standard',
                'modules' => [],
                'caps' => new \stdClass,
                'valid_until' => now()->addDays(30)->toIso8601String(),
            ],
            'directives' => [],
        ]);
    }
}
