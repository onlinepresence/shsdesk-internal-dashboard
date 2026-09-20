<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['name', 'slug', 'api_key_hash', 'api_key_encrypted', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * Prefix marking a bearer product key. Stored as sha256, shown once.
     */
    public const API_KEY_PREFIX = 'cpk_';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Deployments registered under this product slug.
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'product', 'slug');
    }

    /**
     * Licences on this product's deployments.
     */
    public function licences(): HasManyThrough
    {
        return $this->hasManyThrough(Licence::class, Deployment::class, 'product', 'deployment_id', 'slug', 'id');
    }

    /**
     * Unredeemed enrollment codes on this product's deployments —
     * schools holding a code are the sales leads.
     */
    public function openCodes(): HasManyThrough
    {
        return $this->hasManyThrough(EnrollmentCode::class, Deployment::class, 'product', 'deployment_id', 'slug', 'id')
            ->whereNull('enrollment_codes.voided_at')
            ->whereNull('enrollment_codes.consumed_at')
            ->where('enrollment_codes.expires_at', '>', now());
    }

    /**
     * Whether the product authenticates anything. Inactive products
     * authenticate nothing until activated.
     */
    public function isActive(): bool
    {
        return (bool) $this->active;
    }

    /**
     * Whether a product API key is currently issued.
     */
    public function hasApiKey(): bool
    {
        return $this->api_key_hash !== null;
    }

    /**
     * Decrypt the stored key for the secured display. The ciphertext
     * is sealed with the app key — anyone holding the database alone
     * still cannot read it. Null when no key is issued or the app key
     * changed since issuance.
     */
    public function revealApiKey(): ?string
    {
        if ($this->api_key_encrypted === null) {
            return null;
        }

        try {
            return decrypt($this->api_key_encrypted);
        } catch (DecryptException $e) {
            return null;
        }
    }

    /**
     * Masked form for display, e.g. cpk_9f2ka1b8******3d7.
     */
    public function maskedApiKey(): ?string
    {
        $key = $this->revealApiKey();

        if ($key === null) {
            return null;
        }

        return substr($key, 0, 8).'******'.substr($key, -3);
    }

    /**
     * Mint a bearer key. Only the hash is stored — the plaintext is
     * returned once for the show-once display and never retained.
     */
    public static function generateApiKey(): string
    {
        return static::API_KEY_PREFIX.bin2hex(random_bytes(24));
    }

    /**
     * Lookup hash for a presented product key.
     */
    public static function hashApiKey(string $key): string
    {
        return hash('sha256', trim($key));
    }
}
