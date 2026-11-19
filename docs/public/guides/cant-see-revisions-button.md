---
title: "Can’t See the Revisions Button or Links"
order: 2
doc_category: revisions
section: Troubleshooting Revisions
---

# Can’t See the Revisions Button or Links

Table of Contents

- [Check the Status of Your Content](#h-check-the-status-of-your-content)
- [Are Revisions Enabled for the WordPress Core](#h-are-revisions-enabled-for-the-wordpress-core)
- [Check the Revisor Role](#h-check-the-revisor-role)
- [Check the Revisions Setting](#3-toc-title)

If you can’t see the [PublishPress Revisions](https://publishpress.com/links/revisions) buttons and links when editing content, this guide can help.

## Check the Status of Your Content [#](#h-check-the-status-of-your-content)

- The Free version of [PublishPress Revisions](https://publishpress.com/links/revisions) controls who can create revisions for **published** content.
- The Pro version of PublishPress Revisions also works on content that is unpublished.

## Are Revisions Enabled for the WordPress Core [#](#h-are-revisions-enabled-for-the-wordpress-core)

Check that the default revisions feature is enabled on your site. PublishPress Revisions builds on the default revisions feature in WordPress. However, some popular hosts such as [WPEngine do disable revisions](https://wpengine.com/support/platform-settings/#Post_Revisions).

## Check the Revisor Role [#](#h-check-the-revisor-role)

Start by checking that PublishPress Revisions works correctly for users in the “Revisor” role.

- Create a user in the “Revisor” role and log in to their account.
- Can this user create revisions for published content?

If this is not working, then you probably have another plugin enabled that is conflicting with PublishPress Revisions.

## Check the Revisions Setting [#](#3-toc-title)

Next, it is important to check the Revisions setting. Go to Revisions > Settings > Features. Make sure you have checked your post type here.
