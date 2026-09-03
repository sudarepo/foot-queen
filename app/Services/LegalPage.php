<?php

namespace App\Services;

/**
 * The legal pages every domain has to carry.
 *
 * A fixed set rather than a registry file: unlike landing pages, these four
 * are not a marketing choice — an adult site needs all of them, on every
 * domain, or it has a compliance problem. Adding a case here gives every site
 * the page, its route, its footer link and its sitemap entry at once.
 *
 * The case *value* is the storage key (the key under `sites.legal_pages`, and
 * the route-name suffix); `slug()` is the URL. They differ only for 2257,
 * whose conventional URL is a bare number — which makes a poor array key,
 * since PHP silently casts numeric string keys to integers.
 */
enum LegalPage: string
{
    case Usc2257 = 'usc-2257';
    case Privacy = 'privacy-policy';
    case Terms = 'terms-and-conditions';
    case Dmca = 'dmca';

    /**
     * The path this page is served on, without a leading slash.
     */
    public function slug(): string
    {
        return match ($this) {
            self::Usc2257 => '2257',
            default => $this->value,
        };
    }

    public function routeName(): string
    {
        return 'legal.'.$this->value;
    }

    /**
     * Default <h1> and <title>. A site can override it per page.
     */
    public function title(): string
    {
        return match ($this) {
            self::Usc2257 => '18 U.S.C. § 2257 Exemption Statement',
            self::Privacy => 'Privacy Policy',
            self::Terms => 'Terms and Conditions',
            self::Dmca => 'DMCA Notice & Takedown Policy',
        };
    }

    /**
     * Shorter wording for the footer, where four full titles would wrap.
     */
    public function footerLabel(): string
    {
        return match ($this) {
            self::Usc2257 => '2257',
            self::Privacy => 'Privacy Policy',
            self::Terms => 'Terms and Conditions',
            self::Dmca => 'DMCA',
        };
    }

    /**
     * Meta description. Deliberately not derived from the body: the body is
     * long-form legal prose whose opening sentence makes a poor snippet.
     */
    public function metaDescription(): string
    {
        return match ($this) {
            self::Usc2257 => 'Records-keeping exemption statement under 18 U.S.C. § 2257 for the content displayed on this website.',
            self::Privacy => 'What this website collects, why, how long it is kept, and the choices you have over it.',
            self::Terms => 'The terms you agree to by using this website, including the 18+ age requirement.',
            self::Dmca => 'How to report allegedly infringing material, and how to file a counter-notice.',
        };
    }

    /**
     * The Blade view holding this page's default text.
     */
    public function defaultBodyView(): string
    {
        return "legal.defaults.{$this->value}";
    }

    /**
     * @return array<int, self>
     */
    public static function all(): array
    {
        return self::cases();
    }
}
