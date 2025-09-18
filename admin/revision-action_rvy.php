<?php
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die( 'This page cannot be called directly.' );

add_action( '_wp_put_post_revision', 'rvy_review_revision' );

/**
 * @package     PublishPress\Revisions\RevisionaryAction
 * @author      PublishPress <help@publishpress.com>
 * @copyright   Copyright (c) 2025 PublishPress. All rights reserved.
 * @license     GPLv2 or later
 * @since       1.0.0
 */
function rvy_revision_diff() {
}

function rvy_revision_create($post_id = 0, $args = []) {
	if (!$post_id) {
		if (isset($_REQUEST['post'])) {									//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post_id = (int) $_REQUEST['post'];							//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			return;
		}
	}

	if (!rvy_post_revision_supported($post_id)) {
		return;
	}

	$main_post_id = rvy_in_revision_workflow($post_id) ? rvy_post_id($post_id) : $post_id;

	if (!empty($args['force']) || current_user_can('copy_post', $main_post_id)) {
		require_once( dirname(REVISIONARY_FILE).'/revision-creation_rvy.php' );
		$rvy_creation = new PublishPress\Revisions\RevisionCreation();

		$revision_status = (rvy_get_option('auto_submit_revisions') && current_user_can('edit_post', $main_post_id)) ? 'pending-revision' : 'draft-revision';
		$revision_id = $rvy_creation->createRevision($post_id, $revision_status, $args);
	} else {
		$revision_id = 0;
	}

	return $revision_id;
}

// Submits a revision (moving it to pending-revision status)
function rvy_revision_submit($revision_id = 0) {
	global $wpdb, $revisionary;

	// PublishPress Authors: Don't alter revision author
	remove_action(
		'save_post',
		[
			'MultipleAuthors\\Classes\\Post_Editor',
			'action_save_post_set_initial_author',
		],
		10,
		3
	);

	if (!$revision_id) {
		$batch_process = false;

		if (empty($_GET['revision'])) {
			return;
		}

		$revision_id = (int) $_GET['revision'];
	} else {
		$batch_process = true;
	}

	$redirect = '';

	do {
		if (!$revision = get_post($revision_id)) {
			break;
		}

		$old_revision_status = $revision->post_mime_type;

		if (!in_array($revision->post_status, array_merge(['draft', 'pending'], rvy_revision_statuses()))) {
			break;
		}

		if (!current_user_can('administrator') && !current_user_can('set_revision_pending-revision', $revision_id)) {
			break;
		}

		if (!$published_id = rvy_post_id($revision_id)) {
			break;
		}

		if (!$post = get_post($published_id)) {
			break;
		}

		if (!$batch_process) {
			check_admin_referer( "submit-post_$post->ID|$revision->ID" );
		}

		$status_obj = get_post_status_object( $revision->post_mime_type );

		// safeguard: make sure this hasn't already been published
		if ( empty($status_obj->public) && empty($status_obj->private) ) {
			$revision_before = (object) (array) $revision;
			
			$post_mime_type = 'pending-revision';
			$status = (rvy_get_option('permissions_compat_mode')) ? $post_mime_type : 'pending';

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update($wpdb->posts, ['post_status' => $status, 'post_mime_type' => $post_mime_type], ['ID' => $revision_id]);

			$revision->post_status = $status;
			$revision->post_mime_type = $post_mime_type;

			if (defined('REVISIONARY_LIMIT_IGNORE_UNSUBMITTED')) {
				rvy_update_post_meta($published_id, '_rvy_has_revisions', true);
			}

			clean_post_cache($revision_id);

			require_once( dirname(REVISIONARY_FILE).'/revision-workflow_rvy.php' );
			$rvy_workflow_ui = new Rvy_Revision_Workflow_UI();

			$args = ['revision_id' => $revision->ID, 'published_post' => $post, 'object_type' => $post->post_type];
			$rvy_workflow_ui->do_notifications('pending-revision', 'pending-revision', (array) $post, $args );
		} else {
			$approval_error = true;
		}

		if ( !empty($_REQUEST['rvy_redirect']) && 'edit' == $_REQUEST['rvy_redirect'] ) {
			$last_arg = array( 'revision_action' => 1, 'published_post' => $revision->ID );
			$redirect = add_query_arg( $last_arg, "post.php?post=$revision_id&action=edit" );
		} else {
			$redirect = rvy_preview_url($revision, ['post_type' => $post->post_type]);
		}

	} while (0);

	if (!$batch_process) {	
		if ( ! $redirect ) {
			if ( ! empty($post) && is_object($post) && ( 'post' != $post->post_type ) ) {
				$redirect = "edit.php?post_type={$post->post_type}";
			} else
				$redirect = 'edit.php';
		}
	}

	if (empty($approval_error)) {
		$published_id = rvy_post_id($revision_id);
		do_action( 'revision_submitted', $published_id, $revision_id, $old_revision_status );
		do_action( 'revisionary_submitted', $published_id, $revision, $revision_before );
	}

	if (!$batch_process) {
		wp_redirect( $redirect );
		exit;
	}

	if (empty($approval_error)) {
		return true;
	}
}

// Unsubmits a revision (moving it back to draft-revision status)
function rvy_revision_decline($revision_id = 0) {
	global $wpdb, $revisionary;

	if (!$revision_id) {
		$batch_process = false;

		if (empty($_GET['revision'])) {
			return;
		}

		$revision_id = (int) $_GET['revision'];
	} else {
		$batch_process = true;
	}

	$redirect = '';

	do {
		if (!$revision = get_post($revision_id)) {
			break;
		}

		if (!in_array($revision->post_status, array_merge(['draft', 'pending'], rvy_revision_statuses()))) {
			break;
		}

		if (!current_user_can('administrator') && !current_user_can('set_revision_pending-revision', $revision_id)) {
			break;
		}

		if (!$published_id = rvy_post_id($revision_id)) {
			break;
		}

		if (!$post = get_post($published_id)) {
			break;
		}

		if (!$batch_process) {
			check_admin_referer('decline-revision');
		}

		$revision_before = (object) (array) $revision;

		$status_obj = get_post_status_object( $revision->post_mime_type );

		$post_mime_type = apply_filters('revisionary_revision_decline_status', 'draft-revision', $revision_id);

		if (($post_mime_type != 'draft-revision') && rvy_get_option('use_publishpress_notifications')) {
			$status = $post_mime_type;
		} else {
			$status = apply_filters('revisionary_post_revision_status', 'draft', $post_mime_type, $revision_id);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$wpdb->posts, 
			[
				'post_status' => $status, 
				'post_mime_type' => $post_mime_type
			], 
			['ID' => $revision_id]
		);

		$revision->post_status = $status;
		$revision->post_mime_type = $post_mime_type;

		clean_post_cache($revision_id);

		// @todo: notifications for revision decline

		// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
		/*
		require_once( dirname(REVISIONARY_FILE).'/revision-workflow_rvy.php' );
		$rvy_workflow_ui = new Rvy_Revision_Workflow_UI();

		$args = ['revision_id' => $revision->ID, 'published_post' => $post, 'object_type' => $post->post_type];
		$rvy_workflow_ui->do_notifications('pending-revision', 'pending-revision', (array) $post, $args );
		*/

		if ( !empty($_REQUEST['rvy_redirect']) && 'edit' == $_REQUEST['rvy_redirect'] ) {
			$last_arg = array( 'revision_action' => 1, 'published_post' => $revision->ID );
			$redirect = add_query_arg( $last_arg, "post.php?post=$revision_id&action=edit" );
		} else {
			$redirect = rvy_preview_url($revision, ['post_type' => $post->post_type]);
		}

	} while (0);

	if (!$batch_process) {	
		if ( ! $redirect ) {
			if ( ! empty($post) && is_object($post) && ( 'post' != $post->post_type ) ) {
				$redirect = "edit.php?post_type={$post->post_type}";
			} else
				$redirect = 'edit.php';
		}
	}

	clean_post_cache($revision->ID);

	if (empty($decline_error)) {
		do_action( 'revision_declined', $published_id, $revision->ID );
		do_action( 'revisionary_declined', $published_id, $revision, $revision_before );
	}

	if (!$batch_process) {
		wp_redirect( $redirect );
		exit;
	}

	if (empty($decline_error)) {
		return true;
	}
}

// schedules publication of a revision ( or publishes if requested publish date has already passed )
function rvy_revision_approve($revision_id = 0, $args = []) {
	global $current_user, $wpdb;

	if (!$revision_id) {
		$batch_process = false;

		if (empty($_GET['revision'])) {
			return;
		}

		$revision_id = (int) $_GET['revision'];
	} else {
		$batch_process = true;
	}

	$redirect = '';
	
	$blogname = wp_specialchars_decode( get_option('blogname'), ENT_QUOTES );
	
	do {
		if ( !$revision = wp_get_post_revision( $revision_id ) ) {
			if (!$revision = get_post($revision_id)) {
				break;
			}
		}

		$published_id = ('revision' == $revision->post_type) ? $revision->post_parent : rvy_post_id($revision_id);

		if (!$published_id) {
			break;
		}

		if (!$post = get_post($published_id)) {
			break;
		}

		if (!current_user_can('edit_post', $post->ID)) {
			if ($batch_process) {
				break;
			} else {
				return;
			}
		}

		if (!$batch_process) {
			check_admin_referer( "approve-post_$post->ID|$revision->ID" );
		}

		if (!empty($_REQUEST['editor']) && !defined('REVISIONARY_IGNORE_AUTOSAVE')) {
			if (!\PublishPress\Revisions\Utils::isBlockEditorActive()) {
				if ($autosave_post = \PublishPress\Revisions\Utils::get_post_autosave($revision_id, $current_user->ID)) {
					if (strtotime($autosave_post->post_modified_gmt) > strtotime($revision->post_modified_gmt)) {
						$set_post_properties = [       
							'post_content',
							'post_content_filtered',
							'post_title',
							'post_excerpt',
						];
						
						$update_data = [];
	
						foreach($set_post_properties as $prop) {
							if (!empty($autosave_post) && !empty($autosave_post->$prop)) {
								$update_data[$prop] = $autosave_post->$prop;
							}
						}
	
						if ($update_data) {
							// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
							$wpdb->update($wpdb->posts, $update_data, ['ID' => $revision_id]);
						}
	
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->delete($wpdb->posts, ['ID' => $autosave_post->ID]);
					}
				}
			}
		}

		clean_post_cache($post->ID);

		if ($post && rvy_get_option('publish_by_revision')) {
			if (empty($post->post_name)) {
				$data = [];
				$data['post_name'] = wp_unique_post_slug(sanitize_title($post->post_title, $post->ID), $post->ID, 'publish', $post->post_type, $post->post_parent);

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update($wpdb->posts, ['post_name' => $data['post_name']], ['ID' => $post->ID]);
				clean_post_cache($post->ID);
			}
		}

		$published_url = get_permalink($post->ID);

		$type_obj = get_post_type_object($post->post_type);

		if ( empty( $_REQUEST['rvy_redirect'] ) && is_post_type_viewable($type_obj) ) {
			$redirect = $published_url;
		}

		$db_action = false;
		
		// If requested publish date is in the past or now, publish the revision
		if ( strtotime( $revision->post_date_gmt ) <= agp_time_gmt() ) {
			$status_obj = get_post_status_object( $revision->post_mime_type );

			if ( empty($status_obj->public) && empty($status_obj->private) ) {
				$db_action = true;
				
				if ('revision' == $revision->post_type) {
					// prep the revision to look like a normal one so WP doesn't reject it
					$data = array( 'post_status' => 'inherit', 'post_date' => $revision->post_modified, 'post_date_gmt' => $revision->post_modified );
					
					if ( class_exists('WPCom_Markdown') && ! defined( 'RVY_DISABLE_MARKDOWN_WORKAROUND' ) )
						$data['post_content_filtered'] = $revision->post_content;
					
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update( $wpdb->posts, $data, array( 'ID' => $revision->ID ) );
					
					wp_restore_post_revision( $revision->ID, array( 'post_content', 'post_title', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ) );

					rvy_format_content( $revision->post_content, $revision->post_content_filtered, $post->ID );
					
					clean_post_cache( $revision->ID );
				} else {
					$_result = rvy_apply_revision($revision->ID, $revision->post_mime_type);

					if (!$_result || is_wp_error($_result)) {
						// Go ahead with the normal redirect because the revision may have been approved / published already.
						// If revision does not exist, preview's Not Found will prevent false impression of success.
						$approval_error = true;
						break;
					}

					if (!empty($update_data)) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update($wpdb->posts, $update_data, ['ID' => $published_id]);
					}

					clean_post_cache($post->ID);
					$published_url = get_permalink($post->ID);
				}
			}

			$revision_status = '';
			$last_arg = array( 'revision_action' => 1, 'published_post' => $revision->ID );
			$scheduled = '';

		// If requested publish date is in the future, schedule the revision
		} else {
			if ( 'future-revision' != $revision->post_mime_type ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'future-revision' ), array( 'ID' => $revision->ID ) );
				
				rvy_update_next_publish_date(['revision_id' => $revision_id]);
				
				$db_action = true;
				
				clean_post_cache( $revision->ID );

				$revision_before = (object) (array) $revision;
				$revision->post_mime_type = 'future-revision';
				$revision->status = apply_filters('revisionary_post_revision_status', 'future', 'future-revision', $revision_id);

				do_action('revisionary_scheduled', $post->ID, $revision, $revision_before);
			} else {
				// this scheduled revision is already approved, so don't included in reported bulk approval count
				$approval_error = true;
			}

			$revision_status = 'future-revision';
			$last_arg = array( "revision_action" => 1, 'scheduled' => $revision->ID );
			$scheduled = $revision->post_date;

			update_post_meta($revision->ID, '_rvy_approved_by', $current_user->ID);
		}

		clean_post_cache($revision->ID);

		// Support workaround to prevent notification when an Administrator or Editor created the revision
        if (defined('REVISIONARY_LIMIT_ADMIN_NOTIFICATIONS')) {
			$user = ($current_user->ID != $revision->post_author) ? new WP_User($revision->post_author) : $current_user;

			if ($user && !empty($user->ID)) {
				foreach (['REVISIONARY_LIMIT_NOTIFICATION_SUBMITTER_ROLES', 'RVY_MONITOR_ROLES', 'SCOPER_MONITOR_ROLES'] as $const) {
					if (defined($const)) {
						$skip_notification_roles = array_map('trim', explode(',', constant($const)));
						break;
					}
				}

				if (empty($skip_notification_roles)) {
					$skip_notification_roles = ['editor', 'administrator'];
				}
			}

			if (array_intersect($user->roles, $skip_notification_roles)) {
				$skip_notification = true;
			}
		}

		$use_pp_notifications = defined('PUBLISHPRESS_VERSION') && version_compare(PUBLISHPRESS_VERSION, '4.6-beta', '>=') && rvy_get_option('use_publishpress_notifications');

		// Don't send approval notification on restoration of a past revision
		if (('revision' != $revision->post_type) && empty($skip_notification) && !$use_pp_notifications) {
			$type_obj = get_post_type_object( $post->post_type );
			$type_caption = $type_obj->labels->singular_name;

			$title = sprintf(esc_html__('[%s] Revision Approval Notice', 'revisionary' ), $blogname );
			$message = sprintf( esc_html__('A revision to the %1$s "%2$s" has been approved.', 'revisionary' ), $type_caption, $post->post_title ) . "\r\n\r\n";
			$message = str_replace($message, '&quot;', '"', $message);

			if ( $revisor = new WP_User( $revision->post_author ) )
				$message .= sprintf( esc_html__('The submitter was %1$s.', 'revisionary'), $revisor->display_name ) . "\r\n\r\n";

			if ( $scheduled ) {
				$datef = __awp( 'M j, Y @ g:i a' );
				$message .= sprintf( esc_html__('It will be published on %s', 'revisionary' ), agp_date_i18n( $datef, strtotime($revision->post_date) ) ) . "\r\n\r\n";
				
				if (rvy_get_option('revision_preview_links')) {
					$preview_link = rvy_preview_url($revision);
					$message .= esc_html__( 'Preview it here: ', 'revisionary' ) . $preview_link . "\r\n\r\n";
				}

				$message .= esc_html__( 'Editor: ', 'revisionary' ) . rvy_admin_url("post.php?post={$revision->ID}&action=edit") . "\r\n";
			} else {
				$message .= esc_html__( 'View it online: ', 'revisionary' ) . $published_url . "\r\n";	
			}
			
			if ( $db_action && rvy_get_option( 'rev_approval_notify_author' ) ) {
				if (function_exists('get_multiple_authors')) {
					$authors = get_multiple_authors($post);
				} else {
					$author = new WP_User($post->post_author);
					$authors = [$author];
				}

				foreach($authors as $author) {
					if ($author) {
						rvy_mail(
							$author->user_email, 
							$title, 
							$message, 
							[
								'revision_id' => $revision->ID, 
								'post_id' => $post->ID, 
								'notification_type' => 'revision-approval', 
								'notification_class' => 'rev_approval_notify_author'
							]
						);
					}
				}
			}
			
			if ( $db_action && rvy_get_option( 'rev_approval_notify_admin' ) && rvy_get_option('legacy_notifications') ) {
				require_once(dirname(REVISIONARY_FILE).'/revision-workflow_rvy.php');
				$admin_ids = apply_filters('revisionary_approval_notify_admin', Rvy_Revision_Workflow_UI::getRecipients('rev_approval_notify_admin', ['type_obj' => $type_obj, 'published_post' => $post]), ['post_type' => $type_obj->name, 'post_id' => $post->ID, 'revision_id' => $revision->ID]);

				foreach($admin_ids as $user_id) {
					if ($user = new WP_User($user_id)) {
						rvy_mail(
							$user->user_email, 
							$title, 
							$message, 
							[
								'revision_id' => $revision->ID, 
								'post_id' => $post->ID, 
								'notification_type' => 'revision-approval', 
								'notification_class' => 'rev_approval_notify_admin'
							]
						);
					}
				}
			}

			if ( $db_action && defined( 'RVY_NOTIFY_SUPER_ADMIN' ) && is_multisite() && rvy_get_option('legacy_notifications') ) {
				$super_admin_logins = get_super_admins();
				foreach( $super_admin_logins as $user_login ) {
					if ( $super = new WP_User($user_login) )
						rvy_mail( 
							$super->user_email, 
							$title, 
							$message, 
							[
								'revision_id' => $revision->ID, 
								'post_id' => $post->ID, 
								'notification_type' => 'revision-approval', 
								'notification_class' => 'rev_approval_notify_super_admin'
							]
						);
				}
			}
			
			if (($db_action || !empty($args['force_notify'])) && rvy_get_option( 'rev_approval_notify_revisor' ) && rvy_get_option('legacy_notifications')) {
				$title = sprintf(esc_html__('[%s] Revision Approval Notice', 'revisionary' ), $blogname );
				$message = sprintf( esc_html__('The revision you submitted for the %1$s "%2$s" has been approved.', 'revisionary' ), $type_caption, $revision->post_title ) . "\r\n\r\n";

				if (!defined('REVISIONARY_LEGACY_MESSAGE_QUOTES')) {
					$message = str_replace($message, '&quot;', '"', $message);
				}

				if ( $scheduled ) {
					$datef = __awp( 'M j, Y @ g:i a' );
					$message .= sprintf( esc_html__('It will be published on %s', 'revisionary' ), agp_date_i18n( $datef, strtotime($revision->post_date) ) ) . "\r\n\r\n";
					
					if (rvy_get_option('revision_preview_links')) {
						$preview_link = rvy_preview_url($revision);
						$message .= esc_html__( 'Preview it here: ', 'revisionary' ) . $preview_link . "\r\n\r\n";
					}

					$message .= esc_html__( 'Editor: ', 'revisionary' ) . rvy_admin_url("post.php?post={$revision->ID}&action=edit") . "\r\n";
				} else {
					$message .= esc_html__( 'View it online: ', 'revisionary' ) . $published_url . "\r\n";	
				}

				if ( $author = new WP_User( $revision->post_author, '' ) ) {
					rvy_mail( 
						$author->user_email, 
						$title, 
						$message, 
						[
							'revision_id' => $revision->ID, 
							'post_id' => $post->ID, 
							'notification_type' => 'revision-approval', 
							'notification_class' => 'rev_approval_notify_revisor'
						]
					);
				}
			}
		}

		$type_obj = get_post_type_object($post->post_type);

		if ( empty( $_REQUEST['rvy_redirect'] ) && ! $scheduled && is_post_type_viewable($type_obj) ) {
			$redirect = $published_url;

		} elseif ( !empty($_REQUEST['rvy_redirect']) && 'edit' == esc_url_raw($_REQUEST['rvy_redirect']) ) {
			$redirect = add_query_arg( $last_arg, "post.php?post=$revision_id&action=edit" );

		} elseif (is_post_type_viewable($type_obj)) {
			$redirect = rvy_preview_url($revision, ['post_type' => $post->post_type]);
		} else {
			$redirect = admin_url("post.php?post={$post->ID}&action=edit");
		}
		
	} while (0);
	
	clean_post_cache($revision_id);

	delete_metadata('post', $post->ID, '_elementor_element_cache');

	if (!empty($update_next_publish_date)) {
		rvy_update_next_publish_date(['revision_id' => $revision_id]);
	}

	if (!$batch_process) {	
		if ( ! $redirect ) {
			if ( ! empty($post) && is_object($post) && ( 'post' != $post->post_type ) ) {
				$redirect = "edit.php?post_type={$post->post_type}";
			} else
				$redirect = 'edit.php';
		}
	}

	if (empty($approval_error)) {
		do_action( 'revision_approved', $post->ID, $revision->ID );
	}

	if (!$batch_process) {
		wp_redirect( $redirect );
		exit;
	}

	if (empty($approval_error)) {
		return true;
	}
}

function rvy_revision_restore() {
	if (!isset($_GET['revision'])) {
		return;
	}

	$revision_id = (int) $_GET['revision'];
	$redirect = '';
	
	do {
		if ( !$revision = wp_get_post_revision( $revision_id ) )
			break;

		if ( !$post = get_post( $revision->post_parent ) )
			break;

		if (!current_user_can('edit_post', $revision->post_parent)) {
			break;
		}

		check_admin_referer( "restore-post_{$post->ID}|$revision->ID" );

		$published_url = get_permalink($post->ID);

		global $wpdb;
		
		$data = array( 'post_status' => 'inherit', 'post_date' => $revision->post_modified, 'post_date_gmt' => $revision->post_modified );
		
		if ( class_exists('WPCom_Markdown') && ! defined( 'RVY_DISABLE_MARKDOWN_WORKAROUND' ) )
			$data['post_content_filtered'] = $revision->post_content;
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update($wpdb->posts, $data, array('ID' => $revision->ID));
		
		wp_restore_post_revision( $revision->ID, array( 'post_content', 'post_title', 'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt' ) );

		// restore previous meta fields
		revisionary_copy_postmeta($revision, $post->ID);

		revisionary_copy_terms($revision, $post->ID);

		clean_post_cache($revision->ID);
		clean_post_cache($post->ID);

		delete_metadata('post', $post->ID, '_elementor_element_cache');

		rvy_format_content( $revision->post_content, $revision->post_content_filtered, $post->ID );

		if ( 'inherit' == $revision->post_status ) {
			$last_arg = array( 'revision_action' => 1, 'restored_post' => $post->ID );
		} else {
			$last_arg = array( 'revision_action' => 1, 'published_post' => $post->ID );
		}

		if (empty($_REQUEST['rvy_redirect'])) {
			$redirect = $published_url;

		} elseif ( 'edit' == $_REQUEST['rvy_redirect'] ) {
			$redirect = add_query_arg( $last_arg, "post.php?post={$post->ID}&action=edit" );
		} else {
			$redirect = add_query_arg( $last_arg, esc_url_raw($_REQUEST['rvy_redirect']) );
		}

	} while (0);

	if ( ! $redirect ) {
		if ( ! empty($post) && is_object($post) && ( 'post' != $post->post_type ) ) {
			$redirect = "edit.php?post_type={$post->post_type}";
		} else
			$redirect = 'edit.php';
	}

	wp_redirect( $redirect );
	exit;
}

function rvy_apply_revision( $revision_id, $actual_revision_status = '' ) {
	global $wpdb;
	
	if ( ! $revision = get_post( $revision_id ) ) {
		return $revision;
	}

	$original_revision_status = $revision->post_mime_type;

	if (!$published_id = $revision->comment_count) {
		if (! $published_id = rvy_post_id($revision_id)) {
			return false;
		}
	}

	if ('revision' == get_post_field('post_type', $published_id)) {
		return false;
	}

	$update = (array) $revision;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$published = $wpdb->get_row(
		$wpdb->prepare("SELECT * FROM $wpdb->posts WHERE ID = %d", $published_id)
	);

	if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION')) {
		if (!$published_authors = get_multiple_authors($published_id)) {
			if ($author = \MultipleAuthors\Classes\Objects\Author::get_by_user_id((int) $published->post_author)) {
				$published_authors = [$author];
			}
		}
	}

	// published post columns which should not be overwritten by revision values
	$update = array_merge(
		$update, 
		array(
			'ID' => $published->ID,
			'post_author' => $published->post_author,
			'post_type' => $published->post_type,
			'post_status' => $published->post_status,
			'comment_count' => $published->comment_count,
			'post_name' => $published->post_name,
			'guid' => $published->guid,
			'post_mime_type' => $published->post_mime_type
		)
	);

	if (
		((rvy_is_revision_status($revision->post_mime_type) && ('future-revision' != $revision->post_mime_type)) && !rvy_filter_option('pending_revision_update_post_date', ['revision_id' => $revision_id, 'post_id' => $published->ID]))
		|| (('future-revision' == $revision->post_mime_type) && !rvy_filter_option('scheduled_revision_update_post_date', ['revision_id' => $revision_id, 'post_id' => $published->ID]))
	) {
		// todo: how was post_date_gmt of published post previously set to zero?
		if (('0000-00-00 00:00:00' == $published->post_date_gmt) && ('0000-00-00 00:00:00' != $published->post_date)) {
			// reconstruct post_date_gmt from stored post_date
			$timestamp = strtotime($published->post_date);
			$zone_diff = strtotime(current_time('mysql', 'gmt')) - strtotime(current_time('mysql'));
			$set_date_gmt = gmdate('Y-m-d H:i:s', $timestamp + $zone_diff);
		} else {
			$set_date_gmt = $published->post_date_gmt;
		}

		$update = array_merge(
			$update, 
			array(
				'post_date' => $published->post_date,
				'post_date_gmt' => $set_date_gmt,
			)
		);
	}

	if (defined('FL_BUILDER_VERSION')) {
		// If Beaver Builder is active for this post, don't allow pending revision publication to strip terms
		if (get_post_meta($published->ID, '_fl_builder_data', true)) {
			$orig_terms = [];
			$orig_terms['post_tag'] = wp_get_object_terms($published->ID, 'post_tag', ['fields' => 'ids']);
			$orig_terms['category'] = wp_get_object_terms($published->ID, 'category', ['fields' => 'ids']);
		}
	}

	if (defined('POLYLANG_VERSION')) {
		$lang_terms = wp_get_object_terms($published->ID, 'post_translations', ['fields' => 'all']);

		$lang_descripts = [];

		foreach($lang_terms as $term) {
			$lang_descripts[$term->term_taxonomy_id] = $term->description;
		}
	}

	/**
	* Filter revision data before applying the revision.
	*
	* @param array $update Revision data
	* @param WP_Post $revision Revision being applied
	* @param Object $published Currently published post
	*/
	$update = apply_filters( 'revisionary_apply_revision_data', $update, $revision, $published );

	$revision_content = $update['post_content'];

	if (defined('REVISIONARY_APPLY_REVISION_WP_UPDATE')) {
		$post_id = wp_update_post( $update );
	} else {
		// update without filter, action applications
		$post_id = rvy_update_post( $update );
	}

	if ( ! $post_id || is_wp_error( $post_id ) ) {
		return $post_id;
	}

	if (!$set_slug = get_post_meta($revision_id, '_requested_slug', true)) {
		$set_slug = $published->post_name;
	}

	// Apply requested slug, if applicable. 
	// Otherwise, work around unexplained reversion of editor-modified post slug back to default format on some sites  @todo: identify plugin interaction
	$update_fields = ['post_name' => $set_slug, 'guid' => $published->guid, 'post_type' => $published->post_type, 'post_status' => $published->post_status, 'post_mime_type' => $published->post_mime_type];

	// Prevent wp_insert_post() from stripping inline html styles
	if (!defined('RVY_DISABLE_REVISION_CONTENT_PASSTHRU')) {
		$update_fields['post_content'] = $revision_content;
	}
	
	$_post = get_post($post_id);

	// prevent published post status being set to future when a scheduled revision is manually published before the stored post_date
	if ($_post && ($_post->post_status != $published->post_status)) {
		$update_fields['post_status'] = $published->post_status;
	}

	$update_fields = apply_filters('revisionary_apply_revision_fields', $update_fields, $revision, $published, $actual_revision_status);

	$revision_statuses = array_diff(
		rvy_revision_statuses(),
		['future-revision']
	);

	if (
		(in_array($revision->post_mime_type, $revision_statuses) && rvy_filter_option('pending_revision_update_modified_date', ['revision_id' => $revision_id, 'post_id' => $published->ID]))
		|| (('future-revision' == $revision->post_mime_type) && rvy_filter_option('scheduled_revision_update_modified_date', ['revision_id' => $revision_id, 'post_id' => $published->ID]))
	) {
		$post_modified = current_time('mysql');
		$post_modified_gmt = current_time('mysql', 1);
	} else {
		$post_modified = $published->post_modified;
		$post_modified_gmt = $published->post_modified_gmt;
	}

	$update_fields['post_modified'] = $post_modified;
	$update_fields['post_modified_gmt'] = $post_modified_gmt;

	if (
		(in_array($revision->post_mime_type, $revision_statuses) && rvy_filter_option('pending_revision_update_post_date', ['revision_id' => $revision_id, 'post_id' => $published->ID]))
		|| (('future-revision' == $revision->post_mime_type) && rvy_filter_option('scheduled_revision_update_post_date', ['revision_id' => $revision_id, 'post_id' => $published->ID]))
	) {
		$update_fields['post_date'] = current_time('mysql');
		$update_fields['post_date_gmt'] = current_time('mysql', 1);

	} elseif (!empty($update['post_date'])) {
		$update_fields['post_date'] = $update['post_date'];
		$update_fields['post_date_gmt'] = $update['post_date_gmt'];
	}

	// Safeguard: prevent invalid hierarchy and broken Pages admin
	if (!empty($update_fields['post_parent']) && ($post_id == $update_fields['post_parent'])) {
		$update_fields['post_parent'] = 0;
	}

	if ($author_selection = get_post_meta($revision_id, '_rvy_author_selection', true)) {
		$user = get_user_by('ID', $author_selection);

		if (is_a($user, 'WP_User')) {
			$update_fields['post_author'] = $author_selection;
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->update($wpdb->posts, $update_fields, ['ID' => $post_id]);

	// also copy all stored postmeta from revision

	$is_imported = get_post_meta($revision_id, '_rvy_imported_revision', true);

	// work around bug in < 2.0.7 that saved all scheduled revisions without terms
	if (!$is_imported && ('future-revision' == $revision->post_mime_type)) {
		if ($install_time = get_option('revisionary_2_install_time')) {
			if (strtotime($revision->post_modified_gmt) < $install_time) {
				$is_imported = true;
			}
		}
	}

	revisionary_copy_postmeta($revision, $published->ID, ['apply_empty' => !$is_imported]);

	// Allow Multiple Authors revisions to be applied to published post. Revision post_author is forced to actual submitting user.
	revisionary_copy_terms($revision_id, $post_id, ['apply_empty' => !$is_imported, 'applying_revision' => true]);

	if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION') && $published_authors) {
		// Make sure Multiple Authors values were not wiped due to incomplete revision data
		if (function_exists('get_post_authors') && !get_post_authors($post_id, true)) {
			rvy_set_ma_post_authors($post_id, $published_authors);
		}
	}

	if ($published_id != $revision_id) {
		$num_revisions = revisionary_count_revisions($published_id);

		if (!defined('REVISIONARY_NO_SCHEDULED_REVISION_ARCHIVE')) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$wpdb->posts, 
				['post_type' => 'revision', 
				'post_status' => 'inherit', 
				'post_date' => current_time('mysql'), 
				'post_date_gmt' => current_time('mysql', 1), 
				'post_parent' => $post_id, 
				'comment_count' => 0,
				'post_mime_type' => $published->post_mime_type
				],
				['ID' => $revision_id]
			);

			$post = $published;

			if (!is_object($post) || empty($post->ID)) {
				return;
			}
		
			$post_id = $post->ID;
		
			/*
			* If a limit for the number of revisions to keep has been set,
			* delete the oldest ones.
			*/
			$revisions_to_keep = wp_revisions_to_keep( $post );
		
			if ($revisions_to_keep >= 0 ) {
				$revisions = wp_get_post_revisions( $post_id, array( 'order' => 'ASC' ) );
			
				/**
				 * Filters the revisions to be considered for deletion.
				 *
				 * @since 6.2.0
				 *
				 * @param WP_Post[] $revisions Array of revisions, or an empty array if none.
				 * @param int       $post_id   The ID of the post to save as a revision.
				 */
				$revisions = apply_filters(
					'wp_save_post_revision_revisions_before_deletion',
					$revisions,
					$post_id
				);
			
				$delete = count( $revisions ) - $revisions_to_keep;
			
				if ($delete >= 1) {
					$revisions = array_slice( $revisions, 0, $delete );
				
					for ( $i = 0; isset( $revisions[ $i ] ); $i++ ) {
						if ( str_contains( $revisions[ $i ]->post_name, 'autosave' ) ) {
							continue;
						}
				
						wp_delete_post_revision( $revisions[ $i ]->ID );
					}
				}
			}
		} else {
			wp_delete_post($revision_id, true);
		}

		$num_revisions = $num_revisions - 1;

		// If published revision was the last remaining pending / scheduled, clear _rvy_has_revisions postmeta flag 
		revisionary_refresh_postmeta($published_id, compact('num_revisions'));

		if (!rvy_get_option('archive_postmeta')) {
			$approved_by = get_post_meta($revision_id, '_rvy_approved_by', true);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete($wpdb->postmeta, array('post_id' => $revision_id));
		}
	}
	
	rvy_update_post_meta($revision_id, '_rvy_published_gmt', current_time('mysql', 1));

	rvy_update_post_meta($revision_id, '_rvy_prev_revision_status', $actual_revision_status);

	if ('future-revision' != $actual_revision_status) {
		global $current_user;
		rvy_update_post_meta($revision_id, '_rvy_approved_by', $current_user->ID);

	} elseif (!empty($approved_by)) {
		rvy_update_post_meta($revision_id, '_rvy_approved_by', $approved_by);
	}

	if (!empty($orig_terms) && is_array($orig_terms)) {
		foreach($orig_terms as $taxonomy => $terms) {
			if ($terms && !wp_get_object_terms($published->ID, $taxonomy, ['fields' => 'ids'])) {
				wp_set_object_terms($published->ID, $terms, $taxonomy);
			}
		}
	}

	if (defined('POLYLANG_VERSION')) {
		if (!empty($lang_descripts)) {
			foreach($lang_descripts as $tt_id => $descript) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update($wpdb->term_taxonomy, ['description' => $descript], ['term_taxonomy_id' => $tt_id]);
			}
		}
	}

	if (rvy_get_option('copy_revision_comments_to_post')) {
		if ($rev_comments = get_comments([
			'post_id' => $revision_id, 
			'status' => 'editorial-comment',
		])) {
			$post_comments = get_comments([
				'post_id' => $published->ID, 
				'status' => 'editorial-comment',
			]);

			foreach($rev_comments as $comment) {
				$arr_comment = (array) $comment;
				$arr_comment['comment_post_ID'] = $published->ID;

				// Don't copy a revision comment if published post already has an identical comment
				foreach($post_comments as $post_comment) {
					if ($post_comment->comment_content == $comment->comment_content) {
						continue 2;
					}
				}

				wp_insert_comment($arr_comment);
			}
		}
	}

	if (rvy_get_option('trigger_post_update_actions')) {
		global $revisionary;

		$_published = get_post($published->ID);

		if (!defined('RVY_NO_TRANSITION_STATUS_ACTION')) {
			$old_status = (defined('RVY_TRANSITION_ACTION_USE_REVISION_STATUS')) ? $revision->post_status : 'pending';
			do_action('transition_post_status', $published->post_status, $old_status, $_published);
		}

		if (!defined('RVY_NO_SAVE_POST_ACTION')) {
			remove_action('save_post', array($revisionary, 'actSavePost'), 20, 2);

			if (function_exists('presspermit')) {
				presspermit()->flags['ignore_save_post'] = true;
			}

			do_action('save_post', $published->ID, $_published, true);

			if (function_exists('presspermit')) {
				unset(presspermit()->flags['ignore_save_post']);
			}
		}

		if (!defined('RVY_NO_AFTER_INSERT_ACTION') && !empty($_published)) {
			do_action('wp_after_insert_post', $_published->ID, $_published, true, $published);
		}
	}

	if (defined('PUBLISHPRESS_VERSION') && rvy_get_option('rev_publication_delete_ed_comments')) {
		$wpdb->query(											// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM $wpdb->comments WHERE comment_approved = 'editorial-comment' AND (comment_post_ID = %d OR comment_post_ID = %d)",
				$revision_id,
				$published->ID
			)
		);
	}

	if (!rvy_get_option('extended_archive')) {
		rvy_delete_past_revisions($revision_id);

		rvy_delete_redundant_revisions($revision);
	}

	clean_post_cache($revision_id);
	clean_post_cache($published->ID);

	delete_metadata('post', $published->ID, '_elementor_element_cache');

	if (!defined('REVISIONARY_DISABLE_SECONDARY_CACHE_FLUSH')) {
		wp_cache_delete( $published->ID, 'posts' );
		wp_cache_delete( $published->ID, 'post_meta' );
		wp_cache_delete( $revision_id, 'posts' );
		wp_cache_delete( $revision_id, 'post_meta' );
	}

	if (defined('LSCWP_V')) {
		do_action('litespeed_purge_post', $published->ID);
		do_action('litespeed_purge_post', $revision_id);
	}

	if (function_exists('rocket_clean_post') && !defined('REVISIONARY_DISABLE_ROCKET_CLEAN')) {
		rocket_clean_post($published->ID);
		rocket_clean_post($revision_id);
	}

	if (function_exists('w3tc_flush_post') && !defined('REVISIONARY_DISABLE_W3TC_CACHE_FLUSH')) {
		w3tc_flush_post($published->ID, true);
		w3tc_flush_post($revision_id, true);
	}

	// Passing ignore_revision_ids is not theoretically necessary here since this call occurs after deletion, but avoid any cache clearance timing issues.
	revisionary_refresh_revision_flags($published->ID, ['ignore_revision_ids' => $revision_id]);

	/**
	 * Trigger after a revision has been applied.
	 *
	 * @param int $post_id The post ID.
	 * @param int $revision_id The revision object.
	 */
	do_action( 'revision_applied', $published->ID, $revision );

	if (empty($revision) || empty($revision->post_mime_type)) {
		$revision = (object) (array) $published;
		$revision->post_mime_type = $original_post_status;
		$revision->ID = $revision_id;
	}

	do_action('revisionary_revision_published', $published, $revision);

	return $revision;
}

function rvy_delete_redundant_revisions($revision) {
	global $wpdb, $current_user;

	$wpdb->query(												// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->prepare(
			"DELETE FROM $wpdb->posts WHERE post_type = %s AND post_status = %s AND post_author = %d AND post_parent = %d AND ID > %d",
			'revision',
			'inherit',
			$current_user->ID,
			rvy_post_id($revision->ID),
			$revision->ID
		)
	);
}

// Restore a past revision (post_status = inherit)
function rvy_do_revision_restore( $revision_id, $actual_revision_status = '' ) {
	global $wpdb;

	if ( $revision = wp_get_post_revision( $revision_id ) ) {
		if ('future-revision' == $revision->post_mime_type) {
			rvy_publish_scheduled_revisions(array('revision_id' => $revision->ID));
			return $revision;
		}
		
		$revision_date = $revision->post_date;
		$revision_date_gmt = $revision->post_date_gmt;

		wp_restore_post_revision( $revision_id );

		// @todo: why do revision post_date, post_date_gmt get changed?

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "UPDATE $wpdb->posts SET post_date = %s, post_date_gmt = %s WHERE ID = %d", $revision_date, $revision_date_gmt, $revision->ID ) );

		// @todo: why does a redundant revision with post_author = 0 get created at revision publication?

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->posts WHERE post_type = 'revision' AND post_author = 0 AND post_parent = %d", $revision->post_parent ) );
	}

	if ( $revision && ! empty($revision->post_parent) ) {
		rvy_format_content( $revision->post_content, $revision->post_content_filtered, $revision->post_parent );
	}
	
	clean_post_cache( $revision_id );

	return $revision;
}

function rvy_revision_delete() {
	if (!isset($_GET['revision'])) {
		return;
	}

	$revision_id = (int) $_GET['revision'];
	$redirect = '';

	do {
		// this function is only used for past revisions (status=inherit)
		if ( ! $revision = wp_get_post_revision( $revision_id ) )
			break;

		if ( ! $post = get_post( $revision->post_parent ) )
			break;

		if (!is_content_administrator_rvy()) {
			if ($type_obj = get_post_type_object( $post->post_type ) ) {
				if ('revision' == $post->post_type) {
					$check_parent = $post->post_parent;
					
					if (!$type_obj = get_post_type_object(get_post_field('post_type', $check_parent))) {
						break;
					}
	
				} elseif (rvy_in_revision_workflow($post)) {
					$check_parent = rvy_post_id($post);
	
				} else {
					$check_parent = $revision->post_parent;
				}
	
				if ( !current_user_can( $type_obj->cap->delete_post, $check_parent ) ) {
					break;
				}
			}
		}
		
		check_admin_referer('delete-revision_' .  $revision_id);
		
		clean_post_cache(rvy_post_id($revision_id));

		// before deleting the revision, note its status for redirect
		wp_delete_post_revision( $revision_id );

		if (!empty($_SERVER['HTTP_REFERER']) && strpos(esc_url_raw($_SERVER['HTTP_REFERER']), 'revisionary-archive')) {
			$redirect = add_query_arg('deleted', '1', esc_url_raw($_SERVER['HTTP_REFERER']));
		} else {
			$redirect = "admin.php?page=revisionary-archive&origin_post={$revision->post_parent}&revision_status={$revision->post_mime_type}&deleted=1";
		}

		rvy_delete_past_revisions($revision_id);
	} while (0);
	
	if ( ! empty( $_GET['return'] ) && ! empty( $_SERVER['HTTP_REFERER'] ) ) {
		$redirect = str_replace( 'trashed=', 'deleted=', esc_url_raw($_SERVER['HTTP_REFERER']) );

	} elseif ( ! $redirect ) {
		if ( ! empty($post) && is_object($post) && ( 'post' != $post->post_type ) ) {
			$redirect = "edit.php?post_type={$post->post_type}";
		} else
			$redirect = 'edit.php';
	}

	wp_redirect( $redirect );
	exit;
}

function rvy_revision_bulk_delete() {
	check_admin_referer( 'rvy-revisions' );
	
	$redirect = '';
	$delete_count = 0;
	$post_id = 0;
	$revision_status = '';
	
	if ( empty($_POST['delete_revisions']) || empty($_POST['delete_revisions']) ) {

		if ( ! empty( $_POST['left'] ) )
			$post_id = (int) $_POST['left'];

		elseif ( ! empty( $_POST['right'] ) )
			$post_id = (int) $_POST['right'];	

	} else {
		$delete_revisions = array_map('intval', (array) $_POST['delete_revisions']);
		$post_ids = [];

		foreach ($delete_revisions as $revision_id) {
			$published_post_id = rvy_post_id();
			
			// this function is only used for past revisions (status=inherit)
			if ( ! $revision = wp_get_post_revision( $revision_id ) )
				continue;

			if ( ! $post_id ) {
				if ( $post = get_post( $revision->post_parent ) )
					$post_id = $post->ID;
				else
					continue;
			}

			if ( $post = get_post( $revision->post_parent ) ) {
				$post_ids []= $revision->post_parent;

				if ( $type_obj = get_post_type_object( $post->post_type ) ) {
					if ( ! current_user_can( $type_obj->cap->delete_post, $revision->post_parent ) ) {
						continue;
					}
				}
			}
	
			clean_post_cache(rvy_post_id($revision_id));

			// before deleting the revision, note its status for redirect
			$revision_status = $revision->post_mime_type;
			wp_delete_post($revision_id, true);
			$delete_count++;

			do_action('rvy_delete_revision', $revision_id, $published_post_id);

			rvy_delete_past_revisions($revision_id);
		}
	}

	$redirect = "admin.php?page=revisionary-archive&origin_post=$post_id&revision_status=$revision_status&bulk_deleted=$delete_count";
	
	wp_redirect( $redirect );
	exit;
}

function rvy_revision_unschedule($revision_id) {
	global $wpdb;

	$redirect = '';
	
	do {
		if (!$revision = get_post($revision_id)) {
			break;
		}

		if (!rvy_in_revision_workflow($revision)) {
			break;
		}

		$published_id = rvy_post_id($revision->ID);

		if (!$post = get_post($published_id)) {
			break;
		}

		if (!current_user_can('approve_revision', $revision->ID)) {
			break;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( 
			$wpdb->posts, 
			[
				'post_status' => apply_filters('revisionary_post_revision_status', 'draft', 'draft-revision', $revision_id), 
				'post_mime_type' => 'draft-revision'
			], 
			['ID' => $revision->ID] 
		);
		
		clean_post_cache($revision->ID);

		rvy_update_next_publish_date();
	} while (0);

	return true;
}

function rvy_revision_publish($revision_id = false) {
	if ($revision_id) {
		$batch_process = true;
	} else {
		if (isset($_GET['revision'])) {
			$revision_id = (int) $_GET['revision'];
			$redirect = site_url();
			$batch_process = false;
		} else {
			return;
		}
	}

	do {
		if ( !$revision = get_post($revision_id ) ) {
			break;
		}

		if ( 'future-revision' != $revision->post_mime_type ) {
			break;
		}

		if (!$published_id = rvy_post_id($revision_id)) {
			break;
		}

		if (!$post = get_post($published_id)) {
			break;
		}

		if (!current_user_can('approve_revision', $revision_id)) {
			break;
		}

		if (!$batch_process) {
			check_admin_referer( "publish-post_$post->ID|$revision->ID" );
		}

		$do_publish = true;
		do_action( 'revision_published', rvy_post_id($revision->ID), $revision->ID );
	} while (0);
	
	if (!empty($do_publish)) {
		rvy_publish_scheduled_revisions(array('revision_id' => $revision->ID));

		clean_post_cache($revision->ID);

		if ($post) {
			clean_post_cache($post->ID);
		}
	}

	if (!$batch_process) {
		if ($post) {
			$type_obj = get_post_type_object($post->post_type);

			$redirect = ($type_obj && empty($type_obj->public)) ? rvy_admin_url("post.php?action=edit&post=$post->ID") : add_query_arg('mark_current_revision', 1, get_permalink($post->ID)); // published URL
		}

		wp_redirect($redirect);
		exit;
	}

	if (!empty($do_publish)) {
		return true;
	}
}

// rvy_init action passes Revisionary object
function _rvy_publish_scheduled_revisions($revisionary_obj, $args = []) {
	rvy_publish_scheduled_revisions($args);
}

function rvy_publish_scheduled_revisions($args = []) {
	global $wpdb, $wp_version;
	
	if (function_exists('relevanssi_query')) {
		remove_action( 'wp_insert_post', 'relevanssi_insert_edit', 99, 1 );
	}

	if (rvy_get_option('async_scheduled_publish') && !rvy_get_option('scheduled_publish_cron') && !rvy_get_option('wp_cron_usage_detected')) {
		rvy_confirm_async_execution( 'publish_scheduled_revisions' );
	
		// Prevent this function from being triggered simultaneously by another site request
		update_option( 'rvy_next_rev_publish_gmt', '2035-01-01 00:00:00' );
	}
	
	$time_gmt = current_time('mysql', 1);
	
	$restored_post_ids = array();
	$skip_revision_ids = array();
	$blogname = wp_specialchars_decode( get_option('blogname'), ENT_QUOTES );

	$revised_uris = array();

	if (defined('WP_DEBUG') && WP_DEBUG && !empty($_GET['rs_debug'])) {						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo "current time: " . esc_html($time_gmt);
	}

	if (!empty($args['revision_id']) && is_scalar($args['revision_id'])) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( 
			$wpdb->prepare( 
				"SELECT * FROM $wpdb->posts WHERE post_type != 'revision' AND post_status != 'inherit' AND post_mime_type = 'future-revision' AND ID = %d",
				(int) $args['revision_id']
			)
		);
	} else {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( 
			$wpdb->prepare(
				"SELECT * FROM $wpdb->posts WHERE post_type != 'revision' AND post_status != 'inherit' AND post_mime_type = 'future-revision' AND post_date_gmt <= %s ORDER BY post_date_gmt DESC",
				$time_gmt
			)
		);
	}

	$use_pp_notifications = defined('PUBLISHPRESS_VERSION') && version_compare(PUBLISHPRESS_VERSION, '4.6-beta', '>=') && rvy_get_option('use_publishpress_notifications');

	if ( $results ) {
		foreach ( $results as $row ) {
			$published_id = (!empty($row->comment_count)) ? $row->comment_count : rvy_post_id($row->ID);

			if ( ! isset($restored_post_ids[$published_id]) ) {
				$revised_uris []= get_permalink( $row->ID );

				$published_url = get_permalink($published_id);

				$_result = rvy_apply_revision($row->ID, 'future-revision');
				if (!$_result || is_wp_error($_result)) {
					// Don't trip an error because revision may have already been published by a different site request.
					// If not, the redirect to the published URL will indicate the current status
					continue;
				}

				if (defined('WP_DEBUG') && WP_DEBUG && ! empty( $_GET['rs_debug'] ) ) {		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
					echo '<br />' . "publishing revision " . esc_html($row->ID);
				}

				$restored_post_ids[$published_id] = true;
				
				$post = get_post( $published_id );

				$type_obj = get_post_type_object( $post->post_type );
				$type_caption = $type_obj->labels->singular_name;
				
				if (rvy_get_option( 'publish_scheduled_notify_revisor' ) && !$use_pp_notifications && rvy_get_option('legacy_notifications')) {
					$title = sprintf( esc_html__('[%s] %s Publication Notice', 'revisionary' ), $blogname, pp_revisions_status_label('future-revision', 'name') );
					$message = sprintf( esc_html__('The scheduled revision you submitted for the %1$s "%2$s" has been published.', 'revisionary' ), $type_caption, $row->post_title ) . "\r\n\r\n";

					if (!defined('REVISIONARY_LEGACY_MESSAGE_QUOTES')) {
						$message = str_replace($message, '&quot;', '"', $message);
					}

					if ( ! empty($post->ID) )
						$message .= esc_html__( 'View it online: ', 'revisionary' ) . $published_url . "\r\n";

					if ( $author = new WP_User( $row->post_author ) )
						rvy_mail( 
							$author->user_email, 
							$title, 
							$message, 
							[
								'revision_id' => $row->ID, 
								'post_id' => $published_id, 
								'notification_type' => 'publish-scheduled', 
								'notification_class' => 'publish_scheduled_notify_revisor'
							]
						);
				}

				// Prior to 1.3, notification was sent to author even if also revision submitter
				if ( ( ( $post->post_author != $row->post_author ) || defined( 'RVY_LEGACY_SCHEDULED_REV_POST_AUTHOR_NOTIFY' ) ) 
				&& rvy_get_option( 'publish_scheduled_notify_author' ) && !$use_pp_notifications && rvy_get_option('legacy_notifications')
				) {
					$title = sprintf( esc_html__('[%s] %s Publication Notice', 'revisionary' ), $blogname, pp_revisions_status_label('future-revision', 'name') );
					$message = sprintf( esc_html__('A scheduled revision to your %1$s "%2$s" has been published.', 'revisionary' ), $type_caption, $post->post_title ) . "\r\n\r\n";

					if (!defined('REVISIONARY_LEGACY_MESSAGE_QUOTES')) {
						$message = str_replace($message, '&quot;', '"', $message);
					}

					if ( $revisor = new WP_User( $row->post_author ) )
						$message .= sprintf( esc_html__('It was submitted by %1$s.'), $revisor->display_name ) . "\r\n\r\n";

					if ( ! empty($post->ID) )
						$message .= esc_html__( 'View it online: ', 'revisionary' ) . $published_url . "\r\n";
				
					if (function_exists('get_multiple_authors')) {
						$authors = get_multiple_authors($post);
					} else {
						$author = new WP_User($post->post_author);
						$authors = [$author];
					}
	
					foreach($authors as $author) {
						if ($author && !empty($author->user_email)) {
							rvy_mail( 
								$author->user_email, 
								$title, 
								$message, 
								[
									'revision_id' => $row->ID, 
									'post_id' => $published_id, 
									'notification_type' => 'publish-scheduled', 
									'notification_class' => 'publish_scheduled_notify_author'
								]
							);
						}
					}
				}
				
				if ( rvy_get_option( 'publish_scheduled_notify_admin' ) && !$use_pp_notifications && rvy_get_option('legacy_notifications') ) {

					// Support workaround to prevent notification when an user of specified role created the revision
					if (defined('REVISIONARY_LIMIT_ADMIN_NOTIFICATIONS')) {
						global $current_user;
			
						$user = ($current_user->ID != $revision->post_author) ? new WP_User($revision->post_author) : $current_user;
			
						if ($user && !empty($user->ID)) {
							foreach (['REVISIONARY_LIMIT_NOTIFICATION_SUBMITTER_ROLES', 'RVY_MONITOR_ROLES', 'SCOPER_MONITOR_ROLES'] as $const) {
								if (defined($const)) {
									// revision submitter roles for which revision publication should not trigger email notification
									$skip_notification_revisor_roles = array_map('trim', explode(',', constant($const)));
									break;
								}
							}
			
							if (empty($skip_notification_revisor_roles)) {
								$skip_notification_revisor_roles = ['editor', 'administrator'];
							}
						}

						if (!empty($skip_notification_revisor_roles) && array_intersect($user->roles, $skip_notification_revisor_roles)) {
							$skip_notification = true;
						}
					}
					
					if (empty($skip_notification)) {
						$title = sprintf(esc_html__('[%s] %s Publication'), $blogname, pp_revisions_status_label('future-revision', 'name') );
						
						$message = sprintf( esc_html__('A scheduled revision to the %1$s "%2$s" has been published.'), $type_caption, $row->post_title ) . "\r\n\r\n";
	
						if (!defined('REVISIONARY_LEGACY_MESSAGE_QUOTES')) {
							$message = str_replace($message, '&quot;', '"', $message);
						}

						if ( $author = new WP_User( $row->post_author ) )
							$message .= sprintf( esc_html__('It was submitted by %1$s.'), $author->display_name ) . "\r\n\r\n";
	
						if ( ! empty($post->ID) )
							$message .= esc_html__( 'View it online: ', 'revisionary' ) . $published_url . "\r\n";
	
						$object_id = ( isset($post) && isset($post->ID) ) ? $post->ID : $row->ID;
						$object_type = ( isset($post) && isset($post->post_type) ) ? $post->post_type : 'post';
						
	
						// if it was not stored, or cleared, use default recipients
						$to_addresses = array();
						
						do_action('presspermit_init_rvy_interface');

						if ( defined('RVY_CONTENT_ROLES') && ! defined('SCOPER_DEFAULT_MONITOR_GROUPS') && ! defined('REVISIONARY_LIMIT_ADMIN_NOTIFICATIONS') ) { // e-mail to Scheduled Revision Montiors metagroup if Role Scoper is activated
							global $revisionary;
							
							$monitor_groups_enabled = true;
							$revisionary->content_roles->ensure_init();
	
							if ( $default_ids = $revisionary->content_roles->get_metagroup_members( 'Scheduled Revision Monitors' ) ) {
								$cols = ( defined('COLS_ALL_RS') ) ? COLS_ALL_RS : 'all';

								$post_publishers = $revisionary->content_roles->users_who_can('edit_post', $object_id, array( 'cols' => $cols ) );
								
								foreach ($post_publishers as $user) {
									if (in_array($user->ID, $default_ids)) {
										$to_addresses []= $user->user_email;
									}
								}
							}
						} 
						
						if ( ! $to_addresses && ( empty($monitor_groups_enabled) || ! defined('RVY_FORCE_MONITOR_GROUPS') ) ) {  // if RS/PP are not active, monitor groups have been disabled or no monitor group members can publish this post...
							if ( defined( 'SCOPER_MONITOR_ROLES' ) ) {
								$use_wp_roles = SCOPER_MONITOR_ROLES;
							} else {
								$use_wp_roles = (defined('RVY_MONITOR_ROLES')) ? RVY_MONITOR_ROLES : 'administrator,editor';
							}
	
							$use_wp_roles = str_replace( ' ', '', $use_wp_roles );
							$use_wp_roles = explode( ',', $use_wp_roles );
							
							$recipient_ids = array();
	
							foreach ( $use_wp_roles as $role_name ) {
								$search = new WP_User_Query( "search=&fields=id&role=$role_name" );
								$recipient_ids = array_merge( $recipient_ids, $search->results );
							}
							
							foreach ( $recipient_ids as $userid ) {
								$user = new WP_User($userid);
								$to_addresses []= $user->user_email;
							}
						}
						
						if ( defined( 'RVY_NOTIFY_SUPER_ADMIN' ) && is_multisite() ) {
							$super_admin_logins = get_super_admins();
							foreach( $super_admin_logins as $user_login ) {
								if ( $super = new WP_User($user_login) )
									$to_addresses []= $super->user_email;
							}
						}
						
						$to_addresses = array_unique( $to_addresses );
						
						foreach ( $to_addresses as $address ) {
							rvy_mail( 
								$address, 
								$title, 
								$message, 
								[
									'revision_id' => $row->ID, 
									'post_id' => $published_id, 
									'notification_type' => 'publish-scheduled', 
									'notification_class' => 'publish_scheduled_notify_admin'
								]
							);
						}
					} // endif skip_notification
				}
				
				
			} else {
				$skip_revision_ids[$row->ID] = true;
			}
		}
		
		if ( $skip_revision_ids ) {
			// if more than one scheduled revision was not yet published, convert the older ones to regular revisions
			$id_clause = "AND ID IN ('" 
							. implode("','", 
								array_map('intval', array_keys($skip_revision_ids))
								) 
						. "')";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "UPDATE $wpdb->posts SET post_type = 'revision', post_status = 'inherit' WHERE post_mime_type = 'future-revision' $id_clause" );
		}
	}

	if (!rvy_get_option('scheduled_publish_cron')) {
		rvy_update_next_publish_date();
	}

	// if this was initiated by an asynchronous remote call, we're done.
	if ( ! empty( $_GET['action']) && ( 'publish_scheduled_revisions' == $_GET['action'] ) ) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		exit( 0 );
	} elseif (!empty($_SERVER['REQUEST_URI'])) {
		if ( in_array( esc_url_raw($_SERVER['REQUEST_URI']), $revised_uris ) ) {
			wp_redirect( esc_url(esc_url_raw($_SERVER['REQUEST_URI'])) );  // if one of the revised pages is being accessed now, redirect back so revision is published on first access
			exit;
		}
	}
}

function rvy_update_next_publish_date($args = []) {
	global $wpdb, $wp_version;
	
	if ($args && !empty($args['revision_id']) && rvy_get_option('scheduled_publish_cron')) {
		if ($revision = get_post($args['revision_id'])) {
			wp_schedule_single_event(strtotime( $revision->post_date_gmt ), 'publish_revision_rvy', [$args['revision_id']]);
		}
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $next_publish_date_gmt = $wpdb->get_var( "SELECT post_date_gmt FROM $wpdb->posts WHERE post_mime_type = 'future-revision' ORDER BY post_date_gmt ASC LIMIT 1" ) ) {

	} else {
		$next_publish_date_gmt = '2035-01-01 00:00:00';
	}

	update_option( 'rvy_next_rev_publish_gmt', $next_publish_date_gmt );
}

// @todo: is this still needed (now only create normal revisions when publishing scheduled post and more than one change was scheduled.)
function rvy_review_revision( $revision_id ) {
	if ( class_exists('WPCom_Markdown') && ! defined( 'RVY_DISABLE_MARKDOWN_WORKAROUND' ) ) {
		$revision = wp_get_post_revision( $revision_id );
		if ( ! $revision->post_content_filtered ) {
			if ( $post = get_post( $revision->post_parent ) ) {
				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->posts, array( 'post_content_filtered' => $post->post_content_filtered ), array( 'ID' => $revision_id ) );
			}
		}
	}
}

function rvy_delete_past_revisions($post_id) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$revision_ids = $wpdb->get_col($wpdb->prepare("SELECT * FROM $wpdb->posts WHERE post_type = 'revision' AND post_status = 'inherit' AND post_parent = %d", $post_id));

	// delete any associated "inherit" copies that were generated due to revision editing
	foreach($revision_ids as $_revision_id) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete($wpdb->postmeta, array('post_id' => $_revision_id));
	}

	if ($revision_ids) {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete($wpdb->posts, array('post_type' => 'revision', 'post_status' => 'inherit', 'post_parent' => $post_id));
	}
}

// apply any necessary third-party transformations to post content after publishing a revision
function rvy_format_content( $content, $content_filtered, $post_id, $args = array() ) {
	$defaults = array( 'update_db' => true );
	$args = array_merge( $defaults, $args );
	$args = apply_filters( 'rvy_format_content_args', $args, $post_id );
	
	foreach( array_keys( $defaults ) as $var ) {
		if ( ! isset( $$var) ) {
			$$var = ( isset( $args[$var] ) ) ? $args[$var] : $defaults[$var];
		}
	}

	if ( ! $content_filtered )
		$content_filtered = $content;
	
	$formatted_content = $content;
	
	if ( class_exists('WPCom_Markdown') && ! defined( 'RVY_DISABLE_MARKDOWN_WORKAROUND' ) ) {
		$wpcmd = WPCom_Markdown::get_instance();
		
		if ( method_exists( $wpcmd, 'transform' ) ) {
			$formatted_content = $wpcmd->transform( $content_filtered, array( 'post_id' => $post_id ) );
			
			if ( $update_db ) {
				global $wpdb;

				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $wpdb->posts, array( 'post_content' => $formatted_content, 'post_content_filtered' => $content_filtered ), array( 'ID' => $post_id ) );
			}
		}
	}

	$formatted_content = apply_filters( 'rvy_formatted_content', $formatted_content, $post_id, $content, $args );
	
	return $formatted_content;
}


function rvy_update_post($postarr = []) {
	global $wpdb;
	
	if ( is_object( $postarr ) ) {
		// Non-escaped post was passed.
		$postarr = get_object_vars( $postarr );
		$postarr = wp_slash( $postarr );
	}

	// First, get all of the original fields.
	$post = get_post( $postarr['ID'], ARRAY_A );

	if ( is_null( $post ) ) {
		return 0;
	}

	// Escape data pulled from DB.
	$post = wp_slash( $post );

	// Merge old and new fields with new fields overwriting old ones.
	$postarr = sanitize_post( array_merge( $post, $postarr ), 'db' );

	// Get the post ID and GUID.
	$post_before = get_post( $postarr['ID'] );

	if ( empty( $postarr['ID'] ) || is_null( $post_before ) ) {
		return 0;
	}

	$data = array_intersect_key(
		$postarr, 
		array_fill_keys(['post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_content_filtered', 'post_title', 'post_excerpt', 'comment_status', 'post_password', 'pinged', 'menu_order', 'post_mime_type'], true)
	);

	if (!class_exists('WPCom_Markdown') || defined('RVY_DISABLE_MARKDOWN_WORKAROUND')) {
		unset($data['post_content_filtered']);
	}

	$data['guid'] = get_post_field( 'guid', $postarr['ID'] );

	$data['post_type'] = (empty( $postarr['post_type'] )) ? 'post' : $postarr['post_type'];

	$data['post_status'] = (empty( $postarr['post_status'] )) 
	? apply_filters('revisionary_post_revision_status', 'draft', 'draft-revision', $postarr['ID'])
	: $postarr['post_status'];

	$data['post_modified']     = current_time( 'mysql' );
	$data['post_modified_gmt'] = current_time( 'mysql', 1 );

	$data['ping_status'] = empty( $postarr['ping_status'] ) ? get_default_comment_status( $data['post_type'], 'pingback' ) : $postarr['ping_status'];
	$data['to_ping'] = sanitize_trackback_urls( $postarr['to_ping'] );

	$data['post_parent'] = (int) $postarr['post_parent'];

	// For an update, don't modify the post_name if it wasn't supplied as an argument.
	$data['post_name'] = (!isset( $postarr['post_name'] ) ) ? $post_before->post_name : $postarr['post_name'];
	$data['post_name'] = wp_unique_post_slug( $data['post_name'], $postarr['ID'], $data['post_status'], $data['post_type'], $data['post_parent'] );

	$emoji_fields = array( 'post_title', 'post_content', 'post_excerpt' );

	foreach ( $emoji_fields as $emoji_field ) {
		if ( isset( $data[ $emoji_field ] ) ) {
			$charset = $wpdb->get_col_charset( $wpdb->posts, $emoji_field );

			if ( 'utf8' === $charset ) {
				$data[ $emoji_field ] = wp_encode_emoji( $data[ $emoji_field ] );
			}
		}
	}

	$data  = wp_unslash( $data );
	$where = array( 'ID' => $postarr['ID'] );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( false === $wpdb->update( $wpdb->posts, $data, $where ) ) {
		return 0;
	}

	clean_post_cache( $postarr['ID'] );

	$post = get_post( $postarr['ID'] );

	return $postarr['ID'];
}