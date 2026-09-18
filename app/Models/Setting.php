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

    public const CORE_BASE_ANNUAL = 'pricing.core_base_annual';

    public const ALL_MODULES_DISCOUNT_RATE = 'pricing.all_modules_discount_rate';

    public const STUDENT_BANDS = 'pricing.student_bands';

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

    /**
     * Student bands for price previews, highest band last.
     *
     * @return list<array{min: int, max: ?int, multiplier: float, label: string}>
     */
    public static function studentBands(): array
    {
        $decoded = json_decode((string) static::get(static::STUDENT_BANDS, '[]'), true);

        if (! is_array($decoded)) {
            return [];
        }

        $valid = array_filter($decoded, fn (mixed $band): bool => is_array($band)
            && isset($band['min'], $band['multiplier'], $band['label']));

        return array_values(array_map(fn (array $band): array => [
            'min' => (int) $band['min'],
            'max' => isset($band['max']) ? (int) $band['max'] : null,
            'multiplier' => (float) $band['multiplier'],
            'label' => (string) $band['label'],
        ], $valid));
    }
}
