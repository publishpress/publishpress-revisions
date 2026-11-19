---
title: "Create Revisions Using an API"
order: 27
doc_category: revisions
section: New Revisions Integrations
---

# Create Revisions Using an API

Table of Contents

- [Step #1. Add to the wp_posts table](#h-step-1-add-to-the-wp-posts-table)
- [Step #2. Add to the wp_postmeta table](#h-step-2-add-to-the-wp-postmeta-table)
- [Note about other wp_post fields](#h-note-about-other-wp-post-fields)

Currently, [PublishPress Revisions](https://publishpress.com/links/revisions) does not have a plugin API to create a pending revision or scheduled revision. However, you create revisions with some $wpdb->insert() calls. Follow these steps to create revisions.

## Step #1. Add to the wp_posts table [#](#h-step-1-add-to-the-wp-posts-table)

Insert a `wp_posts` row with these details:

- For `post_type`, use the same as the published post
- For `post_status` use `pending`
- For `comment_count` this should be equal to the published post ID
- For `post_mime_type` use `pending-revision`
- For `post content`, `post_title`, `post_parent`, `comment_status` and `ping_status` you modify these as needed.

## Step #2. Add to the wp_postmeta table [#](#h-step-2-add-to-the-wp-postmeta-table)

Insert a `wp_postmeta` row with these details:

- The `post_id` should be equal to the newly inserted pending revision ID
- For `meta_key` use `_rvy_base_post_id`
- The `meta_value` should be equal to the published post ID

Also insert or update a wp_postmeta row with these details:

- The `post_id` should be equal to thee published post ID
- For `meta_key` use `_rvy_has_revisions`
- For `meta_value` use `1`

## Note about other wp_post fields [#](#h-note-about-other-wp-post-fields)

- `post_author` is understood to be the author of the revision, not a requested change to the published post author.
- `post_date` / `post_date_gmt` represents the requested publication date. This may be a past date to update the published post to on revision approval. Or it will the future date to schedule publication for on revision approval.
