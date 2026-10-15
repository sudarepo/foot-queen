<?php

namespace App\Models;

use Database\Factories\McpTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * A bearer token for the admin MCP server.
 *
 * The token authenticates *as* its user, but grants nothing on its own: the
 * middleware re-checks `is_admin` on every request, so demoting someone in
 * the panel cuts off their tokens without having to find and revoke them.
 *
 * Stored twice: hashed in `token`, which is what requests are matched
 * against, and encrypted with APP_KEY in `secret`, so the owner can reopen
 * the AI Assistant page and copy their setup again instead of having to mint
 * a new key every time they set up Claude. That's a deliberate trade for a
 * non-technical admin; it means a database dump *together with* APP_KEY
 * exposes keys — but APP_KEY alone already forges sessions, so it's guarded
 * as carefully either way.
 */
#[Fillable(['user_id', 'name', 'token', 'secret', 'last_used_at', 'expires_at'])]
#[Hidden(['token', 'secret'])]
class McpToken extends Model
{
    /** @use HasFactory<McpTokenFactory> */
    use HasFactory;

    /**
     * Prefixed so a token pasted into the wrong place (a commit, a chat) is
     * recognisable for what it is, and greppable by secret scanners.
     */
    public const PREFIX = 'fqmcp_';

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'secret' => 'encrypted',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mint a token for a user, returning the stored record and the plaintext.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(User $user, string $name, ?\DateTimeInterface $expiresAt = null): array
    {
        $plaintext = self::PREFIX.Str::random(48);

        $token = static::query()->create([
            'user_id' => $user->getKey(),
            'name' => $name,
            'token' => static::hash($plaintext),
            'secret' => $plaintext,
            'expires_at' => $expiresAt,
        ]);

        return [$token, $plaintext];
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * The unexpired token matching a presented bearer string, if any.
     */
    public static function findValid(string $plaintext): ?self
    {
        return static::query()
            ->where('token', static::hash($plaintext))
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->with('user')
            ->first();
    }

    /**
     * Recorded at most once a minute — an agent session makes dozens of calls
     * in a row, and "last used" doesn't need a write for each of them.
     */
    public function markAsUsed(): void
    {
        if ($this->last_used_at === null || $this->last_used_at->lt(now()->subMinute())) {
            $this->forceFill(['last_used_at' => now()])->saveQuietly();
        }
    }
}
