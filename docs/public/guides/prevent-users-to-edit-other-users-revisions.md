---
title: "Block Users from Editing Other User’s Revisions"
order: 14
doc_category: revisions
section: Managing Revisions
---

# Block Users from Editing Other User’s Revisions

Table of Contents

- [Restrict Users to Editing Their Own Revisions](#0-toc-title)
- [Integration with Access Circle from PublishPress Permissions](#1-toc-title)

In some cases, you want a user role that only be able to edit their revisions and cannot edit other user’s revisions.

This is possible in [PublishPress Revisions](https://publishpress.com/links/revisions).

## Restrict Users to Editing Their Own Revisions [#](#0-toc-title)

- Install the [PublishPress Capabilities](https://publishpress.com/links/capabilities) plugin.
- Go to Revisions > Settings > [New Revisions](https://publishpress.com/links/new-revisions) > [New Revisions](https://publishpress.com/links/new-revisions).
- Enable this setting: “Listing others’ Revisions requires role capability”.

![Listing others' Revisions requires role capability](https://docs-media.publishpress.com/publishpress-revisions/only-edit-own-revisions-e36816d3e8.webp)

- Go to Capabilities > Capabilities, and make sure the role have **list_others_revisions** and **preview_others_revisions** capability

![Revisions capabilities](https://docs-media.publishpress.com/publishpress-revisions/revisions-list-capabilities-b67762f853.webp)

Once you did those 2 crucial steps, the role should be able to edit their own revisions while only be able to preview the other user’s revisions.

## Integration with Access Circle from PublishPress Permissions [#](#1-toc-title)

This feature requires the Pro version of the PublishPress Permissions plugin.

Access Circle are features in the Pro version of [PublishPress Permissions](https://publishpress.com/links/permissions) allow you to limit access (either view or edit) only to the same Permissions group. We have 2 access circles, you can read about those here:

- [Editorial Circles](https://publishpress.com/knowledge-base/editorial-circles/)
- [Visibility Circles](https://publishpress.com/knowledge-base/circles-visibility/)

[PublishPress Revisions](https://publishpress.com/links/revisions) has full support with Access Circle from [PublishPress Permissions](https://publishpress.com/links/permissions). This means PublishPress Revisions can allow a user from specific Permissions Group to see/edit the revisions of other team members in their Permissions Group.

- Make sure you have either the “Access Circles” feature enabled.
- Go to Permissions > Settings > Advanced and enable “**Access Circle restrictions apply to revisions**“.

Once you do this, each user from each access circle will be able to view/edit the revisions authored by the same group.

![Revisions settings in Permissions](https://docs-media.publishpress.com/publishpress-revisions/circles-revisions-83c243a971.webp)
