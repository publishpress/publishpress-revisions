---
title: "Scheduled Revisions are Not Publishing"
order: 37
doc_category: revisions
section: Troubleshooting Revisions
---

# Scheduled Revisions are Not Publishing

Table of Contents

- [Disable Any Cache Plugins or Options](#h-disable-any-cache-plugins-or-options)
- [Use WP Control or a Similar Plugin](#h-use-wp-control-or-a-similar-plugin)
- [Check Your Site's Time Zone](#h-check-your-site-s-time-zone)

This guide has advice for what to do if your [scheduled revisions](https://publishpress.com/links/scheduled-revisions) are not publishing at the correct time.

## Disable Any Cache Plugins or Options [#](#h-disable-any-cache-plugins-or-options)

Caching plugins may impact the visibility of your [scheduled revisions](https://publishpress.com/links/scheduled-revisions). [PublishPress Revisions](https://publishpress.com/links/revisions) does attempt to clear many types of WordPress cache when a scheduled revisions is published. But to debug this issues, it is worth disabling any caching systems on your site and then testing the scheduling of revisions.

## Use WP Control or a Similar Plugin [#](#h-use-wp-control-or-a-similar-plugin)

If revisions are not publishing at the scheduled time, try a plugin such as [WP-Cron Status Checker](https://wordpress.org/plugins/wp-cron-status-checker/). This plugin will add a widget to your WordPress dashboard showing if WP-Cron is working correctly on your site.

![Cron is working correctly](https://docs-media.publishpress.com/publishpress-revisions/cron-check-13cbfbee2b.webp)

## Check Your Site’s Time Zone [#](#h-check-your-site-s-time-zone)

A common source of scheduled publishing problems is an incorrect time zone. The timezone on your server and the timezone inside the WordPress admin area should be the same. Your site’s timezone can be found by going to the “Settings” tab in WordPress. Finding the timezone of your server is more complex and will depend on your hosting company. Their support team or documentation should be able to help.

![Timezone for WordPress site](https://docs-media.publishpress.com/publishpress-revisions/timezone-site-6bb79e7ff3.webp)
