<?php

/*
 * Legacy functions for listing Past Revisions, with bulk deletion
 */

/**
 * @package     PublishPress\Revisions\RevisionManagerUI
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2019 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */
function rvy_metabox_notification_list() {
		global $revisionary;

		if (defined('PUBLISHPRESS_VERSION') && version_compare(PUBLISHPRESS_VERSION, '4.6-beta', '>=') && rvy_get_option('use_publishpress_notifications')
		) {
            return;
        }

		$notify_editors = (string) rvy_get_option('pending_rev_notify_admin');
		$notify_author = (string) rvy_get_option('pending_rev_notify_author');
	
		if ( ( '1' !== $notify_editors ) && ( '1' !== (string) $notify_author ) )
			return;
		
		$object_id = rvy_detect_post_id();

		require_once( dirname(REVISIONARY_FILE) . '/revision-workflow_rvy.php' );
		$result = Rvy_Revision_Workflow_UI::default_notification_recipients($object_id);

		foreach (['default_ids', 'post_publishers', 'publisher_ids'] as $var) {
            $$var = $result[$var];
        }

		require_once('agents_checklist_rvy.php');
		
		echo("<div id='rvy_cclist_pending_revision'>");
		
		if ( $default_ids ) {
			RevisionaryAgentsChecklist::agents_checklist( 'user', $post_publishers, 'prev_cc', $default_ids );
		} else {
			if ( ( 'always' === $notify_editors ) && $publisher_ids )
				esc_html_e( 'Publishers will be notified (but cannot be selected here).', 'revisionary' );
			else
				esc_html_e( 'No email notifications will be sent.', 'revisionary' );
		}
		
		echo('</div>');
}

/**
 * Retrieve formatted date timestamp of a revision (linked to that revisions's page).
 *
 * @param int|object $revision Revision ID or revision object.
 * @param bool $link Optional, default is true. Link to revisions's page?
 * @return string i18n formatted datetimestamp or localized 'Current Revision'.
 */
function rvy_post_revision_title( $revision, $link = true, $date_field = 'post_date', $args = array() ) {
	global $revisionary;
	
	$defaults = array( 'post' => false, 'format' => 'list' );
	$args = array_merge( $defaults, (array) $args );
	foreach ( array_keys( $defaults ) as $var ) { $$var = $args[$var]; }
	
	if ( ! is_object($revision) )
		if ( !$revision = get_post( $revision ) )
			return $revision;

	$public_types = array_keys($revisionary->enabled_post_types);
	$public_types []= 'revision';
	
	if ( ! in_array( $revision->post_type, $public_types ) )
		return false;

	/* translators: revision date format, see http://php.net/date */
	$datef = _x( 'j F, Y @ g:i a', 'revision date format', 'revisionary' );
	
	$date = agp_date_i18n( $datef, strtotime( $revision->$date_field ) );
	
	// note: RS filter (un-requiring edit_published/private cap) will be applied to this cap check
	
	if ( $link ) {
		if ('inherit' == $revision->post_status) {
			$link = "revision.php?revision=$revision->ID";
		} else {
			$link = rvy_preview_url($revision);
		}

		$date = "<a href='$link' target='_blank'>$date</a>";
	}

	$status_obj = get_post_status_object( $revision->post_status );
	
	if ( $status_obj && ( $status_obj->public || $status_obj->private ) ) {
		$currentf  = esc_html__( '%1$s (Current)', 'revisionary' );
		$date = sprintf( $currentf, $date );
		
	} elseif ( rvy_post_id($revision->ID) . "-autosave" === $revision->post_name ) {
		$autosavef = esc_html__( '%1$s (Autosave)', 'revisionary' );
		$date = sprintf( $autosavef, $date );
	}

	if ( in_array( $revision->post_status, array( 'inherit', 'pending-revision' ) ) && $post && ( 'list' == $format ) && ( 'post_modified' == $date_field ) ) {
		if ( $post->post_date != $revision->post_date ) {
			$datef = _x( 'j F, Y, g:i a', 'revision schedule date format', 'revisionary' );
			$revision_date = agp_date_i18n( $datef, strtotime( $revision->post_date ) );
		
			if ( 'pending-revision' == $revision->post_status ) {
				$currentf  = __( '%1$s <span>(Requested publication: %2$s)</span>', 'revisionary' );
			} else {
				$currentf  = __( '%1$s <span>(Publish date: %2$s)</span>', 'revisionary' );
			}

			$date = sprintf( $currentf, $date, $revision_date );
		}
	}
	
	return $date;
}

/**
 * Display list of a post's revisions (modified by PublishPress to include view links).
 *
 * Can output either a UL with edit links or a TABLE with diff interface, and
 * restore action links.
 *
 * Second argument controls parameters:
 *   (bool)   parent : include the parent (the "Current Revision") in the list.
 *   (string) format : 'list' or 'form-table'.  'list' outputs UL, 'form-table'
 *                     outputs TABLE with UI.
 *   (int)    right  : what revision is currently being viewed - used in
 *                     form-table format.
 *   (int)    left   : what revision is currently being diffed against right -
 *                     used in form-table format.
 *
 * @uses wp_get_post_revisions()
 * @uses wp_post_revision_title()
 * @uses get_edit_post_link()
 * @uses get_the_author_meta()
 *
 * @todo split into two functions (list, form-table) ?
 *
 * @param int|object $post_id Post ID or post object.
 * @param string|array $args See description {@link wp_parse_args()}.
 * @return null
 */
function rvy_list_post_revisions( $post_id = 0, $status = '', $args = null ) {
	if ( !$post = get_post( $post_id ) )
		return;

	$defaults = array( 'parent' => false, 'right' => false, 'left' => false, 'format' => 'list', 'type' => 'all', 'echo' => true, 'date_field' => '', 'current_id' => 0 );
	$args = wp_parse_args( $args, $defaults );

	foreach( array_keys( $defaults ) as $var ) {
		if ( ! isset( $$var ) ) {
			$$var = ( isset( $args[$var] ) ) ? $args[$var] : $defaults[$var];
		}
	}

	// link to publish date in Edit Form metaboxes, but modification date in Revisions Manager table
	if ( ! $date_field  ) {
		if ( 'list' == $format ) {
			$date_field = 'post_modified';
			$sort_field = $date_field;
		} else {
			$date_field = 'post_modified';
			$sort_field = 'post_date';
		}
	} else {
		if ( ! $sort_field )
			$sort_field = $date_field;	
	}
			
	global $current_user, $revisionary;
	
	switch ( $type ) {
	case 'autosave' :
		if ( !$autosave = wp_get_post_autosave( $post->ID ) )
			return;
		$revisions = array( $autosave );
		break;
	case 'revision' : // just revisions - remove autosave later
	case 'all' :
	default :
		if ( !$revisions = rvy_get_post_revisions( $post->ID, $status, array( 'orderby' => $sort_field ) ) )
			return;
		break;
	}
	
	if ( $parent )
		array_unshift( $revisions, $post );

	$rows = '';
	$class = '';
	
	$can_edit_post = current_user_can('edit_post', $post->ID);
	
	$hide_others_revisions = ! $can_edit_post && empty($current_user->allcaps['list_others_revisions']) && rvy_get_option('revisor_hide_others_revisions');
	
	$count = 0;
	$left_checked_done = false;
	$right_checked_done = false;
	$can_delete_any = false;
	
	// TODO: should this buffer listed revision IDs instead of post ID ?
	if ( defined('RVY_CONTENT_ROLES') ) {
		$revisionary->content_roles->add_listed_ids( 'post', $post->post_type, $post->ID );
	}
	
	foreach ( $revisions as $revision ) {
		if ( $status && ( $status != $revision->post_status ) ) 		 // support arg to display only past / pending / future revisions
			if ( ('revision' == $revision->post_type) || rvy_in_revision_workflow($revision) )  // but always display current rev
				continue;
		
		if ( 'revision' === $type && wp_is_post_autosave( $revision ) )
			continue;
			
		if ( $hide_others_revisions && ( ( 'revision' == $revision->post_type ) || rvy_in_revision_workflow($revision) ) && !rvy_is_post_author($revision) )
			continue;
		
		$date = rvy_post_revision_title( $revision, true, $date_field, compact( 'post', 'format' ) );

		$name = get_the_author_meta( 'display_name', $revision->post_author );

		if ( 'form-table' == $format ) {
			if ( ! $left_checked_done ) {
				if ( $left )
					$left_checked = ( $left == $revision->ID ) ? ' checked="checked"' : '';
				else
					$left_checked = ( $right == $revision->ID ) ? '' : ' checked="checked"';
			}
					
			if ( ! $right_checked_done ) {
				if ( $right )
					$right_checked = ( $right == $revision->ID ) ? ' checked="checked"' : '';
				else
					$right_checked = $left_checked ? '' : ' checked="checked"';
			}
			
			$actions = '';
			if ( $revision->ID == $current_id )
				$class = "rvy-revision-row rvy-current-revision"; 
			elseif ( $class )
				$class = "rvy-revision-row";
			else
				$class = "rvy-revision-row alternate"; 
			
			$datef = __awp( 'M j, Y @ g:i a' );
			
			$rows .= "<tr class='" . esc_attr($class) . "'>";

			if ( $post->ID != $revision->ID ) {
				if ('inherit' == $revision->post_status) {
					// @todo: need this case?
					$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';
					$preview_url = add_query_arg($preview_arg, '1', get_post_permalink( $revision->ID ) . '&post_type=revision' );
				} else {
					$preview_url = rvy_preview_url($revision, ['post_type' => $post->post_type]);
				}

				$rows .= "<td>$date</td>";

				$rows .= "<td>"
				. '<a href="' . esc_url($preview_url) . '" title="' . esc_attr( sprintf( esc_html__( 'Preview &#8220;%s&#8221;' ), $revision->post_title ) ) . '" rel="permalink">' . esc_html__( 'Preview' ) . '</a>'
				. "</td>";

				$rows .= "<td>" . esc_html($name) . "</td>";

				$rows .= "<td class='action-links'>";
				
				if ( $can_edit_post ) {
					if ( 'future-revision' == $status ) {
						$link = "admin.php?page=rvy-revisions&amp;action=unschedule&amp;revision={$revision->ID}";
						$rows .= '<a href="' . esc_url(wp_nonce_url( $link, 'unschedule-revision_' . $revision->ID )) . '" class="rvy-unschedule">' . esc_html__('Unschedule') . '</a>&nbsp;|&nbsp;';
					}
					
					if ('inherit' == $status) {
						$link = "admin.php?page=rvy-revisions&amp;action=delete&amp;revision={$revision->ID}";

						$delete_msg = esc_html__( "The revision will be deleted. Are you sure?", 'revisionary' );

						$rows .= '<a href="' . esc_url(wp_nonce_url( $link, 'delete-revision_' . $revision->ID )) . '" class="rvy-delete" onclick="' 
						. "javascript:if (confirm('" . esc_attr($delete_msg) . "')) {return true;} else {return false;}"
						. '" >' . esc_html(__awp('Delete')) 
						. '</a>';
					}
				}
				
				$rows .= '</td>';
			} else {
				// wp_post_revision_title() returns edit post link for current rev.  Convert it to a revisions.php link for viewing here like the rest
				if ( $post->ID == $revision->ID ) {
					$date = str_replace( "{$post->post_type}.php", 'revision.php', $date );
					$date = str_replace( 'action=edit', '', $date );
					$date = str_replace( 'post=', 'revision=', $date );
					$date = str_replace( '?&amp;', '?', $date );
					$date = str_replace( '?&', '?', $date );

					$date = str_replace( '&revision=', "&amp;revision_status=$status&amp;revision=", $date );
					$date = str_replace( '&amp;revision=', "&amp;revision_status=$status&amp;revision=", $date );
				}
				
				$rows .= "<td>$date</td>";

				$rows .= "<td>"
				. '<a href="' . esc_url(site_url("?p={$revision->ID}&amp;mark_current_revision=1")) . '" target="_blank">' . esc_html(__awp( 'Preview' )) . '</a>'
				. "</td>";

				$rows .= "<td>" . esc_html($name) . "</td>";
				$rows .= "<td class='action-links'></td>";
			}


			if ( $post->ID != $revision->ID 
			&& ( $can_edit_post || ( ('pending-revision' == $status) && rvy_is_post_author($revision) ) )	// allow submitters to delete their own still-pending revisions
			) {
				$rows .= "<td style='text-align:right'><input class='rvy-rev-chk' type='checkbox' name='delete_revisions[]' value='" . esc_attr($revision->ID) . "' /></td>";
				$can_delete_any = true;
			} else
				$rows .= "<td></td>";
			
			$rows .= "</tr>";
			
			if ( $left_checked ) {
				$left_checked = '';
				$left_checked_done = true;
			}
			
			if ( $right_checked ) {
				$right_checked = '';
				$right_checked_done = true;
			}	
			
		} else {
			/* translators: post revision: 1: when, 2: author name */
			$rows .= "<li>" . sprintf( _x( '%1$s by %2$s', 'post revision' ), $date, esc_html($name) ) . "</li>";
		}
		
		$count++;
	}
	
	if ( 'form-table' == $format ) : 
		if ( $count > 1 ) :
	?>
<form action="" method="post">
<?php
wp_nonce_field( 'rvy-revisions' ); 
?>

<?php
// add Ajax goodies we need for fancy publish date editing in Revisions Manager and role duration/content date limit editing Bulk Role Admin
?>
<script type="text/javascript">
/* <![CDATA[ */
jQuery(document).ready( function($) {
	$('#rvy-rev-checkall').on('click', function() {
		$('.rvy-rev-chk').attr( 'checked', this.checked );
	});
});
/* ]]> */
</script>

<table class="widefat post-revisions" cellspacing="0">
	<col class="rvy-col1" />
	<col class="rvy-col2" />
	<col class="rvy-col3" />
	<col class="rvy-col4" />
	<col class="rvy-col5" />
<thead>
<tr>
	<th scope="col"><?php 

esc_html_e( 'Modified Date', 'revisionary' ); 
?></th>
	<th scope="col"></th>
	<th scope="col"><?php echo esc_html(__awp( 'Author' )); ?></th>
	<th scope="col" class="action-links"><?php echo esc_html_e( 'Actions' ); ?></th>
	<th scope="col"  style='text-align:right'><input id='rvy-rev-checkall' type='checkbox' name='rvy-rev-checkall' value='' /></th>
</tr>
</thead>
<tbody>

<?php 
// output variables escaped upstream
echo $rows; 														// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
?>

</tbody>
</table>

<?php if( $can_delete_any ):?>
<br />
<div class="alignright actions">
<select name="action">
<option value="" selected="selected"><?php esc_html_e('Bulk Actions'); ?></option>
<option value="bulk-delete"><?php esc_html_e('Delete'); ?></option>
</select>
<input type="submit" value="<?php echo esc_attr__('Apply'); ?>" name="rvy-action" id="rvy-action" class="button-secondary action" />
</div>
<?php endif; ?>

</form>

<?php
	   endif; // more than one table row displayed
	   
	   // we echoed the table, now return row count
	   return ( $count );
	   
	else :
		// return / echo a simple list
		if ( $echo ) {
			if ($rows) {
				// output variables escaped upstream
				echo "<ul class='post-revisions'>\n$rows</ul>";		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			return $count;	
		} else
			if ($rows) {
				return "<ul class='post-revisions'>\n$rows</ul>";
			} else {
				return '';
			}

		   // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
	endif; // list or table
}
