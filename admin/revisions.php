<?php

if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die( 'This page cannot be called directly.' );
	
/**
 * @package     PublishPress\Revisions\RevisionManager
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2019 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */

include_once( dirname(__FILE__).'/revision-ui_rvy.php' ); 

global $revisionary;

if ( defined( 'FV_FCK_NAME' ) && current_user_can('activate_plugins') ) {
	echo( '<div class="error">' );
	esc_html_e( "<strong>Note:</strong> For visual display of revisions, add the following code to foliopress-wysiwyg.php:<br />&nbsp;&nbsp;if ( strpos( $" . "_SERVER['REQUEST_URI'], 'admin.php?page=rvy-revisions' ) ) return;", 'revisionary');
	echo( '</div><br />' );
}

if ( ! empty($_GET['revision']) )													//phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$revision_id = absint($_GET['revision']);										//phpcs:ignore WordPress.Security.NonceVerification.Recommended

if ( ! empty($_GET['left']) )														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$left = absint($_GET['left']);													//phpcs:ignore WordPress.Security.NonceVerification.Recommended
else
	$left = '';

if ( ! empty($_GET['right']) )														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$right = absint($_GET['right']);												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
else
	$right = '';
	
$revision_status = 'inherit';

if ( ! empty($_GET['action']) )														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$_action = sanitize_key($_GET['action']);										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
else
	$_action = '';

if ( ! empty($_GET['restored_post'] ) ) {											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$revision_id = (int) $_GET['restored_post'];									//phpcs:ignore WordPress.Security.NonceVerification.Recommended
}

if ( empty($revision_id) && ! $left && ! $right ) {
	echo( '<div><br />' );

	if (!empty($_REQUEST['action']) && ('revise' == $_REQUEST['action'])) {			//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// Fallback error message, in case redirect is missed
		if (!empty($_REQUEST['post'])) {											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$_post_id = intval($_REQUEST['post']);									//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$arr = rvy_post_revision_blocked($_post_id);
		}

		if (!empty($arr) && is_array($arr) && !empty($arr['description'])) {
			echo esc_html($arr['description']);
		} else {
			esc_html_e( 'Revision of this post is not allowed.', 'revisionary');
		}
	} else {
		esc_html_e( 'No revision specified.', 'revisionary');
	}

	echo( '</div>' );
	return;
}

$revision_status_captions = array( 
	'inherit' => esc_html__( 'Past', 'revisionary' ), 
	'pending-revision' => __awp('Pending', 'revisionary'), 
	'future-revision' => __awp( 'Scheduled', 'revisionary' ) 
);

if( 'edit' == $_action )
	$_action = 'view';

switch ( $_action ) :
case 'diff' :
	break;
case 'view' :
default :
	$left = 0;
	$right = 0;
	$h2 = '';
	
	if ( ! $revision = wp_get_post_revision( $revision_id ) ) {
		if ($revision = get_post($revision_id)) {
			if (!rvy_in_revision_workflow($revision)) {
				$revision = false;
			}
		}
	}

	if ( ! $revision ) {
		// Support published post/page in revision argument
		if ( ! $rvy_post = get_post( $revision_id) )
			break;

		if ( ! in_array( $rvy_post->post_type, array_keys($revisionary->enabled_post_types) ) ) {
			$rvy_post = '';  // todo: is this necessary?
			break;
		}

		// revision_id is for a published post.  List all its revisions - either for type specified or default to past
		if ( ! $revision_status )
			$revision_status = 'inherit';

		if (!current_user_can('edit_post', $rvy_post->ID) && !rvy_is_post_author($rvy_post))
			wp_die();

	} else {
		if ( !$rvy_post = get_post( rvy_post_id($revision->ID) ) )
			break;

		// actual status of compared objects overrides any revision_Status arg passed in
		$revision_status = $revision->post_mime_type;

		if (!current_user_can( 'approve_revision', $revision->ID ) /*&& !rvy_is_post_author($revision)*/ ) {
			wp_die();
		}
	}

	if ( $type_obj = get_post_type_object( $rvy_post->post_type ) ) {
		$edit_cap = 'edit_post';
		$edit_others_cap = $type_obj->cap->edit_others_posts;
		$delete_cap = $type_obj->cap->delete_post;
	}

	?>
	<h1>
	<?php printf(
			esc_html__('Revisions of %s', 'revisionary'), 
			"<a href='post.php?action=edit&post=" . esc_attr($rvy_post->ID) . "'>" . esc_html($rvy_post->post_title) . "</a>"
		);
	?>
	</h1>
	<?php

	// Sets up the diff radio buttons
	$right = $rvy_post->ID;

	if ( $revision ) {
		$left = $revision_id;
		$post_title = "<a href='post.php?action=edit&post=" . esc_attr($rvy_post->ID) . "'>" . esc_html($rvy_post->post_title) . "</a>";
	} else {
		$revision = $rvy_post;	
	}

	// pending revisions are newer than current revision
	if ( 'pending-revision' == $revision_status ) {
		$buffer_left = $left;
		$left  = $right;
		$right = $buffer_left;
	}

	break;
endswitch;


if ( empty($revision) && empty($right_revision) && empty($left_revision) ) {
	echo( '<div><br />' );
	esc_html_e( 'The requested revision does not exist.', 'revisionary');
	echo( '</div>' );
	return;
}

if ( ! $revision_status )
	$revision_status = 'inherit'; 	// default to showing past revisions
?>

<div class="wrap">

<?php
if (!$can_fully_edit_post = current_user_can( $edit_cap, $rvy_post->ID)) {
	// post-assigned Revisor role is sufficient to edit others' revisions, but post-assigned Contributor role is not
	$_can_edit_others = (!rvy_get_option('revisor_lock_others_revisions') || rvy_is_full_editor($rvy_post)) && current_user_can( $edit_others_cap, $rvy_post->ID);
}

if ( 'diff' != $_action ) {
	$can_edit = ( ( 'revision' == $revision->post_type ) || rvy_in_revision_workflow($revision) ) && (
		$can_fully_edit_post || 
		( (rvy_is_post_author($revision) || $_can_edit_others) && rvy_is_revision_status($revision->post_mime_type) && ('future-revision' != $revision->post_mime_type))
		);
}
?>

<?php
if ( $is_administrator = is_content_administrator_rvy() ) {
	global $wpdb;

	$base_status_csv = implode("','", array_merge(rvy_revision_base_statuses(), ['inherit']));
	$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$results = $wpdb->get_results( 
		$wpdb->prepare(
			"SELECT post_mime_type, COUNT( * ) AS num_posts FROM {$wpdb->posts}"
			. " WHERE post_status IN ('$base_status_csv')"																// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. " AND ((post_type = 'revision' AND post_status = 'inherit' AND post_parent = %d)"
			. " OR (post_type != 'revision' AND post_mime_type IN ('$revision_status_csv') AND comment_count = %d))"	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			. " GROUP BY post_mime_type",
			$rvy_post->ID,
			$rvy_post->ID
		)
	);
	
	$num_revisions = array( '' => 0, 'pending-revision' => 0, 'future-revision' => 0 );
	foreach( $results as $row ) {
		$num_revisions[$row->post_mime_type] = $row->num_posts;
	}

	$num_revisions['inherit'] = $num_revisions[''];
	unset($num_revisions['']);

	$num_revisions = (object) $num_revisions;
}

echo '<ul class="subsubsub">';
foreach ( array_keys($revision_status_captions) as $_revision_status ) {
	$_post_id = ( ! empty($rvy_post->ID) ) ? $rvy_post->ID : $revision_id;
	
	if ('inherit' == $_revision_status) {
		$_link = "admin.php?page=rvy-revisions&amp;revision={$_post_id}&amp;revision_status=$_revision_status";
		$target = '';
	} else {
		$_link = rvy_admin_url("admin.php?page=revisionary-q&published_post={$rvy_post->ID}&post_status={$_revision_status}");
		$target = "_blank";
	}

	$class = ( $revision_status == $_revision_status ) ? ' rvy_current_status rvy_select_status' : 'rvy_select_status';

	switch( $_revision_status ) {
		case 'inherit':
			$status_caption = esc_html__( 'Past Revisions', 'revisionary' );
			break;
		case 'draft-revision':
		case 'pending-revision':
		case 'future-revision':
			$status_caption = pp_revisions_status_label($_revision_status, 'plural');
			break;
	}
	
	if ( $is_administrator ) {
		if ($num_revisions->$_revision_status) {
			echo "<li class='" . esc_attr($class) . "'><a href='" . esc_url($_link) . "' target='" . esc_attr($target) . "'>";
			
			$span_style = ('inherit' == $_revision_status) ? 'font-weight:bold' : '';

			printf( 
				esc_html__( '%1$s %2$s (%3$s)%4$s', 'revisionary' ), 
				"<span class='count' style='" . esc_attr($span_style) . "'>",
				esc_html($status_caption), 
				esc_html(number_format_i18n( $num_revisions->$_revision_status )),
				'</span>'
			) ;
			
			echo '</a></li>&nbsp;&nbsp;';
		}
	} else {
		echo "<li class='" . esc_attr($class) . "'><a href='" . esc_url($_link) . "' target='" . esc_attr($target) . "'>" . esc_html($status_caption) . '</a></li>';
	}
}

echo '</ul>';

$args = array( 'format' => 'form-table', 'parent' => true, 'right' => $right, 'left' => $left, 'current_id' => isset($revision_id) ? $revision_id : 0 );

$count = rvy_list_post_revisions( $rvy_post, $revision_status, $args );
if ( $count < 2 ) {
	echo( '<br class="clear" /><p>' );
	printf( esc_html__( 'no %s revisions available.', 'revisionary'), esc_html(strtolower($revision_status_captions[$revision_status])) );
	echo( '</p>' );
}

?>

</div>
