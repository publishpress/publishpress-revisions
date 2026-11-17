---
title: "How Scheduling Works in PublishPress Revisions"
order: 9
doc_category: revisions
---

# How Scheduling Works in PublishPress Revisions

The [PublishPress Revisions](https://publishpress.com/links/revisions) plugin uses the default WordPress cron to schedule revisions to go live. This guide will show you how to confirm that your site is correctly set up for [scheduled revisions](https://publishpress.com/links/scheduled-revisions).

You can see in the “[New Revisions](https://publishpress.com/links/new-revisions)” screen that a revision has been scheduled. Look in the “Status” column for “Scheduled”, as in the image below:

![New Revisions screen](https://docs-media.publishpress.com/publishpress-revisions/new-revisions-types-57ac26de1d.webp)

*Schedule Revision*

When you preview the post, you will see a banner saying “This is a Scheduled Revision” as in the next image below.

![Scheduled revision toolbar](https://docs-media.publishpress.com/publishpress-revisions/scheduled-revision-toolbar-7244c783e2.webp)

To confirm that revision will be published on time, we recommend also using a plugin such as [WP Crontrol](https://wordpress.org/plugins/wp-crontrol/). This will highlight any issues with WP Cron on your site. You will also be able to search the “Cron Events” table to see if the cron job was created correctly. In this image below, the details are the same as in the banner above. This revision is scheduled go live in March 31, 2022.

![Wp Cron Publish](https://docs-media.publishpress.com/publishpress-revisions/wp-cron-publish-093ddcb758.webp)

*Wp Cron Publish*
