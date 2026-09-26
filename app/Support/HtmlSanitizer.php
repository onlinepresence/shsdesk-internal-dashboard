<?php

namespace App\Support;

/**
 * Dependency-free HTML allowlist for rich-text bodies. Trix emits a
 * small tag set; everything outside it — scripts, event handlers,
 * javascript: links, rogue attributes — is stripped on save.
 */
class HtmlSanitizer
{
    /**
     * @var array<string, list<string>>
     */
    protected const ALLOWED = [
        'p' => [],
        'br' => [],
        'div' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        's' => [],
        'del' => [],
        'a' => ['href'],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'blockquote' => [],
        'pre' => [],
        'h1' => [],
        'h2' => [],
        'h3' => [],
        'figure' => [],
        'figcaption' => [],
    ];

    public static function clean(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        $document = new \DOMDocument('1.0', 'UTF-8');

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $wrapper = $document->getElementsByTagName('div')->item(0);

        if (! $wrapper instanceof \DOMElement) {
            return '';
        }

        static::scrub($wrapper);

        $clean = '';

        foreach ($wrapper->childNodes as $child) {
            $clean .= $document->saveHTML($child);
        }

        return $clean;
    }

    protected static function scrub(\DOMNode $node): void
    {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->tagName);

                if (! array_key_exists($tag, static::ALLOWED)) {
                    static::unwrap($child);

                    continue;
                }

                foreach (iterator_to_array($child->attributes) as $attribute) {
                    $name = strtolower($attribute->nodeName);

                    if (! in_array($name, static::ALLOWED[$tag], true)
                        || ($name === 'href' && ! static::safeHref($attribute->nodeValue))
                    ) {
                        $child->removeAttribute($attribute->nodeName);
                    }
                }

                static::scrub($child);
            } elseif ($child instanceof \DOMComment || $child instanceof \DOMProcessingInstruction) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * Drop a disallowed element but keep its children in place.
     */
    protected static function unwrap(\DOMElement $element): void
    {
        $parent = $element->parentNode;

        if ($parent === null) {
            return;
        }

        static::scrub($element);

        while ($element->firstChild !== null) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    protected static function safeHref(string $href): bool
    {
        $href = trim(strtolower($href));

        return $href === '' || str_starts_with($href, 'http://') || str_starts_with($href, 'https://') || str_starts_with($href, 'mailto:') || str_starts_with($href, '#');
    }
}
