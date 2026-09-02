<?php
namespace PublishPress;

/**
 * Builds payloads used by the dedicated compare_only screen.
 *
 * Loaded lazily only for Visual Post Compare REST requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Visual_Post_Compare_Dedicated_Payload_Builder {
	/**
	 * Builds the dedicated compare_only comparison set.
	 *
	 * @param WP_Post $revision       URL-selected comparison post.
	 * @param string  $comparison_key Optional sidebar definition key.
	 * @return array
	 */
	public static function build( \WP_Post $revision, $comparison_key = '' ) {
		/**
		 * Filters additional posts available on the compare_only selection slider.
		 *
		 * @param array<int|WP_Post> $posts Additional post IDs and/or WP_Post objects.
		 * @param WP_Post            $revision URL-selected comparison post.
		 */
		$arr = (array) apply_filters( 'visual_post_compare_listed_revisions', array(), $revision );

		if (!empty($arr['listed'])) {
			$listed = $arr['listed'];
		}

		if (!empty($arr['comparison_key'])) {
			$comparison_key = $arr['comparison_key'];
		}

		if (!$current_post_id = wp_is_post_revision($revision)) {
			if (rvy_in_revision_workflow($revision)) {
				$current_post_id = rvy_post_id($revision);
			}
		}

		if (!$current_post_id) {
			return [];
		}

		if (!$current_post = get_post($current_post_id)) {
			return [];
		}

		$active_revision_title = __('This was an update to a revision which is still in the workflow process.', 'revisionary');
		$from_revision_title = __('This was an update to a revision which was published after further editing.', 'revisionary');

		$comparison_posts = [];
		$seen             = array( $current_post->ID => true );

		foreach ( $listed as $post_item ) {
			$post = $post_item instanceof \WP_Post ? $post_item : get_post( absint( $post_item ) );
			if ( ! $post || isset( $seen[ $post->ID ] ) || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}

			$seen[ $post->ID ]  = true;
			$comparison_posts[] = $post;
		}

		if (empty($seen[ $post->ID ])) {
			$comparison_posts []= $revision;
		}



		// Core's revisions selector reverses the REST collection visually. Mirror
		// that behavior here: fixed current post first, then reversed comparison order.
		$slider_posts = array_merge( array( $current_post ), array_reverse( $comparison_posts ) );
		$slider_data  = array_map( array( __CLASS__, 'post_data' ), $slider_posts );

		return array(
			'presentation' => self::presentation_options( $comparison_key, $comparison_posts ),
			'current'      => self::post_data( $current_post ),
			'revision'     => self::post_data( $revision ),
			'posts'        => $slider_data,
			'selectedId'   => (int) $revision->ID,
		);
	}

	/**
	 * Returns presentation options for the dedicated comparison screen.
	 *
	 * @param string $key Optional comparison sidebar key.
	 * @return array
	 */
	private static function presentation_options( $key = '', $posts = [] ) {
		$definition = $key ? Visual_Post_Compare::comparison_sidebar_by_key( $key, $posts ) : null;
		if ( ! $definition ) {
			$definitions = Visual_Post_Compare::comparison_sidebar_definitions();
			$definition  = ! empty( $definitions ) ? reset( $definitions ) : array();
		}

		return array(
			'showRightStatus'  => isset( $definition['showRightStatus'] ) ? (bool) $definition['showRightStatus'] : true,
			'mimeTypeStatus' => isset( $definition['mimeTypeStatus'] ) ? (bool) $definition['mimeTypeStatus'] : false,
			'showModified'     => isset( $definition['showModified'] ) ? (bool) $definition['showModified'] : true,
			'showPostDate'     => isset( $definition['showPostDate'] ) ? (bool) $definition['showPostDate'] : true,
			'sliderPostDate'     => isset( $definition['sliderPostDate'] ) ? (bool) $definition['sliderPostDate'] : true,
			'modifiedPrefix'   => esc_html__( 'Modified: ', 'revisionary' ),
			'approvedDatePrefix'   => '',
			'postDatePrefix'   => isset( $definition['postDatePrefix'] ) ? (string) $definition['postDatePrefix'] : __( 'Post Date: ', 'revisionary' ),
			'showAuthor'       => isset( $definition['showAuthor'] ) ? (bool) $definition['showAuthor'] : true,
			'authorName'	   => __('Author: %s', 'revisionary'),
			'currentStatusCaption' => __('Status: %s', 'revisionary'),
			'actionCaption'	   => __('Action: %s', 'revisionary'),
			'currentCaption'   => esc_html__('Current', 'revisionary'),
			'approvedByCaption' => __('by: %s', 'revisionary'),
			'legendCaption' => esc_html__('Comparison legend', 'revisionary'),
			'addedCaption' => esc_html__('Added', 'revisionary'),
			'removedCaption' => esc_html__('Removed', 'revisionary'),
			'modifiedCaption' => esc_html__('Modified', 'revisionary'),
			'loadingComparison' => esc_html__('Loading comparison...', 'revisionary'),
			'revisionApplied' => esc_html__('The target post content has been replaced with the approved content.', 'revisionary'),
			'classicCompareCaption' => esc_html__('Classic compare screen', 'revisionary'),
			'settingsURL'	  => current_user_can('manage_options') ? admin_url('admin.php?page=revisionary-settings&ppr_tab=working_copy&ppr_subtab=revision-queue') : '',
			'settingsCaption' => current_user_can('manage_options') ? esc_html__('Visual comparison settings', 'revisionary') : '',
		);
	}

	/**
	 * Serializes one post for the standalone JavaScript application.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function post_data( \WP_Post $post ) {
		$revision_parent_id = wp_is_post_revision($post);

		$author   = get_userdata( $post->post_author );
		$can_edit = current_user_can( 'edit_post', $post->ID );
		$url      = $can_edit ? get_edit_post_link( $post->ID, 'raw' ) : get_permalink( $post );

		$status_obj = get_post_status_object($post->post_status);
		$mime_type_status_obj = get_post_status_object($post->post_mime_type);

		$_post = array(
			'id'                  => (int) $post->ID,
			'type'                => $post->post_type,
			'title'               => (string) $post->post_title,
			'content'             => $post->post_content,
			'status'			  => $post->post_status,
			'statusLabel'		  => ('inherit' == $post->post_status) ? esc_html__('Past Revision', 'revisionary') : ((!empty($status_obj) && !empty($status_obj->label)) ? (!rvy_get_option('permissions_compat') && rvy_in_revision_workflow($post->ID) ? $mime_type_status_obj->label : $status_obj->label) : $post->post_status),
			'mimeTypeStatusLabel' => ('inherit' == $post->post_status) ? esc_html__('Past Revision', 'revisionary') : ((!empty($mime_type_status_obj) && !empty($mime_type_status_obj->label)) ? $mime_type_status_obj->label : $post->post_mime_type),
			'modified'            => mysql_to_rfc3339( $post->post_modified ),
			'modifiedLabel'       => mysql2date( 'M j, Y g:i a', $post->post_modified, true ),
			'postDate'            => mysql_to_rfc3339( $post->post_date ),
			'postDateLabel'       => mysql2date( 'M j, Y g:i a', $post->post_date, true ),
			'author'              => (int) $post->post_author,
			'authorName'          => $author ? $author->display_name : '',
			'authorAvatar'        => get_option('show_avatars') ? esc_url_raw( get_avatar_url( $post->post_author, array( 'size' => 32 ) ) ) : '',
			'sliderModifiedLabel' => mysql2date( 'F j, Y g:i a', $post->post_modified, true ),
			'sliderModifiedTitle' => mysql2date( 'F j, Y g:i a', $post->post_modified, true ),
			'sliderPostDateLabel' => mysql2date( 'F j, Y g:i a', $post->post_date, true ),
			'sliderPostDateTitle' => mysql2date( 'F j, Y g:i a', $post->post_date, true ),
			'canEdit'             => (bool) $can_edit,
			'canApprove'		  => ($revision_parent_id) ?  (bool) current_user_can( 'edit_post', $revision_parent_id ) : (bool) current_user_can( 'approve_revision', $post->ID ),
			'url'                 => $url ? esc_url_raw( $url ) : '',
			'viewURL'			  => rvy_in_revision_workflow($post->ID) ? rvy_preview_url($post->ID) : get_permalink($post),
			'classicCompareURL'	  => rvy_compare_url($post->ID, ['use_visual' => false]),

			'direct_edit' => false,
			'from_revision_workflow' => false,
			'parent_in_revision_workflow' => false,
			'parent_from_revision_workflow' => false,
			'revision_action' => '',
			'approver' => 0,
		);

		if ('inherit' == $post->post_status) {
			$_post['approveCaption'] = esc_html__('Restore', 'revisionary');
			$_post['approvingCaption'] = esc_html__('Restoring', 'revisionary');

			unset($_post['viewURL']);

			$approver_id = 0;

			if ($published_gmt = get_post_meta($post->ID, '_rvy_published_gmt', true)) {
				$_post['from_revision_workflow'] = get_post_meta($post->ID, '_rvy_prev_revision_status', true);
				
				if (!$_post['from_revision_workflow']) {
					$_post['from_revision_workflow'] = true;
				}

			} elseif ($revision_status = rvy_in_revision_workflow($post->post_parent)) {
				$_post['parent_in_revision_workflow'] = $revision_status;
			
			} elseif ($revision_status = rvy_from_revision_workflow($post->post_parent)) {
				$_post['parent_from_revision_workflow'] = $revision_status;
			} else {
				$_post['direct_edit'] = true;
			}

			if ($_post['from_revision_workflow']) {
				switch ($_post['from_revision_workflow']) {
					case 'future-revision':
						$_post['revision_action'] = esc_html__('Scheduled Revision Publication', 'revisionary');
						break;

					default:
						$_post['revision_action'] = esc_html__('Revision Publication', 'revisionary');
				}
			} elseif ($_post['parent_in_revision_workflow']) {
				if ($status_obj = get_post_status_object($_post['parent_in_revision_workflow'])) {
					$status_label = $status_obj->label;
				} else {
					$status_label = $status_name;
				}

				$_post['revision_action'] = sprintf(esc_html__('Edit of %s', 'revisionary'), $status_label);

			} elseif ($_post['parent_from_revision_workflow']) {
				$_post['revision_action'] = esc_html__('Edit of published Revision', 'revisionary');

			} elseif ($_post['direct_edit']) {
				$_post['revision_action'] = esc_html__('Direct Edit', 'revisionary');
			}

			if ($_post['direct_edit']) {
				$approver_id = $post->post_author;

			} elseif ($_post['from_revision_workflow']) {
				$approver_id = get_post_meta($post->ID, '_rvy_approved_by', true);
			}

			if (!empty($approver_id)) {
				$_post['approver'] = esc_html(get_the_author_meta('display_name', $approver_id));
			}
		} elseif ('future-revision' == $post->post_mime_type) {
			$_post['approveCaption'] = esc_html__('Publish', 'revisionary');
			$_post['approvingCaption'] = esc_html__('Publishing', 'revisionary');
		} else {
			$_post['approveCaption'] = esc_html__('Approve', 'revisionary');
			$_post['approvingCaption'] = esc_html__('Approving', 'revisionary');
		}
		
		return $_post;
	}
}
