<?php

namespace App\Models;

use App\Support\ProposalNumber;
use App\Support\ProposalPricing;
use Database\Factories\ProposalFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['proposal_template_id', 'template_version', 'proposal_no', 'lead_id', 'deployment_id', 'values', 'sections_snapshot', 'pricing_snapshot', 'status'])]
class Proposal extends Model
{
    /** @use HasFactory<ProposalFactory> */
    use HasFactory;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SENT = 'sent';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SENT, self::STATUS_ACCEPTED];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'values' => 'array',
            'sections_snapshot' => 'array',
            'pricing_snapshot' => 'array',
        ];
    }

    /**
     * Template this instance was frozen from.
     */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ProposalTemplate::class, 'proposal_template_id');
    }

    /**
     * Lead this proposal was quoted from, if any.
     */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /**
     * Deployment this proposal was quoted for, if any.
     */
    public function deployment(): BelongsTo
    {
        return $this->belongsTo(Deployment::class);
    }

    /**
     * Next status in the draft → sent → accepted chain, if any.
     */
    public function nextStatus(): ?string
    {
        return match ($this->status) {
            self::STATUS_DRAFT => self::STATUS_SENT,
            self::STATUS_SENT => self::STATUS_ACCEPTED,
            default => null,
        };
    }

    /**
     * Create an instance frozen off a template: section text, filled
     * values, pricing, and the next sequential proposal number are all
     * stored, so re-downloads never move with later edits.
     *
     * @param  array<string, mixed>  $values
     */
    public static function generateFrom(ProposalTemplate $template, array $values, ?int $leadId = null, ?int $deploymentId = null): self
    {
        $template->loadMissing('sections');

        return static::query()->create([
            'proposal_template_id' => $template->id,
            'template_version' => $template->version,
            'proposal_no' => ProposalNumber::next(),
            'lead_id' => $leadId,
            'deployment_id' => $deploymentId,
            'values' => $values,
            'sections_snapshot' => $template->sections->map(fn (TemplateSection $section): array => [
                'heading' => $section->heading,
                'body_html' => $section->body_html,
                'type' => $section->type,
                'config' => $section->config,
            ])->all(),
            'pricing_snapshot' => ProposalPricing::snapshot(),
            'status' => self::STATUS_DRAFT,
        ]);
    }
}
