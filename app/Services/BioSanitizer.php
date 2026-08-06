<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Turns a Chaturbate "about me" blob into plain text we can safely print.
 *
 * These bios are not prose with a little markup — they're layout hacks.
 * A representative one contains absolutely-positioned trophy images, a
 * `display:none` paragraph holding a tracking token, and — the reason this
 * class exists at all — anchors carrying *someone else's* affiliate id
 * (`campaign=51VtT`), pointing at signup pages and OnlyFans/Linktree
 * profiles. Rendering that markup would break the page layout, leak our
 * traffic to a competitor's revenue share, and hand a third party an
 * injection surface on our domain.
 *
 * So nothing structural survives: hidden and positioned elements are
 * dropped whole (their text included — that's the point of hiding it),
 * media and script nodes are dropped, links keep their text but lose their
 * destination, and bare URLs left behind in the text are stripped too. What
 * comes out is paragraph-separated plain text, escaped at render time.
 */
class BioSanitizer
{
    /**
     * Longest bio we'll keep. Past this it's promo copy padding, not
     * something a visitor reads; the page has a live cam to get to.
     */
    private const MAX_LENGTH = 1200;

    /**
     * Dropped entirely, contents and all.
     */
    private const DROPPED_TAGS = [
        'script', 'style', 'noscript', 'iframe', 'img', 'svg', 'video',
        'audio', 'object', 'embed', 'form', 'input', 'button', 'canvas',
    ];

    /**
     * Style declarations that mean "this isn't part of the readable bio":
     * either hidden outright, or torn out of flow to float a badge over the
     * room. Matched against a whitespace-stripped `style` attribute.
     */
    private const HIDDEN_STYLE_PATTERNS = [
        'display:none',
        'visibility:hidden',
        'opacity:0',
        'position:absolute',
        'position:fixed',
        'font-size:0',
    ];

    /**
     * Block-level tags that should separate paragraphs in the output.
     */
    private const BLOCK_TAGS = [
        'p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'blockquote', 'section', 'article',
    ];

    /**
     * …but the tag name alone isn't enough. Chaturbate's bio editor produces
     * layouts built almost entirely from inline tags forced into blocks with
     * CSS — one real bio lays out its whole page with 131 `<strong
     * style="display:block">` elements. Going by tag name only, all of that
     * runs together into a single unreadable paragraph.
     */
    private const BLOCK_STYLE_PATTERNS = [
        'display:block',
        'display:flex',
        'display:grid',
        'display:table',
        'display:list-item',
    ];

    public function sanitize(?string $html): ?string
    {
        if (blank($html)) {
            return null;
        }

        $document = $this->parse($html);
        if ($document === null) {
            return null;
        }

        $text = $this->extractText($document);
        $text = $this->stripUrls($text);
        $text = $this->normalizeWhitespace($text);

        if ($text === '') {
            return null;
        }

        return $this->truncate($text);
    }

    private function parse(string $html): ?DOMDocument
    {
        $document = new DOMDocument;

        // Bios are user-authored fragments — unclosed tags and stray entities
        // are normal, and libxml complains loudly about all of it. We only
        // want the tree, so warnings are suppressed rather than logged.
        $previous = libxml_use_internal_errors(true);

        // The XML declaration forces UTF-8 without the <meta> guesswork;
        // HTML_NO_DEFAULT_ADD_IMPLIED keeps DOMDocument from wrapping the
        // fragment in <html><body>, which would add nothing but noise.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$html,
            LIBXML_NOERROR | LIBXML_NOWARNING
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded ? $document : null;
    }

    /**
     * Walk the tree, keeping text from nodes that survive the drop rules and
     * inserting break markers where block boundaries were.
     */
    private function extractText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->wholeText;
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->nodeName);

            if (in_array($tag, self::DROPPED_TAGS, strict: true) || $this->isHidden($node)) {
                return '';
            }

            if ($tag === 'br') {
                return "\n";
            }
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->extractText($child);
        }

        if ($node instanceof DOMElement && $this->isBlock($node)) {
            $text = "\n\n".$text."\n\n";
        }

        return $text;
    }

    private function isHidden(DOMElement $element): bool
    {
        return $this->styleMatches($element, self::HIDDEN_STYLE_PATTERNS);
    }

    private function isBlock(DOMElement $element): bool
    {
        return in_array(strtolower($element->nodeName), self::BLOCK_TAGS, strict: true)
            || $this->styleMatches($element, self::BLOCK_STYLE_PATTERNS);
    }

    /**
     * @param  array<int, string>  $patterns  whitespace-free declarations to look for
     */
    private function styleMatches(DOMElement $element, array $patterns): bool
    {
        $style = strtolower(preg_replace('/\s+/', '', $element->getAttribute('style')) ?? '');

        if ($style === '') {
            return false;
        }

        foreach ($patterns as $pattern) {
            if (str_contains($style, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Anchors keep their text but lose their href during extraction — so what
     * this removes is the other kind: URLs typed straight into the bio, which
     * are overwhelmingly links off-platform (Linktree, OnlyFans, Throne).
     */
    private function stripUrls(string $text): string
    {
        return preg_replace(
            '~\b(?:https?://|www\.)\S+~i',
            '',
            $text
        ) ?? $text;
    }

    private function normalizeWhitespace(string $text): string
    {
        // Non-breaking spaces are used as spacers throughout these bios and
        // won't collapse on their own.
        $text = str_replace(["\xc2\xa0", "\r\n", "\r"], [' ', "\n", "\n"], $text);

        // Runs of spaces/tabs collapse, but newlines are preserved — they're
        // the paragraph structure the block markers just established.
        $text = preg_replace('/[^\S\n]+/', ' ', $text) ?? $text;

        $lines = array_map('trim', explode("\n", $text));

        // Bios separate sections with rules like "---" or "***". Once the link
        // or image that sat between two of them has been stripped, what's left
        // is a pair of dividers around nothing. Emoji are non-ASCII and so
        // don't match here — a line of hearts is content, "---" isn't.
        $lines = array_map(
            fn (string $line) => preg_match('/^[[:punct:]]+$/', $line) === 1 ? '' : $line,
            $lines,
        );

        // Any run of blank lines becomes exactly one, so a bio wrapped in six
        // nested <p>s doesn't render as a column of gaps.
        $text = preg_replace('/\n{2,}/', "\n\n", implode("\n", $lines)) ?? $text;

        return trim($text);
    }

    private function truncate(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_LENGTH) {
            return $text;
        }

        $cut = mb_substr($text, 0, self::MAX_LENGTH);

        // Prefer to end on a word boundary, but only if one is reasonably
        // close — otherwise a bio with no spaces would lose most of itself.
        $lastSpace = mb_strrpos($cut, ' ');
        if ($lastSpace !== false && $lastSpace > self::MAX_LENGTH * 0.8) {
            $cut = mb_substr($cut, 0, $lastSpace);
        }

        return rtrim($cut).'…';
    }
}
