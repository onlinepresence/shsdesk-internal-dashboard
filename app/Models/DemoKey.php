<?php

namespace App\Models;

use Database\Factories\DemoKeyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['label', 'code_hash', 'expires_at', 'host', 'revoked_at', 'last_used_at', 'converted_deployment_id'])]
class DemoKey extends Model
{
    /** @use HasFactory<DemoKeyFactory> */
    use HasFactory;

    /**
     * Default lifetime for a minted key when no expiry is picked.
     */
    public const DEFAULT_EXPIRY_MINUTES = 30;

    /**
     * Human-typable prefix marking a demo key apart from claim codes.
     */
    public const CODE_PREFIX = 'demo-';

    /**
     * Crockford-style alphabet without 0/O/1/I so keys survive
     * transcription over the phone or from a whiteboard photo.
     */
    public const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Random body length behind the prefix.
     */
    public const CODE_LENGTH = 9;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    /**
     * Whether the key still verifies. Null expiry means never.
     */
    public function isUsable(): bool
    {
        return ! $this->isRevoked() && ! $this->isExpired();
    }

    /**
     * Whether the expiry moment has passed. Null never lapses.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(now());
    }

    /**
     * Whether the key was revoked.
     */
    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    /**
     * Deployment this key was converted into, if ever.
     */
    public function convertedDeployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class, 'converted_deployment_id');
    }

    /**
     * Whether the key already became a live deployment. Converted
     * keys never convert again.
     */
    public function isConverted(): bool
    {
        return $this->converted_deployment_id !== null;
    }

    /**
     * Point the key at its live deployment.
     */
    public function markConverted(Deployment $deployment): void
    {
        $this->update(['converted_deployment_id' => $deployment->id]);
    }

    /**
     * Retire the key. Offline copies keep verifying until they lapse
     * — revocation bites on the next online check.
     */
    public function markRevoked(): void
    {
        $this->update(['revoked_at' => now()]);
    }

    /**
     * Stamp the last online use.
     */
    public function touchUsed(): void
    {
        $this->update(['last_used_at' => now()]);
    }

    /**
     * Signed artifact for this key. The code is only the lookup
     * handle and stays out of the payload: validity (including
     * never) and the host binding live INSIDE the signed payload
     * only — the verifier must trust nothing outside the signature.
     * Rebuilt byte-identically any time: issued_at is the row's
     * creation, so re-downloads match the minted bytes exactly.
     * Features always come from licence rows, never from this
     * document — there is no scope key by design.
     *
     * @return array{payload: array<string, mixed>, signature: string, algorithm: string}
     */
    public function buildDocument(): array
    {
        return Licence::exportDocument([
            'expires_at' => $this->expires_at?->toIso8601String(),
            'host' => $this->host,
            'issued_at' => $this->created_at?->toIso8601String() ?? now()->toIso8601String(),
        ]);
    }

    /**
     * Mint a key and its first signed artifact. Only the hash is
     * stored — the code is shown once and never retained.
     *
     * @return array{record: DemoKey, code: string, document: array{payload: array<string, mixed>, signature: string, algorithm: string}}
     */
    public static function mintFor(string $label, ?string $expiresAt, ?string $host): array
    {
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = static::generateCode();
            $hash = static::hashCode($code);

            if (! static::query()->where('code_hash', $hash)->exists()) {
                $record = static::query()->create([
                    'label' => $label,
                    'code_hash' => $hash,
                    'expires_at' => $expiresAt,
                    'host' => $host,
                ]);

                return ['record' => $record, 'code' => $code, 'document' => $record->buildDocument()];
            }
        }

        throw new \RuntimeException('Could not mint a unique demo key.');
    }

    /**
     * Random prefixed code from the unambiguous alphabet.
     */
    public static function generateCode(): string
    {
        $alphabet = static::CODE_ALPHABET;
        $length = strlen($alphabet);
        $code = '';

        for ($i = 0; $i < static::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $length - 1)];
        }

        return static::CODE_PREFIX.$code;
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
