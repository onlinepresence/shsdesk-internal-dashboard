<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\EnrollRequest;
use App\Models\Deployment;
use App\Models\EnrollmentCode;
use App\Models\Licence;
use App\Models\Product;
use Illuminate\Http\JsonResponse;

class EnrollController extends Controller
{
    /**
     * Redeem an enrollment claim code for a heartbeat token. The code
     * is the only credential on this endpoint. A consumed code asked
     * again answers idempotently with the live licence snapshot and
     * no fresh token.
     */
    public function __invoke(EnrollRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $record = EnrollmentCode::query()
            ->where('code_hash', EnrollmentCode::hashCode($validated['code']))
            ->first();

        if ($record === null) {
            activity('enrollment')->withProperties(['reason' => 'unknown_code'])->log('enrollment.redeem_failed');

            return $this->rejected('Unknown claim code.', 'unknown_code');
        }

        if ($record->isVoided()) {
            $this->logFailed($record, 'code_voided');

            return $this->rejected('This claim code was voided.', 'code_voided');
        }

        if ($record->isExpired()) {
            $this->logFailed($record, 'code_expired');

            return $this->rejected('This claim code has expired.', 'code_expired');
        }

        if ($record->failed_attempts >= EnrollmentCode::MAX_ATTEMPTS) {
            $record->markVoided();

            activity('enrollment')
                ->performedOn($record)
                ->withProperties(['reason' => 'too_many_attempts'])
                ->log('enrollment.code_voided');

            $this->logFailed($record, 'attempts_exceeded');

            return $this->rejected('This claim code was voided after too many failed attempts.', 'attempts_exceeded');
        }

        $deployment = $record->deployment;
        $requestedUuid = $validated['deployment_uuid'] ?? null;

        if ($record->isConsumed()) {
            if ($deployment === null || ($requestedUuid !== null && $requestedUuid !== $deployment->uuid)) {
                $this->logFailed($record, 'deployment_mismatch');

                return $this->rejected('This claim code belongs to a different deployment.', 'deployment_mismatch');
            }

            $productRejection = $this->productRejection($deployment, $validated['product'] ?? null);

            if ($productRejection !== null) {
                $this->logFailed($record, $productRejection[0]);

                return $this->rejected($productRejection[1], $productRejection[0]);
            }

            activity('enrollment')
                ->performedOn($record)
                ->causedBy($deployment)
                ->log('enrollment.redeem_replayed');

            return response()->json([
                'deployment_uuid' => $deployment->uuid,
                'heartbeat_token' => null,
                'licence' => Licence::heartbeatResponse($deployment->latestLicence)['licence'],
            ]);
        }

        if ($requestedUuid !== null) {
            $requested = Deployment::where('uuid', $requestedUuid)->firstOrFail();

            if ($deployment !== null) {
                if (! $requested->is($deployment)) {
                    $voided = $record->recordFailure();

                    if ($voided) {
                        activity('enrollment')
                            ->performedOn($record)
                            ->withProperties(['reason' => 'too_many_attempts'])
                            ->log('enrollment.code_voided');
                    }

                    $this->logFailed($record, 'deployment_mismatch');

                    return $this->rejected('This claim code belongs to a different deployment.', 'deployment_mismatch');
                }
            } else {
                $record->bind($requested);
                $deployment = $requested;
            }
        }

        if ($deployment === null) {
            $this->logFailed($record, 'unbound_code');

            return $this->rejected('This claim code is not bound to a deployment yet.', 'unbound_code');
        }

        if ($deployment->revoked_at !== null) {
            $this->logFailed($record, 'deployment_revoked');

            return $this->rejected('This deployment was revoked.', 'deployment_revoked');
        }

        $productRejection = $this->productRejection($deployment, $validated['product'] ?? null);

        if ($productRejection !== null) {
            $this->logFailed($record, $productRejection[0]);

            return $this->rejected($productRejection[1], $productRejection[0]);
        }

        $record->markConsumed();

        $deployment->update(array_filter([
            'app_version' => $validated['app_version'] ?? null,
            'last_seen_at' => now(),
        ]));

        $token = $deployment->createToken('heartbeat', [Deployment::HEARTBEAT_ABILITY]);

        activity('enrollment')
            ->performedOn($record)
            ->causedBy($deployment)
            ->log('enrollment.redeemed');

        return response()->json([
            'deployment_uuid' => $deployment->uuid,
            'heartbeat_token' => $token->plainTextToken,
            'licence' => Licence::heartbeatResponse($deployment->latestLicence)['licence'],
        ]);
    }

    /**
     * Reject a claimed product that mismatches the code-resolved
     * deployment, or a deployment whose product is unknown/inactive.
     *
     * @return array{string, string}|null [reason, message]
     */
    protected function productRejection(Deployment $deployment, ?string $claimedProduct): ?array
    {
        if ($claimedProduct !== null && $claimedProduct !== $deployment->product) {
            return ['product_mismatch', 'The claimed product does not match this deployment.'];
        }

        $product = Product::where('slug', $deployment->product)->first();

        if ($product === null || ! $product->isActive()) {
            return ['product_inactive', 'This product is not active.'];
        }

        return null;
    }

    /**
     * Log a rejected presentation against its code row.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function logFailed(EnrollmentCode $record, string $reason, array $properties = []): void
    {
        activity('enrollment')
            ->performedOn($record)
            ->causedBy($record->deployment)
            ->withProperties(array_merge(['reason' => $reason], $properties))
            ->log('enrollment.redeem_failed');
    }

    /**
     * Machine-readable rejection envelope.
     */
    protected function rejected(string $message, string $error): JsonResponse
    {
        return response()->json(['message' => $message, 'error' => $error], 422);
    }
}
