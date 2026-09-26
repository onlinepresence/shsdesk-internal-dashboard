<?php

namespace App\Support;

use App\Models\Proposal;
use App\Models\TemplateSection;

/**
 * Single render path for proposals. Draft previews, PDF downloads, and
 * Word exports all resolve through here, so preview and download cannot
 * diverge: same blocks, same numbering, same placeholder fills.
 *
 * Each block: {number: int, heading: string, kind: string, html: string}
 * with kind one of prose|modules_table|bands_table|signature.
 */
class ProposalRenderer
{
    /**
     * Sample fills for template previews (no instance exists yet).
     *
     * @return array<string, string>
     */
    public static function sampleValues(): array
    {
        return [
            'client' => 'Ama Serwaa',
            'school' => 'Sample College',
            'contact' => 'ama@example.com',
            'band' => '1-500',
            'date' => today()->format('F j, Y'),
            'proposal_no' => 'PROP-'.today()->format('Y').'-000',
        ];
    }

    /**
     * Resolve raw sections (draft arrays or snapshot rows) into blocks.
     * Section numbers are derived here at render time, never stored.
     *
     * @param  list<array{heading?: ?string, body_html?: ?string, type?: ?string, config?: ?array}>  $sections
     * @param  array<string, mixed>  $values
     * @param  array{modules?: array, bands?: array, currency?: string}|null  $pricing
     * @return list<array{number: int, heading: string, kind: string, html: string}>
     */
    public static function resolve(array $sections, array $values, ?array $pricing = null): array
    {
        $blocks = [];
        $number = 0;

        foreach ($sections as $section) {
            $type = $section['type'] ?? TemplateSection::TYPE_PROSE;
            $heading = ProposalPlaceholders::resolveHeading($section['heading'] ?? null, $values);
            $number++;

            if ($type === TemplateSection::TYPE_PRICING_TABLE) {
                $source = is_array($section['config'] ?? null) ? ($section['config']['source'] ?? null) : null;

                if ($source === TemplateSection::PRICING_MODULES) {
                    $blocks[] = ['number' => $number, 'heading' => $heading, 'kind' => 'modules_table', 'html' => ProposalPlaceholders::modulesTableHtml($pricing)];

                    continue;
                }

                if ($source === TemplateSection::PRICING_BANDS) {
                    $blocks[] = ['number' => $number, 'heading' => $heading, 'kind' => 'bands_table', 'html' => ProposalPlaceholders::bandsTableHtml($pricing)];

                    continue;
                }
            }

            if ($type === TemplateSection::TYPE_SIGNATURE) {
                $blocks[] = ['number' => $number, 'heading' => $heading, 'kind' => 'signature', 'html' => static::signatureHtml($section['body_html'] ?? null, $values, $pricing)];

                continue;
            }

            $blocks[] = [
                'number' => $number,
                'heading' => $heading,
                'kind' => 'prose',
                'html' => ProposalPlaceholders::resolve($section['body_html'] ?? null, $values, $pricing),
            ];
        }

        return $blocks;
    }

    /**
     * Blocks for a stored instance — always from its frozen snapshot.
     *
     * @return list<array{number: int, heading: string, kind: string, html: string}>
     */
    public static function instanceBlocks(Proposal $proposal): array
    {
        $values = array_merge(
            static::sampleValues(),
            is_array($proposal->values) ? $proposal->values : [],
            ['proposal_no' => $proposal->proposal_no]
        );

        return static::resolve(
            is_array($proposal->sections_snapshot) ? $proposal->sections_snapshot : [],
            $values,
            is_array($proposal->pricing_snapshot) ? $proposal->pricing_snapshot : null,
        );
    }

    /**
     * Render blocks through the shared document fragment.
     *
     * @param  list<array{number: int, heading: string, kind: string, html: string}>  $blocks
     */
    public static function renderHtml(array $blocks): string
    {
        return view('proposals.document', ['blocks' => $blocks])->render();
    }

    /**
     * Sign-off block: the section's own (resolved) body plus signature
     * lines for both parties, dated from the filled values.
     *
     * @param  array<string, mixed>  $values
     * @param  array{modules?: array, bands?: array, currency?: string}|null  $pricing
     */
    protected static function signatureHtml(?string $body, array $values, ?array $pricing): string
    {
        $html = ProposalPlaceholders::resolve($body, $values, $pricing);

        $school = e((string) ($values['school'] ?? ''));
        $contact = e((string) ($values['contact'] ?? ($values['client'] ?? '')));
        $date = e((string) ($values['date'] ?? ''));

        $html .= '<table class="proposal-signoff"><tbody>'
            .'<tr><td>Prepared for</td><td>'.$school.'</td></tr>'
            .'<tr><td>Contact</td><td>'.$contact.'</td></tr>'
            .'<tr><td>Date</td><td>'.$date.'</td></tr>'
            .'</tbody></table>'
            .'<div class="proposal-signatures">'
            .'<div><p>For ControlDesk</p><p class="proposal-signline">Signature &amp; date</p></div>'
            .'<div><p>For the school</p><p class="proposal-signline">Signature &amp; date</p></div>'
            .'</div>';

        return $html;
    }
}
