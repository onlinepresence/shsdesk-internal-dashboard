<?php

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['key', 'value'])]
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    public const CURRENCY = 'pricing.currency';

    /**
     * Live core pricing table (JSON list). Each band carries its key,
     * label, student range, upfront/renewal core figures, and the module
     * multiplier — mirroring FlowEdu's `core_pricing` (+ the matching
     * `module_pricing.multipliers`). The 3500+ band is custom-quoted.
     */
    public const CORE_PRICING = 'pricing.core_pricing';

    /**
     * Bundle discount off modules once the threshold count is selected.
     */
    public const BUNDLE_DISCOUNT_RATE = 'pricing.bundle_discount_rate';

    public const BUNDLE_THRESHOLD = 'pricing.bundle_threshold';

    /**
     * Founding-client discount off the core only (upfront and renewal).
     */
    public const FOUNDING_DISCOUNT_RATE = 'pricing.founding_discount_rate';

    /**
     * One-time hosting setup fees by mode.
     */
    public const HOSTING_SELF_HOSTED_FEE = 'pricing.hosting_self_hosted_fee';

    public const HOSTING_MANAGED_FEE = 'pricing.hosting_managed_fee';

    public const HOSTING_NONE_FEE = 'pricing.hosting_none_fee';

    /**
     * One-time implementation addons behind the config/migration booleans.
     */
    public const CONFIG_SETUP_FEE = 'pricing.config_setup_fee';

    public const MIGRATION_FEE = 'pricing.migration_fee';

    /**
     * Per-unit training rates.
     */
    public const TRAINING_ADMIN_RATE = 'pricing.training_admin_rate';

    public const TRAINING_TEACHER_RATE = 'pricing.training_teacher_rate';

    public const TRAINING_ONSITE_RATE = 'pricing.training_onsite_rate';

    /**
     * Proforma invoice document settings.
     */
    public const INVOICE_DOC_TITLE = 'invoicing.doc_title';

    public const INVOICE_COMPANY = 'invoicing.company';

    public const INVOICE_DEPARTMENT = 'invoicing.department';

    public const INVOICE_EMAIL = 'invoicing.email';

    public const INVOICE_PHONE = 'invoicing.phone';

    public const INVOICE_LOCATION = 'invoicing.location';

    /**
     * Default payment window in days from issue. Overridable per invoice.
     */
    public const INVOICE_DUE_DAYS = 'invoicing.due_days';

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::query()->where('key', $key)->value('value');

        return $value ?? $default;
    }

    public static function set(string $key, mixed $value): Setting
    {
        return static::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value === null ? null : (string) $value],
        );
    }

    public static function getFloat(string $key, float $default = 0.0): float
    {
        $value = static::get($key);

        return $value === null || $value === '' ? $default : (float) $value;
    }

    public static function getInt(string $key, int $default = 0): int
    {
        $value = static::get($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    /**
     * Live core pricing bands, custom-quote band last.
     *
     * @return list<array{key: string, label: string, min: int, max: ?int, core_upfront: float, core_renewal: float, multiplier: float, custom: bool}>
     */
    public static function corePricing(): array
    {
        return static::decodeCorePricing(static::get(static::CORE_PRICING, '[]'));
    }

    /**
     * Decode a stored core-pricing JSON blob into bands. Split out so
     * callers that already hold the raw value (e.g. a batched read)
     * can decode without a second query.
     *
     * @return list<array{key: string, label: string, min: int, max: ?int, core_upfront: float, core_renewal: float, multiplier: float, custom: bool}>
     */
    public static function decodeCorePricing(?string $raw): array
    {
        $decoded = json_decode((string) $raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $bands = [];

        foreach (array_values($decoded) as $band) {
            if (! is_array($band) || ! isset($band['key'], $band['label'])) {
                continue;
            }

            $bands[] = [
                'key' => (string) $band['key'],
                'label' => (string) $band['label'],
                'min' => (int) ($band['min'] ?? 0),
                'max' => array_key_exists('max', $band) && $band['max'] !== null ? (int) $band['max'] : null,
                'core_upfront' => (float) ($band['core_upfront'] ?? 0),
                'core_renewal' => (float) ($band['core_renewal'] ?? 0),
                'multiplier' => (float) ($band['multiplier'] ?? 1),
                'custom' => (bool) ($band['custom'] ?? false),
            ];
        }

        return $bands;
    }
}
