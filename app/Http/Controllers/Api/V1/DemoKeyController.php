<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\VerifyDemoKeyRequest;
use App\Models\DemoKey;
use Illuminate\Http\JsonResponse;

class DemoKeyController extends Controller
{
    /**
     * Verify a demo key online and return its signed document for
     * offline caching. Revocation bites here; offline copies verify
     * against the signature alone. Every outcome is logged and each
     * hit stamps last_used_at.
     */
    public function __invoke(VerifyDemoKeyRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $record = DemoKey::query()
            ->where('code_hash', DemoKey::hashCode($validated['code']))
            ->first();

        if ($record === null) {
            activity('demo')->withProperties(['reason' => 'unknown_code'])->log('demo.rejected');

            return response()->json(['message' => 'Unknown demo key.', 'error' => 'unknown_code'], 422);
        }

        if ($record->isRevoked()) {
            activity('demo')
                ->performedOn($record)
                ->withProperties(['reason' => 'key_revoked'])
                ->log('demo.rejected');

            return response()->json(['message' => 'This demo key was revoked.', 'error' => 'key_revoked'], 422);
        }

        if ($record->isExpired()) {
            activity('demo')
                ->performedOn($record)
                ->withProperties(['reason' => 'key_expired'])
                ->log('demo.rejected');

            return response()->json(['message' => 'This demo key has expired.', 'error' => 'key_expired'], 422);
        }

        $record->touchUsed();

        activity('demo')
            ->performedOn($record)
            ->log('demo.verified');

        return response()->json($record->buildDocument());
    }
}
