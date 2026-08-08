<?php

namespace App\Filament\Resources\Sites\Schemas;

use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Everything that makes one domain look and behave like its own website.
 *
 * Grouped into tabs by what you'd come here to change: what the site *is*,
 * what it *looks like*, which performers it *shows*, what it *says*, and how
 * its revenue is *attributed*.
 */
class SiteForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make()
                    ->columnSpanFull()
                    ->tabs([
                        self::identityTab(),
                        self::brandingTab(),
                        self::contentTab(),
                        self::copyTab(),
                        self::trackingTab(),
                    ]),
            ]);
    }

    private static function identityTab(): Tab
    {
        return Tab::make('Identity')
            ->icon('heroicon-o-globe-alt')
            ->schema([
                TextInput::make('name')
                    ->label('Site name')
                    ->required()
                    ->maxLength(120)
                    ->helperText('Shown in the header, the <title>, the footer, and Open Graph tags.')
                    ->live(onBlur: true)
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set) {
                        // Only auto-fill the slug while it's still untouched,
                        // so renaming a live site can't silently change the
                        // key its assets and analytics are filed under.
                        if (blank($get('slug'))) {
                            $set('slug', Str::slug((string) $state));
                        }
                    }),

                TextInput::make('slug')
                    ->required()
                    ->maxLength(64)
                    ->alphaDash()
                    ->unique(ignoreRecord: true)
                    ->helperText('Internal key — used for asset folders and as the default SEO registry name. Avoid changing it once the site is live.'),

                Repeater::make('domains')
                    ->label('Domains')
                    ->simple(
                        TextInput::make('domain')
                            ->required()
                            ->helperText(false)
                            ->placeholder('example.com')
                    )
                    ->helperText('Hostnames that serve this site — no scheme, no port. Include local dev hosts (e.g. mysite.test) so the site resolves while you work on it.')
                    ->addActionLabel('Add domain')
                    ->columnSpanFull(),

                Grid::make(2)->schema([
                    Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive sites stop resolving and stop contributing their tags to the sync, so a parked domain costs nothing.'),

                    Toggle::make('is_default')
                        ->label('Default site')
                        ->helperText('Serves any host that matches no other site — a bare IP, a preview URL, a domain pointed here before it has a record. Turning this on turns it off everywhere else.'),
                ]),
            ]);
    }

    private static function brandingTab(): Tab
    {
        return Tab::make('Branding')
            ->icon('heroicon-o-paint-brush')
            ->schema([
                FileUpload::make('logo_path')
                    ->label('Logo')
                    ->image()
                    ->disk('public')
                    ->directory('sites/logos')
                    ->visibility('public')
                    ->helperText('Shown next to the site name in the header. Leave empty to fall back to public/img/logo.png, or to show the name as text only.'),

                FileUpload::make('og_image_path')
                    ->label('Social sharing image')
                    ->image()
                    ->disk('public')
                    ->directory('sites/og')
                    ->visibility('public')
                    ->helperText('Used as og:image when a link to this site is shared. 1200×630 is the safe size. Falls back to public/og-image.png.'),

                Grid::make(2)->schema([
                    ColorPicker::make('accent_color')
                        ->helperText('Overrides the --accent CSS variable — buttons, links, live badges. Empty keeps the stylesheet default.'),

                    ColorPicker::make('theme_color')
                        ->helperText('Browser UI colour on mobile (<meta name="theme-color">).'),
                ]),

                Repeater::make('nav_links')
                    ->label('Header navigation')
                    ->schema([
                        TextInput::make('label')->required()->maxLength(40),
                        TextInput::make('url')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('/girls or https://othersite.com')
                            ->helperText('Full URLs open in a new tab; paths starting with / navigate on this site.'),
                    ])
                    ->columns(2)
                    ->addActionLabel('Add link')
                    ->reorderable()
                    ->columnSpanFull(),
            ]);
    }

    private static function contentTab(): Tab
    {
        return Tab::make('Content')
            ->icon('heroicon-o-funnel')
            ->schema([
                Select::make('gender')
                    ->options([
                        'female' => 'Female',
                        'male' => 'Male',
                        'trans' => 'Trans',
                        'couple' => 'Couples',
                    ])
                    ->placeholder('Any gender')
                    ->helperText('Restricts the site to one gender. Also narrows what the sync fetches for it.'),

                TagsInput::make('tags')
                    ->label('Keywords / tags')
                    ->placeholder('feet')
                    ->helperText('The heart of the site. These are the provider tags the sync searches for, and the hard boundary of what this domain shows — no filter in the UI can escape them. Leave empty to show everything matching the gender above.')
                    ->columnSpanFull(),

                TextInput::make('default_category')
                    ->helperText('Pre-selected in the category dropdown on the homepage and feed. Unlike the tags above, a visitor can clear it with "All categories" — which still cannot leave the tags.'),

                TagsInput::make('featured_categories')
                    ->label('Category dropdown options')
                    ->placeholder('Leave empty for the shared default list')
                    ->helperText('Overrides config/cam-taxonomy.php featured_categories for this site only.')
                    ->columnSpanFull(),
            ]);
    }

    private static function copyTab(): Tab
    {
        return Tab::make('Copy & SEO')
            ->icon('heroicon-o-document-text')
            ->schema([
                TextInput::make('home_h1')
                    ->label('Homepage heading')
                    ->maxLength(160),

                TextInput::make('home_title')
                    ->label('Homepage <title>')
                    ->maxLength(200)
                    ->helperText('The site name is appended automatically.'),

                Textarea::make('tagline')
                    ->rows(2)
                    ->maxLength(320)
                    ->helperText('Sub-heading on the homepage, and the fallback meta description for any page that has none.')
                    ->columnSpanFull(),

                Textarea::make('home_meta')
                    ->label('Homepage meta description')
                    ->rows(2)
                    ->maxLength(320)
                    ->columnSpanFull(),

                Textarea::make('feed_meta')
                    ->label('Feed meta description')
                    ->rows(2)
                    ->maxLength(320)
                    ->columnSpanFull(),

                TextInput::make('meta_keywords')
                    ->maxLength(320)
                    ->helperText('Optional. Left empty, no keywords tag is emitted at all — which is the current behaviour, and what most search engines expect.')
                    ->columnSpanFull(),

                TextInput::make('profile_title_live')
                    ->label('Profile title — live')
                    ->maxLength(120)
                    ->helperText('Follows the performer name: "anna — Live Feet Cam".'),

                TextInput::make('profile_title_offline')
                    ->label('Profile title — offline')
                    ->maxLength(120),

                TextInput::make('profile_noun')
                    ->label('Profile description phrase')
                    ->maxLength(120)
                    ->helperText('Describes a performer with no bio of their own: "anna — live feet and foot fetish cam (24yo, blonde)."')
                    ->columnSpanFull(),

                Select::make('seo_pages')
                    ->label('SEO pages registry')
                    ->options(fn () => collect(array_keys(config('seo-pages', [])))->mapWithKeys(
                        fn (string $registry) => [$registry => $registry.' ('.count(config("seo-pages.{$registry}", [])).' pages)']
                    ))
                    ->placeholder('None — no landing pages')
                    ->helperText('Which file under config/seo-pages/ supplies this site\'s landing pages (/girls, /blonde, …). Sites in the same niche can share one. Adding a registry means adding a file there.')
                    ->columnSpanFull(),
            ]);
    }

    private static function trackingTab(): Tab
    {
        return Tab::make('Tracking')
            ->icon('heroicon-o-chart-bar')
            ->schema([
                TextInput::make('track_prefix')
                    ->maxLength(32)
                    ->helperText('Prefixes the `track` sub-id on outbound Chaturbate links, so this domain\'s revenue is separable in the affiliate dashboard: "fq" sends fq-grid, fq-feed, fq-profile. Leave empty to send the bare source label — which is what an existing site already reports, so changing it breaks continuity with its history.')
                    ->columnSpanFull(),

                TextInput::make('ga_measurement_id')
                    ->label('Google Analytics measurement ID')
                    ->maxLength(32)
                    ->placeholder('G-XXXXXXXXXX')
                    ->helperText('This domain\'s own GA4 property. Only emitted in production — local and preview hosts resolve to the default site and would otherwise report into its live property. Leave empty for no analytics tag.')
                    ->columnSpanFull(),
            ]);
    }
}
