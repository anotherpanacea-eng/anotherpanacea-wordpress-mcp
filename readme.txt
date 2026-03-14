=== AnotherPanacea MCP ===
Contributors: anotherpanacea
Tags: mcp, ai, content-management, abilities-api
Requires at least: 6.8
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later

Registers MCP abilities for full post lifecycle management via the WordPress Abilities API.

== Description ==

AnotherPanacea MCP is an abilities provider plugin that gives Claude (via Claude Code or Claude Desktop) full post lifecycle control over a self-hosted WordPress site.

Bundles [MCP Adapter](https://github.com/WordPress/mcp-adapter) v0.4.1, which translates MCP protocol to WordPress abilities. If MCP Adapter is already installed as a standalone plugin, the bundled copy is automatically skipped. No separate installation required.

= Registered Abilities =

**Phase 1 (Read-Only):**

* `search-posts` — Search and filter posts by status, text, category, tag, date range
* `get-post` — Retrieve full post content as Markdown and raw block markup
* `list-categories` — List all post categories
* `list-tags` — List all post tags

**Phase 2 (Write):**

* `create-post` — Create new posts (accepts Markdown, converts to blocks)
* `update-post` — Partial update of existing posts
* `transition-post-status` — Change post status (draft, publish, schedule, etc.)
* `upload-media` — Upload images to the media library
* `delete-post` — Move posts to trash

= Markdown Conversion =

The plugin automatically converts between Markdown (what Claude works with) and Gutenberg block markup (what WordPress stores). Supports paragraphs, headings, lists, blockquotes, code blocks, images, and separators.

== Installation ==

1. Upload the `anotherpanacea-mcp` directory to `wp-content/plugins/`.
2. Activate the plugin. (MCP Adapter is bundled — no separate install needed.)
3. Verify abilities appear in Settings > MCP Settings.
4. If you already have MCP Adapter installed standalone, the bundled copy is automatically skipped.

== Changelog ==

= 1.0.0 =
* Initial release with all Phase 1 and Phase 2 abilities.
* Bidirectional Markdown ↔ Gutenberg block markup converter.
* Bundles MCP Adapter v0.4.1.
