<?php

namespace App\Support;

use App\Models\Proposal;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as DomPdf;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\Shared\Html;
use PhpOffice\PhpWord\Style;

/**
 * File downloads for proposals. PDFs render the shared Blade fragment
 * (same path as the editor preview); Word documents are built from the
 * same resolved blocks. Both always read the instance snapshot.
 */
class ProposalFiles
{
    /**
     * PDF rendered through the shared document fragment.
     */
    public static function pdf(Proposal $proposal): DomPdf
    {
        $values = static::instanceValues($proposal);

        return Pdf::loadView('proposals.pdf', [
            'proposal' => $proposal,
            'values' => $values,
            'blocks' => ProposalRenderer::instanceBlocks($proposal),
        ]);
    }

    /**
     * Path to a generated .docx scratch file. Caller streams it and
     * removes it (deleteFileAfterSend).
     */
    public static function docxPath(Proposal $proposal): string
    {
        $values = static::instanceValues($proposal);
        $blocks = ProposalRenderer::instanceBlocks($proposal);

        // PhpWord leaves XML escaping off unless asked; without this,
        // catalogue labels like "Staff & HR" corrupt the document.
        Settings::setOutputEscapingEnabled(true);

        $document = new PhpWord;
        $document->setDefaultFontName('Calibri');
        $document->setDefaultFontSize(11);

        // Registered after construction: new PhpWord wipes the static
        // style registry, and titles only become real headings (readable
        // back on import) once Heading_1 exists.
        Style::addTitleStyle(1, ['bold' => true, 'size' => 16, 'color' => '1F2937']);

        $section = $document->addSection();
        $section->addText($proposal->proposal_no, ['bold' => true, 'size' => 16]);
        $section->addText((string) ($values['school'] ?? ''), ['size' => 13, 'color' => '555555']);
        $section->addTextBreak();

        foreach ($blocks as $block) {
            $heading = trim(html_entity_decode(strip_tags($block['heading']), ENT_QUOTES, 'UTF-8'));

            if ($heading !== '') {
                $section->addTitle($block['number'].'. '.$heading, 1);
            }

            static::addBlockHtml($section, $block['kind'], $block['html']);
        }

        $path = tempnam(sys_get_temp_dir(), 'proposal').'.docx';

        IOFactory::createWriter($document, 'Word2007')->save($path);

        return $path;
    }

    /**
     * Filled values with the sample fallback and the real proposal number.
     *
     * @return array<string, mixed>
     */
    protected static function instanceValues(Proposal $proposal): array
    {
        return array_merge(
            ProposalRenderer::sampleValues(),
            is_array($proposal->values) ? $proposal->values : [],
            ['proposal_no' => $proposal->proposal_no]
        );
    }

    /**
     * Append one resolved block. Rich HTML goes through PhpWord's HTML
     * reader; anything it chokes on falls back to plain text so an
     * exotic tag can never sink the whole export.
     *
     * @param  Section  $section
     */
    protected static function addBlockHtml(object $section, string $kind, string $html): void
    {
        if (trim(strip_tags($html)) === '') {
            return;
        }

        try {
            Html::addHtml($section, $html, false, false);
        } catch (\Throwable) {
            $plain = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

            foreach (preg_split('/\R+/', $plain) as $paragraph) {
                $paragraph = trim($paragraph);

                if ($paragraph !== '') {
                    $section->addText($paragraph);
                }
            }
        }
    }
}
