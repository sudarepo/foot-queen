<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * What a given site's legal pages actually say.
 *
 * Every site serves all four pages whether or not anyone has touched them:
 * the text comes from the shared Blade defaults under resources/views/legal/
 * defaults, with that site's name, domain and contact address filled in. An
 * admin who edits a page stores an override on the site record, and only that
 * page on that site stops following the default.
 *
 * That direction matters — defaults are the live text rather than a one-time
 * seed — because these four pages are the same document on every domain. A
 * correction to the DMCA procedure has to reach every site that hasn't
 * deliberately rewritten it, without an admin re-pasting it site by site.
 */
class LegalPageResolver
{
    public function title(Site $site, LegalPage $page): string
    {
        return $site->legalOverride($page, 'title') ?: $page->title();
    }

    /**
     * The page body, ready to echo.
     *
     * An override is sanitised on the way out rather than on the way in, so a
     * site whose text was written before a sanitiser rule existed is still
     * safe, and so what is stored stays exactly what the admin typed.
     *
     * The default text is not put through the sanitiser: it is a Blade view in
     * this repo — every value interpolated into it is already escaped — and
     * the sanitiser rewrites entities on the way through (an `@` in a mailto
     * address comes back as `&#64;`), which is noise in a page nobody
     * untrusted wrote.
     */
    public function body(Site $site, LegalPage $page): HtmlString
    {
        $override = $site->legalOverride($page, 'body');

        return new HtmlString($override === null
            ? $this->defaultBody($site, $page)
            : Str::sanitizeHtml($override));
    }

    /**
     * The standard text for this page, with this site's details in it — what
     * the page serves until someone overrides it, and what the admin form
     * shows as the starting point for an override.
     */
    public function defaultBody(Site $site, LegalPage $page): string
    {
        return trim(view($page->defaultBodyView(), [
            'site' => $site,
            'siteName' => $site->name,
            'domain' => $site->primaryDomain(),
            'contactEmail' => $site->legalContactEmail(),
        ])->render());
    }

    /**
     * Whether this site has rewritten any part of a page — what the admin
     * form reports back, so "standard text" versus "edited for this site" is
     * visible without diffing anything.
     */
    public function isOverridden(Site $site, LegalPage $page): bool
    {
        return $site->legalOverride($page, 'title') !== null
            || $site->legalOverride($page, 'body') !== null;
    }

    /**
     * The footer links, in the order they're shown.
     *
     * @return array<int, array{label: string, url: string}>
     */
    public function footerLinks(): array
    {
        return array_map(fn (LegalPage $page) => [
            'label' => $page->footerLabel(),
            'url' => route($page->routeName()),
        ], LegalPage::all());
    }
}
