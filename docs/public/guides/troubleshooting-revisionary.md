---
title: "Troubleshooting the PublishPress Revisions Plugin"
order: 41
doc_category: revisions
---

# Troubleshooting the PublishPress Revisions Plugin

Table of Contents

- [Revisions are Missing From the Queue](#h-revisions-are-missing-from-the-queue)
- [iframe or scripts Tags are Missing](#h-iframe-or-scripts-tags-are-missing)
- [Preview Button is Not Working](#h-preview-button-is-not-working)
- [The unfiltered_html Capability is Required](#3-toc-title)

This guide has advice for fixing various problems that users sometimes find the with [the PublishPress Revisions plugin](https://publishpress.com/revisions/).

## Revisions are Missing From the Queue [#](#h-revisions-are-missing-from-the-queue)

If some revisions are missing from the [Revision Queue](https://publishpress.com/links/links/revision-queue), you can disable a performance enhancement for better compatibility with themes and plugins.

- Go to Revisions > Settings > Revision Creation > Compatibility Mode
- Choose a different mode for “Compatibility Mode” box.

## iframe or scripts Tags are Missing [#](#h-iframe-or-scripts-tags-are-missing)

If you see HTML tags such as “iframe” or “scripts” being stripped from revisions, make sure that your user has the [unfiltered html](https://publishpress.com/wordpress-permissions/unfiltered_html/) permission.

This solution may also work if revisions are converting characters such as “&” to HTML code such as `&amp;`

## Preview Button is Not Working [#](#h-preview-button-is-not-working)

There is some variation in how themes respond to revision previews. Please try adjusting the following plugin setting:

- Revisions > Settings > Options.
- Change the options for “Preview Link Type”.

![Preview link type](https://docs-media.publishpress.com/publishpress-revisions/preview-link-type-95f38718d9.webp)

## The unfiltered_html Capability is Required [#](#3-toc-title)

You may see a message that reads: “The [unfiltered_html](https://publishpress.com/links/unfiltered_html) capability is required to create a revision of this post.”

This happens because of a restriction in WordPress. The [unfiltered_html](https://publishpress.com/links/unfiltered_html) capability grants users the ability to use HTML such as `script`, `style`, and `iframe` in WordPress posts. If you a user does not has this capability and tries to create a revision for a post with this kind of HTML, they will not be able to create the revision.
