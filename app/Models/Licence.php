<?php

namespace App\Models;

use Database\Factories\LicenceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['deployment_id', 'core', 'modules', 'caps', 'price_snapshot', 'starts_at', 'expires_at', 'notes', 'hosting_mode', 'config_setup', 'migration', 'training_admin', 'training_teacher', 'training_onsite', 'founding_client'])]
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
     * Student count above which FlowEdu stops auto-pricing.
     */
    public const CUSTOM_QUOTE_MIN_STUDENTS = 3501;

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
            'config_setup' => 'boolean',
            'migration' => 'boolean',
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
     * Read-only quote preview for a set of module flags, cap, and quote
     * dimensions. Mirrors FlowEdu's QuoteCalculationService line order:
     * core (upfront + renewal, founding discount off core only), modules
     * (scaled one-time + renewal, bundle discount at the threshold
     * count), hosting setup (one-time), config/migration addons, then
     * trainings — finishing in TWO totals, Upfront and Renewal.
     *
     * @param  array<string, bool>  $moduleFlags  Catalogue module keys to enabled flags.
     * @param  array{hosting_mode?: ?string, config_setup?: ?bool, migration?: ?bool, training_admin?: ?int, training_teacher?: ?int, training_onsite?: ?int, founding_client?: ?bool}  $quote
     */
    public static function previewFor(array $moduleFlags, ?int $maxStudents, ?int $reportedStudents = null, array $quote = []): array
    {
        $globals = static::pricingGlobals();
        $quote = static::normalizeQuote($quote);
        $band = static::resolveBand($maxStudents, $reportedStudents, $globals['bands']);

        if ($band['is_custom']) {
            return [
                'is_custom' => true,
                'band_key' => $band['band_key'],
                'band_label' => $band['band_label'],
                'multiplier' => $band['multiplier'],
                'currency' => $globals['currency'],
                'core_upfront' => 0.0,
                'core_renewal' => 0.0,
                'core_upfront_final' => 0.0,
                'core_renewal_final' => 0.0,
                'apply_founding' => $quote['founding_client'],
                'founding_discount_rate' => $globals['founding_discount_rate'],
                'founding_discount_upfront' => 0.0,
                'founding_discount_renew' => 0.0,
                'modules' => [],
                'modules_onetime_sum' => 0.0,
                'modules_renew_sum' => 0.0,
                'modules_onetime_final' => 0.0,
                'modules_renew_final' => 0.0,
                'apply_bundle' => false,
                'bundle_discount_rate' => $globals['bundle_discount_rate'],
                'bundle_discount_onetime' => 0.0,
                'bundle_discount_renew' => 0.0,
                'hosting_setup' => $quote['hosting_mode'],
                'hosting_label' => static::hostingLabels()[$quote['hosting_mode']],
                'hosting_setup_fee' => 0.0,
                'addons' => [],
                'configuration_fee' => 0.0,
                'trainings' => [],
                'training_fee' => 0.0,
                'admin_training_qty' => $quote['training_admin'],
                'teacher_training_qty' => $quote['training_teacher'],
                'onsite_training_qty' => $quote['training_onsite'],
                'upfront_total' => null,
                'renew_total' => null,
                'max_students' => $maxStudents,
                'reported_students' => $reportedStudents,
            ];
        }

        $multiplier = $band['multiplier'];
        $coreUpfront = $band['core_upfront'];
        $coreRenewal = $band['core_renewal'];

        $foundingDiscountUpfront = $quote['founding_client']
            ? round($coreUpfront * $globals['founding_discount_rate'], 2)
            : 0.0;
        $foundingDiscountRenew = $quote['founding_client']
            ? round($coreRenewal * $globals['founding_discount_rate'], 2)
            : 0.0;

        $coreUpfrontFinal = round($coreUpfront - $foundingDiscountUpfront, 2);
        $coreRenewalFinal = round($coreRenewal - $foundingDiscountRenew, 2);

        $modules = Feature::query()->where('kind', 'module')->where('active', true)->orderBy('id')->get(['key', 'label', 'base_price', 'renewal_base']);

        $modulesDetails = [];
        $modulesOnetimeSum = 0.0;
        $modulesRenewSum = 0.0;

        foreach ($modules as $module) {
            if (! empty($moduleFlags[$module->key])) {
                $onetime = round((float) ($module->base_price ?? 0) * $multiplier, 2);
                $renew = round((float) ($module->renewal_base ?? 0) * $multiplier, 2);

                $modulesOnetimeSum = round($modulesOnetimeSum + $onetime, 2);
                $modulesRenewSum = round($modulesRenewSum + $renew, 2);

                $modulesDetails[] = [
                    'key' => $module->key,
                    'label' => $module->label,
                    'onetime' => $onetime,
                    'renew' => $renew,
                ];
            }
        }

        $applyBundle = count($modulesDetails) >= $globals['bundle_threshold'] && $globals['bundle_threshold'] > 0;
        $bundleDiscountOnetime = $applyBundle ? round($modulesOnetimeSum * $globals['bundle_discount_rate'], 2) : 0.0;
        $bundleDiscountRenew = $applyBundle ? round($modulesRenewSum * $globals['bundle_discount_rate'], 2) : 0.0;

        $modulesOnetimeFinal = round($modulesOnetimeSum - $bundleDiscountOnetime, 2);
        $modulesRenewFinal = round($modulesRenewSum - $bundleDiscountRenew, 2);

        $hostingSetupFee = $globals['hosting_fees'][$quote['hosting_mode']] ?? 0.0;

        $addons = [];

        if ($quote['config_setup']) {
            $addons[] = ['label' => 'System Configuration & Data Entry', 'price' => $globals['config_setup_fee']];
        }

        if ($quote['migration']) {
            $addons[] = ['label' => 'Legacy Data Migration', 'price' => $globals['migration_fee']];
        }

        $configurationFee = round(array_sum(array_column($addons, 'price')), 2);

        $trainings = [];

        if ($quote['training_admin'] > 0) {
            $trainings[] = [
                'label' => "Remote Admin Training ({$quote['training_admin']} sessions)",
                'price' => round($quote['training_admin'] * $globals['training_admin_rate'], 2),
            ];
        }

        if ($quote['training_teacher'] > 0) {
            $trainings[] = [
                'label' => "Remote Lecturer Training ({$quote['training_teacher']} sessions)",
                'price' => round($quote['training_teacher'] * $globals['training_teacher_rate'], 2),
            ];
        }

        if ($quote['training_onsite'] > 0) {
            $trainings[] = [
                'label' => "On-Site Training Days ({$quote['training_onsite']} days)",
                'price' => round($quote['training_onsite'] * $globals['training_onsite_rate'], 2),
            ];
        }

        $trainingFee = round(array_sum(array_column($trainings, 'price')), 2);

        return [
            'is_custom' => false,
            'band_key' => $band['band_key'],
            'band_label' => $band['band_label'],
            'multiplier' => $multiplier,
            'currency' => $globals['currency'],
            'core_upfront' => $coreUpfront,
            'core_renewal' => $coreRenewal,
            'core_upfront_final' => $coreUpfrontFinal,
            'core_renewal_final' => $coreRenewalFinal,
            'apply_founding' => $quote['founding_client'],
            'founding_discount_rate' => $globals['founding_discount_rate'],
            'founding_discount_upfront' => $foundingDiscountUpfront,
            'founding_discount_renew' => $foundingDiscountRenew,
            'modules' => $modulesDetails,
            'modules_onetime_sum' => $modulesOnetimeSum,
            'modules_renew_sum' => $modulesRenewSum,
            'modules_onetime_final' => $modulesOnetimeFinal,
            'modules_renew_final' => $modulesRenewFinal,
            'apply_bundle' => $applyBundle,
            'bundle_discount_rate' => $globals['bundle_discount_rate'],
            'bundle_discount_onetime' => $bundleDiscountOnetime,
            'bundle_discount_renew' => $bundleDiscountRenew,
            'hosting_setup' => $quote['hosting_mode'],
            'hosting_label' => static::hostingLabels()[$quote['hosting_mode']],
            'hosting_setup_fee' => $hostingSetupFee,
            'addons' => $addons,
            'configuration_fee' => $configurationFee,
            'trainings' => $trainings,
            'training_fee' => $trainingFee,
            'admin_training_qty' => $quote['training_admin'],
            'teacher_training_qty' => $quote['training_teacher'],
            'onsite_training_qty' => $quote['training_onsite'],
            'upfront_total' => round($coreUpfrontFinal + $modulesOnetimeFinal + $hostingSetupFee + $configurationFee + $trainingFee, 2),
            'renew_total' => round($coreRenewalFinal + $modulesRenewFinal, 2),
            'max_students' => $maxStudents,
            'reported_students' => $reportedStudents,
        ];
    }

    /**
     * Grant-time price record for a set of module flags, cap, and quote
     * dimensions. Carries the normalized quote inputs so a snapshot stays
     * interpretable after catalogue prices move on.
     *
     * @param  array<string, bool>  $moduleFlags  Catalogue module keys to enabled flags.
     * @param  array{hosting_mode?: ?string, config_setup?: ?bool, migration?: ?bool, training_admin?: ?int, training_teacher?: ?int, training_onsite?: ?int, founding_client?: ?bool}  $quote
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
     * Pricing globals from settings storage. Nothing here reads the
     * deprecated config file — the seed fallback lives in
     * SettingsSeeder.
     *
     * @return array{currency: string, bands: array, bundle_discount_rate: float, bundle_threshold: int, founding_discount_rate: float, hosting_fees: array<string, float>, config_setup_fee: float, migration_fee: float, training_admin_rate: float, training_teacher_rate: float, training_onsite_rate: float}
     */
    protected static function pricingGlobals(): array
    {
        return [
            'currency' => Setting::get(Setting::CURRENCY, 'GHS') ?? 'GHS',
            'bands' => Setting::corePricing(),
            'bundle_discount_rate' => Setting::getFloat(Setting::BUNDLE_DISCOUNT_RATE),
            'bundle_threshold' => Setting::getInt(Setting::BUNDLE_THRESHOLD, 4),
            'founding_discount_rate' => Setting::getFloat(Setting::FOUNDING_DISCOUNT_RATE),
            'hosting_fees' => [
                'self_hosted' => Setting::getFloat(Setting::HOSTING_SELF_HOSTED_FEE),
                'managed' => Setting::getFloat(Setting::HOSTING_MANAGED_FEE),
                'none' => Setting::getFloat(Setting::HOSTING_NONE_FEE),
            ],
            'config_setup_fee' => Setting::getFloat(Setting::CONFIG_SETUP_FEE),
            'migration_fee' => Setting::getFloat(Setting::MIGRATION_FEE),
            'training_admin_rate' => Setting::getFloat(Setting::TRAINING_ADMIN_RATE),
            'training_teacher_rate' => Setting::getFloat(Setting::TRAINING_TEACHER_RATE),
            'training_onsite_rate' => Setting::getFloat(Setting::TRAINING_ONSITE_RATE),
        ];
    }

    /**
     * Normalize raw quote inputs. A missing hosting mode behaves like
     * self-hosted.
     *
     * @param  array{hosting_mode?: ?string, config_setup?: ?bool, migration?: ?bool, training_admin?: ?int, training_teacher?: ?int, training_onsite?: ?int, founding_client?: ?bool}  $quote
     * @return array{hosting_mode: string, config_setup: bool, migration: bool, training_admin: int, training_teacher: int, training_onsite: int, founding_client: bool}
     */
    protected static function normalizeQuote(array $quote): array
    {
        $hostingMode = $quote['hosting_mode'] ?? null;

        return [
            'hosting_mode' => in_array($hostingMode, static::HOSTING_MODES, true) ? $hostingMode : 'self_hosted',
            'config_setup' => (bool) ($quote['config_setup'] ?? false),
            'migration' => (bool) ($quote['migration'] ?? false),
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

    /**
     * One-time hosting setup fee for a mode, from settings storage.
     */
    public static function hostingSetupFee(?string $hostingMode): float
    {
        return static::pricingGlobals()['hosting_fees'][$hostingMode] ?? 0.0;
    }

    /**
     * Hosting modes with their live one-time setup fees, for forms.
     *
     * @return array<string, array{label: string, fee: float}>
     */
    public static function hostingOptions(): array
    {
        $fees = static::pricingGlobals()['hosting_fees'];

        return [
            'self_hosted' => ['label' => static::hostingLabels()['self_hosted'], 'fee' => $fees['self_hosted']],
            'managed' => ['label' => static::hostingLabels()['managed'], 'fee' => $fees['managed']],
            'none' => ['label' => static::hostingLabels()['none'], 'fee' => $fees['none']],
        ];
    }

    public static function configSetupFee(): float
    {
        return static::pricingGlobals()['config_setup_fee'];
    }

    public static function migrationFee(): float
    {
        return static::pricingGlobals()['migration_fee'];
    }

    /**
     * @return array{admin: float, teacher: float, onsite: float}
     */
    public static function trainingRates(): array
    {
        $globals = static::pricingGlobals();

        return [
            'admin' => $globals['training_admin_rate'],
            'teacher' => $globals['training_teacher_rate'],
            'onsite' => $globals['training_onsite_rate'],
        ];
    }

    public static function foundingDiscountRate(): float
    {
        return static::pricingGlobals()['founding_discount_rate'];
    }

    public static function bundleDiscountRate(): float
    {
        return static::pricingGlobals()['bundle_discount_rate'];
    }

    public static function bundleThreshold(): int
    {
        return static::pricingGlobals()['bundle_threshold'];
    }

    public static function currency(): string
    {
        return static::pricingGlobals()['currency'];
    }

    /**
     * Resolve the pricing band. An explicit cap always wins; otherwise the
     * latest reported student count bands honestly; only with neither do
     * we fall back to the unmultiplied default. Counts past the largest
     * auto-priced band resolve to the custom-quote state.
     *
     * @param  list<array{key: string, label: string, min: int, max: ?int, core_upfront: float, core_renewal: float, multiplier: float, custom: bool}>  $bands
     * @return array{band_key: ?string, band_label: string, multiplier: float, core_upfront: float, core_renewal: float, is_custom: bool}
     */
    protected static function resolveBand(?int $maxStudents, ?int $reportedStudents, array $bands): array
    {
        if ($maxStudents !== null) {
            $match = static::bandContaining($maxStudents, $bands);

            if ($match !== null) {
                return static::bandResult($match, $match['label'], $maxStudents);
            }
        }

        if ($reportedStudents !== null) {
            $match = static::bandContaining($reportedStudents, $bands);

            if ($match !== null) {
                if (! empty($match['custom'])) {
                    return static::bandResult($match, $match['label'], $reportedStudents);
                }

                return static::bandResult($match, "{$reportedStudents} students (reported)", $reportedStudents);
            }

            foreach ($bands as $band) {
                if (empty($band['custom'])) {
                    return static::bandResult($band, "{$reportedStudents} students (reported)", $reportedStudents);
                }
            }
        }

        $default = null;

        foreach ($bands as $band) {
            if (empty($band['custom'])) {
                $default = $band;

                break;
            }
        }

        if ($default === null) {
            return [
                'band_key' => null,
                'band_label' => 'No student cap',
                'multiplier' => 1.0,
                'core_upfront' => 0.0,
                'core_renewal' => 0.0,
                'is_custom' => false,
            ];
        }

        return [
            'band_key' => $default['key'],
            'band_label' => 'No student cap',
            'multiplier' => 1.0,
            'core_upfront' => (float) $default['core_upfront'],
            'core_renewal' => (float) $default['core_renewal'],
            'is_custom' => false,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, min: int, max: ?int, core_upfront: float, core_renewal: float, multiplier: float, custom: bool}>  $bands
     * @return array{key: string, label: string, min: int, max: ?int, core_upfront: float, core_renewal: float, multiplier: float, custom: bool}|null
     */
    protected static function bandContaining(int $students, array $bands): ?array
    {
        foreach ($bands as $band) {
            if ($students >= $band['min'] && ($band['max'] === null || $students <= $band['max'])) {
                return $band;
            }
        }

        return null;
    }

    /**
     * @param  array{key: string, label: string, min: int, max: ?int, core_upfront: float, core_renewal: float, multiplier: float, custom: bool}  $band
     * @return array{band_key: ?string, band_label: string, multiplier: float, core_upfront: float, core_renewal: float, is_custom: bool}
     */
    protected static function bandResult(array $band, string $label, int $students): array
    {
        if (! empty($band['custom'])) {
            return [
                'band_key' => $band['key'],
                'band_label' => $band['label'],
                'multiplier' => 1.0,
                'core_upfront' => 0.0,
                'core_renewal' => 0.0,
                'is_custom' => true,
            ];
        }

        return [
            'band_key' => $band['key'],
            'band_label' => $label,
            'multiplier' => (float) $band['multiplier'],
            'core_upfront' => (float) $band['core_upfront'],
            'core_renewal' => (float) $band['core_renewal'],
            'is_custom' => false,
        ];
    }
}
