# Revisions MCP integration

PublishPress Revisions registers private WordPress abilities in the `publishpress` category under `publishpress-revisions/`. PublishPress Hub can expose selected actions through the separately installed WordPress MCP Adapter. The authenticated WordPress account's capabilities apply to every operation.

## Working with revisions

1. Discover supported post types and enabled fields with `get-content-choices`.
2. Read a source with `get-source-post`, then pass its `expected_hash` to `create-revision`. A revision is a separate working copy; creating or editing it does not replace the source content.
3. Use `get-revision` and `compare-revision` to inspect the copy. Supply `compare_to` to compare two readable revisions of the same source. Pass the latest `expected_hash` to `update-revision`.
4. Use `transition-revisions` to submit, decline, approve, schedule, publish now, unschedule or manage trash. Approval honors the revision's date. Applying or restoring content requires the latest `parent_hash` as well as the copy's token.

`list-revisions` supports source, status, author and search filters. Use `status: "archive"` for past revisions and `status: "trash"` for trashed working copies. `duplicate-post` creates a native draft duplicate according to the configured duplication fields.

Editable content includes enabled title, content, excerpt, slug, author, parent, date, featured image, page template and taxonomy assignments. Dates use ISO 8601 with a timezone. Term and author IDs are JSON integers. Use `thumbnail_id: 0` to clear an image and `template: "default"` for the theme default. The selected post author is separate from `revision_author`, which identifies the revision owner. Archive reads omit fields whose historical values were not stored; an omitted field does not mean it was cleared. Arbitrary metadata and page-builder document editing are not provided by these actions. Native cloning, publication and restoration retain the plugin's metadata and integration hooks.

## Settings

`get-plugin-settings` returns current values, native stored representations, per-field schemas and a revision token. `update-plugin-settings` accepts a partial `settings` object and that token. Preserve the exact discovered JSON types: checkbox values are booleans, field/post-type maps contain booleans, notification policies and revision limits are string choices, and `num_revisions` is an integer or null for the native default.

Revision field switches use `_thumbnail_id` and `_wp_page_template`; the native duplication switches use `post_thumbnail` and `post_template`. These are separate maps. Empty map patches do not change inherited defaults.

Omitted fields and map entries remain unchanged. Unknown fields, unsupported choices and malformed values are rejected before saving. Network-controlled settings require network permission. Edition and dependency restrictions still apply. Licensing, database index maintenance, storage-schema migration and network policy/default administration remain in the native administration screens.

## Pro actions

Pro adds native deletion-request discovery and request/clear/approval operations. Approving an existing request permanently deletes its post through the native workflow and requires the native deletion permission. Previews send no notifications. Request pagination excludes unreadable records; queues above 1,000 stored requests use native administration.

`get-comparison-fields` returns the native authorized custom-field comparison; internal and sensitive fields are filtered by the native providers. Personal ignored fields and site-wide suppressed fields have separate discovery/update actions. Personal preferences apply only to the authenticated user.

All edits to an already scheduled revision require approval authority. Publishing an unpublished source through Pro also requires the source post type’s publication capability.

Native integration availability is returned by `get-content-choices`. Integrations are loaded by Revisions according to the active plugins and native settings.

## Safe operation

Write actions support `dry_run: true`. Read the object again after saving and obtain fresh tokens before subsequent writes. A stale object or source returns a conflict requiring a new read, not a connection refresh. Bulk transitions and deletion requests validate all targets first, then process them individually. A partial result identifies confirmed IDs and the failed target; do not retry an entire batch blindly. These operations are not transactional.

The integration does not export authentication secrets or signed preview links. Readable post content and authorized custom-field comparison values are shared with the connected AI client. A denied capability remains denied for an administrator account. The Hub connection's selected actions and write-access policy apply in addition to these plugin checks.

See [feature coverage](mcp-feature-coverage.md) and [request examples](mcp-prompt-examples.md) for current supported operations and scope.

Revision list pagination includes only readable copies. Filter requests matching more than 1,000 revisions by source, author, status or search, or use native administration.
