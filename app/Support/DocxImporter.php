<?php

namespace App\Support;

use PhpOffice\PhpWord\Element\Image;
use PhpOffice\PhpWord\Element\ListItem;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\Element\Title;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Style\Font;

/**
 * .docx-only importer (Word is never parsed from PDF). Headings become
 * sections, body text is preserved with basic bold/italic, {{keys}} are
 * detected, and tables/images are counted and queued for human mapping —
 * nothing unrecognized is silently swallowed.
 */
class DocxImporter
{
    /**
     * @return array{sections: list<array{heading: string, body_html: string}>, tables: int, images: int, placeholders: list<string>, notes: list<string>}
     */
    public static function parse(string $path): array
    {
        $document = IOFactory::load($path);

        $sections = [];
        $current = null;
        $tables = 0;
        $images = 0;
        $notes = [];

        $flush = function () use (&$sections, &$current): void {
            if ($current !== null && ($current['heading'] !== '' || $current['body_html'] !== '')) {
                $sections[] = $current;
            }

            $current = null;
        };

        foreach ($document->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if ($element instanceof Title) {
                    $flush();

                    $depth = (int) $element->getDepth();
                    $text = trim((string) $element->getText());

                    if ($depth <= 1) {
                        $current = ['heading' => $text, 'body_html' => ''];
                    } else {
                        $current ??= ['heading' => '', 'body_html' => ''];
                        $current['body_html'] .= '<h3>'.e($text).'</h3>';
                    }

                    continue;
                }

                $current ??= ['heading' => '', 'body_html' => ''];

                if ($element instanceof TextBreak) {
                    continue;
                }

                if ($element instanceof Text || $element instanceof TextRun || $element instanceof ListItem) {
                    $text = static::richText($element);

                    if (trim(strip_tags($text)) === '') {
                        continue;
                    }

                    $current['body_html'] .= $element instanceof ListItem
                        ? '<ul><li>'.$text.'</li></ul>'
                        : '<p>'.$text.'</p>';

                    continue;
                }

                if ($element instanceof Table) {
                    $tables++;
                    $notes[] = 'Table '.($tables).' ('.static::tableExcerpt($element).') needs human mapping — re-create it as a pricing-table section or drop it.';

                    continue;
                }

                if ($element instanceof Image) {
                    $images++;
                    $notes[] = 'Image '.($images).' needs human mapping — images never import; re-attach it after generation.';

                    continue;
                }

                $notes[] = 'Unrecognized element ('.basename(str_replace('\\', '/', $element::class)).') was skipped — carry its content over by hand.';
            }
        }

        $flush();

        $chunks = [];

        foreach ($sections as $parsed) {
            $chunks[] = $parsed['heading'];
            $chunks[] = $parsed['body_html'];
        }

        return [
            'sections' => $sections,
            'tables' => $tables,
            'images' => $images,
            'placeholders' => ProposalPlaceholders::extractKeys($chunks),
            'notes' => array_values(array_unique($notes)),
        ];
    }

    /**
     * Text with basic bold/italic preserved as HTML (escaped).
     */
    protected static function richText(object $element): string
    {
        if ($element instanceof Text) {
            return static::styledText((string) $element->getText(), $element->getFontStyle());
        }

        if ($element instanceof ListItem) {
            return static::styledText((string) $element->getText(), $element->getFontStyle());
        }

        $html = '';

        if ($element instanceof TextRun) {
            foreach ($element->getElements() as $child) {
                if ($child instanceof TextBreak) {
                    $html .= '<br>';

                    continue;
                }

                if ($child instanceof Text) {
                    $html .= static::styledText((string) $child->getText(), $child->getFontStyle());
                }
            }
        }

        return $html;
    }

    protected static function styledText(string $text, mixed $fontStyle): string
    {
        $escaped = e($text);

        if ($escaped === '') {
            return '';
        }

        $bold = false;
        $italic = false;

        if ($fontStyle instanceof Font) {
            $bold = (bool) $fontStyle->isBold();
            $italic = (bool) $fontStyle->isItalic();
        }

        if ($bold) {
            $escaped = '<strong>'.$escaped.'</strong>';
        }

        if ($italic) {
            $escaped = '<em>'.$escaped.'</em>';
        }

        return $escaped;
    }

    /**
     * First row of a table, for the mapping queue excerpt.
     */
    protected static function tableExcerpt(Table $table): string
    {
        $cells = [];

        foreach ($table->getRows() as $rowIndex => $row) {
            if ($rowIndex > 0) {
                break;
            }

            foreach ($row->getCells() as $cell) {
                foreach ($cell->getElements() as $element) {
                    if ($element instanceof Text) {
                        $cells[] = trim((string) $element->getText());
                    } elseif ($element instanceof TextRun) {
                        $cells[] = trim(strip_tags(static::richText($element)));
                    }
                }
            }
        }

        $excerpt = implode(' | ', array_filter($cells));

        return $excerpt === '' ? 'no readable first row' : mb_substr($excerpt, 0, 80);
    }
}
