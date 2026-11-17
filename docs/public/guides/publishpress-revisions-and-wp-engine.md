---
title: "PublishPress Revisions and WP Engine"
order: 18
doc_category: revisions
---

# PublishPress Revisions and WP Engine

If you are using WP Engine, you may find some issue when using our [PublishPress Revisions](https://publishpress.com/links/revisions) plugin. This is due to the fact that WP Engine disable post revisions. They explained why in [this article](https://wpengine.com/support/platform-settings/#Post_Revisions).

![WPEngine and Revisions](https://docs-media.publishpress.com/publishpress-revisions/wp-engine-disabled-revisions-ba6e3b1002.webp)

Common issues you might face include inability to schedule revisions, view [past revisions](https://publishpress.com/links/past-revisions), or compare them. Notifications indicating that revisions are disabled may also appear.

![PublishPress Revisions and WP Engine](https://docs-media.publishpress.com/publishpress-revisions/revisions-wp-engine-588e38d4b1.webp)

To resolve this, reach out to WP Engine support as they are the only ones who can enable post revisions on your site. Attempting to enable them through php.ini or wp-config.php won’t work, as server-level configurations take precedence.

So, if your site is hosted on WP Engine and you’re using [PublishPress Revisions](https://publishpress.com/links/revisions), please contact WP Engine support to enable post revisions for your site.
