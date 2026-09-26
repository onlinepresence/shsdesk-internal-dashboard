<?php

namespace Database\Seeders;

use App\Models\Feature;
use App\Models\Product;
use Illuminate\Database\Seeder;

class CatalogueSeeder extends Seeder
{
    /**
     * Import every catalogue entry. Existing rows are left untouched so
     * re-running never clobbers admin edits; add new keys here instead.
     */
    public function run(): void
    {
        foreach (config('licence-catalogue.core_features', []) as $key => $feature) {
            $this->importFeature(
                key: ($feature['locked'] ?? false) ? $key : $feature['db_column'],
                label: $feature['label'],
                description: $feature['description'],
                kind: 'core',
                locked: (bool) ($feature['locked'] ?? false),
                defaultOn: (bool) ($feature['default'] ?? false),
                basePrice: null,
                renewalBase: null,
            );
        }

        foreach (config('licence-catalogue.modules', []) as $module) {
            $this->importFeature(
                key: $module['db_column'],
                label: $module['label'],
                description: $module['description'],
                kind: 'module',
                locked: false,
                defaultOn: (bool) ($module['default'] ?? false),
                basePrice: $module['base_price'] ?? null,
                renewalBase: $module['renewal_base'] ?? null,
            );
        }
    }

    private function importFeature(string $key, string $label, string $description, string $kind, bool $locked, bool $defaultOn, ?float $basePrice, ?float $renewalBase): void
    {
        // The shipped catalogue belongs to FlowEdu; other products grow
        // their own rows from the catalogue page. Ensured here (not via
        // seeder order) so this seeder stays runnable standalone.
        $productId = Product::query()->firstOrCreate(
            ['slug' => 'flowedu'],
            ['name' => 'FlowEdu', 'active' => true],
        )->id;

        if (Feature::query()->where('product_id', $productId)->where('key', $key)->exists()) {
            return;
        }

        $feature = new Feature;

        // forceFill: key is deliberately absent from Fillable (immutable).
        $feature->forceFill([
            'product_id' => $productId,
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'kind' => $kind,
            'locked' => $locked,
            'default_on' => $defaultOn,
            'base_price' => $basePrice,
            'renewal_base' => $renewalBase,
            'active' => true,
        ])->save();
    }
}
