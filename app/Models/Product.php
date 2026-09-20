<?php

namespace App\Models;

use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

#[Fillable(['name', 'slug', 'active'])]
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    /**
     * Deployments registered under this product slug.
     */
    public function deployments(): HasMany
    {
        return $this->hasMany(Deployment::class, 'product', 'slug');
    }

    /**
     * Licences on this product's deployments.
     */
    public function licences(): HasManyThrough
    {
        return $this->hasManyThrough(Licence::class, Deployment::class, 'product', 'deployment_id', 'slug', 'id');
    }

    /**
     * Quote requests queued for this product.
     */
    public function leads(): HasMany
    {
        return $this->hasMany(Lead::class);
    }

    /**
     * Leads still awaiting human review or conversion.
     */
    public function openLeads(): HasMany
    {
        return $this->hasMany(Lead::class)->whereIn('status', [Lead::STATUS_NEW, Lead::STATUS_REVIEWED]);
    }

    /**
     * Whether the product authenticates anything. Inactive products
     * authenticate nothing until activated.
     */
    public function isActive(): bool
    {
        return (bool) $this->active;
    }
}
