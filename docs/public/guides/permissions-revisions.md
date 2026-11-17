---
title: "Understanding Permissions in Revisions"
order: 12
doc_category: revisions
---

# Understanding Permissions in Revisions

Table of Contents

- [Permissions for Default Roles](#h-permissions-for-default-roles)
- [Permissions for the Revisor Role](#h-permissions-for-the-revisor-role)

## Permissions for Default Roles [#](#h-permissions-for-default-roles)

[PublishPress Revisions](https://publishpress.com/links/revisions) automatically provides revision permissions for the default WordPress users roles:

- Users in the “[Subscriber](https://publishpress.com/blog/permissions-subscribers-wordpress/)” role are not able to submit revisions.
- Users in the “[Contributor](https://publishpress.com/blog/contributor-permissions-wordpress/)” role can submit revisions to their own published posts, but they will not be able to publish them.
- Users in the “[Author](https://publishpress.com/blog/author-permissions-wordpress/)” role can publish their own revisions immediately.
- Users in the “[Editor](https://publishpress.com/blog/editor-permissions-wordpress/)” and “Administrator” roles are able to publish revisions for any post. These users are also ideally placed to act as moderators for revisions submitted by other users. [They can get email notifications for new revisions](https://publishpress.com/docs/emails-revisionary/).

## Permissions for the Revisor Role [#](#h-permissions-for-the-revisor-role)

[PublishPress Revisions](https://publishpress.com/links/revisions) does add one new role called “Revisor”.

The “Revisor” role is almost identical to the “Editor” role, except when a Revisor edits a published post, those changes are held for moderation. In contrast, users in the “Editor” role can publish their changes without moderation.

![](https://docs-media.publishpress.com/publishpress-revisions/revisor-9785ff3694.webp)

When a Revisor logs into WordPress and edits a post, all they will see is the “New Revision” button, as in the image below. The Revisor submit [new revisions](https://publishpress.com/links/new-revisions) but they will never be able to publish them. They will have to wait for a higher level user to login and approve the revision.

![New Revision Link 2](https://docs-media.publishpress.com/publishpress-revisions/new-revision-link-2-845edf102e.webp)

*New Revision Link 2*
