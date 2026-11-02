<?php
defined('ABSPATH') || exit;

/** Whether scheduled revision publication should use WP-Cron for this site. */
if ( ! function_exists( 'rvy_use_wp_cron_for_revision_publication' ) ) {
	function rvy_use_wp_cron_for_revision_publication() {
		// Cron activity does not select the publication backend. Honor the explicit setting.
		return (bool) rvy_get_option( 'scheduled_publish_cron' );
	}
}
