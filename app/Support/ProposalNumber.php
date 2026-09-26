<?php

namespace App\Support;

use App\Models\Proposal;

/**
 * Sequential proposal numbers (PROP-2026-001). The sequence restarts
 * each year and is stored on the instance at generation time.
 */
class ProposalNumber
{
    /**
     * Next free number for the current year.
     */
    public static function next(?int $year = null): string
    {
        $year ??= (int) today()->format('Y');
        $prefix = "PROP-{$year}-";

        $max = Proposal::query()
            ->where('proposal_no', 'like', $prefix.'%')
            ->orderByDesc('proposal_no')
            ->value('proposal_no');

        $sequence = $max === null ? 1 : ((int) substr((string) $max, strlen($prefix)) + 1);

        return $prefix.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
    }
}
