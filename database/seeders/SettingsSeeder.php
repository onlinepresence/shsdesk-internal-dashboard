<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /**
     * Seed pricing globals from config once. Existing rows are left
     * untouched so re-running never clobbers superadmin edits.
     */
    public function run(): void
    {
        $this->seedIfMissing(Setting::CURRENCY, (string) config('licence-catalogue.pricing.currency', 'GHS'));
        $this->seedIfMissing(Setting::CORE_BASE_ANNUAL, (string) config('licence-catalogue.pricing.core.base_annual', '12000'));
        $this->seedIfMissing(Setting::ALL_MODULES_DISCOUNT_RATE, (string) config('licence-catalogue.pricing.discounts.all_modules_rate', '0.2'));
        $this->seedIfMissing(Setting::STUDENT_BANDS, json_encode(array_values(config('licence-catalogue.student_pricing_bands', []))));
    }

    private function seedIfMissing(string $key, string $value): void
    {
        if (! Setting::query()->where('key', $key)->exists()) {
            Setting::query()->create(['key' => $key, 'value' => $value]);
        }
    }
}
