<?php
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
		if ( $message == 'trashed' && isset( $_REQUEST['ids'] ) ) {								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
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
		if ( $message == 'trashed' && isset( $_REQUEST['ids'] ) 								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		) {
			$ids = preg_replace( '/[^0-9,]/', '', sanitize_text_field($_REQUEST['ids']));		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			
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
		$_SERVER['REQUEST_URI'] = remove_query_arg( array( 'locked', 'skipped', 'updated', 'approved_count', 'published_count', 'deleted', 'trashed', 'untrashed' ), esc_url(esc_url_raw($_SERVER['REQUEST_URI'])) );
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
	<?php $wp_list_table->views(); ?>
	<form method="get">
		<?php
		$wp_list_table->search_box( __( 'Search Revisions', 'revisionary' ), 'revision' );
		$wp_list_table->hidden_input();
		$wp_list_table->display();
		?>
    </form>

	<?php do_action( 'revisionary_admin_footer' ); ?>
</div>
