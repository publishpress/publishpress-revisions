---
name: publishpress-mcp
description: Implement or extend Revisions actions through the WordPress Abilities API. Use for action registration, schemas, permissions, feature operations, and integration tests.
---

# PublishPress MCP integration

Hub provides an optional connection through the separately installed WordPress MCP Adapter; it does not bundle the adapter or implement MCP transport. Users connect through a compatible external MCP client. The integration does not require a particular AI provider or integrate with WordPress.com's hosted MCP service.

## Hub connection contract

Hub owns the shared connection and discovers abilities in category `publishpress`. Connection code and generic documentation live in the Hub repository. Keep Revisions action implementations and coverage in this repository; adding actions must not require Hub registration changes.

## Plugin ownership

- Register abilities independently of Hub on `wp_abilities_api_init`, outside admin-only bootstrap paths. Check API availability to preserve older WordPress compatibility.
- Register shared category `publishpress` on `wp_abilities_api_categories_init` if `wp_has_ability_category('publishpress')` is false. Do not probe with `wp_get_ability_category`: a missing category emits a developer notice. Every participating plugin must work alone and coexist with other category registrants.
- Use names in the form `{plugin-namespace}/{action-name}`, explicit input/output JSON schemas, clear descriptions, and an execution callback that reuses plugin services.
- Set `meta.public`, `meta.mcp.public`, and `meta.show_in_rest` to false. Custom-server registration is explicit; public default-server discovery is a different route.
- Match the operation's existing permission checks, including object-specific checks where relevant. Never replace permissions with a nonce or trust a tool annotation as authorization.
- Check the actual operation capability with `current_user_can`; do not bypass a denial just because the user has the administrator role. Respect application-password capability restrictions.
- Preserve each plugin's validation, storage formats, filters and lockout protections. Validate all bulk targets before writing, and reuse existing services instead of simulating admin forms or minting nonces.
- Gate edition-specific registrations on the active edition and network operations on their existing network permissions. Update shared dependencies through the normal release process instead of editing vendored copies.
- Do not change the authenticated MCP identity or return authentication secrets.
- Annotate read-only, destructive, and idempotent behavior truthfully. Avoid arbitrary PHP, SQL, option writes, or generic unrestricted method dispatch.
- Keep Free/Pro bootstrap and minimum PHP compatibility in mind. Do not register duplicates when Pro loads shared Free code.

## Revisions implementation

- Follow Revisions' procedural bootstrap and native engine lifecycle. Free abilities live in `includes/Mcp`; Pro extends them from `includes-pro/Mcp.php` after shared Free code loads.
- Use `RevisionCreation`, the native editor save lifecycle, revision-action functions, archive metadata/term copying and deletion workflow directly. Browser wrappers retain their nonce checks. Do not simulate forms or alter the authenticated identity.
- Working copies use native workflow MIME statuses and a source relationship; past revisions use WordPress revision records. Use `rvy_post_id` and native revision predicates. Queue queries must retain native revision filtering and MIME matching.
- Discover native captions, defaults and enabled fields. JSON booleans map to native checkbox storage; notification policy and revision-limit selections are string enums. Post-type and field maps are partial boolean maps. Preserve omitted keys.
- Require native per-object read, copy, duplicate, edit, submit, approval, delete and configured restore capabilities. Enforce queue restrictions and network-controlled settings even for administrators. Never grant administrator-level custom-type capabilities through MCP settings.
- Use fresh revision tokens for settings, source content, working copies and Pro preferences. Applying or restoring content also requires the current source token. Validate entire batches before writing and return confirmed IDs on partial failure.
- Do not expose passwords, license keys, raw metadata, preview nonces or signed browser URLs. Pro comparison uses native provider authorization and sensitive-field filtering. Do not provide arbitrary metadata, option, PHP or SQL operations.
- Keep native edition and dependency gates. Pro dependency updates happen through Composer; never edit bundled Free code or vendor dependencies. Shared code follows Revisions' PHP 8.2 runtime requirement.

## Coverage

Keep [feature coverage](../../../docs/mcp-feature-coverage.md), [request examples](../../../docs/mcp-prompt-examples.md) and usage documentation aligned with current native behavior. Reuse native field definitions for settings discovery and inspect the complete derived role-capability set when reviewing grants.

## Verification

Run `tests/Mcp/smoke.php` against a disposable `revisions_mcp_*` database with actual WordPress and the released MCP Adapter. Test Free and Pro bootstraps, discovery, preview, save and native read-back, invalid types/choices and mixed patches, stale tokens, denied object/capability access, locks and partial outcomes. Block external mail/network calls in fixtures. Distinguish assembled distribution fixtures from release ZIP testing.
