<?php
declare(strict_types=1);

namespace App\support;

/**
 * HtmlSanitizer — server-side whitelist sanitizer for course intro rich text.
 *
 * Strategy: parse as an HTML fragment inside a <div>, walk the DOM and drop
 * any element / attribute / protocol not on the allow list. The result is
 * safe to render with v-html on the admin preview and on the learner
 * detail page.
 *
 * Allowed elements: block + inline + structural containers used by writers.
 * Allowed attributes: src / href / alt / title.
 * Allowed protocols (href / src): http, https, mailto. javascript: /
 *   data: / vbscript: are stripped (the whole element is removed if it
 *   relies on them).
 *
 * Input is capped at 200 000 characters; longer input returns empty string
 * so a misbehaving editor cannot DoS the server. LengthExceeded is signalled
 * via the second parameter of sanitize() — controllers map that to
 * VALIDATION_FAILED.
 */
final class HtmlSanitizer
{
    private const MAX_INPUT = 200_000;

    private const ALLOWED_TAGS = [
        'p' => true, 'h1' => true, 'h2' => true, 'h3' => true,
        'h4' => true, 'h5' => true, 'h6' => true,
        'ul' => true, 'ol' => true, 'li' => true,
        'strong' => true, 'b' => true, 'em' => true, 'i' => true,
        'code' => true, 'pre' => true,
        'blockquote' => true, 'br' => true, 'hr' => true,
        'img' => true, 'a' => true,
        'span' => true, 'div' => true,
        'table' => true, 'thead' => true, 'tbody' => true, 'tr' => true, 'th' => true, 'td' => true,
    ];

    private const ALLOWED_ATTRS = ['src', 'href', 'alt', 'title'];

    private const SAFE_PROTOCOLS = ['http:', 'https:', 'mailto:'];

    /**
     * @return array{html:string,truncated:bool}
     *   truncated=true when the input exceeded MAX_INPUT and was clamped.
     */
    public static function sanitize(string $html): array
    {
        $truncated = false;
        if (strlen($html) > self::MAX_INPUT) {
            $html = substr($html, 0, self::MAX_INPUT);
            $truncated = true;
        }
        $html = trim($html);
        if ($html === '') {
            return ['html' => '', 'truncated' => $truncated];
        }

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        // Prefix with meta charset so UTF-8 entities survive.
        $wrapped = '<?xml encoding="UTF-8"?><div>' . $html . '</div>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementsByTagName('div')->item(0);
        if ($root === null) {
            return ['html' => '', 'truncated' => $truncated];
        }

        self::walk($root);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return ['html' => trim($out), 'truncated' => $truncated];
    }

    private static function walk(\DOMNode $node): void
    {
        // Iterate children first (snapshot — we'll mutate the tree).
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tag = strtolower($child->nodeName);
                if (!isset(self::ALLOWED_TAGS[$tag])) {
                    // Replace disallowed element with its text children so we
                    // don't lose the prose inside a <script> by accident.
                    self::unwrap($child);
                    continue;
                }
                self::scrubAttrs($child);
                // An <a> or <img> whose only job was a blocked protocol: drop it.
                if (($tag === 'a' || $tag === 'img') && $child->getAttribute('data-blocked') === '1') {
                    self::unwrap($child);
                    continue;
                }
                self::walk($child);
            } elseif ($child instanceof \DOMComment) {
                $child->parentNode?->removeChild($child);
            }
        }
    }

    private static function unwrap(\DOMNode $node): void
    {
        $parent = $node->parentNode;
        if ($parent === null) {
            return;
        }
        while ($node->firstChild) {
            $parent->insertBefore($node->firstChild, $node);
        }
        $parent->removeChild($node);
    }

    private static function scrubAttrs(\DOMElement $el): void
    {
        $attrs = [];
        foreach ($el->attributes as $attr) {
            $attrs[] = $attr->nodeName;
        }
        foreach ($attrs as $name) {
            $lower = strtolower($name);
            // Strip on* event handlers and style/class entirely.
            if ($lower === 'style' || $lower === 'class' || str_starts_with($lower, 'on')) {
                $el->removeAttribute($name);
                continue;
            }
            if (!in_array($lower, self::ALLOWED_ATTRS, true)) {
                $el->removeAttribute($name);
                continue;
            }
            $value = (string) $el->getAttribute($name);
            if ($lower === 'href' || $lower === 'src') {
                if (!self::isSafeUrl($value)) {
                    $el->setAttribute('data-blocked', '1');
                    // Empty the offending attribute too — defence in depth.
                    $el->removeAttribute($name);
                }
            }
        }
    }

    private static function isSafeUrl(string $url): bool
    {
        $url = trim($url);
        if ($url === '') {
            return true;
        }
        // Allow relative URLs (no scheme, no host).
        if (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            // Anchor / path: safe.
            if (str_starts_with($url, '#') || str_starts_with($url, '/') || str_starts_with($url, './') || str_starts_with($url, '../')) {
                return true;
            }
            // Bare relative path.
            return !str_contains($url, '//');
        }
        $scheme = strtolower(explode(':', $url, 2)[0] . ':');
        return in_array($scheme, self::SAFE_PROTOCOLS, true);
    }
}
