<?php

namespace App\Models;

use Database\Factories\LicenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'core', 'modules', 'caps', 'price_snapshot', 'starts_at', 'expires_at', 'notes', 'hosting_mode', 'implementation_fee', 'config_fee', 'migration_fee', 'training_admin', 'training_teacher', 'training_onsite', 'founding_client'])]
class Licence extends Model
{
    /** @use HasFactory<LicenceFactory> */
    use HasFactory;

    /**
     * Hosting modes mirrored from FlowEdu's quote calculator.
     *
     * @var list<string>
     */
    public const HOSTING_MODES = ['self_hosted', 'managed', 'none'];

    /**
     * Annual hosting fee. Only managed cloud hosting bills yearly;
     * self-hosted and none bill nothing. Figure from FlowEdu.
     */
    public const HOSTING_ANNUAL_FEE = 1500.00;

    /**
     * One-time fee defaults. Figures from FlowEdu.
     */
    public const DEFAULT_IMPLEMENTATION_FEE = 3500.00;

    public const DEFAULT_CONFIG_FEE = 800.00;

    public const DEFAULT_MIGRATION_FEE = 2000.00;

    /**
     * Per-session training rates. Figures from FlowEdu.
     */
    public const TRAINING_ADMIN_RATE = 600.00;

    public const TRAINING_TEACHER_RATE = 500.00;

    public const TRAINING_ONSITE_RATE = 1500.00;

    /**
     * Founding-client discount, applied to the core annual only.
     * Figure from FlowEdu.
     */
    public const FOUNDING_DISCOUNT_RATE = 0.15;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'core' => 'array',
            'modules' => 'array',
            'caps' => 'array',
            'price_snapshot' => 'array',
            'starts_at' => 'date',
            'expires_at' => 'date',
            'implementation_fee' => 'decimal:2',
            'config_fee' => 'decimal:2',
            'migration_fee' => 'decimal:2',
            'training_admin' => 'integer',
            'training_teacher' => 'integer',
            'training_onsite' => 'integer',
            'founding_client' => 'boolean',
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
     * Locked core features come from the live catalogue; toggleable core
     * and module keys come from the stored JSON flags, filtered to
     * features still active so withdrawn offerings stop being answered.
     *
     * @return list<string>
     */
    public function enabledKeys(): array
    {
        $keys = Feature::query()
            ->where('kind', 'core')
            ->where('locked', true)
            ->where('active', true)
            ->pluck('key')
            ->all();

        $offered = Feature::query()->where('active', true)->pluck('key')->all();

        foreach ((array) ($this->core ?? []) as $key => $enabled) {
            if ($enabled && in_array($key, $offered, true)) {
                $keys[] = $key;
            }
        }

        foreach ((array) ($this->modules ?? []) as $key => $enabled) {
            if ($enabled && in_array($key, $offered, true)) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Read-only annual price preview for a set of module flags, cap, and
     * quote dimensions. Mirrors FlowEdu's quote maths: founding discount
     * comes off the core annual only, everything else lands on its own
     * line, and the band multiplier applies to the combined total.
     *
     * @param  array<string, bool>  $moduleFlags  Catalogue module keys to enabled flags.
     * @param  array{hosting_mode?: ?string, implementation_fee?: ?float, config_fee?: ?float, migration_fee?: ?float, training_admin?: ?int, training_teacher?: ?int, training_onsite?: ?int, founding_client?: ?bool}  $quote
     * @return array{currency: string, band: string, multiplier: float, lines: list<array{label: string, amount: float}>, discount: float, founding_discount: float, total: float}
     */
    public static function previewFor(array $moduleFlags, ?int $maxStudents, ?int $reportedStudents = null, array $quote = []): array
    {
        $globals = static::pricingGlobals();
        $quote = static::normalizeQuote($quote);

        $band = static::bandFor($maxStudents, $reportedStudents, $globals['bands']);
        $lines = [
            ['label' => 'Core annual', 'amount' => $globals['core_base']],
        ];

        $modules = Feature::query()->where('kind', 'module')->where('active', true)->orderBy('id')->get(['key', 'label', 'base_price']);

        $modulesTotal = 0.0;
        $enabledCount = 0;

        foreach ($modules as $module) {
            if (! empty($moduleFlags[$module->key])) {
                $enabledCount++;
                $modulesTotal += (float) $module->base_price;
                $lines[] = ['label' => $module->label, 'amount' => (float) $module->base_price];
            }
        }

        $discount = 0.0;

        if ($enabledCount > 0 && $enabledCount === $modules->count()) {
            $discount = round($modulesTotal * $globals['discount_rate'], 2);
        }

        $foundingDiscount = $quote['founding_client']
            ? round($globals['core_base'] * static::FOUNDING_DISCOUNT_RATE, 2)
            : 0.0;

        $hostingAmount = static::hostingAnnualFee($quote['hosting_mode']);

        $lines[] = ['label' => 'Hosting — '.static::hostingLabels()[$quote['hosting_mode']], 'amount' => $hostingAmount];

        foreach ([
            ['label' => 'Implementation (one-time)', 'amount' => $quote['implementation_fee']],
            ['label' => 'Configuration (one-time)', 'amount' => $quote['config_fee']],
            ['label' => 'Migration (one-time)', 'amount' => $quote['migration_fee']],
            ['label' => "Remote Admin Training ({$quote['training_admin']} sessions)", 'amount' => $quote['training_admin'] * static::TRAINING_ADMIN_RATE],
            ['label' => "Remote Lecturer Training ({$quote['training_teacher']} sessions)", 'amount' => $quote['training_teacher'] * static::TRAINING_TEACHER_RATE],
            ['label' => "On-Site Training Days ({$quote['training_onsite']} days)", 'amount' => $quote['training_onsite'] * static::TRAINING_ONSITE_RATE],
        ] as $line) {
            if ($line['amount'] > 0) {
                $lines[] = ['label' => $line['label'], 'amount' => round($line['amount'], 2)];
            }
        }

        $total = round(($globals['core_base'] - $foundingDiscount + $modulesTotal - $discount + $hostingAmount + $quote['implementation_fee'] + $quote['config_fee'] + $quote['migration_fee'] + $quote['training_admin'] * static::TRAINING_ADMIN_RATE + $quote['training_teacher'] * static::TRAINING_TEACHER_RATE + $quote['training_onsite'] * static::TRAINING_ONSITE_RATE) * $band['multiplier'], 2);

        return [
            'currency' => $globals['currency'],
            'band' => $band['label'],
            'multiplier' => $band['multiplier'],
            'lines' => $lines,
            'discount' => $discount,
            'founding_discount' => $foundingDiscount,
            'total' => $total,
        ];
    }

    /**
     * Grant-time price record for a set of module flags, cap, and quote
     * dimensions. Carries the normalized quote inputs so a snapshot stays
     * interpretable after catalogue prices move on.
     *
     * @param  array<string, bool>  $moduleFlags  Catalogue module keys to enabled flags.
     * @param  array{hosting_mode?: ?string, implementation_fee?: ?float, config_fee?: ?float, migration_fee?: ?float, training_admin?: ?int, training_teacher?: ?int, training_onsite?: ?int, founding_client?: ?bool}  $quote
     * @return array{currency: string, band: string, multiplier: float, lines: list<array{label: string, amount: float}>, discount: float, founding_discount: float, total: float, granted_modules: list<string>, quote: array{hosting_mode: string, implementation_fee: float, config_fee: float, migration_fee: float, training_admin: int, training_teacher: int, training_onsite: int, founding_client: bool}}
     */
    public static function priceSnapshot(array $moduleFlags, ?int $maxStudents, ?int $reportedStudents = null, array $quote = []): array
    {
        return static::previewFor($moduleFlags, $maxStudents, $reportedStudents, $quote)
            + [
                'granted_modules' => array_keys(array_filter($moduleFlags)),
                'quote' => static::normalizeQuote($quote),
            ];
    }

    /**
     * Pricing globals from settings storage. The seed fallback lives in
     * SettingsSeeder; nothing here reads the deprecated config file.
     *
     * @return array{currency: string, core_base: float, discount_rate: float, bands: array}
     */
    protected static function pricingGlobals(): array
    {
        return [
            'currency' => Setting::get(Setting::CURRENCY, 'GHS') ?? 'GHS',
            'core_base' => (float) (Setting::get(Setting::CORE_BASE_ANNUAL) ?? 0),
            'discount_rate' => (float) (Setting::get(Setting::ALL_MODULES_DISCOUNT_RATE) ?? 0),
            'bands' => Setting::studentBands(),
        ];
    }

    /**
     * Normalize raw quote inputs. Unset fees fall back to the FlowEdu
     * figures; an explicit zero stays zero. A missing hosting mode
     * behaves like self-hosted (no annual fee).
     *
     * @param  array{hosting_mode?: ?string, implementation_fee?: ?float, config_fee?: ?float, migration_fee?: ?float, training_admin?: ?int, training_teacher?: ?int, training_onsite?: ?int, founding_client?: ?bool}  $quote
     * @return array{hosting_mode: string, implementation_fee: float, config_fee: float, migration_fee: float, training_admin: int, training_teacher: int, training_onsite: int, founding_client: bool}
     */
    protected static function normalizeQuote(array $quote): array
    {
        $hostingMode = $quote['hosting_mode'] ?? null;

        return [
            'hosting_mode' => in_array($hostingMode, static::HOSTING_MODES, true) ? $hostingMode : 'self_hosted',
            'implementation_fee' => round(max(0.0, (float) ($quote['implementation_fee'] ?? static::DEFAULT_IMPLEMENTATION_FEE)), 2),
            'config_fee' => round(max(0.0, (float) ($quote['config_fee'] ?? static::DEFAULT_CONFIG_FEE)), 2),
            'migration_fee' => round(max(0.0, (float) ($quote['migration_fee'] ?? static::DEFAULT_MIGRATION_FEE)), 2),
            'training_admin' => max(0, (int) ($quote['training_admin'] ?? 0)),
            'training_teacher' => max(0, (int) ($quote['training_teacher'] ?? 0)),
            'training_onsite' => max(0, (int) ($quote['training_onsite'] ?? 0)),
            'founding_client' => (bool) ($quote['founding_client'] ?? false),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function hostingLabels(): array
    {
        return [
            'self_hosted' => 'Self-hosted',
            'managed' => 'Managed cloud',
            'none' => 'No hosting',
        ];
    }

    public static function hostingAnnualFee(?string $hostingMode): float
    {
        return $hostingMode === 'managed' ? static::HOSTING_ANNUAL_FEE : 0.0;
    }

    /**
     * Resolve the pricing band. An explicit cap always wins; otherwise the
     * latest reported student count bands honestly; only with neither do
     * we fall back to the unmultiplied default.
     *
     * @param  array<int, array{min: int, max: ?int, multiplier: float, label: string}>  $bands
     * @return array{multiplier: float, label: string}
     */
    protected static function bandFor(?int $maxStudents, ?int $reportedStudents, array $bands): array
    {
        if ($maxStudents !== null) {
            foreach ($bands as $band) {
                if ($maxStudents >= $band['min'] && ($band['max'] === null || $maxStudents <= $band['max'])) {
                    return ['multiplier' => (float) $band['multiplier'], 'label' => $band['label']];
                }
            }
        }

        if ($reportedStudents !== null) {
            foreach ($bands as $band) {
                if ($reportedStudents >= $band['min'] && ($band['max'] === null || $reportedStudents <= $band['max'])) {
                    return ['multiplier' => (float) $band['multiplier'], 'label' => "{$reportedStudents} students (reported)"];
                }
            }
        }

        return ['multiplier' => 1.0, 'label' => 'No student cap'];
    }
}
