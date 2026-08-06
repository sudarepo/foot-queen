<?php

namespace Tests\Unit;

use App\Services\BioSanitizer;
use PHPUnit\Framework\TestCase;

class BioSanitizerTest extends TestCase
{
    private BioSanitizer $sanitizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sanitizer = new BioSanitizer;
    }

    public function test_it_returns_null_for_an_empty_bio(): void
    {
        $this->assertNull($this->sanitizer->sanitize(null));
        $this->assertNull($this->sanitizer->sanitize(''));
        $this->assertNull($this->sanitizer->sanitize('   '));
        $this->assertNull($this->sanitizer->sanitize('<p><br/></p>'));
    }

    public function test_it_keeps_the_readable_text(): void
    {
        $bio = '<p>Hi, I&#39;m Masha ❤️ Tip for requests.</p>';

        $this->assertSame(
            "Hi, I'm Masha ❤️ Tip for requests.",
            $this->sanitizer->sanitize($bio),
        );
    }

    /**
     * The whole reason this class exists. Bios routinely carry a *different*
     * affiliate's campaign id, pointing at signup pages or another
     * performer's room — traffic we'd be handing to a competitor's revenue
     * share if the markup survived.
     */
    public function test_it_strips_links_but_keeps_their_text(): void
    {
        $bio = '<p>click <a href="https://chaturbate.com/in/?campaign=51VtT&amp;room=someoneelse" '
            .'rel="nofollow" target="_blank">HERE</a> to sign up</p>';

        $result = $this->sanitizer->sanitize($bio);

        $this->assertSame('click HERE to sign up', $result);
        $this->assertStringNotContainsString('51VtT', $result);
        $this->assertStringNotContainsString('href', $result);
    }

    /**
     * Removing an inline element joins the text either side of it, with no
     * space inserted — which is exactly what a browser does with the same
     * markup, so the result reads the way the bio looked.
     */
    public function test_removing_inline_elements_does_not_insert_whitespace(): void
    {
        $this->assertSame('foobar', $this->sanitizer->sanitize('foo<a href="https://x.test">bar</a>'));
        $this->assertSame('foobar', $this->sanitizer->sanitize('foo<img src="https://x.test/t.png"/>bar'));
    }

    public function test_it_strips_bare_urls_left_in_the_text(): void
    {
        $bio = '<p>My links: https://linktr.ee/someone and www.onlyfans.com/someone — enjoy</p>';

        $result = $this->sanitizer->sanitize($bio);

        $this->assertStringNotContainsString('linktr.ee', $result);
        $this->assertStringNotContainsString('onlyfans', $result);
        $this->assertStringContainsString('My links:', $result);
    }

    public function test_it_drops_images_and_scripts_entirely(): void
    {
        $bio = '<p>Hello<img src="https://camo.mmcdn.com/tracker.png"/>'
            .'<script>alert(1)</script><style>body{display:none}</style> there</p>';

        $result = $this->sanitizer->sanitize($bio);

        $this->assertSame('Hello there', $result);
        $this->assertStringNotContainsString('alert', $result);
        $this->assertStringNotContainsString('mmcdn', $result);
    }

    /**
     * Hidden text is hidden for a reason on Chaturbate too — it's where the
     * tracking tokens live. Stripping the tag alone would promote that token
     * to visible body copy on our page.
     */
    public function test_it_drops_hidden_and_absolutely_positioned_elements_with_their_text(): void
    {
        $bio = '<p>Real bio text'
            .'<p style="display: none" rel="nofollow">cbx_qLotsj</p>'
            .'<p style="position:absolute;z-index:100;top:0px">TROPHY BADGE</p>'
            .'</p>';

        $result = $this->sanitizer->sanitize($bio);

        $this->assertStringContainsString('Real bio text', $result);
        $this->assertStringNotContainsString('cbx_qLotsj', $result);
        $this->assertStringNotContainsString('TROPHY BADGE', $result);
    }

    public function test_it_breaks_paragraphs_on_br_and_block_tags(): void
    {
        $bio = '<p>Line one<br/>Line two</p><p>Second paragraph</p>';

        $this->assertSame(
            "Line one\nLine two\n\nSecond paragraph",
            $this->sanitizer->sanitize($bio),
        );
    }

    /**
     * Chaturbate's bio editor builds layouts out of inline tags forced into
     * blocks with CSS. Going by tag name alone, a real bio using 131
     * `<strong style="display:block">` elements collapses into one wall of
     * text with words run together across every boundary.
     */
    public function test_it_treats_css_blocks_as_paragraph_breaks(): void
    {
        $bio = '<strong style="display:block">COPYRIGHT NOTICE</strong>'
            .'<strong style="display: block;">All content is mine.</strong>';

        $this->assertSame(
            "COPYRIGHT NOTICE\n\nAll content is mine.",
            $this->sanitizer->sanitize($bio),
        );
    }

    public function test_it_collapses_runs_of_blank_lines_and_stray_dividers(): void
    {
        // The dividers wrapped a link, which is now gone — leaving two rules
        // around nothing.
        $bio = '<p>Above</p><p>---</p><p></p><p>---</p><p>Below</p>';

        $this->assertSame("Above\n\nBelow", $this->sanitizer->sanitize($bio));
    }

    public function test_it_keeps_emoji_only_lines(): void
    {
        $bio = '<p>Welcome</p><p>💋💋💋</p>';

        $this->assertSame("Welcome\n\n💋💋💋", $this->sanitizer->sanitize($bio));
    }

    public function test_it_collapses_non_breaking_spaces(): void
    {
        $bio = "<p>Tip\u{00a0}\u{00a0}\u{00a0}menu\u{00a0}below</p>";

        $this->assertSame('Tip menu below', $this->sanitizer->sanitize($bio));
    }

    public function test_it_truncates_very_long_bios_on_a_word_boundary(): void
    {
        $bio = '<p>'.str_repeat('word ', 500).'</p>';

        $result = $this->sanitizer->sanitize($bio);

        $this->assertLessThanOrEqual(1201, mb_strlen($result));
        $this->assertStringEndsWith('…', $result);
        $this->assertStringNotContainsString('wor…', $result);
    }

    public function test_it_handles_multibyte_text_without_mangling_it(): void
    {
        $bio = '<p>Привет! Я говорю по-русски 🇺🇦</p>';

        $this->assertSame('Привет! Я говорю по-русски 🇺🇦', $this->sanitizer->sanitize($bio));
    }

    public function test_it_survives_malformed_markup(): void
    {
        $bio = '<p>Unclosed <strong>bold <em>and italic</p><div>next';

        $result = $this->sanitizer->sanitize($bio);

        $this->assertStringContainsString('Unclosed', $result);
        $this->assertStringContainsString('next', $result);
    }
}
