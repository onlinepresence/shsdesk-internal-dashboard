<?php

namespace App\Models;

use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['label', 'description', 'kind', 'locked', 'default_on', 'base_price', 'renewal_base', 'active'])]
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked' => 'boolean',
            'default_on' => 'boolean',
            'base_price' => 'decimal:2',
            'renewal_base' => 'decimal:2',
            'active' => 'boolean',
        ];
    }

    /**
     * Features currently offered (the only ones new grants may enable).
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('active', true);
    }
}
