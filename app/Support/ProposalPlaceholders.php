<?php

namespace App\Support;

/**
 * {{snake_case}} placeholders for proposal text. The insert helper offers
 * exactly these keys; anything else is flagged at validation and never
 * silently kept. Pricing blocks render catalogue tables inline.
 */
class ProposalPlaceholders
{
    public const CLIENT = 'client';

    public const SCHOOL = 'school';

    public const CONTACT = 'contact';

    public const BAND = 'band';

    public const DATE = 'date';

    public const PROPOSAL_NO = 'proposal_no';

    public const MODULES_TABLE = 'modules_table';

    public const BANDS_TABLE = 'bands_table';

    /**
     * @var list<string>
     */
    public const ALLOWED = [
        self::CLIENT,
        self::SCHOOL,
        self::CONTACT,
        self::BAND,
        self::DATE,
        self::PROPOSAL_NO,
        self::MODULES_TABLE,
        self::BANDS_TABLE,
    ];

    /**
     * @var list<string>
     */
    public const PRICING_BLOCKS = [self::MODULES_TABLE, self::BANDS_TABLE];

    /**
     * Every {{key}} referenced in the given HTML/text chunks.
     *
     * @param  list<string|null>  $chunks
     * @return list<string>
     */
    public static function extractKeys(array $chunks): array
    {
        $keys = [];

        foreach ($chunks as $chunk) {
            if (! is_string($chunk) || $chunk === '') {
                continue;
            }

            preg_match_all('/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/', $chunk, $matches);

            foreach ($matches[1] as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Referenced keys outside the allowed list, in first-seen order.
     *
     * @param  list<string|null>  $chunks
     * @return list<string>
     */
    public static function unknownKeys(array $chunks): array
    {
        return array_values(array_diff(static::extractKeys($chunks), static::ALLOWED));
    }

    /**
     * Fill a plain-text heading. Only the six text keys resolve here;
     * anything else (pricing blocks included — tables don't belong in
     * headings) renders as a visible flag. Output is HTML-safe.
     *
     * @param  array<string, mixed>  $values
     */
    public static function resolveHeading(?string $heading, array $values): string
    {
        if ($heading === null || $heading === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            function (array $matches) use ($values): string {
                $key = $matches[1];

                if (! in_array($key, [self::CLIENT, self::SCHOOL, self::CONTACT, self::BAND, self::DATE, self::PROPOSAL_NO], true)) {
                    return '[unknown placeholder: '.$key.']';
                }

                return e((string) ($values[$key] ?? ''));
            },
            e($heading)
        );
    }

    /**
     * Fill known placeholders. Unknown keys render as a visible flag —
     * save-time validation blocks them, so instances never carry any.
     *
     * @param  array<string, mixed>  $values
     * @param  array{modules?: array, bands?: array, currency?: string}|null  $pricing
     */
    public static function resolve(?string $html, array $values, ?array $pricing = null): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        return (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z_][A-Za-z0-9_]*)\s*\}\}/',
            function (array $matches) use ($values, $pricing): string {
                $key = $matches[1];

                if ($key === static::MODULES_TABLE) {
                    return static::modulesTableHtml($pricing);
                }

                if ($key === static::BANDS_TABLE) {
                    return static::bandsTableHtml($pricing);
                }

                if (! in_array($key, static::ALLOWED, true)) {
                    return '<mark class="proposal-unknown">unknown placeholder: '.$key.'</mark>';
                }

                $value = $values[$key] ?? '';

                return e((string) $value);
            },
            $html
        );
    }

    /**
     * Inline module pricing table for the modules_table block.
     *
     * @param  array{modules?: array, bands?: array, currency?: string}|null  $pricing
     */
    public static function modulesTableHtml(?array $pricing = null): string
    {
        $currency = $pricing['currency'] ?? ProposalPricing::currency();
        $rows = ProposalPricing::moduleRows($pricing['modules'] ?? null);

        $html = '<table class="proposal-table"><thead><tr><th>Module</th><th>One-time</th><th>Renewal</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $html .= '<tr><td>'.e($row['label']).'</td><td>'.e($currency.' '.number_format((float) $row['onetime'], 2)).'</td><td>'.e($currency.' '.number_format((float) $row['renew'], 2)).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }

    /**
     * Inline band pricing table for the bands_table block.
     *
     * @param  array{modules?: array, bands?: array, currency?: string}|null  $pricing
     */
    public static function bandsTableHtml(?array $pricing = null): string
    {
        $currency = $pricing['currency'] ?? ProposalPricing::currency();
        $rows = ProposalPricing::bandRows($pricing['bands'] ?? null);

        $html = '<table class="proposal-table"><thead><tr><th>Students</th><th>Upfront</th><th>Renewal</th></tr></thead><tbody>';

        foreach ($rows as $row) {
            $upfront = $row['custom'] ? 'Custom quote' : $currency.' '.number_format((float) $row['upfront'], 2);
            $renew = $row['custom'] ? 'Custom quote' : $currency.' '.number_format((float) $row['renew'], 2);
            $html .= '<tr><td>'.e($row['label']).'</td><td>'.e($upfront).'</td><td>'.e($renew).'</td></tr>';
        }

        return $html.'</tbody></table>';
    }
}
