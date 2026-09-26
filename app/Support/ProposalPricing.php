<?php

namespace App\Support;

use App\Models\Feature;
use App\Models\Setting;

/**
 * Catalogue pricing for proposals. Live reads power template previews;
 * generation-time snapshots power instances, so re-downloads never move
 * with later price edits. Figures always come from here — never typed.
 */
class ProposalPricing
{
    /**
     * Full snapshot for freezing onto an instance. Product-scoped rows
     * win over globals, so each proposal prices its own product.
     *
     * @return array{currency: string, modules: list<array{key: string, label: string, onetime: float, renew: float}>, bands: list<array{key: string, label: string, range: string, upfront: float, renew: float, custom: bool}>}
     */
    public static function snapshot(?int $productId = null): array
    {
        return [
            'currency' => static::currency($productId),
            'modules' => static::moduleRows(null, $productId),
            'bands' => static::bandRows(null, $productId),
        ];
    }

    public static function currency(?int $productId = null): string
    {
        return (string) (Setting::getForProduct($productId, Setting::CURRENCY, 'GHS') ?? 'GHS');
    }

    /**
     * Active catalogue modules with current prices, for one product.
     *
     * @return list<array{key: string, label: string, onetime: float, renew: float}>
     */
    public static function moduleRows(?array $snapshot = null, ?int $productId = null): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        return Feature::query()
            ->where('kind', 'module')
            ->where('active', true)
            ->when($productId !== null, fn ($query) => $query->where('product_id', $productId))
            ->orderBy('id')
            ->get()
            ->map(fn (Feature $feature): array => [
                'key' => $feature->key,
                'label' => $feature->label,
                'onetime' => (float) ($feature->base_price ?? 0),
                'renew' => (float) ($feature->renewal_base ?? 0),
            ])
            ->all();
    }

    /**
     * Student bands with upfront/renewal figures, for one product.
     *
     * @return list<array{key: string, label: string, range: string, upfront: float, renew: float, custom: bool}>
     */
    public static function bandRows(?array $snapshot = null, ?int $productId = null): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        return array_map(fn (array $band): array => [
            'key' => $band['key'],
            'label' => $band['label'],
            'range' => $band['max'] === null ? "{$band['min']}+" : "{$band['min']} – {$band['max']}",
            'upfront' => (float) $band['core_upfront'],
            'renew' => (float) $band['core_renewal'],
            'custom' => (bool) $band['custom'],
        ], Setting::corePricingFor($productId));
    }
}
