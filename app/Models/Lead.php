<?php

namespace App\Models;

use Database\Factories\LeadFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'contact_name', 'contact_email', 'contact_role', 'contact_phone', 'school', 'band', 'modules', 'quote_upfront', 'quote_renewal', 'quote_lines', 'status'])]
class Lead extends Model
{
    /** @use HasFactory<LeadFactory> */
    use HasFactory;

    public const STATUS_NEW = 'new';

    public const STATUS_REVIEWED = 'reviewed';

    public const STATUS_CONVERTED = 'converted';

    /**
     * Days a same-email resubmission refreshes the open lead instead
     * of opening a duplicate.
     */
    public const DEDUPE_DAYS = 30;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'modules' => 'array',
            'quote_upfront' => 'decimal:2',
            'quote_renewal' => 'decimal:2',
            'quote_lines' => 'array',
        ];
    }

    /**
     * Product the lead quoted for.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * Whether the lead still sits in the human review queue.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [static::STATUS_NEW, static::STATUS_REVIEWED], true);
    }
}
