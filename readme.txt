=== AnotherPanacea MCP ===
Contributors: anotherpanacea
Tags: mcp, ai, content-management, abilities-api, claude
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.6.2
License: GPL-2.0-or-later

Give Claude full post lifecycle control over your self-hosted WordPress site via MCP.

== Description ==

AnotherPanacea MCP gives Claude (via Claude Desktop, Claude Code, or any MCP client) full read/write control over a self-hosted WordPress site. It registers 35 abilities through the WordPress Abilities API (new in WP 6.9), covering the entire content lifecycle: search, create, edit, publish, schedule, trash, upload media, manage taxonomy, handle comments, and manage themes.

Claude works in Markdown; WordPress stores Gutenberg blocks. The plugin converts between the two automatically.

Bundles [MCP Adapter](https://github.com/WordPress/mcp-adapter) v0.4.1, which translates MCP protocol to WordPress abilities. If MCP Adapter is already installed as a standalone plugin, the bundled copy is automatically skipped.

= Abilities =

**Read-only (12):**

* `search-posts` — Filter by status, text, category, tag, date range, post type
* `get-post` — Full content as Markdown + raw block markup + preview URL
* `get-blocks` — Block-level content for surgical edits
* `list-categories` / `list-tags` — Taxonomy listings
* `list-revisions` — Revision history with optional line-based diff
* `search-media` — Search the media library
* `search-comments` — Search and filter comments
* `audit-post` — Scan for dead links (with Wayback Machine lookup), HTTP links, missing metadata, deprecated HTML, broken images
* `list-themes` — List installed themes with status and type (block/classic)
* `get-theme-info` — Detailed theme metadata, theme.json contents, file tree
* `get-theme-file` — Read any theme file (templates, parts, patterns, styles, assets)

**Editorial (10):**

* `create-post` / `update-post` — Create and edit posts/pages (Markdown input, block output)
* `update-blocks` — Direct block-level editing
* `create-comment` / `update-comment` — Comment management
* `manage-category` / `manage-tag` — Taxonomy CRUD
* `upload-media` / `update-media` — Media management with SSRF protection
* `repair-post` — Automated fixes: http-to-https, legacy HTML-to-blocks, excerpt generation, entity decoding, dead link replacement via Wayback Machine

**Destructive (8):**

* `transition-status` — Change post status (draft, publish, schedule, private, trash)
* `delete-post` — Permanent deletion
* `delete-comment` — Comment deletion
* `list-audit-log` — Query the audit trail
* `create-theme` — Scaffold new block themes with starter templates and design tokens
* `update-theme-file` — Write/update theme files with path traversal protection
* `delete-theme-file` — Remove theme files (protects required files)
* `activate-theme` — Switch active theme with dry-run support

**Resources (3):** taxonomy-map, recent-drafts, site-info
**Prompts (2):** draft-post, review-post

= Safety Features =

* **dry_run mode** — Preview what create/update/transition would do before committing
* **Concurrency guards** — Pass `expected_modified_gmt` to prevent accidental overwrites
* **Server segmentation** — Three MCP surfaces (reader/editorial/full) with different permission levels
* **Audit logging** — All changes recorded with MCP ability name vs wp-admin context
* **SSRF protection** — Upload URLs validated against internal IP ranges and cloud metadata endpoints
* **Per-post permissions** — Respects WordPress capability system throughout

= Self-Updater =

The plugin checks GitHub releases for updates every 12 hours. Go to Dashboard > Updates > "Check again" for immediate checks. Updates install through the standard WordPress plugin updater.

== Installation ==

= Requirements =

* WordPress 6.9+ (for the Abilities API)
* PHP 7.4+
* A dedicated WordPress user for Claude (Editor role recommended)

= Quick Start =

1. Download the latest release from [GitHub](https://github.com/anotherpanacea-eng/anotherpanacea-wordpress-mcp/releases).
2. In wp-admin, go to Plugins > Add New Plugin > Upload Plugin. Upload the zip and activate.
3. Create a dedicated WordPress user for Claude:
   * Go to Users > Add New User.
   * Set the role to **Editor** (can manage content but not plugins/themes/settings).
   * Log in as that user and go to Users > Profile > Application Passwords.
   * Enter a name (e.g. "Claude MCP") and click "Add New Application Password."
   * Copy the generated password. You will not see it again.
4. Configure your MCP client to connect to the plugin.

= Claude Desktop Configuration =

Add to your `claude_desktop_config.json` (on macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`):

`{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@anthropic-ai/mcp-remote@latest", "https://YOUR-SITE.com/wp-json/mcp/mcp-adapter-default-server", "--header", "Authorization: Basic BASE64_CREDENTIALS"]
    }
  }
}`

Replace `BASE64_CREDENTIALS` with the Base64 encoding of `username:application-password`. On macOS/Linux:

`echo -n "claude-editor:xxxx xxxx xxxx xxxx xxxx xxxx" | base64`

= Claude Code Configuration =

Create a `.mcp.json` in your project directory:

`{
  "mcpServers": {
    "wordpress": {
      "command": "npx",
      "args": ["-y", "@anthropic-ai/mcp-remote@latest", "https://YOUR-SITE.com/wp-json/mcp/mcp-adapter-default-server", "--header", "Authorization: Basic BASE64_CREDENTIALS"]
    }
  }
}`

= Segmented Server Endpoints =

For finer-grained access control, use one of the segmented endpoints instead:

* `/wp-json/mcp/reader` — 12 read-only abilities + resources (requires `edit_posts`)
* `/wp-json/mcp/editorial` — 22 read+write abilities + resources + prompts (requires `edit_posts`)
* `/wp-json/mcp/full` — All 35 abilities + resources + prompts (requires `edit_others_posts`)

= Verify Installation =

After activating and connecting, ask Claude: "Search for my recent posts." If it returns results, you are connected.

= Compatibility =

* The plugin registers a compat route at `/wp-json/wp/v2/wpmcp` for the `@automattic/mcp-wordpress-remote` client.
* If MCP Adapter is already installed standalone, the bundled copy is skipped automatically.

== Frequently Asked Questions ==

= Do I need to install MCP Adapter separately? =

No. The plugin bundles MCP Adapter v0.4.1. If you already have it installed as a standalone plugin, the bundled copy is automatically skipped.

= What WordPress version do I need? =

WordPress 6.9 or later. The plugin uses the Abilities API (`wp_register_ability()`), which was introduced in 6.9.

= Is this safe to use? =

The plugin is designed around a dedicated Editor-role user for Claude. Editors can manage content but cannot modify plugins, themes, users, or site settings. All changes are logged in an audit table. SSRF protections prevent the upload-media ability from accessing internal network resources.

= Does this work with WordPress.com? =

No. This plugin requires a self-hosted WordPress installation (wp-admin access to install plugins). For WordPress.com sites, use the built-in WordPress.com MCP connector.

= Can I use this with other AI tools besides Claude? =

Yes. Any MCP-compatible client can connect to the plugin's endpoints. The abilities are standard MCP tools.

== Changelog ==

= 1.6.1 =
* Wayback Machine integration: audit-post reports archive.org availability for dead links.
* repair-post: new replace-dead-links operation substitutes dead URLs with archive.org versions.
* WPCS fixes: pre-increment and equals sign alignment.

= 1.6.0 =
* Theme management: 7 new abilities for full theme lifecycle.
* list-themes, get-theme-info, get-theme-file (read-only, on reader surface).
* create-theme: scaffold block themes with starter templates, parts, and theme.json design tokens.
* update-theme-file, delete-theme-file, activate-theme (on full surface, requires edit_themes/switch_themes).
* Child theme support in create-theme.
* Server segmentation updated for theme abilities.

= 1.5.6 =
* Self-updater: lock down debug endpoint to update_plugins capability.
* Fix WPCS: param type alignment, rename() replaced with WP_Filesystem::move().

= 1.5.5 =
* Test release for self-updater verification.

= 1.5.4 =
* Self-updater: dual filter hooks (pre_set + get) for update transient.
* Clear GitHub cache on Dashboard > Updates > "Check again."
* Debug REST endpoint at /apmcp/v1/updater-debug (admin-only).
* Fix WPCS array alignment warnings.

= 1.5.3 =
* Fix null cache bug in GitHub API transient that blocked update detection.
* Add error logging for self-updater failures.

= 1.5.2 =
* Added GitHub-based self-updater for plugin updates via Dashboard > Updates.

= 1.5.1 =
* Security: upload-media SSRF hardening — validate URL scheme, resolve DNS, block RFC 1918/loopback/link-local/metadata targets.
* Security: audit-post per-post permission check — prevents auditing another user's unpublished content.
* Security: audit-post link checker — re-enabled TLS verification, restricted to editor+ role, scheme-validated.
* Security: transition-status enforces post-type-specific publish capability after loading the post.
* Improved: audit log now records the real MCP ability name and distinguishes MCP-driven vs wp-admin changes.
* Fixed: version metadata in plugin header and readme.txt now matches actual release.

= 1.5.0 =
* audit-post: read-only scan for dead links, HTTP links, missing blocks, deprecated HTML, missing metadata, broken images, numeric slugs.
* repair-post: automated fixes — http-to-https, strip-deprecated-html, convert-to-blocks, generate-excerpt, whitespace-normalize, decode-title-entities, strip-empty-tags.
* HTML-to-blocks converter for legacy classic editor content.
* Markdown converter: pre-process legacy img tags before wp_kses strips them.

= 1.4.0 =
* Pages and post types extended throughout.
* Revisions: list with optional line-based diff.
* Media: search-media, update-media, upload hardening (MIME allowlist, 10 MB limit, EXIF stripping).
* Preview URLs in get-post output.
* Audit logging via custom DB table.
* Server segmentation: 3 surfaces (reader/editorial/full).
* CI: GitHub Actions (lint, PHPCS, PHPUnit).

= 1.3.0 =
* MCP resources: taxonomy-map, recent-drafts, site-info.
* MCP prompts: draft-post, review-post.
* Comments: search/create/update/delete.
* Taxonomy CRUD: manage-category, manage-tag.
* Block operations: get-blocks, update-blocks.

= 1.2.0 =
* Concurrency guards (expected_modified_gmt).
* dry_run mode on create/update/transition.

= 1.0.0 =
* Initial release with search, get, create, update, transition, upload, delete abilities.
* Bidirectional Markdown to Gutenberg block markup converter.
* Bundles MCP Adapter v0.4.1.
