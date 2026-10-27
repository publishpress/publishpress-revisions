<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/** Whether scheduled revision publication should use WP-Cron for this site. */
if ( ! function_exists( 'rvy_use_wp_cron_for_revision_publication' ) ) {
	function rvy_use_wp_cron_for_revision_publication() {
		// A custom WP-Cron trigger is an explicit signal that revision publication
		// must use WP-Cron rather than Action Scheduler's queue runner.
		return (bool) ( rvy_get_option( 'scheduled_publish_cron' ) || rvy_get_option( 'wp_cron_usage_detected' ) );
	}
}
