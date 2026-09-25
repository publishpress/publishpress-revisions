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

function rvy_register_scheduling_log_menu() {
	if (! rvy_get_manageable_types()) return;

	add_submenu_page(
		'revisionary-q',
		esc_html__( 'Scheduled Revisions', 'revisionary' ),
		esc_html__( 'Scheduled Revisions', 'revisionary' ),
		'read',
		'admin.php?page=revisionary-q&post_status=future-revision'
	);
}
add_action( 'revisionary_admin_menu', 'rvy_register_scheduling_log_menu' );

function rvy_get_scheduling_log_table() {
	static $table;
	if ( $table ) return $table;
	if ( ! class_exists( 'ActionScheduler_ListTable' ) ) return null;
	if ( ! class_exists( 'Revisionary_Scheduling_Log_Table' ) ) {
		class Revisionary_Scheduling_Log_Table extends ActionScheduler_ListTable {
			public function __construct( $store, $logger, $runner ) {
				parent::__construct( $store, $logger, $runner );
				$this->table_header = __( 'Scheduling Log', 'revisionary' );
				$this->columns = [
					'revision' => __( 'Revision', 'revisionary' ),
					'preview' => '',
					'hook' => __( 'Hook', 'revisionary' ),
					'status' => __( 'Publication', 'revisionary' ),
					'args' => __( 'Arguments', 'revisionary' ),
					'schedule' => __( 'Scheduled Date', 'revisionary' ),
					'log_entries' => __( 'Log', 'revisionary' ),
				];
				$this->sort_by = ['schedule'];
				$this->bulk_actions = [];
				$this->row_actions = [];
			}

			public function get_search_box_button_text() {
				return __( 'Search revision or hook ID', 'revisionary' );
			}

			public function get_columns() { return $this->columns; }

			public function prepare_items() {
				$this->prepare_column_headers();
				$per_page = $this->get_items_per_page( $this->get_per_page_option_name(), $this->items_per_page );
				$query = [
					'hook' => 'publish_revision_rvy_action_scheduler',
					'per_page' => $per_page,
					'offset' => $this->get_items_offset(),
					'status' => $this->get_request_status(),
					'orderby' => $this->get_request_orderby(),
					'order' => $this->get_request_order(),
					'search' => $this->get_request_search_query(),
				];
				if ( 'past-due' === $this->get_request_status() ) {
					$query['status'] = ActionScheduler_Store::STATUS_PENDING;
					$query['date'] = as_get_datetime_object();
				}
				$this->items = [];
				$total = $this->store->query_actions( $query, 'count' );
				$labels = $this->store->get_status_labels();
				foreach ( $this->store->query_actions( $query ) as $action_id ) {
					try { $action = $this->store->fetch_action( $action_id ); } catch ( Exception $e ) { continue; }
					if ( is_a( $action, 'ActionScheduler_NullAction' ) ) continue;
					$status = $this->store->get_status( $action_id );
					$this->items[$action_id] = [
						'ID' => $action_id, 'hook' => $action->get_hook(), 'status_name' => $status,
						'status' => isset( $labels[$status] ) ? $labels[$status] : $status,
						'args' => $action->get_args(), 'group' => $action->get_group(),
						'log_entries' => $this->logger->get_logs( $action_id ),
						'claim_id' => null, 'recurrence' => $this->get_recurrence( $action ),
						'schedule' => $action->get_schedule(),
					];
				}
				$this->set_pagination_args( [ 'total_items' => $total, 'per_page' => $per_page, 'total_pages' => ceil( $total / $per_page ) ] );
				$this->status_counts = [];
				foreach ( $labels as $status => $label ) {
					$this->status_counts[$status] = $this->store->query_actions( [ 'hook' => 'publish_revision_rvy_action_scheduler', 'status' => $status ], 'count' );
				}
			}

			public function column_revision( $row ) {
				$revision = get_post( rvy_scheduling_log_revision_id( $row ) );
				if ( ! $revision ) return '<span aria-hidden="true">—</span>';
				return rvy_scheduling_log_revision_cell( $revision );
			}

			public function column_preview( $row ) {
				$revision_id = rvy_scheduling_log_revision_id( $row );
				if ( ! $revision_id ) return '';
				return sprintf( '<a href="%s"><span class="dashicons dashicons-cover-image" title="%s"></span></a>', esc_url( rvy_preview_url( $revision_id ) ), esc_attr__( 'View preview', 'revisionary' ) );
			}
		}
	}
	$table = new Revisionary_Scheduling_Log_Table( ActionScheduler::store(), ActionScheduler::logger(), ActionScheduler::runner() );
	return $table;
}

function rvy_scheduling_log_revision_cell( $revision ) {
	static $renderer;
	if ( ! $renderer ) {
		require_once( dirname( __FILE__ ) . '/class-list-table_rvy.php' );
		$renderer = new Revisionary_List_Table( [ 'screen' => 'revisionary-q' ] );
	}
	return $renderer->rvy_scheduling_log_title_cell( $revision );
}

function rvy_process_scheduling_log() {
	$table = rvy_get_scheduling_log_table();
	if ( $table ) $table->process_actions();
}

function rvy_render_scheduling_log() {
	if ( ! is_content_administrator_rvy() ) wp_die( esc_html__( 'Permission denied.', 'revisionary' ) );
	$legacy_count = count( rvy_legacy_scheduled_revision_ids() );
	if ( $legacy_count ) {
		$url = admin_url( 'admin.php?page=revisionary-settings&ppr_tab=working_copy&ppr_subtab=revision-scheduling' );
		$message = _n(
			'Note: %1$d Revision is scheduled for future publication with legacy triggering. %2$s for better reliability.',
			'Note: %1$d Revisions are scheduled for future publication with legacy triggering. %2$s for better reliability.',
			$legacy_count,
			'revisionary'
		);
		$link_text = _n(
			'Migrate it to Action Scheduler',
			'Migrate those to Action Scheduler',
			$legacy_count,
			'revisionary'
		);
		echo '<div class="notice notice-info"><p>';
		printf(
			wp_kses( $message, [ 'a' => [ 'href' => true ] ] ),
			(int) $legacy_count,
			'<a href="' . esc_url( $url ) . '">' . esc_html( $link_text ) . '</a>'
		);
		echo '</p></div>';
	}
	$table = rvy_get_scheduling_log_table();
	if ( $table ) $table->display_page();
}

function rvy_load_scheduled_revisions() {
	wp_enqueue_script( 'jquery-ui-dialog' );
	wp_enqueue_style( 'wp-jquery-ui-dialog' );
}

function rvy_render_scheduled_revisions() {
	global $rvy_scheduled_revisions_mode;
	$rvy_scheduled_revisions_mode = true;
	require dirname( __FILE__ ) . '/revision-queue_rvy.php';
}

function rvy_scheduled_revision_action_rows() {
	static $rows;
	if ( isset( $rows ) ) return $rows;
	$rows = [];
	if ( rvy_get_option( 'scheduled_publish_cron' ) || ! class_exists( 'ActionScheduler' ) ) return $rows;

	$store = ActionScheduler::store();
	$logger = ActionScheduler::logger();
	$labels = $store->get_status_labels();
	try {
		$action_ids = $store->query_actions( [
			'hook' => 'publish_revision_rvy_action_scheduler',
			'per_page' => -1,
			'orderby' => 'date',
			'order' => 'DESC',
		] );
	} catch ( Exception $e ) {
		return $rows;
	}

	foreach ( $action_ids as $action_id ) {
		try {
			$action = $store->fetch_action( $action_id );
		} catch ( Exception $e ) {
			continue;
		}
		if ( is_a( $action, 'ActionScheduler_NullAction' ) ) continue;
		$status = $store->get_status( $action_id );
		$args = $action->get_args();
		$revision_id = isset( $args['revision_id'] ) ? (int) $args['revision_id'] : (int) reset( $args );
		if ( ! $revision_id ) continue;
		$rows[$revision_id][] = [
			'ID' => (int) $action_id,
			'hook' => $action->get_hook(),
			'status_name' => $status,
			'status' => isset( $labels[$status] ) ? $labels[$status] : $status,
			'args' => $args,
			'log_entries' => $logger->get_logs( $action_id ),
			'schedule' => $action->get_schedule(),
		];
	}
	return $rows;
}

function rvy_scheduled_revision_publication_cell( $row, $revision_id ) {
	if ( empty( $row ) ) return '<span aria-hidden="true">—</span>';
	$table = rvy_get_scheduling_log_table();
	if ( ! $table ) return esc_html( $row['status'] );
	$modal_id = 'rvy-scheduled-action-' . (int) $row['ID'] . '-' . (int) $revision_id;
	$log_html = $table->column_log_entries( $row );
	$log_html = preg_replace( '#^\s*<ol>|</ol>\s*$#', '', $log_html );
	$log_html = preg_replace( '#<li([^>]*)>#', '<div class="rvy-scheduled-action-log-entry"$1>', $log_html );
	$log_html = str_replace( '</li>', '</div>', $log_html );
	$log_html = preg_replace( '#</?strong[^>]*>#', '', $log_html );
	$details = '<dl class="rvy-scheduled-action-details">'
		. '<dt>' . esc_html__( 'Hook', 'revisionary' ) . '</dt><dd><code>' . esc_html( $row['hook'] ) . '</code></dd>'
		. '<dt>' . esc_html__( 'Arguments', 'revisionary' ) . '</dt><dd>' . $table->column_args( $row ) . '</dd>'
		. '<dt>' . esc_html__( 'Scheduled Date', 'revisionary' ) . '</dt><dd>' . $table->column_schedule( $row ) . '</dd>'
		. '<dt>' . esc_html__( 'Log', 'revisionary' ) . '</dt><dd><div class="rvy-scheduled-action-log">' . $log_html . '</div></dd></dl>';
	return '<a href="#' . esc_attr( $modal_id ) . '" class="rvy-publication-modal-open" data-modal="' . esc_attr( $modal_id )
		. '" title="' . esc_attr__( 'Click to view scheduling log.', 'revisionary' ) . '">'
		. esc_html( $row['status'] ) . '</a><div id="' . esc_attr( $modal_id ) . '" class="rvy-scheduled-action-modal" title="'
		. esc_attr__( 'Scheduled Revision Publication', 'revisionary' ) . '" hidden>' . $details . '</div>';
}

function rvy_scheduled_revisions_modal_ui() {
	?>
	<style>
	.rvy-publication-modal-open{text-decoration:underline}.rvy-scheduled-action-details{display:grid;grid-template-columns:max-content 1fr;gap:8px 14px}.rvy-scheduled-action-details dt{font-weight:600}.rvy-scheduled-action-details dd{margin:0;min-width:0;overflow-wrap:anywhere}.rvy-scheduled-action-details ul{margin-top:0}.rvy-scheduled-action-log-entry{margin:0 0 12px;padding:0;font-weight:400}.rvy-scheduled-action-log-entry:last-child{margin-bottom:0}.rvy-scheduled-action-modal{max-width:760px}.rvy-scheduled-action-dialog,.rvy-scheduled-action-dialog .ui-dialog-titlebar,.rvy-scheduled-action-dialog .ui-dialog-content,.rvy-scheduled-action-dialog .rvy-scheduled-action-details,.rvy-scheduled-action-dialog .rvy-scheduled-action-log-entry{background:transparent}
	</style>
	<script>
	jQuery(function($){$(document).on('click','.rvy-publication-modal-open',function(event){event.preventDefault();var id=$(this).data('modal'),$modal=$('#'+id);$modal.removeAttr('hidden').dialog({dialogClass:'rvy-scheduled-action-dialog',modal:true,width:Math.min(760,$(window).width()-40),maxHeight:$(window).height()-60,close:function(){$modal.attr('hidden','hidden').dialog('destroy');}});});});
	</script>
	<?php
}
