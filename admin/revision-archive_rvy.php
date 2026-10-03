<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

set_current_screen( 'revisionary-archive' );

// Modal popup to view changes from a specific revision
add_thickbox();
wp_add_inline_script(
	'thickbox',
	"jQuery(document).ready(function($) {
		$('.rvy-open-popup').unbind('click').click(function (e) {
			e.preventDefault();
			var label = '" . esc_html__( 'Revision for:', 'revisionary' ) . "' + ' ' + $(this).data('label');
			tb_show(label, $(this).attr('href'));
		});
	});"
);

require_once( dirname( __FILE__ ) . '/class-list-table-archive.php' );
$wp_list_table = new Revisionary_Archive_List_Table(['screen' => 'revisionary-archive']);		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
$wp_list_table->prepare_items();

$past_empty_unfiltered = false;
if ( ! $wp_list_table->has_items() ) {
	$past_filter_keys = ['s', 'origin_post', 'origin_post_type', 'post_author', 'post_parent', 'revision_date', 'origin_post_date', 'origin_post_author', 'approved_by'];
	$past_empty_unfiltered = true;
	foreach ( $past_filter_keys as $filter_key ) {
		if ( isset( $_REQUEST[$filter_key] ) && '' !== sanitize_text_field( wp_unslash( $_REQUEST[$filter_key] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$past_empty_unfiltered = false;
			break;
		}
	}

	if ( ! empty( $_REQUEST['v'] ) && 'all' !== sanitize_key( $_REQUEST['v'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$past_empty_unfiltered = false;
	}
}

if (rvy_get_option('revision_archive_deletion') && !empty($_REQUEST['deleted'])) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$bulk_counts = array(
		'deleted'   => isset($_REQUEST['deleted']) ? absint( $_REQUEST['deleted'] ) : 0,		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
	);

	$bulk_messages = [];
	$bulk_messages['post'] = array(
		'deleted'   => sprintf(esc_html(_n( '%s revision permanently deleted.', '%s revisions permanently deleted.', $bulk_counts['deleted'] )), $bulk_counts['deleted']),
	);

	$bulk_messages['page'] = $bulk_messages['post'];

	$bulk_counts = array_filter( $bulk_counts );


	// If we have a bulk message to issue:
	$messages = [];

	foreach ( $bulk_counts as $message => $count ) {
		if ( 'trashed' == $message && isset( $_REQUEST['ids'] ) ) {								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$any_messages = true;
			break;
		} elseif (!empty($bulk_messages['post'][$message])) {
			$any_messages = true;
			break;
		}
	}

	if (!empty($any_messages)) {
		echo '<div id="message" class="updated notice is-dismissible"><p>';
	}

	foreach ( $bulk_counts as $message => $count ) {
		if ( 'trashed' == $message && isset( $_REQUEST['ids'] ) 								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			$ids = preg_replace( '/[^0-9,]/', '', sanitize_text_field(wp_unslash($_REQUEST['ids'])));		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			
			echo '<a href="' . esc_url( wp_nonce_url( "edit.php?post_type=$post_type&doaction=undo&action=untrash&ids=$ids", "bulk-revision-queue" ) ) . '">' . esc_html__('Undo') . '</a> ';
		
		} elseif (!empty($bulk_messages['post'][$message])) {
			echo esc_html($bulk_messages['post'][$message]) . ' ';
		}
	}

	if (!empty($any_messages)) {
		echo '</p></div>';
	}
	unset( $messages );

	if (!empty($_SERVER['REQUEST_URI'])) {
		$_SERVER['REQUEST_URI'] = remove_query_arg( array( 'locked', 'skipped', 'updated', 'approved_count', 'published_count', 'deleted', 'trashed', 'untrashed' ), esc_url(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI']))) );
	}
}

?>
<div class="wrap pressshack-admin-wrapper revision-archive">
	<header>
		<h1 class="wp-heading-inline">
			<span class="dashicons dashicons-backup"></span>
			<?php
			esc_html_e( 'Past Revisions', 'revisionary' );
			$wp_list_table->filters_in_heading();
			?>
		</h1>
		<?php $wp_list_table->search_in_heading(); ?>
	</header>
	<form method="get">
		<div class="revisionary-list-header-controls">
	<?php $wp_list_table->views(); ?>
			<?php $wp_list_table->search_box( esc_html__( 'Search Revisions', 'revisionary' ), 'revision' ); ?>
		</div>
		<?php
		$wp_list_table->hidden_input();
		if ( $past_empty_unfiltered ) :
			$features_url = add_query_arg(
				[
					'page' => 'revisionary-settings',
					'ppr_tab' => 'ppr-tab-post_types',
				],
				admin_url( 'admin.php' )
			) . '#ppr-tab-post_types';
			$past_revisions_url = add_query_arg(
				[
					'page' => 'revisionary-settings',
					'ppr_tab' => 'ppr-tab-archive',
				],
				admin_url( 'admin.php' )
			) . '#ppr-tab-archive';
		?>
			<div class="revisionary-scheduled-empty revisionary-past-empty">
				<div class="revisionary-scheduled-empty-header">
					<span class="revisionary-scheduled-empty-icon dashicons dashicons-backup" aria-hidden="true"></span>
					<h2><?php esc_html_e( 'About Past Revisions', 'revisionary' ); ?></h2>
				</div>
				<p><?php esc_html_e( 'Every time you edit a post or page, WordPress can save a backup copy for you, so you\'ll always have a history of your changes.', 'revisionary' ); ?></p>
				<ul>
					<li><?php
						printf(
							esc_html__( 'Visit %s to adjust which post types keep Past Revisions.', 'revisionary' ),
							'<a href="' . esc_url( $features_url ) . '">' . esc_html__( 'Revisions > Settings', 'revisionary' ) . '</a>'
						);
					?></li>
					<li><?php
						if ( defined( 'PUBLISHPRESS_REVISIONS_PRO_VERSION' ) ) {
							if ( rvy_get_option( 'archive_postmeta_on_edit' ) ) {
								printf(
									esc_html__( 'Revisions Pro is %s in Past Revisions.', 'revisionary' ),
									'<a href="' . esc_url( $past_revisions_url ) . '">' . esc_html__( 'configured to save custom fields', 'revisionary' ) . '</a>'
								);
							} else {
								printf(
									esc_html__( 'To save custom fields in Past Revisions, see %s.', 'revisionary' ),
									'<a href="' . esc_url( $past_revisions_url ) . '">' . esc_html__( 'Revisions > Settings > Past Revisions', 'revisionary' ) . '</a>'
								);
							}
						} else {
							esc_html_e( 'PublishPress Revisions Pro can also save your custom fields in Past Revisions, so nothing gets left behind.', 'revisionary' );
						}
					?></li>
				</ul>
			</div>
		<?php else : ?>
			<?php $wp_list_table->display(); ?>
		<?php endif; ?>
    </form>

	<?php do_action( 'revisionary_admin_footer' ); ?>
</div>
