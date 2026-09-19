<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Database\Factories\DeploymentFactory;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['product', 'school_name', 'url', 'app_version', 'last_seen_at', 'revoked_at'])]
class Deployment extends Model implements AuthenticatableContract
{
    /** @use HasFactory<DeploymentFactory> */
    use Authenticatable, HasApiTokens, HasFactory;

    /**
     * Products that may register a deployment.
     *
     * @var list<string>
     */
    public const PRODUCTS = ['flowedu'];

    /**
     * Sanctum ability guarding the heartbeat endpoint.
     */
    public const HEARTBEAT_ABILITY = 'heartbeat';

    /**
     * Hours without a heartbeat before a deployment reads as stale.
     */
    public const STALE_AFTER_HOURS = 48;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Deployment $deployment): void {
            if ($deployment->uuid === null) {
                $deployment->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Heartbeat receipts for this deployment, newest first.
     */
    public function heartbeats(): HasMany
    {
        return $this->hasMany(DeploymentHeartbeat::class)->latest();
    }

    /**
     * Licence rows for this deployment, history preserved.
     */
    public function licences(): HasMany
    {
        return $this->hasMany(Licence::class)->latest();
    }

    /**
     * Stored proforma invoices for this deployment, newest first.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class)->latest();
    }

    /**
     * Newest licence row — the terms currently in force.
     */
    public function latestLicence(): HasOne
    {
        return $this->hasOne(Licence::class)->latestOfMany();
    }

    /**
     * Deployments still allowed to check in.
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(function (Builder $query): void {
            $query->where('last_seen_at', '>=', static::staleThreshold())
                ->orWhere(function (Builder $query): void {
                    $query->whereNull('last_seen_at')
                        ->where('created_at', '>=', static::staleThreshold());
                });
        });
    }

    /**
     * Deployments silent for longer than the stale window.
     */
    #[Scope]
    protected function stale(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')->where(function (Builder $query): void {
            $query->where('last_seen_at', '<', static::staleThreshold())
                ->orWhere(function (Builder $query): void {
                    $query->whereNull('last_seen_at')
                        ->where('created_at', '<', static::staleThreshold());
                });
        });
    }

    /**
     * Deployments whose tokens were revoked.
     */
    #[Scope]
    protected function revoked(Builder $query): Builder
    {
        return $query->whereNotNull('revoked_at');
    }

    /**
     * Derived status: revoked wins, then stale, otherwise active.
     *
     * @return Attribute<string, never>
     */
    protected function status(): Attribute
    {
        return Attribute::get(function (): string {
            if ($this->revoked_at !== null) {
                return 'revoked';
            }

            $threshold = static::staleThreshold();

            if ($this->last_seen_at !== null && $this->last_seen_at->lt($threshold)) {
                return 'stale';
            }

            if ($this->last_seen_at === null && $this->created_at !== null && $this->created_at->lt($threshold)) {
                return 'stale';
            }

            return 'active';
        });
    }

    protected static function staleThreshold(): CarbonInterface
    {
        return now()->subHours(static::STALE_AFTER_HOURS);
    }
}
