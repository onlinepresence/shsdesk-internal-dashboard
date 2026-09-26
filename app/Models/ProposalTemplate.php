<?php

namespace App\Models;

use Database\Factories\ProposalTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['product_id', 'title', 'version'])]
class ProposalTemplate extends Model
{
    /** @use HasFactory<ProposalTemplateFactory> */
    use HasFactory;

    /**
     * Product this template proposes.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Sections in render order.
     */
    public function sections(): HasMany
    {
        return $this->hasMany(TemplateSection::class)->orderBy('order');
    }

    /**
     * Generated instances frozen off this template.
     */
    public function proposals(): HasMany
    {
        return $this->hasMany(Proposal::class);
    }
}
