<?php

namespace App\Models;

use Database\Factories\LicenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'core', 'modules', 'caps', 'starts_at', 'expires_at', 'notes'])]
class Licence extends Model
{
    /** @use HasFactory<LicenceFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'core' => 'array',
            'modules' => 'array',
            'caps' => 'array',
            'starts_at' => 'date',
            'expires_at' => 'date',
        ];
    }

    /**
     * Deployment this licence row belongs to.
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * Licences still in force (indefinite ones never lapse).
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where(function (Builder $query): void {
            $query->whereNull('expires_at')
                ->orWhereDate('expires_at', '>=', today());
        });
    }

    /**
     * Licences lapsing within the next 30 days.
     */
    #[Scope]
    protected function expiringSoon(Builder $query, int $days = 30): Builder
    {
        return $query->whereNotNull('expires_at')
            ->whereDate('expires_at', '>=', today())
            ->whereDate('expires_at', '<=', today()->addDays($days));
    }

    /**
     * Licences already past expiry.
     */
    #[Scope]
    protected function expired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')
            ->whereDate('expires_at', '<', today());
    }

    /**
     * Whether this row has lapsed. Null expiry means indefinite.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->lt(today());
    }

    /**
     * Whether this row lapses within the next 30 days.
     */
    public function isExpiringSoon(int $days = 30): bool
    {
        return ! $this->isExpired()
            && $this->expires_at !== null
            && $this->expires_at->lte(today()->addDays($days));
    }

    /**
     * Catalogue keys this licence entitles, verbatim.
     *
     * Locked core features are always on; toggleable core and module keys
     * come from the stored JSON flags.
     *
     * @return list<string>
     */
    public function enabledKeys(): array
    {
        $keys = [];

        foreach (config('licence-catalogue.core_features', []) as $key => $feature) {
            if (($feature['locked'] ?? false) === true) {
                $keys[] = $key;
            }
        }

        foreach ((array) ($this->core ?? []) as $key => $enabled) {
            if ($enabled) {
                $keys[] = $key;
            }
        }

        foreach ((array) ($this->modules ?? []) as $key => $enabled) {
            if ($enabled) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Read-only annual price preview for a set of module flags and cap.
     *
     * @param  array<string, bool>  $moduleFlags  Catalogue module keys to enabled flags.
     * @return array{currency: string, band: string, multiplier: float, lines: list<array{label: string, amount: float}>, discount: float, total: float}
     */
    public static function previewFor(array $moduleFlags, ?int $maxStudents): array
    {
        $catalogue = config('licence-catalogue');
        $currency = $catalogue['pricing']['currency'] ?? 'GHS';

        $band = static::bandFor($maxStudents, $catalogue['student_pricing_bands'] ?? []);
        $lines = [
            ['label' => 'Core annual', 'amount' => (float) $catalogue['pricing']['core']['base_annual']],
        ];

        $modulesTotal = 0.0;
        $enabledCount = 0;

        foreach ($catalogue['modules'] as $module) {
            if (! empty($moduleFlags[$module['db_column'] ?? ''])) {
                $enabledCount++;
                $modulesTotal += (float) $module['base_price'];
                $lines[] = ['label' => $module['label'], 'amount' => (float) $module['base_price']];
            }
        }

        $discount = 0.0;

        if ($enabledCount > 0 && $enabledCount === count($catalogue['modules'])) {
            $discount = round($modulesTotal * (float) $catalogue['pricing']['discounts']['all_modules_rate'], 2);
        }

        $total = round(($catalogue['pricing']['core']['base_annual'] + $modulesTotal - $discount) * $band['multiplier'], 2);

        return [
            'currency' => $currency,
            'band' => $band['label'],
            'multiplier' => $band['multiplier'],
            'lines' => $lines,
            'discount' => $discount,
            'total' => $total,
        ];
    }

    /**
     * @param  array<string, array{min: int, max: ?int, multiplier: float, label: string}>  $bands
     * @return array{multiplier: float, label: string}
     */
    protected static function bandFor(?int $maxStudents, array $bands): array
    {
        if ($maxStudents !== null) {
            foreach ($bands as $band) {
                if ($maxStudents >= $band['min'] && ($band['max'] === null || $maxStudents <= $band['max'])) {
                    return ['multiplier' => (float) $band['multiplier'], 'label' => $band['label']];
                }
            }
        }

        return ['multiplier' => 1.0, 'label' => 'No student cap'];
    }
}
