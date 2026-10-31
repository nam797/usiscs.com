<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A plain-text version of an HTML email, for mail apps that don't show
 * HTML and for spam filters, which mark down HTML-only emails. Links keep
 * their address ("View your order: https://..."), block ends become line
 * breaks, and runs of blank lines are squeezed to one.
 */
final class HtmlToText
{
    public static function convert(string $html): string
    {
        $text = preg_replace('{<(head|style|script)\b.*?</\1>}is', '', $html) ?? $html;
        // Line breaks in the source mean nothing in HTML; only tags break lines.
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        // Hidden preview text (the inbox snippet) isn't part of the email.
        $text = preg_replace('{<[a-z]+[^>]*display:\s*none[^>]*>.*?</[a-z]+>}is', '', $text) ?? $text;
        $text = preg_replace_callback(
            '{<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>}is',
            static function (array $match): string {
                $label = trim(strip_tags($match[2]));
                $url = html_entity_decode($match[1], \ENT_QUOTES | \ENT_HTML5);
                // An image link (the logo) has no words to keep.
                if ('' === $label || $label === $url || str_starts_with($url, 'mailto:')) {
                    return $label;
                }

                return sprintf('%s: %s', $label, $url);
            },
            $text,
        ) ?? $text;
        $text = preg_replace('{<br\s*/?>}i', "\n", $text) ?? $text;
        // A paragraph or heading starts and ends a line (a blank line between two); a table row ends one.
        $text = preg_replace('{</?(p|div|h[1-6]|table|ul|ol)\b[^>]*>}i', "\n", $text) ?? $text;
        $text = preg_replace('{</(tr|li)>}i', "\n", $text) ?? $text;
        $text = preg_replace('{</t[dh]>}i', '  ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

        $lines = array_map(static fn (string $line): string => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $line) ?? $line), explode("\n", $text));
        $text = implode("\n", $lines);

        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text)."\n";
    }
}
