---
title: "Does My Plugin Support PublishPress Revisions?"
order: 40
doc_category: revisions
section: New Revisions Integrations
---

# Does My Plugin Support PublishPress Revisions?

Table of Contents

- [How PublishPress Revisions support works](#0-toc-title)

[PublishPress Revisions](https://publishpress.com/links/revisions) Free is available on WordPress.org. This plugin supports standard editing with the WordPress core.

[PublishPress Revisions](https://publishpress.com/links/revisions) Pro is available for PublishPress members. The Pro version provides support for 3rd party plugins and custom fields. [Click here for an overview of some supported plugins](https://publishpress.com/knowledge-base/plugins-revisions-support/).

If you want to check that your plugin supports PublishPress Revisions Pro, [contact us](https://publishpress.com/contact/).

## How PublishPress Revisions support works [#](#0-toc-title)

Generally, if your plugin stores data in the post_meta table, PublishPress Revisions will support revisions for the plugin.

However, the database structure isn’t the only factor.

The other big factor is that we need the plugin code to or update those post_meta entries on revision creation. This is what happens on a normal post save.

So if your plugin is using normal WordPress development standards, it should work.

However, sometimes the plugin has some proprietary “safeguards” in place that causes the storage to be skipped. This happens if the revision ID does not match the published post ID, or because the revision’s post status is unexpected. We have been able to work around those safeguards in Advanced Custom Fields and Yoast SEO. We did this in a way that will also apply to other plugins that have similar safeguards.
