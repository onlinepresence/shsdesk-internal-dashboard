<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Seed live pricing globals from config once. Existing rows are left
     * untouched so re-running never clobbers superadmin edits.
     *
     * The seed source is the LIVE FlowEdu maths (`core_pricing`,
     * `module_pricing`, `bundle_discount`, `founding_client_discount`,
     * plus the `fees`/`bundle_threshold` figures transcribed from
     * QuoteCalculationService) — never the stale `pricing` /
     * `student_pricing_bands` system, which nothing live reads.
     */
    public function run(): void
    {
        $this->seedIfMissing(Setting::CURRENCY, (string) config('licence-catalogue.currency', 'GHS'));
        $this->seedIfMissing(Setting::CORE_PRICING, json_encode($this->corePricingSeed()));
        $this->seedIfMissing(Setting::BUNDLE_DISCOUNT_RATE, (string) config('licence-catalogue.bundle_discount', '0.12'));
        $this->seedIfMissing(Setting::BUNDLE_THRESHOLD, (string) config('licence-catalogue.bundle_threshold', '4'));
        $this->seedIfMissing(Setting::FOUNDING_DISCOUNT_RATE, (string) config('licence-catalogue.founding_client_discount', '0.15'));
        $this->seedIfMissing(Setting::HOSTING_SELF_HOSTED_FEE, (string) config('licence-catalogue.fees.hosting.self_hosted', '1200'));
        $this->seedIfMissing(Setting::HOSTING_MANAGED_FEE, (string) config('licence-catalogue.fees.hosting.managed', '1600'));
        $this->seedIfMissing(Setting::HOSTING_NONE_FEE, (string) config('licence-catalogue.fees.hosting.none', '0'));
        $this->seedIfMissing(Setting::CONFIG_SETUP_FEE, (string) config('licence-catalogue.fees.config_setup', '800'));
        $this->seedIfMissing(Setting::MIGRATION_FEE, (string) config('licence-catalogue.fees.migration', '2000'));
        $this->seedIfMissing(Setting::TRAINING_ADMIN_RATE, (string) config('licence-catalogue.fees.training.admin', '600'));
        $this->seedIfMissing(Setting::TRAINING_TEACHER_RATE, (string) config('licence-catalogue.fees.training.teacher', '500'));
        $this->seedIfMissing(Setting::TRAINING_ONSITE_RATE, (string) config('licence-catalogue.fees.training.onsite', '1500'));
        $this->seedIfMissing(Setting::INVOICE_DOC_TITLE, 'Proforma Invoice');
        $this->seedIfMissing(Setting::INVOICE_COMPANY, 'Matme Inc.');
        $this->seedIfMissing(Setting::INVOICE_DEPARTMENT, 'Systems Integration & Licensing Team');
        $this->seedIfMissing(Setting::INVOICE_EMAIL, 'successinnovativehub@gmail.com');
        $this->seedIfMissing(Setting::INVOICE_PHONE, '0249100268');
        $this->seedIfMissing(Setting::INVOICE_LOCATION, 'Accra, Ghana');
        $this->seedIfMissing(Setting::INVOICE_DUE_DAYS, '30');
    }

    /**
     * Core pricing bands with fixed student ranges. Ranges live here (not
     * in settings) so ops can edit figures without redefining bands.
     *
     * @return list<array<string, mixed>>
     */
    private function corePricingSeed(): array
    {
        $ranges = [
            '1-500' => ['min' => 1, 'max' => 500],
            '501-1000' => ['min' => 501, 'max' => 1000],
            '1001-2000' => ['min' => 1001, 'max' => 2000],
            '2001-3500' => ['min' => 2001, 'max' => 3500],
            '3500+' => ['min' => 3501, 'max' => null],
        ];

        $corePricing = config('licence-catalogue.core_pricing', []);
        $multipliers = config('licence-catalogue.module_pricing.multipliers', []);
        $bands = [];

        foreach ($ranges as $key => $range) {
            $band = $corePricing[$key] ?? [];

            $bands[] = [
                'key' => $key,
                'label' => $band['label'] ?? $key,
                'min' => $range['min'],
                'max' => $range['max'],
                'core_upfront' => (float) ($band['core_upfront'] ?? 0),
                'core_renewal' => (float) ($band['core_renewal'] ?? 0),
                'multiplier' => (float) ($multipliers[$key] ?? $band['multiplier'] ?? 1),
                'custom' => (bool) ($band['custom'] ?? false),
            ];
        }

        return $bands;
    }

    private function seedIfMissing(string $key, string $value): void
    {
        if (! Setting::query()->where('key', $key)->exists()) {
            Setting::query()->create(['key' => $key, 'value' => $value]);
        }
    }
}
