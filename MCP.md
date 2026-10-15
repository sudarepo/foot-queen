# Admin MCP server

The app exposes an [MCP](https://modelcontextprotocol.io) server at **`/mcp/admin`**. It lets an AI client such as Claude, ChatGPT or Codex read and manage the whole network for an administrator: sites, ads, users, stats, syncs and Forge.

- **Admins only.** Every request needs a bearer token. The server checks on every call that the token's owner is still an admin, so demoting someone in the panel cuts their tokens off immediately.
- **The same on every domain.** `https://ebony18.com/mcp/admin` and `https://freecam.xxx/mcp/admin` reach the same server with the same data. Use whichever domain you like, but always over **https**.
- Defined in `routes/ai.php` and `app/Mcp/`. Tokens are stored hashed in `mcp_tokens`.

## 1. Issue a token

**The easy way: the admin panel.** Sign in as an admin, open **AI Assistant** in the sidebar (`/admin/ai-assistant`) and click **Create a connection key**. The page then shows:

- step-by-step Claude Desktop setup, with the complete settings file already filled in with the key and this server's address. One click copies it.
- starter messages to paste into Claude.
- copy-ready versions for developers: the key on its own, the server address and the Claude Code command.

The key is only shown until you leave the page. The same page lists every key with a **Revoke** button.

Each person and each client should get its own token, so you can revoke one without affecting the others.

**From a terminal:**

```bash
php artisan mcp:issue-token you@example.com --name="Claude Code – laptop" --expires=90
```

The token (`fqmcp_…`) is printed **once**. It can't be retrieved later, so if you lose it, issue a new one. Leave out `--expires` for a token that never expires.

On production, SSH in and use `php8.4`, because the server's default `php` is 8.1:

```bash
ssh forge@208.122.217.32 'cd /home/forge/foot-queen-nt0t2zvf.on-forge.com/current && php8.4 artisan mcp:issue-token you@example.com --name="Claude Code"'
```

Manage tokens with these commands:

```bash
php artisan mcp:list-tokens          # id, name, owner, last used, expiry (secrets are never shown)
php artisan mcp:revoke-token 3       # revoke token #3
```

In the examples below, replace `YOUR-DOMAIN` with one of the network's domains and `fqmcp_…` with your token. Keep the token in an environment variable, not in a file you commit:

```bash
export FOOT_QUEEN_MCP_TOKEN="fqmcp_…"
```

## 2. Connect a client

### Claude Code (CLI, VS Code and JetBrains extensions)

```bash
claude mcp add --transport http --scope user foot-queen \
  https://YOUR-DOMAIN/mcp/admin \
  --header "Authorization: Bearer $FOOT_QUEEN_MCP_TOKEN"
```

`--scope user` makes the server available in every project. Use `--scope local` to limit it to the current one. Run `claude mcp list` (or `/mcp` inside a session) to check that it connects.

To share the config with a team without sharing a token, add a project `.mcp.json`. Each person sets `FOOT_QUEEN_MCP_TOKEN` to their own token:

```json
{
  "mcpServers": {
    "foot-queen": {
      "type": "http",
      "url": "https://YOUR-DOMAIN/mcp/admin",
      "headers": {
        "Authorization": "Bearer ${FOOT_QUEEN_MCP_TOKEN}"
      }
    }
  }
}
```

### Claude Desktop

Claude Desktop's **Settings → Connectors → Add custom connector** form only supports OAuth or no auth, so it can't send this token. Use the `mcp-remote` bridge instead, which needs Node.js. Edit `claude_desktop_config.json`, found under **Settings → Developer → Edit Config**:

```json
{
  "mcpServers": {
    "foot-queen": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "https://YOUR-DOMAIN/mcp/admin",
        "--header", "Authorization:${AUTH_HEADER}"
      ],
      "env": {
        "AUTH_HEADER": "Bearer fqmcp_…"
      }
    }
  }
}
```

Restart Claude Desktop afterwards. The `Authorization:${AUTH_HEADER}` form, with no space after the colon, avoids an argument-escaping bug on Windows.

### Claude API (Messages API MCP connector)

```bash
curl https://api.anthropic.com/v1/messages \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $ANTHROPIC_API_KEY" \
  -H "anthropic-version: 2023-06-01" \
  -H "anthropic-beta: mcp-client-2025-11-20" \
  -d '{
    "model": "claude-opus-5-5",
    "max_tokens": 2000,
    "messages": [{"role": "user", "content": "How did each site do on CTR over the last 7 days?"}],
    "mcp_servers": [{
      "type": "url",
      "url": "https://YOUR-DOMAIN/mcp/admin",
      "name": "foot-queen",
      "authorization_token": "'"$FOOT_QUEEN_MCP_TOKEN"'"
    }],
    "tools": [{"type": "mcp_toolset", "mcp_server_name": "foot-queen"}]
  }'
```

The token is sent as `Authorization: Bearer …`. The same `mcp_servers` and `tools` entries work in the Python and TypeScript SDKs through `client.beta.messages.create(..., betas=["mcp-client-2025-11-20"])`.

### OpenAI API (Responses API)

```python
from openai import OpenAI
import os

client = OpenAI()

response = client.responses.create(
    model="gpt-5",
    tools=[{
        "type": "mcp",
        "server_label": "foot_queen",
        "server_description": "Admin for the Foot Queen cam-site network",
        "server_url": "https://YOUR-DOMAIN/mcp/admin",
        "authorization": os.environ["FOOT_QUEEN_MCP_TOKEN"],
        "require_approval": {"never": {"tool_names": [
            "get-network-overview", "list-sites", "get-site", "get-traffic-stats",
            "get-ad-stats", "get-chaturbate-revenue",
        ]}},
    }],
    input="Which site had the best feed CTR this month?",
)
print(response.output_text)
```

- `authorization` is sent as a bearer token. OpenAI doesn't store it, so pass it on every request.
- `require_approval` above approves the read-only tools automatically and asks before anything else. Use `"never"` to approve every tool automatically, or `"always"` to approve each call yourself.
- `allowed_tools: [...]` limits which tools the model sees at all. For example, list only the `get-*` and `list-*` tools for a read-only reporting agent.

### OpenAI Codex (CLI and IDE extension)

```bash
codex mcp add foot-queen --url https://YOUR-DOMAIN/mcp/admin
```

Then add the token variable in `~/.codex/config.toml`:

```toml
[mcp_servers.foot-queen]
url = "https://YOUR-DOMAIN/mcp/admin"
bearer_token_env_var = "FOOT_QUEEN_MCP_TOKEN"
```

`FOOT_QUEEN_MCP_TOKEN` must be set in the shell Codex runs from. Run `codex mcp list` to check the server.

### ChatGPT and claude.ai web connectors

The custom connectors in the ChatGPT apps (developer mode) and on claude.ai only support **OAuth**, or no auth at all. A static bearer token can't be used there. Supporting them would mean adding Laravel Passport and `Mcp::oauthRoutes()`, which laravel/mcp supports. That isn't set up yet. Until then, use one of the clients above.

## 3. Check the connection and discover the tools

You don't need to remember tool names. When a client connects, the server sends it a list of every tool with a description, plus instructions that explain how the network is organised. Ask the assistant in plain language.

**Check that it's connected.** Start with one of these prompts:

> What tools does the foot-queen MCP server give you? Group them by what they do.

> Using foot-queen, give me an overview of the network.

The first prompt should list about two dozen tools, such as `list-sites`, `update-site` and `get-traffic-stats`. The second runs `get-network-overview` and returns site, cam, ad and user counts, the last 24 hours of traffic, and when each sync last ran. If the assistant says it has no such tools, see [Debugging](#5-debugging).

You can also check outside a chat:

- **Claude Code:** run `/mcp` in a session. It should show `foot-queen · connected`, and you can open the server's entry to browse its tools.
- **Claude Desktop:** click the tools/plug icon under the message box. `foot-queen` and its tools should be listed there.
- **Codex:** run `codex mcp list`, or `/mcp` inside a session.
- **APIs:** the response includes an `mcp_list_tools` item (OpenAI) or `mcp_tool_use` blocks (Claude) showing what the model saw and called.

**Find out how to use a tool.** Ask about a tool before you use it:

> What can I change on a site with foot-queen? List every field update-site accepts.

> How do I create an ad through foot-queen? What's required for a banner vs. an embed?

> Explain what each field in get-traffic-stats means.

**Name the server when it matters.** If several MCP servers are connected, start with "Using foot-queen, …" so the assistant doesn't use a different server's tools or answer from memory.

### Example prompts

These prompts are run against the live data. Refer to sites by name, slug or id, and the assistant will look up the rest.

**Reporting (read-only, safe to run any time)**

> How did each site do over the last 7 days? Show pageviews, outbound clicks and CTR in a table, best first.

> Is the grid or the feed winning the A/B test on ebony18, on mobile vs. desktop? Is the gap big enough to call it?

> What did Chaturbate pay us this month, by day? Compare it with last month.

> Which ads have the best and worst CTR in the last 30 days?

> Show me every setting for freecam.xxx.

> Which cams are live on foot-queen right now, sorted by viewers?

**Changes (the assistant should confirm with you first)**

> On ebony18, change the homepage title to "…" and the meta description to "…".

> Switch freecam.xxx to feed-only on mobile but keep the A/B test on desktop.

> Add the tag "soles" to foot-queen's tags without removing the existing ones.

> Set the logo for ebony18 to https://example.com/logo.png.

> Pause every ad from Acme.

> Create a homepage banner for Acme on all sites: image https://…/banner.png, linking to https://acme.com, running from Nov 1 to Nov 30.

> Create a login for partner@example.com who can only manage ebony18.

**Operations**

> Run a cam sync now and tell me how many rooms came back.

> Set up newsite.com: create the site with the tags "bbw" and "curvy", then check Forge and tell me what's missing. Do a dry run before adding anything.

> Pull the latest Chaturbate stats, then show me yesterday's revenue.

### Tips

- **Read before writing.** For bigger changes, ask the assistant to show the current values first, for example: "Show me ebony18's copy fields, then propose new ones before changing anything".
- **Updates are partial.** Only the fields you mention change. List fields such as domains, tags and nav links are *replaced*, so when you add to one, say "add" and the assistant will read the current list first. The prompts above are worded that way.
- **Destructive actions.** Deleting a site can be undone (`restore-site`). Deleting an ad or a user is permanent. Pausing an ad (`is_active: false`) keeps its stats.
- **Forge.** `sync-forge-domains` only reports what it would do until you explicitly say "go ahead" or "apply it". DNS has to point at the server before an SSL certificate can be issued.
- **Approvals.** Claude Code and Claude Desktop ask before every tool call by default. To stop being asked about the read-only tools, choose "Always allow" when Claude Code asks, or add `"mcp__foot-queen__get-*"` and `"mcp__foot-queen__list-*"` to `permissions.allow` in `.claude/settings.json`. For OpenAI, see `require_approval` above.

## 4. What the server can do

| Area | Tools |
|---|---|
| Overview | `get-network-overview`: counts, the last 24h of traffic, when each sync last ran. A good first call. |
| Sites | `list-sites`, `get-site`, `create-site`, `update-site` (any field: identity, domains, branding images by URL, layout, gender and tags, copy and SEO, legal page overrides, tracking), `delete-site` (soft delete), `restore-site` |
| Ads | `list-ads`, `get-ad`, `create-ad` (image banner by URL, or embed code), `update-ad`, `delete-ad` (permanent) |
| Users | `list-users`, `create-user`, `update-user` (admin flag, site assignments, password), `delete-user` |
| Cams | `list-cams` (optionally exactly what one site shows), `get-cam`. These are read-only because the provider sync overwrites them. |
| Stats | `get-traffic-stats` (views, clicks and CTR by page, device, site and day, which covers the grid-vs-feed A/B test), `get-ad-stats`, `get-chaturbate-revenue` (network-wide only) |
| Operations | `run-sync` (cams, cam-profiles, chaturbate-stats), `get-forge-status`, `sync-forge-domains` (does a dry run unless `dry_run: false`) |

Sites are referred to by id or slug. Update tools change only the fields you pass. Validation follows the Filament forms, so the server rejects anything the panel would reject.

**Not available:** landing-page registries (`config/seo-pages/`) and the cam taxonomy are code, so changing them needs a deploy.

## 5. Debugging

```bash
php artisan mcp:inspector mcp/admin
```

This opens the MCP Inspector against your local server. Add an `Authorization: Bearer fqmcp_…` header in its connection settings.

Server responses for common failures:

- **401**: the token is missing, unknown, revoked or expired.
- **403**: the token is valid, but its owner isn't an admin.
- **429**: more than 120 requests a minute from one client.
