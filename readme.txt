=== AnotherPanacea MCP ===
Contributors: anotherpanacea
Tags: mcp, ai, content-management, abilities-api
Requires at least: 6.9
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.5.3
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
3. Verify abilities are registered by visiting `https://your-site.com/wp-json/wp/v2/abilities` (requires authentication).
4. If you already have MCP Adapter installed standalone, the bundled copy is automatically skipped.

== Changelog ==

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
* Initial release with all Phase 1 and Phase 2 abilities.
* Bidirectional Markdown ↔ Gutenberg block markup converter.
* Bundles MCP Adapter v0.4.1.
