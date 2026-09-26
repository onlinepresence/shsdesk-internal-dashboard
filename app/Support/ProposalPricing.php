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
     * Full snapshot for freezing onto an instance.
     *
     * @return array{currency: string, modules: list<array{key: string, label: string, onetime: float, renew: float}>, bands: list<array{key: string, label: string, range: string, upfront: float, renew: float, custom: bool}>}
     */
    public static function snapshot(): array
    {
        return [
            'currency' => static::currency(),
            'modules' => static::moduleRows(),
            'bands' => static::bandRows(),
        ];
    }

    public static function currency(): string
    {
        return (string) (Setting::get(Setting::CURRENCY, 'GHS') ?? 'GHS');
    }

    /**
     * Active catalogue modules with current prices.
     *
     * @return list<array{key: string, label: string, onetime: float, renew: float}>
     */
    public static function moduleRows(?array $snapshot = null): array
    {
        if (is_array($snapshot)) {
            return $snapshot;
        }

        return Feature::query()
            ->where('kind', 'module')
            ->where('active', true)
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
     * Student bands with upfront/renewal figures.
     *
     * @return list<array{key: string, label: string, range: string, upfront: float, renew: float, custom: bool}>
     */
    public static function bandRows(?array $snapshot = null): array
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
        ], Setting::corePricing());
    }
}
