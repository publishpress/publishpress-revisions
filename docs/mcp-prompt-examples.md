# Revisions MCP request examples

Resolve ambiguous titles to explicit readable source IDs. Read discovery before selecting settings or field identifiers. Preview writes and read back their results. Approval, publication, deletion and restoration require the user's requested scope and the latest object/source tokens.

| Request | Expected tool behavior |
| --- | --- |
| What can I do with Revisions? | Read `get-content-choices` and the relevant settings schema. Distinguish revision creation, archive access and duplication. |
| Make a revision of my About page. | Resolve its ID, read `get-source-post`, then preview/create an unsubmitted revision. The source stays unchanged. |
| Change this revision's author to Jane. | Resolve Jane's native user ID, inspect enabled author controls, then update the author selection. Preserve the revision owner and all other content. |
| Change the page template and featured image. | Read scoped choices with `post_id`, choose an allowed template and readable image attachment, then patch the revision. Use integer zero to clear an image. |
| Remove all tags from this revision. | Check the dedicated Tags switch and term assignment permission; send an empty `post_tag` list. Do not change categories or custom taxonomies. |
| Submit these two changes for review. | Read both copies and preview a bulk submit. A stale or invalid target must prevent preflight writes. |
| Publish this revision next Monday at 09:00. | Confirm the site's timezone and date, update the copy's ISO 8601 date, then preview/approve with its current source token. Verify the native scheduled state. |
| Change the text of an already scheduled revision. | Require both native editing and approval authority. Update only that copy; verify the source is unchanged until publication. |
| Compare these two past revisions. | Read both records, confirm they share a source and call `compare-revision` with `compare_to`. Pro custom fields use the corresponding field comparison action. |
| Duplicate this post without its thumbnail. | Read duplication fields, not revision fields. The native thumbnail switch is `post_thumbnail`; preview/save only the requested configuration, then duplicate the source. |
| Keep at most 12 past revisions per post. | Read settings and preview/save the integer `num_revisions: 12`. Preserve unrelated options. Null selects the native default; zero and minus one are distinct integer choices. |
| Always notify the original author when a revision is submitted. | Read the notification policy and select the discovered string choice `always`, not a boolean. |
| Restore the older version. | Clarify the exact archive, read its and the current source's tokens, then preview restoration with the required native permissions. Omitted historical fields are unavailable, not empty. |
| Hide this custom field from comparison for me. | Pro: read personal ignored fields, preserve their other entries and update the authenticated user's preference. Do not alter global suppression. |
| Approve this deletion request. | Pro: inspect the existing request and its token, preview permanent deletion and verify the native result. |

If the installed edition or dependency does not provide an action, explain that limitation. Do not substitute direct database writes, arbitrary metadata changes or browser credentials.
