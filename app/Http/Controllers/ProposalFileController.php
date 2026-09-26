<?php

namespace App\Http\Controllers;

use App\Models\Proposal;
use App\Support\ProposalFiles;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProposalFileController extends Controller
{
    /**
     * Download the proposal as PDF. Renders the shared Blade fragment
     * from the frozen instance snapshot — the editor preview path.
     */
    public function pdf(Proposal $proposal)
    {
        activity('proposals')
            ->performedOn($proposal)
            ->causedBy(Auth::user())
            ->log('proposal.downloaded_pdf');

        return ProposalFiles::pdf($proposal)->download("{$proposal->proposal_no}.pdf");
    }

    /**
     * Download the proposal as Word. Built from the same resolved blocks
     * as the PDF, straight from the frozen instance snapshot.
     */
    public function docx(Proposal $proposal): BinaryFileResponse
    {
        activity('proposals')
            ->performedOn($proposal)
            ->causedBy(Auth::user())
            ->log('proposal.downloaded_docx');

        return response()->download(
            ProposalFiles::docxPath($proposal),
            "{$proposal->proposal_no}.docx"
        )->deleteFileAfterSend(true);
    }
}
