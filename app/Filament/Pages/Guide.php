<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Html;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * The manual for this panel: what each screen is for, and what every field on
 * a site record actually changes on the live domain.
 *
 * Schema-driven with screenshots from public/img/docs — for the same reason
 * ConversionDashboard is: nothing in this project builds Tailwind for custom
 * Filament views, so hand-written markup here would render unstyled. Sections,
 * Text and Image are all pre-styled by Filament's own CSS.
 *
 * Re-shooting the screenshots after a UI change is a manual job; they live
 * under public/img/docs/ and are listed in the shot map below.
 */
class Guide extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?string $navigationLabel = 'Guide';

    protected static ?string $title = 'How to use this admin';

    /** Last — it's reference material, not somewhere you work. */
    protected static ?int $navigationSort = 20;

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->orientation(),
            $this->sitesList(),
            $this->configuringASite(),
            $this->identity(),
            $this->branding(),
            $this->homepageLayout(),
            $this->contentTab(),
            $this->copyAndSeo(),
            $this->legal(),
            $this->tracking(),
            $this->launchingASite(),
            $this->abTest(),
            $this->cams(),
            $this->users(),
        ]);
    }

    /* ----------  Building blocks  ---------- */

    /**
     * A screenshot from public/img/docs, linked to itself so it can be opened
     * full size — shrunk into this column the smaller labels aren't readable.
     *
     * Raw HTML rather than the Image component because that one can't be
     * wrapped in a link; the inline styles are deliberate, since no Tailwind
     * build scans this file.
     */
    private function screenshot(string $file, string $alt): Html
    {
        $url = asset("img/docs/{$file}.png");

        return Html::make(new HtmlString(sprintf(
            '<a href="%s" target="_blank" rel="noopener" title="Open full size">
                <img src="%s" alt="%s" loading="lazy" style="display: block; width: 100%%; height: auto; border: 1px solid rgba(0, 0, 0, 0.1); border-radius: 0.5rem;">
            </a>',
            e($url),
            e($url),
            e($alt),
        )));
    }

    /**
     * A field-by-field list. Each entry is "**Field** — what it does", so the
     * name you're looking for is scannable down the left.
     *
     * @param  array<string, string>  $fields
     */
    private function fields(array $fields): Html
    {
        $items = collect($fields)
            ->map(fn (string $description, string $label) => sprintf(
                '<li style="margin-bottom: 0.5rem;"><strong>%s</strong> — %s</li>',
                e($label),
                e($description),
            ))
            ->implode('');

        return Html::make(new HtmlString(
            '<ul style="list-style: disc outside; padding-inline-start: 1.25rem;">'.$items.'</ul>'
        ));
    }

    /* ----------  Sections  ---------- */

    private function orientation(): Section
    {
        return Section::make('What this panel is')
            ->icon(Heroicon::OutlinedMap)
            ->schema([
                Text::make('One deploy of this codebase serves several websites. They share one database and one pool of performers; what makes each domain look and behave like its own site is a single record under Sites. Adding a domain is a record here plus a DNS entry — never a code change.'),
                $this->fields([
                    'Dashboard' => 'The landing screen. Your account, and nothing you have to act on.',
                    'A/B Test' => 'How the two homepage layouts are converting, per site and per device.',
                    'Cams' => 'The shared pool of performers, pulled in from Chaturbate. Read-only.',
                    'Sites' => 'One record per domain. This is where nearly all the work happens.',
                    'Users' => 'Who can log in here, and which sites they may touch. Administrators only.',
                ]),
                Text::make('What you can see depends on your account. An administrator manages the whole network; anyone else sees only the sites assigned to them, and the fields that decide which domain serves which site are read-only for them.')
                    ->color('gray'),
                Text::make('The screenshots below were taken against example data — the sites and numbers in them are there to show the shape of the screen, not to describe this network.')
                    ->color('gray'),
            ]);
    }

    private function sitesList(): Section
    {
        return Section::make('The Sites list')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->collapsible()
            ->schema([
                Text::make('Every domain this deploy answers for, one per row. It is built to be read at a glance: the columns are the handful of things that most often go wrong.'),
                $this->screenshot('sites-list', 'The Sites list, showing two sites with their domains, keywords, live cam counts and homepage layouts'),
                $this->fields([
                    'Name' => 'The site name, with its slug underneath — the internal key the site is filed under.',
                    'Domains' => 'The hostnames that serve this site. A site with none only ever answers as the default.',
                    'Keywords' => 'The provider tags this site is built from. "Everything" means it is limited by gender alone, or not at all.',
                    'Live now' => 'How many performers the site\'s keywords match right now. Green is working; a red zero means the keywords match nothing — usually a typo, or a niche the provider spells differently.',
                    'Homepage' => 'What "/" currently serves, per kind of screen: the 50/50 A/B test, or one layout outright.',
                    'Default' => 'The site that answers any hostname no other site claims. Exactly one row has this.',
                    'Active' => 'Off means the domain stops resolving here and stops pulling its keywords into the sync.',
                ]),
                Text::make('Edit opens the record. New site and deleting a site are administrator-only, since both change which domains this deploy answers for.')
                    ->color('gray'),
            ]);
    }

    private function configuringASite(): Section
    {
        return Section::make('Configuring a site')
            ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
            ->schema([
                Text::make('The site record is split into six tabs, in the order you would come to them: what the site is, what it looks like, what "/" serves, which performers it shows, what it says, and how its revenue is attributed. Each is covered below.'),
                Text::make('Nothing is saved until you press Save changes, and Save saves every tab — not just the one you are looking at. Most fields are optional and fall back to something sensible, so a site with a name, a domain and its keywords is already a working site.'),
                Text::make('Changes are live immediately. There is no publish step and no cache to clear.')
                    ->color('gray'),
            ]);
    }

    private function identity(): Section
    {
        return Section::make('Identity — what the site is')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->collapsible()
            ->schema([
                $this->screenshot('site-identity', 'The Identity tab of a site record, with site name, slug, domains and the Active and Default toggles'),
                $this->fields([
                    'Site name' => 'Shown in the header, the browser tab title, the footer and in link previews. Typing it on a new record fills in the slug for you.',
                    'Slug' => 'The internal key: the folder its uploads are filed under, and the default name of its SEO landing-page registry. Changing it on a live site orphans both, so treat it as permanent.',
                    'Domains' => 'The hostnames that serve this site — bare hostnames, no https:// and no port. Add every variant you want answered, including www, and add the local .test host so the site resolves while you work on it.',
                    'Active' => 'Turning this off stops the domain resolving here and drops its keywords from the sync, so a parked domain costs nothing and fetches nothing.',
                    'Default site' => 'The catch-all: it serves any hostname no other site claims — a bare IP, a preview URL, a domain pointed here before it has a record. Turning it on for one site turns it off everywhere else, so there is always exactly one.',
                ]),
                Text::make('Domains, Active, Default and the slug are network-level wiring: they decide which domain serves which site, so they are administrator-only. If you manage a single site they are shown to you but locked.')
                    ->color('gray'),
            ]);
    }

    private function branding(): Section
    {
        return Section::make('Branding — what it looks like')
            ->icon(Heroicon::OutlinedPaintBrush)
            ->collapsible()
            ->schema([
                $this->screenshot('site-branding', 'The Branding tab, with logo and social image uploads, accent and theme colour pickers, and the header navigation repeater'),
                $this->fields([
                    'Logo' => 'Shown beside the site name in the header, rendered at 32px tall with the width scaling to match — a transparent PNG around 200×64 is the safe choice. With none, the site falls back to the shared logo file, and failing that shows its name as text.',
                    'Favicon' => 'The browser tab icon — a 32×32 (or larger, square) PNG is the safe choice; .ico and .svg work too, but only a PNG doubles as the iOS home-screen icon. With none, the site falls back to the shared favicon set.',
                    'Social sharing image' => 'Used when a link to the site is shared — 1200×630 is the size that survives every platform. Falls back to the shared default image.',
                    'Accent colour' => 'Drives buttons, links and live badges across the site. Leave it empty to keep the stylesheet default.',
                    'Theme colour' => 'Tints the browser chrome on mobile. It does not affect the page itself.',
                    'Header navigation' => 'The links in the header, in the order listed — drag to reorder. A link starting with / navigates within this site; a full URL opens in a new tab.',
                ]),
                Text::make('Uploads are stored per site, so two sites can carry the same filename without colliding.')
                    ->color('gray'),
            ]);
    }

    private function homepageLayout(): Section
    {
        return Section::make('Homepage layout — what "/" serves')
            ->icon(Heroicon::OutlinedSquares2x2)
            ->collapsible()
            ->schema([
                Text::make('The homepage exists in two shapes: the grid, and the feed at "/feed". This tab decides which one a visitor gets, and it asks separately for desktop and mobile, because the feed is a phone-shaped layout and the honest answer often differs.'),
                $this->screenshot('site-layout', 'The Homepage layout tab, with separate layout selects for desktop and mobile visitors'),
                $this->fields([
                    'A/B test (50/50)' => 'Split visitors evenly between the two and remember each visitor\'s side by cookie. This is what fills the A/B Test dashboard, and what a new site starts on.',
                    'Grid only' => 'Everyone on that kind of screen gets the grid, cookie or no cookie. Setting both devices to this also drops "/feed" from the site\'s sitemap.',
                    'Feed only' => 'Everyone on that kind of screen gets the feed.',
                ]),
                Text::make('Pick a single layout once the A/B Test dashboard has told you which one wins for this site on that kind of screen — an experiment is meant to end. Settling on mobile while still testing on desktop is the normal case, not an edge one.'),
                Text::make('Search engines are unaffected either way: crawlers always get the grid at "/" and never a redirect. "/feed" also stays reachable for anyone who has it bookmarked, whatever is set here.')
                    ->color('gray'),
            ]);
    }

    private function contentTab(): Section
    {
        return Section::make('Content — which performers it shows')
            ->icon(Heroicon::OutlinedFunnel)
            ->collapsible()
            ->schema([
                Text::make('This is the tab that defines the site. Every domain looks at the same shared pool of performers; what is set here is the lens it looks through.'),
                $this->screenshot('site-content', 'The Content tab, with the gender select, the keywords tags input, and the category fields'),
                $this->fields([
                    'Gender' => 'Restricts the site to one gender, and narrows what the sync fetches for it. Leave it on "Any gender" for a site that is defined by its keywords alone.',
                    'Keywords / tags' => 'The provider tags the sync searches for, and the hard boundary of the site: a performer must carry at least one of them to appear, and no filter a visitor picks can escape them. Empty means the site shows everything matching the gender above.',
                    'Default category' => 'Pre-selected in the category dropdown on the homepage and feed. Unlike the keywords, a visitor can clear it with "All categories" — which still cannot take them outside the keywords.',
                    'Category dropdown options' => 'Replaces the shared default list of categories for this site only. Leave it empty to use the shared list.',
                ]),
                Text::make('Keywords have to match the provider\'s spelling to match anything at all. After changing them, check the Live now column on the Sites list: a red zero means nothing matched.')
                    ->color('warning'),
            ]);
    }

    private function copyAndSeo(): Section
    {
        return Section::make('Copy & SEO — what it says')
            ->icon(Heroicon::OutlinedDocumentText)
            ->collapsible()
            ->schema([
                Text::make('Every field here is optional and falls back to something reasonable, so an unfinished site still renders. Filling them in is how a domain stops reading like a copy of its neighbour.'),
                $this->screenshot('site-copy', 'The Copy & SEO tab, with the homepage heading, titles, meta descriptions, profile phrases and the SEO pages registry select'),
                $this->fields([
                    'Homepage heading' => 'The h1 on "/". Falls back to the site name.',
                    'Homepage title' => 'The browser tab title on "/" — the site name is appended for you. Falls back to the site name alone.',
                    'Tagline' => 'The sub-heading under the homepage title, and the fallback meta description for any page that has none of its own.',
                    'Homepage meta description' => 'The description search engines show for "/". Falls back to the tagline.',
                    'Feed meta description' => 'The same, for "/feed". Falls back to the homepage description.',
                    'Meta keywords' => 'Optional, and empty is the sensible default — left blank, no keywords tag is emitted at all, which is what search engines now expect.',
                    'Profile title — live' => 'Follows the performer name on a profile that is streaming: "anna — Live Feet Cam".',
                    'Profile title — offline' => 'The same, for a performer who is offline.',
                    'Profile description phrase' => 'Describes a performer who has written no bio: "anna — live feet and foot fetish cam (24yo, blonde)".',
                    'SEO pages registry' => 'Which set of landing pages (/girls, /blonde, …) this site publishes. Sites in the same niche can share one; adding a new set means adding a file to the codebase, so this list is fixed until a developer extends it.',
                ]),
            ]);
    }

    private function legal(): Section
    {
        return Section::make('Legal — the four required pages')
            ->icon(Heroicon::OutlinedScale)
            ->collapsible()
            ->schema([
                Text::make('Every site publishes four legal pages — 2257, Privacy Policy, Terms and Conditions, and DMCA — linked in the footer of every page it serves. They exist from the moment a site is created: each one is written out for that site, with its name, its domain and its contact address already in it. There is nothing on this tab you have to fill in for that to be true.'),
                $this->fields([
                    'Legal contact address' => 'Where the four pages tell people to send DMCA notices, privacy requests and reports of content that should not be online. Left empty they derive abuse@ at the site\'s first domain — which is only useful if that mailbox exists and someone reads it.',
                    'The four page sections' => 'Each is collapsed, and each says whether this site is on the standard text or has its own. Open one only to make this site say something different.',
                    'Use the standard text' => 'Puts the current standard wording into the editor so you can edit it instead of starting from an empty box.',
                    'Page heading' => 'Optional. Changes the heading and the browser tab title for that page on this site only.',
                ]),
                Text::make('An empty editor is not a missing page — it means "keep following the standard text". That is worth leaving alone where you can: the standard text is shared, so a correction to it reaches every site still following it, while a site that has been rewritten keeps whatever was typed until someone edits it again. Emptying the editor puts a page back on the shared text.')
                    ->color('gray'),
                Text::make('The standard text describes what this network actually does — it hosts nothing, embeds everything from Chaturbate, and takes no payments on its own domains. If that ever stops being true of a site, its pages have to be rewritten, and the 2257 statement first. Either way this is a starting point written to fit the setup, not legal advice: have a lawyer read it before a site takes real traffic.')
                    ->color('warning'),
            ]);
    }

    private function tracking(): Section
    {
        return Section::make('Tracking — how its revenue is attributed')
            ->icon(Heroicon::OutlinedChartBar)
            ->collapsible()
            ->schema([
                $this->screenshot('site-tracking', 'The Tracking tab, with the track prefix and Google Analytics measurement ID fields'),
                $this->fields([
                    'Track prefix' => 'Prefixes the sub-id on every outbound link to Chaturbate, so this domain\'s earnings are separable in the affiliate dashboard: "fq" sends fq-grid-m, fq-feed-d, fq-profile-m … The middle part is the page the click came from and the trailing letter is the device (m = mobile, d = desktop).',
                    'Google Analytics measurement ID' => 'This domain\'s own GA4 property, in the form G-XXXXXXXXXX. Leave it empty for no analytics tag at all.',
                ]),
                Text::make('Set the prefix when the site is created. Adding or changing one on a site that already earns breaks the continuity of its affiliate history, because its clicks start arriving under a label that has no past.')
                    ->color('warning'),
                Text::make('The analytics tag is only emitted in production — local and preview hostnames resolve to the default site, and would otherwise report into its live property.')
                    ->color('gray'),
            ]);
    }

    private function launchingASite(): Section
    {
        return Section::make('Launching a new site')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->collapsible()
            ->visible(fn (): bool => (bool) Auth::user()?->isAdmin())
            ->schema([
                Text::make('New site opens the same six tabs, empty. In order:'),
                $this->screenshot('site-create', 'The New site form, showing the Identity tab of an empty site record'),
                $this->fields([
                    '1. Point the domain here' => 'DNS first — the record below only decides what is served once requests arrive.',
                    '2. Identity' => 'Name the site, check the slug it generated, and add every hostname including www. Leave Default site off unless you mean to move the catch-all.',
                    '3. Content' => 'Set the keywords. This is what the site is; nothing else matters if these match nothing.',
                    '4. Tracking' => 'Set the track prefix now, while the site has no affiliate history to break.',
                    '5. Save, then check' => 'Open the Sites list and read the Live now column. A green count means the keywords match real performers and the site has something to show.',
                    '6. Branding and copy' => 'Logo, colours, headings and descriptions — the part that stops it reading like its neighbour. None of it blocks launch.',
                ]),
                Text::make('New performers reach the site on the next scheduled sync. Sync cams now, on the A/B Test page, pulls them immediately if you don\'t want to wait.')
                    ->color('gray'),
            ]);
    }

    private function abTest(): Section
    {
        return Section::make('Reading the A/B Test page')
            ->icon(Heroicon::OutlinedChartBar)
            ->collapsible()
            ->schema([
                Text::make('This is where the grid-versus-feed split reports back. It answers one question — which homepage layout earns more clicks through to Chaturbate — and it is the input to the Homepage layout tab.'),
                $this->screenshot('ab-dashboard', 'The A/B Test page, with site, device and date filters above an explanation panel and three conversion stat cards'),
                $this->fields([
                    'Site' => 'Which domain\'s traffic to measure. Every site logs into the same tables, so pooling them averages different audiences into a rate that describes neither — read it per site.',
                    'Device' => 'Mobile means a phone-shaped screen, desktop everything else. Worth checking both: a pooled rate can hide a layout that wins on phones and loses on laptops.',
                    'From / Until' => 'The date range. It starts at the day the split shipped, because before that "/" always served the grid and nothing linked to the feed — all-time totals make the grid look artificially ahead.',
                    'Grid (/) and Feed (/feed)' => 'Clicks divided by views for that layout, as a percentage. This — not raw click volume — is the number that answers which layout converts better.',
                    'Profile → Chaturbate' => 'The same rate for performer profile pages, which both layouts feed into.',
                    'Sync cams now' => 'Fetches the latest rooms from Chaturbate immediately instead of waiting for the scheduled run. It fetches for every active site at once, so it is administrator-only.',
                ]),
                Text::make('Views and clicks are real visitors only — crawlers are excluded before anything is logged, so they never inflate these counts. Give a site a few hundred views per layout before believing a difference between them.')
                    ->color('gray'),
            ]);
    }

    private function cams(): Section
    {
        return Section::make('Cams')
            ->icon(Heroicon::OutlinedRectangleStack)
            ->collapsible()
            ->schema([
                Text::make('The shared pool every site draws from, refreshed from Chaturbate on a schedule. It is deliberately read-only: anything edited here would be overwritten by the next sync. Use it to check what the sync is actually seeing.'),
                $this->screenshot('cams-list', 'The Cams list, with thumbnails, usernames, viewer counts and the online, HD and gender filters'),
                $this->fields([
                    'Filters' => 'Online is on by default — switch it off to see performers who have been seen before but are not streaming now.',
                    'Search' => 'By username, which is the quickest way to check whether a specific performer is in the pool and how they are tagged.',
                    'View' => 'Opens everything the sync knows about a performer, including the tags that decide which sites show them.',
                    'Visit room' => 'Opens the performer\'s actual Chaturbate room in a new tab.',
                ]),
                Text::make('A performer appears on a site when their tags include one of that site\'s keywords and their gender matches. If someone you expect is missing, open them here and compare their tags with the site\'s Content tab.')
                    ->color('gray'),
            ]);
    }

    private function users(): Section
    {
        return Section::make('Users and access')
            ->icon(Heroicon::OutlinedUsers)
            ->collapsible()
            ->visible(fn (): bool => (bool) Auth::user()?->isAdmin())
            ->schema([
                Text::make('Who can log into this panel, and how much of the network they see. Administrators only — this form is what grants administrator in the first place.'),
                $this->screenshot('users-list', 'The Users list, showing each account\'s email, admin flag and assigned sites'),
                Text::make('The Sites column reads "All sites" for an administrator, who reaches every site without being assigned any, and lists the assignments for everyone else.'),
                $this->screenshot('user-access', 'The user edit form, with the account fields and the Access section granting admin or per-site assignments'),
                $this->fields([
                    'Name and email address' => 'The email address is what they sign in with, and has to be unique.',
                    'Password' => 'Required when creating an account. On an edit, leave it blank to keep the current one — filling it in resets it.',
                    'Administrator' => 'Full access: every site, plus the ability to create sites and manage users. Turning it on hides the site assignments, since they no longer limit anything.',
                    'Sites this user administers' => 'The sites a non-administrator may edit. They can change these sites\' branding, content and copy, but not create or delete sites, and not touch the domain wiring on the Identity tab.',
                ]),
                Text::make('A site assigned to nobody is not orphaned — administrators still reach it. Assignments only ever widen what a non-administrator can see.'),
                Text::make('You cannot delete your own account: it would sign you out mid-session, and if you were the last administrator it would leave the panel with no one able to create another.')
                    ->color('gray'),
            ]);
    }
}
