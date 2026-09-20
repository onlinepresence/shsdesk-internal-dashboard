<?php

namespace App\Models;

use Database\Factories\EnrollmentCodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'code_hash', 'expires_at', 'consumed_at', 'failed_attempts', 'voided_at'])]
class EnrollmentCode extends Model
{
    /** @use HasFactory<EnrollmentCodeFactory> */
    use HasFactory;

    /**
     * Claim code length. Nine unambiguous characters carry 45 bits.
     */
    public const CODE_LENGTH = 9;

    /**
     * Crockford-style alphabet without 0/O/1/I so codes survive
     * transcription over the phone or from a whiteboard photo.
     */
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Minutes a minted code stays redeemable.
     */
    public const EXPIRY_MINUTES = 30;

    /**
     * Failed presentations after which a code voids itself.
     */
    public const MAX_ATTEMPTS = 5;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    /**
     * Deployment this code enrolls, if pre-bound at mint.
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * Whether the code can still be redeemed.
     */
    public function isUsable(): bool
    {
        return ! $this->isVoided() && ! $this->isConsumed() && ! $this->isExpired();
    }

    /**
     * Whether the code was already redeemed.
     */
    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    /**
     * Whether the 30-minute window has lapsed.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now());
    }

    /**
     * Whether the code was voided by hand or by too many failures.
     */
    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    /**
     * Mark the code redeemed. Consumed codes answer idempotently
     * without issuing another token.
     */
    public function markConsumed(): void
    {
        $this->update(['consumed_at' => now()]);
    }

    /**
     * Void the code so it can never be redeemed.
     */
    public function markVoided(): void
    {
        $this->update(['voided_at' => now()]);
    }

    /**
     * Bind an open code to its deployment on first redeem.
     */
    public function bind(Deployment $deployment): void
    {
        $this->update(['deployment_id' => $deployment->id]);
    }

    /**
     * Record a failed presentation. Returns true when the failure
     * crossed the threshold and voided the code.
     */
    public function recordFailure(): bool
    {
        $this->increment('failed_attempts');

        if ($this->failed_attempts >= static::MAX_ATTEMPTS && ! $this->isVoided()) {
            $this->markVoided();

            return true;
        }

        return false;
    }

    /**
     * Mint a fresh code bound to the deployment. Only the hash is
     * stored — the plaintext is shown once and never retained.
     *
     * @return array{record: EnrollmentCode, code: string}
     */
    public static function mintFor(Deployment $deployment): array
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = static::generateCode();
            $hash = static::hashCode($code);

            if (! static::query()->where('code_hash', $hash)->exists()) {
                $record = static::query()->create([
                    'deployment_id' => $deployment->id,
                    'code_hash' => $hash,
                    'expires_at' => now()->addMinutes(static::EXPIRY_MINUTES),
                ]);

                return ['record' => $record, 'code' => $code];
            }
        }

        throw new \RuntimeException('Could not mint a unique enrollment code.');
    }

    /**
     * Random code from the unambiguous alphabet.
     */
    public static function generateCode(): string
    {
        $alphabet = static::CODE_ALPHABET;
        $length = strlen($alphabet);
        $code = '';

        for ($i = 0; $i < static::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $length - 1)];
        }

        return $code;
    }

    /**
     * Normalize user transcription before hashing or comparing.
     */
    public static function normalizeCode(string $code): string
    {
        return strtoupper(trim($code));
    }

    /**
     * Lookup hash for a presented code.
     */
    public static function hashCode(string $code): string
    {
        return hash('sha256', static::normalizeCode($code));
    }
}
