<?php

namespace App\Service;

use DOMDocument;
use DOMElement;
use DOMNode;

final class RichTextSanitizer
{
    private const ALLOWED_ELEMENTS = [
        'a',
        'b',
        'blockquote',
        'br',
        'div',
        'em',
        'h1',
        'h2',
        'h3',
        'i',
        'li',
        'ol',
        'p',
        'pre',
        's',
        'span',
        'strong',
        'sub',
        'sup',
        'u',
        'ul',
    ];

    private const REMOVED_ELEMENTS = [
        'iframe',
        'math',
        'object',
        'script',
        'style',
        'svg',
        'template',
    ];

    private const ALLOWED_CLASS_PATTERN = '/^ql-(?:align-(?:center|right|justify)|direction-rtl|font-(?:serif|monospace)|indent-[1-8]|size-(?:small|large|huge)|syntax|ui)$/';
    private const ALLOWED_COLOR_PATTERN = '/^(?:#[\da-f]{3,8}|rgba?\(\s*\d{1,3}\s*,\s*\d{1,3}\s*,\s*\d{1,3}(?:\s*,\s*(?:0|1|0?\.\d+))?\s*\))$/i';

    public function sanitize(string $html): string
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorSetting = libxml_use_internal_errors(true);
        try {
            $document->loadHTML(
                '<?xml encoding="utf-8" ?><div data-rich-text-root="true">'.$html.'</div>',
                LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }

        $root = $document->getElementsByTagName('div')->item(0);
        if (!$root instanceof DOMElement) {
            return '';
        }

        $this->sanitizeChildren($root);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    public function plainText(string $html): string
    {
        $withBlockSeparators = preg_replace(
            ['~<br\b[^>]*>~i', '~</(?:blockquote|div|h[1-3]|li|p|pre)\s*>~i'],
            ' ',
            $html,
        ) ?? $html;
        $text = html_entity_decode(strip_tags($withBlockSeparators), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if (!$child instanceof DOMElement) {
                if (!$child instanceof \DOMText) {
                    $parent->removeChild($child);
                }
                continue;
            }

            $tagName = strtolower($child->tagName);
            if (in_array($tagName, self::REMOVED_ELEMENTS, true)) {
                $parent->removeChild($child);
                continue;
            }

            $this->sanitizeChildren($child);
            if (!in_array($tagName, self::ALLOWED_ELEMENTS, true)) {
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            foreach (iterator_to_array($child->attributes) as $attribute) {
                $attributeName = strtolower($attribute->name);
                if ($tagName === 'a' && $attributeName === 'href') {
                    $safeHref = $this->safeHref($attribute->value);
                    if ($safeHref !== null) {
                        $child->setAttribute('href', $safeHref);
                    } else {
                        $child->removeAttributeNode($attribute);
                    }
                    continue;
                }
                if ($attributeName === 'class') {
                    $safeClasses = $this->safeClasses($attribute->value);
                    if ($safeClasses !== '') {
                        $child->setAttribute('class', $safeClasses);
                    } else {
                        $child->removeAttributeNode($attribute);
                    }
                    continue;
                }
                if ($tagName === 'li' && $attributeName === 'data-list'
                    && in_array($attribute->value, ['bullet', 'ordered'], true)
                ) {
                    continue;
                }
                if ($tagName === 'span' && $attributeName === 'style') {
                    $safeStyle = $this->safeStyle($attribute->value);
                    if ($safeStyle !== '') {
                        $child->setAttribute('style', $safeStyle);
                    } else {
                        $child->removeAttributeNode($attribute);
                    }
                    continue;
                }

                $child->removeAttributeNode($attribute);
            }
        }
    }

    private function safeClasses(string $value): string
    {
        $classes = preg_split('/\s+/', trim($value)) ?: [];
        $safeClasses = array_filter(
            $classes,
            static fn (string $class): bool => preg_match(self::ALLOWED_CLASS_PATTERN, $class) === 1,
        );

        return implode(' ', $safeClasses);
    }

    private function safeStyle(string $value): string
    {
        $safeDeclarations = [];
        foreach (explode(';', $value) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            [$property, $color] = array_map('trim', $parts);
            $property = strtolower($property);
            if (!in_array($property, ['color', 'background-color'], true)
                || preg_match(self::ALLOWED_COLOR_PATTERN, $color) !== 1
            ) {
                continue;
            }
            $safeDeclarations[] = $property.': '.$color;
        }

        return implode('; ', $safeDeclarations);
    }

    private function safeHref(string $href): ?string
    {
        $href = trim($href);
        $normalizedHref = preg_replace('/[\x00-\x20\x7f]+/', '', $href) ?? $href;
        $scheme = parse_url($normalizedHref, PHP_URL_SCHEME);

        if ($href === ''
            || str_starts_with($normalizedHref, '//')
            || (is_string($scheme) && !in_array(strtolower($scheme), ['http', 'https', 'mailto'], true))
        ) {
            return null;
        }

        return $normalizedHref;
    }
}
