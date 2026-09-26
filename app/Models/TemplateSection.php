<?php

namespace App\Models;

use Database\Factories\TemplateSectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['proposal_template_id', 'order', 'heading', 'body_html', 'type', 'config'])]
class TemplateSection extends Model
{
    /** @use HasFactory<TemplateSectionFactory> */
    use HasFactory;

    public const TYPE_PROSE = 'prose';

    public const TYPE_PRICING_TABLE = 'pricing_table';

    public const TYPE_SIGNATURE = 'signature';

    public const TYPES = [self::TYPE_PROSE, self::TYPE_PRICING_TABLE, self::TYPE_SIGNATURE];

    /**
     * Pricing table sources. Modules list catalogue modules with current
     * prices; bands list student bands with upfront/renewal figures.
     */
    public const PRICING_MODULES = 'modules';

    public const PRICING_BANDS = 'bands';

    public const PRICING_SOURCES = [self::PRICING_MODULES, self::PRICING_BANDS];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'config' => 'array',
        ];
    }

    /**
     * Template this section belongs to.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProposalTemplate::class, 'proposal_template_id');
    }

    /**
     * Pricing source for pricing_table sections (modules|bands).
     */
    public function pricingSource(): ?string
    {
        $source = is_array($this->config) ? ($this->config['source'] ?? null) : null;

        return in_array($source, self::PRICING_SOURCES, true) ? $source : null;
    }
}
