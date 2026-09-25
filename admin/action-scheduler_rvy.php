<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function rvy_scheduled_revision_cron_events() {
	$events = [];
	$cron = function_exists( '_get_cron_array' ) ? _get_cron_array() : [];
	foreach ( (array) $cron as $timestamp => $hooks ) {
		if ( empty( $hooks['publish_revision_rvy'] ) ) continue;
		foreach ( $hooks['publish_revision_rvy'] as $event ) {
			$args = isset( $event['args'] ) ? (array) $event['args'] : [];
			$revision_id = isset( $args['revision_id'] ) ? (int) $args['revision_id'] : (int) reset( $args );
			if ( ! $revision_id ) continue;
			$events[$revision_id][] = [
				'timestamp' => (int) $timestamp,
				'args' => $args,
			];
		}
	}
	return $events;
}

function rvy_future_revision_ids() {
	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	return array_map( 'intval', (array) $wpdb->get_col(
		"SELECT ID FROM $wpdb->posts WHERE post_mime_type = 'future-revision' AND post_status != 'trash'"
	) );
}

function rvy_revision_has_action_scheduler_event( $revision_id ) {
	return function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action(
		'publish_revision_rvy_action_scheduler',
		[(int) $revision_id],
		'revisionary'
	);
}

function rvy_legacy_scheduled_revision_ids() {
	$ids = array_unique( array_merge( array_keys( rvy_scheduled_revision_cron_events() ), rvy_future_revision_ids() ) );
	return array_values( array_filter( $ids, function( $revision_id ) {
		return ! rvy_revision_has_action_scheduler_event( $revision_id );
	} ) );
}

function rvy_scheduled_revision_migration_ui() {
	if ( ! is_content_administrator_rvy() || ! function_exists( 'as_schedule_single_action' ) ) return;
	if ( ! rvy_legacy_scheduled_revision_ids() ) return;
	$count = array_sum( array_map( 'count', rvy_scheduled_revision_cron_events() ) );
	?>
	<div class="publish_revision_rvy_action_scheduler" data-nonce="<?php echo esc_attr( wp_create_nonce( 'rvy-migrate-scheduled-revisions' ) ); ?>">
		<button type="button" class="button rvy-migrate-scheduled-revisions">
			<?php printf( translate_nooped_plural( _n_noop('Migrate %d Scheduled Revision', 'Migrate %d Scheduled Revisions', 'revisionary' ), (int) $count, 'revisionary' ), number_format_i18n((int) $count)); ?>
		</button>
		<span class="spinner"></span>
		<span class="rvy-scheduled-revision-migration-progress" aria-live="polite"></span>
	</div>
	<script>
	jQuery(function($) {
		var $ui = $('.publish_revision_rvy_action_scheduler');
		var $checkbox = $('#scheduled_publish_cron');
		if ($checkbox.length) $ui.insertAfter($checkbox.parent().parent());
		$ui.on('click', '.rvy-migrate-scheduled-revisions', function() {
			var $button = $(this), $spinner = $ui.find('.spinner'), $progress = $ui.find('.rvy-scheduled-revision-migration-progress');
			$button.prop('disabled', true); $spinner.addClass('is-active');
			var migrate = function() {
				$.post(ajaxurl, { action: 'rvy_migrate_scheduled_revisions', _ajax_nonce: $ui.data('nonce') })
					.done(function(response) {
						if (!response.success) { $spinner.removeClass('is-active'); $button.prop('disabled', false); $progress.text(response.data && response.data.message ? response.data.message : '<?php echo esc_js( __( 'Migration failed.', 'revisionary' ) ); ?>'); return; }
						$checkbox.prop('checked', false);
						$progress.text(response.data.message);
						if (response.data.remaining > 0) migrate(); else { $spinner.removeClass('is-active'); $ui.delay(1200).fadeOut(); }
					}).fail(function() { $spinner.removeClass('is-active'); $button.prop('disabled', false); $progress.text('<?php echo esc_js( __( 'Migration failed.', 'revisionary' ) ); ?>'); });
			};
			migrate();
		});
	});
	</script>
	<?php
}

function rvy_ajax_migrate_scheduled_revisions() {
	check_ajax_referer( 'rvy-migrate-scheduled-revisions' );
	if ( ! is_content_administrator_rvy() ) wp_send_json_error( [ 'message' => __( 'Permission denied.', 'revisionary' ) ], 403 );
	if ( ! function_exists( 'as_schedule_single_action' ) ) wp_send_json_error( [ 'message' => __( 'Action Scheduler is unavailable.', 'revisionary' ) ], 500 );

	update_option( 'rvy_scheduled_publish_cron', '0' );
	rvy_refresh_options();
	$events = rvy_scheduled_revision_cron_events();
	$candidates = array_slice( rvy_legacy_scheduled_revision_ids(), 0, 10 );
	$migrated = 0;
	foreach ( $candidates as $revision_id ) {
		$revision = get_post( $revision_id );
		if ( ! $revision ) {
			foreach ( isset( $events[$revision_id] ) ? $events[$revision_id] : [] as $event ) {
				wp_unschedule_event( $event['timestamp'], 'publish_revision_rvy', $event['args'] );
			}
			continue;
		}
		$event_timestamp = ! empty( $events[$revision_id][0]['timestamp'] ) ? $events[$revision_id][0]['timestamp'] : 0;
		$timestamp = max( time() + 1, $event_timestamp ?: strtotime( $revision->post_date_gmt . ' UTC' ) );
		$action_id = as_schedule_single_action( $timestamp, 'publish_revision_rvy_action_scheduler', [$revision_id], 'revisionary', true );
		if ( ! $action_id ) continue;
		foreach ( isset( $events[$revision_id] ) ? $events[$revision_id] : [] as $event ) {
			wp_unschedule_event( $event['timestamp'], 'publish_revision_rvy', $event['args'] );
		}
		$migrated++;
	}
	$remaining = count( rvy_legacy_scheduled_revision_ids() );
	if ( $remaining && ! $migrated ) {
		wp_send_json_error( [ 'message' => __( 'No Scheduled Revisions could be migrated.', 'revisionary' ) ], 500 );
	}
	wp_send_json_success( [
		'migrated' => $migrated,
		'remaining' => $remaining,
		'message' => $remaining
			? sprintf( __( '%d Scheduled Revisions remaining.', 'revisionary' ), $remaining )
			: __( 'Scheduled Revision migration complete.', 'revisionary' ),
	] );
}
add_action( 'wp_ajax_rvy_migrate_scheduled_revisions', 'rvy_ajax_migrate_scheduled_revisions' );

function rvy_remove_action_scheduler_tools_menu() {
	remove_submenu_page( 'tools.php', 'action-scheduler' );
}
add_action( 'admin_menu', 'rvy_remove_action_scheduler_tools_menu', 999 );

function rvy_scheduling_log_revision_id( $row ) {
	$args = isset( $row['args'] ) ? (array) $row['args'] : [];
	return isset( $args['revision_id'] ) ? (int) $args['revision_id'] : (int) reset( $args );
}

function rvy_has_scheduling_log_actions() {
	if ( ! class_exists( 'ActionScheduler' ) ) return false;

	try {
		return (bool) ActionScheduler::store()->query_actions(
			[ 'hook' => 'publish_revision_rvy_action_scheduler', 'per_page' => 1 ],
			'count'
		);
	} catch ( Exception $e ) {
		return false;
	}
}
