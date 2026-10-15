<?php

namespace App\Filament\Pages;

use App\Models\McpToken;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontFamily;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * Connecting an AI assistant (Claude) to the admin MCP server, without a
 * terminal: one button mints a key, and everything that has to be pasted
 * somewhere is shown with that key and this domain already filled in, each
 * with its own copy button.
 *
 * The plaintext key lives only in this component's state for as long as the
 * page stays open — it is stored hashed (see McpToken) and can't be shown
 * again, so "lost it" means "make another".
 */
class AiAssistant extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'AI Assistant';

    protected static ?string $title = 'Connect an AI assistant';

    protected static ?int $navigationSort = 19;

    /** The key just created, shown until the page is left. */
    public ?string $newKey = null;

    public ?string $newKeyName = null;

    /**
     * The server can change anything in the network, so the page that hands
     * out access to it is admin-only, like the server itself.
     */
    public static function canAccess(): bool
    {
        return (bool) Auth::user()?->isAdmin();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createKey')
                ->label('Create a connection key')
                ->icon(Heroicon::OutlinedKey)
                ->modalHeading('Create a connection key')
                ->modalDescription('A key lets an AI assistant work in this admin as you. Make one per computer, so you can switch one off without affecting the others.')
                ->modalSubmitActionLabel('Create key')
                ->schema([
                    TextInput::make('name')
                        ->label('What is this key for?')
                        ->placeholder('e.g. Claude on my laptop')
                        ->required()
                        ->maxLength(100),
                    Select::make('expires')
                        ->label('Stops working after')
                        ->options([
                            'never' => 'Never',
                            '30' => '30 days',
                            '90' => '90 days',
                            '365' => '1 year',
                        ])
                        ->default('never')
                        ->selectablePlaceholder(false),
                ])
                ->action(function (array $data): void {
                    [, $plaintext] = McpToken::issue(
                        Auth::user(),
                        $data['name'],
                        $data['expires'] !== 'never' ? now()->addDays((int) $data['expires']) : null,
                    );

                    $this->newKey = $plaintext;
                    $this->newKeyName = $data['name'];
                }),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            /**
             * Both halves are always built and toggled by closures: Filament
             * builds this schema once per request, before the "create" action
             * runs, so branching on $newKey here would only show the new key
             * on the *next* request.
             */
            $this->introduction()->hidden(fn (): bool => $this->hasNewKey()),
            ...array_map(
                fn (Section|Callout $component) => $component->visible(fn (): bool => $this->hasNewKey()),
                $this->newKeySections(),
            ),
            Section::make('Keys')
                ->description('Every key that can connect to this admin. Revoke any you no longer use — or that might have been shared by mistake.')
                ->schema([EmbeddedTable::make()]),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(McpToken::query()->with('user'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name'),
                TextColumn::make('user.email')->label('Belongs to'),
                TextColumn::make('created_at')->label('Created')->since(),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never'),
                TextColumn::make('expires_at')->label('Expires')->date()->placeholder('Never'),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Revoke')
                    ->icon(Heroicon::OutlinedNoSymbol)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Revoke this key?')
                    ->modalDescription('Any assistant using it stops working straight away. This can\'t be undone — you\'d create a new key instead.')
                    ->action(fn (McpToken $record) => $record->delete()),
            ])
            ->emptyStateHeading('No keys yet')
            ->emptyStateDescription('Use "Create a connection key" at the top of the page.');
    }

    /* ----------  Before a key is made  ---------- */

    private function introduction(): Section
    {
        return Section::make('What this does')
            ->schema([
                Text::make('Wherever this page shows grey text in a box, one click copies the whole thing — no need to select it.'),
                Text::make('Connect Claude to this admin, then just ask it things in plain English — "How did each site do this week?", "Change the homepage title on ebony18 to …", "Pause the Acme ads". Claude reads and changes the same data you see here.'),
                Text::make('1. Click "Create a connection key" at the top of this page.'),
                Text::make('2. Follow the three short steps that appear — install Claude, copy one box into its settings, restart it.'),
                Text::make('3. Ask Claude anything about the sites.'),
                Callout::make('Treat a key like a password.')
                    ->description('Anyone who has it can change every site. Don\'t email it or paste it into a chat — only into Claude\'s settings file.')
                    ->warning(),
            ]);
    }

    /* ----------  After a key is made  ---------- */

    /**
     * @return array<int, Section|Callout>
     */
    private function newKeySections(): array
    {
        return [
            Callout::make(fn (): string => 'Your key "'.$this->newKeyName.'" is ready — finish the steps below before leaving this page.')
                ->description('For security the key is only shown now. If you leave or reload the page before copying it, no harm done: revoke it below and create another.')
                ->warning(),

            Section::make('Step 1 — Install (once per computer)')
                ->schema([
                    Text::make('Claude Desktop: download from claude.ai/download, install, and sign in.'),
                    Text::make('Node.js: download the "LTS" version from nodejs.org, install it with the default options. (Claude uses it to reach this admin; you never open it.)'),
                ]),

            Section::make('Step 2 — Paste into Claude')
                ->schema([
                    Text::make('In Claude Desktop open Settings → Developer → Edit Config. A file called claude_desktop_config.json opens in a text editor.'),
                    Text::make('If the file is empty or only contains {} — select everything in it, delete it, then click the grey text below once (it copies itself — you\'ll see "Copied") and paste it into the file. If it already has other text in it, don\'t paste: send it to your developer.'),
                    $this->copyBox('Click to copy', fn (): string => $this->claudeDesktopConfig()),
                    Text::make('Save the file, then quit Claude completely (on Mac: Claude menu → Quit; on Windows: right-click the Claude icon by the clock → Quit) and open it again.'),
                ]),

            Section::make('Step 3 — Check it works')
                ->schema([
                    Text::make('In a new Claude chat, paste this:'),
                    $this->copyBox('First message', 'Using foot-queen, give me an overview of the network.'),
                    Text::make('Claude will ask permission to use the foot-queen tool — click "Allow always" so it stops asking for things that only read data. You should get back counts of sites, cams, ads and today\'s traffic.'),
                    Text::make('Then try:'),
                    $this->copyBox('Find out what it can do', 'What can the foot-queen tools do? Explain them simply, with an example request for each.'),
                    $this->copyBox('A weekly report', 'Using foot-queen, how did each site do over the last 7 days? Show pageviews, clicks and CTR in a table, best first.'),
                    Callout::make('Changes are real.')
                        ->description('When you ask for a change, Claude edits the live sites. It should tell you what it is about to change and ask first — read that before saying yes.')
                        ->info(),
                ]),

            Section::make('Other ways to connect (for developers)')
                ->collapsed()
                ->schema([
                    $this->copyBox('The key on its own', fn (): string => (string) $this->newKey),
                    $this->copyBox('Server address', $this->serverUrl()),
                    $this->copyBox('Claude Code (terminal) — one command', fn (): string => $this->claudeCodeCommand()),
                    Text::make('ChatGPT and the claude.ai website can\'t use this kind of key yet. OpenAI API, Codex and other setups are described in MCP.md in the code.'),
                ]),
        ];
    }

    private function hasNewKey(): bool
    {
        return $this->newKey !== null;
    }

    private function copyBox(string $label, string|Closure $value): TextEntry
    {
        return TextEntry::make(md5($label))
            ->label($label)
            ->state($value)
            ->fontFamily(FontFamily::Mono)
            ->copyable()
            ->copyMessage('Copied — now paste it')
            ->extraAttributes(['style' => 'white-space: pre-wrap; word-break: break-all;']);
    }

    private function serverUrl(): string
    {
        return url('/mcp/admin');
    }

    /**
     * Claude Desktop's own connector form only speaks OAuth, so the key goes
     * in through the mcp-remote bridge. The `Authorization:${AUTH_HEADER}`
     * spelling (no space) dodges an argument-escaping bug on Windows.
     */
    private function claudeDesktopConfig(): string
    {
        return json_encode([
            'mcpServers' => [
                'foot-queen' => [
                    'command' => 'npx',
                    'args' => ['-y', 'mcp-remote', $this->serverUrl(), '--header', 'Authorization:${AUTH_HEADER}'],
                    'env' => ['AUTH_HEADER' => 'Bearer '.$this->newKey],
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    private function claudeCodeCommand(): string
    {
        return sprintf(
            'claude mcp add --transport http --scope user foot-queen %s --header "Authorization: Bearer %s"',
            $this->serverUrl(),
            $this->newKey,
        );
    }
}
