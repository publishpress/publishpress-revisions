<?php
global $pagenow, $revisionary;

add_action( 'init', '_rvy_post_edit_ui' );

function _rvy_post_edit_ui() {
	global $pagenow, $revisionary;

	if (in_array($pagenow, ['post.php', 'post-new.php'])) {
		if ($pagenow == 'post.php') {
			require_once( dirname(__FILE__).'/post-editor-workflow-ui_rvy.php' );

			if (rvy_get_option('revision_limit_per_post')) {
				$post_id = rvy_detect_post_id();
				revisionary_refresh_postmeta($post_id);
			}

			if (\PublishPress\Revisions\Utils::isBlockEditorActive()) {
				require_once( dirname(__FILE__).'/post-edit-block-ui_rvy.php' );
			} else {
				require_once( dirname(__FILE__).'/post-edit_rvy.php' );
				$revisionary->post_edit_ui = new RvyPostEdit();
			}
		}
	} elseif ('edit.php' == $pagenow) {
		require_once( dirname(__FILE__).'/admin-posts_rvy.php' );
		new RevisionaryAdminPosts();
	}
}

function rvy_load_textdomain() {
	if ( defined('RVY_TEXTDOMAIN_LOADED') )
		return;

	load_plugin_textdomain('revisionary', false, dirname(plugin_basename(REVISIONARY_FILE)) . '/languages');

	define('RVY_TEXTDOMAIN_LOADED', true);
}

function rvy_admin_init() {
	do_action('pp_revisions_admin_init');

	// @todo: clean up "Restore Revision" URL on Diff screen
	// Until the integration with WP revisions.php is resolved, limit the scope of this workaround to relevant actions
	if (!empty($_GET['amp;revision']) && !empty($_GET['amp;action']) && !empty($_GET['amp;_wpnonce']) && in_array($_GET['amp;action'], ['approve', 'publish'])) {
		$_GET['revision'] = (int) $_GET['amp;revision'];
		$_GET['action'] = sanitize_key($_GET['amp;action']);
		$_GET['_wpnonce'] = sanitize_key($_GET['amp;_wpnonce']);

		if (!empty($_REQUEST['amp;revision'])) {
			$_REQUEST['revision'] = (int) $_REQUEST['amp;revision'];
		}

		if (!empty($_REQUEST['amp;action'])) {
			$_REQUEST['action'] = sanitize_key($_REQUEST['amp;action']);
		}

		if (!empty($_REQUEST['amp;_wpnonce'])) {
			$_REQUEST['_wpnonce'] = sanitize_key($_REQUEST['amp;_wpnonce']);
		}
	}

	if ( ! empty($_POST['rvy_submit']) || ! empty($_POST['rvy_defaults']) ) {
		require_once( RVY_ABSPATH . '/submittee_rvy.php');	
		$handler = new Revisionary_Submittee();
	
		if ( isset($_POST['rvy_submit']) ) {
			$sitewide = isset($_POST['rvy_options_doing_sitewide']);
			$customize_defaults = isset($_POST['rvy_options_customize_defaults']);
			$handler->handle_submission( 'update', $sitewide, $customize_defaults );
			
		} elseif ( isset($_POST['rvy_defaults']) ) {
			$sitewide = isset($_POST['rvy_options_doing_sitewide']);
			$customize_defaults = isset($_POST['rvy_options_customize_defaults']);
			$handler->handle_submission( 'default', $sitewide, $customize_defaults );
		}
		
	} elseif (isset($_REQUEST['action2']) && !empty($_REQUEST['page']) && ('revisionary-archive' == $_REQUEST['page']) && !empty($_REQUEST['post']) && rvy_get_option('revision_archive_deletion')) {
		$doaction = (!empty($_REQUEST['action']) && !is_numeric($_REQUEST['action'])) ? sanitize_key($_REQUEST['action']) : sanitize_key($_REQUEST['action2']);

		check_admin_referer('bulk-revision-archive');

		if (!$url = str_replace('#038;', '&', wp_get_referer())) {
			$url = admin_url("admin.php?page=revisionary-archive");
		}

		if ('delete' == $doaction) {
			$url = admin_url("admin.php?page=revisionary-archive");
		}

		$sendback = remove_query_arg( array('deleted', 'ids', 'posts', '_wp_nonce', '_wp_http_referer'), $url);
	
		if ( isset( $_REQUEST['ids'] ) ) {
			$post_ids =  array_map('intval', explode( ',', sanitize_text_field($_REQUEST['ids']) ));
		} elseif ( !empty( $_REQUEST['post'] ) ) {
			$post_ids = array_map('intval', $_REQUEST['post']);
		}
	
		if ( !isset( $post_ids ) ) {
			exit;
		}
	
		switch ( $doaction ) {
			case 'delete':
				$deleted = 0;
				foreach ( (array) $post_ids as $post_id ) {
					if ( ! $revision = wp_get_post_revision($post_id)) {
						continue;
					}

					if ( !current_user_can('administrator') && !current_user_can( 'edit_post', $revision->post_parent) ) {  // @todo: review Administrator cap check
						wp_die( esc_html__('Sorry, you are not allowed to delete this revision.', 'revisionary') );
					} 
	
					if ( !wp_delete_post_revision($post_id, true) )
						wp_die( esc_html__('Error in deleting.') );
	
					$deleted++;
				}
				$sendback = add_query_arg('deleted', $deleted, $sendback);
				break;
	
			default:
				if (!$sendback = apply_filters('revisionary_handle_archive_action', $sendback, $doaction, $post_ids)) {
					if (function_exists('get_current_screen')) {
						$sendback = apply_filters( 'handle_bulk_actions-' . get_current_screen()->id, $sendback, $doaction, $post_ids );
					}
				}
				
				break;
		}
	
		if ($sendback) {
			$sendback = remove_query_arg( array('action', 'action2', '_wp_http_referer', '_wpnonce', 'post', 'bulk_edit', 'post_view'), $sendback );
			$sendback = str_replace('#038;', '&', $sendback);	// @todo Proper decode
			wp_redirect($sendback);
			exit;
		}

	} elseif (
		(isset($_REQUEST['action2']) && !empty($_REQUEST['page']) && ('revisionary-q' == $_REQUEST['page']) && !empty($_REQUEST['post']))
		|| (isset($_REQUEST['action']) && in_array($_REQUEST['action'], ['decline_revision']))
	) {
		$doaction = (!empty($_REQUEST['action']) && !is_numeric($_REQUEST['action'])) ? sanitize_key($_REQUEST['action']) : sanitize_key($_REQUEST['action2']);

		if (empty($_POST) && in_array($_REQUEST['action'], ['decline_revision'])) {
			check_admin_referer('decline-revision');
		} else {
			check_admin_referer('bulk-revision-queue');
		}

		if (!$url = str_replace('#038;', '&', wp_get_referer())) {
			$url = admin_url("admin.php?page=revisionary-q");
		}

		$sendback = remove_query_arg( array('trashed', 'untrashed', 'submitted_count', 'declined_count', 'approved_count', 'published_count', 'scheduled_count', 'unscheduled_count', 'deleted', 'locked', 'ids', 'posts', '_wp_nonce', '_wp_http_referer'), $url);
	
		if ( 'delete_all' == $doaction ) {
			// Prepare for deletion of all posts with a specified post status (i.e. Empty trash).
			
			if (isset($_REQUEST['post_status'])) {
				$post_status = sanitize_key($_REQUEST['post_status']);
			} else {
				$post_status = '';
			}

			// Verify the post status exists.
			if ( get_post_status_object( $post_status ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_type=%s AND post_mime_type = %s", $post_type, $post_status ) );
			}
			$doaction = 'delete';
		} elseif ( isset( $_REQUEST['media'] ) ) {
			$post_ids =  array_map('intval', (array) $_REQUEST['media']);
		} elseif ( isset( $_REQUEST['ids'] ) ) {
			$post_ids =  array_map('intval', explode( ',', sanitize_text_field($_REQUEST['ids']) ));
		} elseif ( !empty( $_REQUEST['post'] ) ) {
			$post_ids = array_map('intval', (array) $_REQUEST['post']);
		}
	
		if ( !isset( $post_ids ) ) {
			exit;
		}
	
		switch ( $doaction ) {
			case 'approve_revision': // If pending revisions has a requested publish date, schedule it, otherwise schedule for near future. Leave currently scheduled revisions alone. 
			case 'publish_revision': // Schedule all selected revisions for near future publishing.
				$approved = 0;
				$is_administrator = current_user_can('administrator');
	
				require_once( dirname(__FILE__).'/revision-action_rvy.php');
	
				foreach ((array) $post_ids as $post_id) {
					if (!$revision = get_post($post_id)) {
						continue;
					}
					
					if (!rvy_in_revision_workflow($revision)) {
						continue;
					}
					
					if (!$is_administrator && !current_user_can('edit_post', rvy_post_id($revision->ID))) {
						if (count($post_ids) == 1) {
							wp_die( esc_html__('Sorry, you are not allowed to approve this revision.', 'revisionary') );
						} else {
							continue;
						}
					}
	
					if (('future-revision' == $revision->post_mime_type) && ('publish_revision' == $doaction)) {
						if (rvy_revision_publish($revision->ID)) {
							$approved++;
						}
					} else {
						if (rvy_revision_approve($revision->ID)) {
							$approved++;
						}
					}
				}
	
				if ($approved) {
					$arg = ('publish_revision' == $doaction) ? 'published_count' : 'approved_count';
					$sendback = add_query_arg($arg, $approved, $sendback);
				}
	
				break;
	
			case 'submit_revision' :
				$submitted = 0;
				$is_administrator = current_user_can('administrator');

				require_once( dirname(__FILE__).'/revision-action_rvy.php');
	
				foreach ((array) $post_ids as $post_id) {
					if (!$revision = get_post($post_id)) {
						continue;
					}
					
					if (!$is_administrator && !current_user_can('set_revision_pending-revision', $revision->ID)) {
						if (count($post_ids) == 1) {
							wp_die( esc_html__('Sorry, you are not allowed to submit this revision.', 'revisionary') );
						} else {
							continue;
						}
					}	

					if (rvy_revision_submit($revision->ID)) {
						$submitted++;
					}
				}
	
				if ($submitted) {
					$arg = 'submitted_count';
					$sendback = add_query_arg($arg, $submitted, $sendback);
				}

				break;

			case 'decline_revision' :
				$declined = 0;

				$is_administrator = current_user_can('administrator');

				require_once( dirname(__FILE__).'/revision-action_rvy.php');

				foreach ((array) $post_ids as $post_id) {
					if (!$revision = get_post($post_id)) {
						continue;
					}

					if (defined('REVISIONARY_DECLINE_REVISIONS_SKIP_PENDING')) {
						if ('pending' != $revision->post_status) {
							continue;
						}
					}

					if (!$is_administrator && !current_user_can('set_revision_pending-revision', $revision->ID)) {
						if (count($post_ids) == 1) {
							wp_die( esc_html__('Sorry, you are not allowed to decline this revision.', 'revisionary') );
						} else {
							continue;
						}
					}	

					if (rvy_revision_decline($revision->ID)) {
						$declined++;
					}
				}
	
				if ($declined) {
					$arg = 'declined_count';
					$sendback = add_query_arg($arg, $declined, $sendback);
				}

				break;

			case 'unschedule_revision' :
				$unscheduled = 0;
				$is_administrator = current_user_can('administrator');
	
				require_once( dirname(__FILE__).'/revision-action_rvy.php');

				foreach ((array) $post_ids as $post_id) {
					if (!$revision = get_post($post_id)) {
						continue;
					}
					
					if ('future-revision' != $revision->post_mime_type) {
						continue;
					}
					
					if (!$is_administrator && !current_user_can('edit_post', rvy_post_id($revision->ID))) {
						if (count($post_ids) == 1) {
							wp_die( esc_html__('Sorry, you are not allowed to approve this revision.', 'revisionary') );
						} else {
							continue;
						}
					}
						
					if (rvy_revision_unschedule($revision->ID)) {
						$unscheduled++;
					}
				}

				if ($unscheduled) {
					$arg = 'unscheduled_count';
					$sendback = add_query_arg($arg, $unscheduled, $sendback);
				}

				break;

			case 'delete':
				$revision_statuses = array_diff(
					array_map('sanitize_key', rvy_revision_statuses()),
					['future-revision']
				);
				
				$deleted = 0;
				foreach ( (array) $post_ids as $post_id ) {
					if ( ! $revision = get_post($post_id) )
						continue;
					
					if ( ! rvy_in_revision_workflow($revision) )
						continue;
					
					if ( ! current_user_can('administrator') && ! current_user_can( 'delete_post', rvy_post_id($revision->ID) ) ) {  // @todo: review Administrator cap check
						if (!in_array($revision->post_mime_type, $revision_statuses) || !rvy_is_post_author($revision)) {	// allow submitters to delete their own still-pending revisions
							wp_die( esc_html__('Sorry, you are not allowed to delete this revision.', 'revisionary') );
						}
					} 
	
					// Work around Nested Pages plugin assuming get_current_screen() function declaration
					if (class_exists('NestedPages')) {
						if (!function_exists('get_current_screen')) {
							function get_current_screen() {
								return false;
							}
						}
					}

					if ( !wp_delete_post($post_id, true) )
						wp_die( esc_html__('Error in deleting.') );
	
					$deleted++;
				}
				$sendback = add_query_arg('deleted', $deleted, $sendback);
				break;
	
			default:
				if (!$sendback = apply_filters('revisionary_handle_admin_action', $sendback, $doaction, $post_ids)) {
					if (function_exists('get_current_screen')) {
						$sendback = apply_filters( 'handle_bulk_actions-' . get_current_screen()->id, $sendback, $doaction, $post_ids );
					}
				}
				
				break;
		}
	
		if ($sendback) {
			$sendback = remove_query_arg( array('action', 'action2', '_wp_http_referer', '_wpnonce', 'deleted', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status', 'post', 'bulk_edit', 'post_view'), $sendback );
			$sendback = str_replace('#038;', '&', $sendback);	// @todo Proper decode
			wp_redirect($sendback);
			exit;
		}

	// don't bother with the checks in this block unless action arg was passed
	} elseif ( ! empty($_GET['action']) || ! empty($_POST['action']) ) {
		if (isset($_SERVER['REQUEST_URI']) && false !== strpos(urldecode(esc_url_raw($_SERVER['REQUEST_URI'])), 'admin.php') && !empty($_REQUEST['page']) && ('rvy-revisions' == $_REQUEST['page'])) {
			if (!defined('REVISIONARY_ACTIONS_DISABLE_WP_INCLUSION')) {
				include_once(ABSPATH . 'wp-admin/includes/post.php');
			}
			
			if ( ! empty($_GET['action']) && ('restore' == $_GET['action']) ) {
				require_once( dirname(__FILE__).'/revision-action_rvy.php');	
				add_action( 'wp_loaded', 'rvy_revision_restore' );
		
			} elseif ( ! empty($_GET['action']) && ('delete' == $_GET['action']) ) {
				require_once( dirname(__FILE__).'/revision-action_rvy.php');	
				add_action( 'wp_loaded', 'rvy_revision_delete' );
				
			} elseif ( ! empty($_GET['action']) && ('revise' == $_GET['action']) ) {
				if (!empty($_REQUEST['post'])) {
					$post_id = intval($_REQUEST['post']);
					$arr = rvy_post_revision_blocked($post_id);
				}
		
				if (!empty($arr) && is_array($arr) && !empty($arr['code'])) {
					if (!empty($_REQUEST['referer'])) {
						$url = add_query_arg('revision_action', $arr['code'], esc_url_raw($_REQUEST['referer']));
						wp_redirect($url);
						exit;
					}
				}

				require_once( dirname(__FILE__).'/revision-action_rvy.php');	
				add_action( 'wp_loaded', 'rvy_revision_create' );

			} elseif ( ! empty($_GET['action']) && ('submit' == $_GET['action']) ) {
				require_once( dirname(__FILE__).'/revision-action_rvy.php');	
				add_action( 'wp_loaded', 'rvy_revision_submit' );

			} elseif ( ! empty($_GET['action']) && ('approve' == $_GET['action']) ) {
				require_once( dirname(__FILE__).'/revision-action_rvy.php');	
				add_action( 'wp_loaded', 'rvy_revision_approve' );
				
			} elseif ( ! empty($_GET['action']) && ('publish' == $_GET['action']) ) {
				require_once( dirname(__FILE__).'/revision-action_rvy.php');	
				add_action( 'wp_loaded', 'rvy_revision_publish' );

			} elseif ( ! empty($_POST['action']) && ('bulk-delete' == $_POST['action'] ) ) {
				require_once( dirname(__FILE__).'/revision-action_rvy.php');	
				add_action( 'wp_loaded', 'rvy_revision_bulk_delete' );
			}
		}
		
	} elseif (is_admin() && (false !== strpos(esc_url_raw($_SERVER['REQUEST_URI']), 'revision.php'))) { // endif action arg passed

		if (!empty($_REQUEST['revision'])) {
			$revision_id = (int) $_REQUEST['revision'];
		} elseif (isset($_REQUEST['to'])) {
			$revision_id = (int) $_REQUEST['to'];
		} else {
			return;
		}

		if (('modified' == rvy_get_option('past_revisions_order_by')) && !rvy_in_revision_workflow($revision_id)) {
			require_once(dirname(__FILE__).'/history_rvy.php');
			add_filter('query', ['RevisionaryHistory', 'fltOrderRevisionsByModified']);
		}
	}

	if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && !empty($_REQUEST['rvy_ajax_settings'])) {
		include_once(REVISIONARY_PRO_ABSPATH . '/includes-pro/pro-activation-ajax.php');
	}

	if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && !empty($_REQUEST['rvy_refresh_updates'])) {
		do_action('revisionary_refresh_updates');
	}

	if (!empty($_REQUEST['rvy_refresh_done']) && empty($_POST)) {
		if (current_user_can('activate_plugins')) {
			$url = admin_url('update-core.php');
			wp_redirect($url);
			exit;
		}
	}
}

function rvy_dismissable_notice( $msg_id, $message ) {
	return;
}

function rvy_get_post_revisions($post_id, $status = '', $args = '' ) {
	global $wpdb;
	
	$defaults = array( 'order' => 'DESC', 'orderby' => 'post_modified_gmt', 'use_memcache' => true, 'fields' => COLS_ALL_RVY, 'return_flipped' => false );
	$args = wp_parse_args( $args, $defaults );
	
	foreach( array_keys( $defaults ) as $var ) {
		$$var = ( isset( $args[$var] ) ) ? $args[$var] : $defaults[$var];
	}
	
	if (!$status) {
		$all_rev_statuses_clause = " AND (post_mime_type = 'draft-revision' OR post_mime_type = 'pending-revision' OR post_mime_type = 'future-revision')";
	} else {
		if (!in_array( 
			$status, 
			array_merge(rvy_revision_statuses(), array('inherit')) 
		) ) {
			return [];
		}
	}

	if ( COL_ID_RVY == $fields ) {
		// performance opt for repeated calls by user_has_cap filter
		if ( $use_memcache ) {
			static $last_results;
			
			if ( ! isset($last_results) )
				$last_results = array();
		
			elseif ( isset($last_results[$post_id][$status]) )
				return $last_results[$post_id][$status];
		}
		
		if ('inherit' == $status) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$revisions = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d AND post_status = %s",
					$post_id,
					$status
				)
			);
		} else {
			if (!empty($all_rev_statuses_clause)) { 
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$revisions = $wpdb->get_col(
					$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts "
					. " INNER JOIN $wpdb->postmeta pm_published ON $wpdb->posts.ID = pm_published.post_id AND pm_published.meta_key = '_rvy_base_post_id'"
						. " WHERE pm_published.meta_value = %s $all_rev_statuses_clause",	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$post_id
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$revisions = $wpdb->get_col(
					$wpdb->prepare(
					"SELECT ID FROM $wpdb->posts "
					. " INNER JOIN $wpdb->postmeta pm_published ON $wpdb->posts.ID = pm_published.post_id AND pm_published.meta_key = '_rvy_base_post_id'"
						. " WHERE pm_published.meta_value = %s AND post_mime_type = %s",
						$post_id,
						$status
					)
				);
			}
		}

		if ( $return_flipped )
			$revisions = array_fill_keys( $revisions, true );

		if ( $use_memcache ) {
			if ( ! isset($last_results[$post_id]) )
				$last_results[$post_id] = array();
				
			$last_results[$post_id][$status] = $revisions;
		}	
			
	} else {
		$order_clause = "ORDER BY $orderby $order";

		if ('inherit' == $status) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$revisions = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->posts WHERE post_type = 'revision' AND post_parent = %d AND post_status = %s $order_clause",	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$post_id,
					$status
				)
			);
		} else {
			if (!empty($all_rev_statuses_clause)) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$revisions = $wpdb->get_results(
					$wpdb->prepare(
					"SELECT * FROM $wpdb->posts "
					. " INNER JOIN $wpdb->postmeta pm_published ON $wpdb->posts.ID = pm_published.post_id AND pm_published.meta_key = '_rvy_base_post_id'"
						. " WHERE pm_published.meta_value = %d $all_rev_statuses_clause $order_clause",									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$post_id
					)
				);
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$revisions = $wpdb->get_results(
					$wpdb->prepare(
					"SELECT * FROM $wpdb->posts "
					. " INNER JOIN $wpdb->postmeta pm_published ON $wpdb->posts.ID = pm_published.post_id AND pm_published.meta_key = '_rvy_base_post_id'"
						. " WHERE pm_published.meta_value = %d AND post_mime_type = %s $order_clause",									// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						$post_id,
						$status
					)
				);
			}
		}
	}

	return $revisions;
}

function rvy_order_types($types, $args = [])
{
	$defaults = ['order_property' => '', 'item_type' => 'post', 'labels_property' => ''];
	$args = array_merge($defaults, $args);
	foreach (array_keys($defaults) as $var) {
		$$var = $args[$var];
	}

	if ('post' == $item_type) {
		$post_types = get_post_types([], 'object');
	} elseif ('taxonomy' == $item_type) {
		$taxonomies = get_taxonomies([], 'object');
	}

	$ordered_types = [];
	foreach (array_keys($types) as $name) {
		if ('post' == $item_type) {
			$ordered_types[$name] = (isset($post_types[$name]->labels->singular_name))
				? $post_types[$name]->labels->singular_name
				: '';
		} elseif ('taxonomy' == $item_type) {
			$ordered_types[$name] = (isset($taxonomies[$name]->labels->singular_name))
				? $taxonomies[$name]->labels->singular_name
				: '';
		} else {
			if (!is_object($types[$name])) {
				return $types;
			}

			if ($order_property) {
				$ordered_types[$name] = (isset($types[$name]->$order_property))
					? $types[$name]->$order_property
					: '';
			} else {
				$ordered_types[$name] = (isset($types[$name]->labels->$labels_property))
					? $types[$name]->labels->$labels_property
					: '';
			}
		}
	}

	asort($ordered_types);

	foreach (array_keys($ordered_types) as $name) {
		$ordered_types[$name] = $types[$name];
	}

	return $ordered_types;
}

/**
 * Wrapper for WP function wp_get_admin_notice(), which was introduced by WP 6.4.0
 *
 * @param string $message The message.
 * @param array  $args {
 *     Optional. An array of arguments for the admin notice. Default empty array.
 *
 *     @type string   $type               Optional. The type of admin notice.
 *                                        For example, 'error', 'success', 'warning', 'info'.
 *                                        Default empty string.
 *     @type string[] $additional_classes Optional. A string array of class names. Default empty array.
 * }
 * @return string The markup for an admin notice.
 */
function rvy_get_admin_notice( $message, $args = array() ) {
	if (function_exists('wp_get_admin_notice')) {
		return wp_get_admin_notice($message, $args);
	}

	$classes    = 'notice';

	if ( '' !== $type ) {
		$classes .= ' notice-' . $type;
	}

	if ( is_array( $args['additional_classes'] ) && ! empty( $args['additional_classes'] ) ) {
		$classes .= ' ' . implode( ' ', $args['additional_classes'] );
	}

	return sprintf( '<div class="%1$s">%2$s</div>', $classes, $message );
}
	