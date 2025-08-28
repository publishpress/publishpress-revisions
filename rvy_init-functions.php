<?php

if (isset($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die();

require_once( dirname(__FILE__).'/lib/agapetry_wp_core_lib.php');

if (defined('REVISIONARY_PRO_FILE')) {
	$filepath = REVISIONARY_PRO_FILE;
} else {
	$filepath = defined('REVISIONARY_FILE') ? REVISIONARY_FILE : '';
}

if ($filepath && !defined('RVY_NETWORK')) {
	define('RVY_NETWORK', awp_is_mu() && rvy_plugin_active_for_network(plugin_basename($filepath)));
}

require_once(dirname(__FILE__).'/functions.php');
require_once(dirname(__FILE__).'/utils.php');
require_once(dirname(__FILE__).'/defaults_rvy.php');

function _rvy_delete_revision($revision_id, $published_post_id) {
	revisionary_refresh_revision_flags($published_post_id, ['ignore_revision_ids' => $revision_id]);
}

function _rvy_rest_prepare($response, $post, $request) {
	if (!rvy_in_revision_workflow($post)) {
		return $response;
	}

	if ($type_obj = get_post_type_object($post->post_type)) {
		$rest_base = ! empty( $type_obj->rest_base ) ? $type_obj->rest_base : $type_obj->name;
		$namespace = ! empty( $type_obj->rest_namespace ) ? $type_obj->rest_namespace : 'wp/v2';

		$base = sprintf( '%s/%s', $namespace, $rest_base );
		$href = rest_url( trailingslashit( $base ) . $post->ID );

		$response->add_link('https://api.w.org/action-publish', $href);
	}

	return $response;
}

function rvy_mail_check_buffer($new_msg = [], $args = []) {
	if (empty($args['log_only'])) {
		if (!$use_buffer = rvy_get_option('use_notification_buffer')) {
			return (defined('REVISIONARY_DISABLE_MAIL_LOG'))
			? array_fill_keys(['buffer', 'sent_mail', 'send_limits', 'sent_counts', 'new_msg_buffered'], [])
			: [];
		}
	}

	require_once( dirname(__FILE__).'/mail-buffer_rvy.php');
	return _rvy_mail_check_buffer($new_msg, $args);
}

function rvy_send_buffered_mail() {
	require_once( dirname(__FILE__).'/mail-buffer_rvy.php');
	_rvy_send_buffered_mail();
}

function rvy_set_notification_buffer_cron() {
	$cron_timestamp = wp_next_scheduled( 'rvy_mail_buffer_hook' );

	if (rvy_get_option('use_notification_buffer')) {
		if (!$cron_timestamp) {
			wp_schedule_event(time(), 'two_minutes', 'rvy_mail_buffer_hook');
		}
	} else {
		wp_unschedule_event($cron_timestamp, 'rvy_mail_buffer_hook');
	}
}

function rvy_mail_buffer_cron_interval( $schedules ) {
    $schedules['two_minutes'] = array(
        'interval' => 120,
        'display'  => '120_sec',
    );

    if (did_action('init')) {
        $schedules['two_minutes']['display'] = esc_html__( 'Every 2 Minutes', 'revisionary' );
    }
 
    return $schedules;
}

function _revisionary_publish_scheduled_cron($revision_id) {
	if (is_array($revision_id) && isset($revision_id['revision_id'])) {
		$revision_id = $revision_id['revision_id'];
	}

	if (rvy_get_option('scheduled_revisions') && rvy_get_option('scheduled_publish_cron')) {
		revisionary_publish_scheduled(compact('revision_id'));
	}
}

/*=================== End WP-Cron implementation ====================*/


function _rvy_existing_schedules_to_cron($prev_use_cron, $use_cron) {
	if ($use_cron && !$prev_use_cron) {
		global $wpdb;

		$time_gmt = current_time('mysql', 1);
	
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results( 
			$wpdb->prepare(
				"SELECT * FROM $wpdb->posts WHERE post_type != 'revision' AND post_status != 'inherit' AND post_mime_type = 'future-revision' AND post_date_gmt > %s ORDER BY post_date_gmt DESC",
				$time_gmt
			)
		);

		foreach($results as $revision) {
			if (!wp_get_scheduled_event('publish_revision_rvy', ['revision_id' => $revision->ID])) {
				wp_schedule_single_event(strtotime($revision->post_date_gmt), 'publish_revision_rvy', ['revision_id' => $revision->ID]);
			}
		}
	}

	if (!$use_cron && $prev_use_cron) {
		require_once( dirname(__FILE__).'/admin/revision-action_rvy.php');
		rvy_update_next_publish_date();
	}
}


/*
 * Revision previews: prevent redirect for non-standard post url
 */
function _rvy_no_redirect_filter($redirect, $orig) {
	global $current_user, $wpdb;

	if (!empty($current_user->ID) && (empty($wpdb) || empty($wpdb->is_404))) {
		$redirect = $orig;
	}

	return $redirect;
}

function rvy_ajax_handler() {
	global $current_user, $wpdb;

	if (!empty($_REQUEST['rvy_ajax_field']) && !empty($_REQUEST['rvy_ajax_value'])) {	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if ($post_id = intval($_REQUEST['rvy_ajax_value'])) {							// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

			global $revisionary_revision_id;

			$revisionary_revision_id = $post_id;

			switch ($_REQUEST['rvy_ajax_field']) {										// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
				case 'create_revision':
					if (current_user_can('copy_post', $post_id)) {
						$time_gmt = (!empty($_REQUEST['rvy_date_selection'])) ? intval($_REQUEST['rvy_date_selection']) : '';  // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

						require_once( dirname(REVISIONARY_FILE).'/revision-creation_rvy.php' );
						$rvy_creation = new PublishPress\Revisions\RevisionCreation();

						$revision_status = (rvy_get_option('auto_submit_revisions') && current_user_can('edit_post', $post_id)) ? 'pending-revision' : 'draft-revision';
						$rvy_creation->createRevision($post_id, $revision_status, compact('time_gmt'));
					}
					exit;

				case 'submit_revision':
					// capability check is applied within function to support batch execution without redundant checks
					require_once( dirname(__FILE__).'/admin/revision-action_rvy.php');	
					rvy_revision_submit($post_id);
					$check_autosave = true;

					break;

				case 'create_scheduled_revision':
					if (!empty($_REQUEST['rvy_date_selection'])) {						// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
						$time_gmt = intval($_REQUEST['rvy_date_selection']);			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

						if (current_user_can('edit_post', $post_id)) {
							require_once( dirname(REVISIONARY_FILE).'/revision-creation_rvy.php' );
							$rvy_creation = new PublishPress\Revisions\RevisionCreation();
							$rvy_creation->createRevision($post_id, 'future-revision', compact('time_gmt'));
						}
					}

					break;

				case 'author_select':
					if (!empty($_REQUEST['rvy_selection'])) {							// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
						if (current_user_can('edit_post', $post_id)) {
							update_post_meta($post_id, '_rvy_author_selection', (int) $_REQUEST['rvy_selection']);  // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
						}
					}

					break;

				default:
			}
																						// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			if (('submit_revision' != $_REQUEST['rvy_ajax_field']) && !empty($check_autosave) && !defined('REVISIONARY_IGNORE_REVISION_AUTOSAVE')) {
				if ($autosave_post = PublishPress\Revisions\Utils::get_post_autosave($post_id, $current_user->ID)) {
					$main_post = get_post($post_id);

					// If revision autosave is newer than revision post_updated date, copy over post data
					if (strtotime($autosave_post->post_modified_gmt) > strtotime($main_post->post_modified_gmt)) {
						$set_post_properties = [       
							'post_content',
							'post_content_filtered',
							'post_title',
							'post_excerpt',
						];

						foreach($set_post_properties as $prop) {
							if (!empty($autosave_post->$prop)) {
								$update_data[$prop] = $autosave_post->$prop;
							}
						}

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update($wpdb->posts, $update_data, ['ID' => $post_id]);

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->delete($wpdb->posts, ['ID' => $autosave_post->ID]);
					}
				}
			}

			exit;
		}

	}
																						// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	if (defined('DOING_AJAX') && DOING_AJAX && isset($_REQUEST['action']) && ('get-revision-diffs' == $_REQUEST['action'])) {
		require_once( dirname(__FILE__).'/admin/history_rvy.php' );
		new RevisionaryHistory();
	}
}

function rvy_get_post_meta($post_id, $meta_key, $unused = false) {
	return get_post_meta($post_id, $meta_key, true);
}

function rvy_update_post_meta($post_id, $meta_key, $meta_value) {
	global $wpdb, $revisionary;

	if (!empty($revisionary)) {
		$revisionary->internal_meta_update = true;
	}

	update_post_meta($post_id, $meta_key, $meta_value);

	if (!empty($revisionary)) {
		$revisionary->internal_meta_update = true;
	}
}

function rvy_delete_post_meta($post_id, $meta_key) {
	delete_post_meta($post_id, $meta_key);
}

function rvy_status_registrations() {
	$block_editor = \PublishPress\Revisions\Utils::isBlockEditorActive();

	$labels = apply_filters('revisionary_status_labels',
		rvy_get_option('revision_statuses_noun_labels') ?
		[
			'draft-revision' => [
				'name' => esc_html__('Working Copy', 'revisionary'),
				'submit' => esc_html__('Create Working Copy', 'revisionary'),
				'submit_short' => esc_html__('Copy', 'revisionary'),
				'submitting' => esc_html__('Creating Working Copy...', 'revisionary'),
				'submitted' => esc_html__('Working Copy ready', 'revisionary'),
				'approve' => esc_html__('Approve Changes', 'revisionary'), 
				'approve_short' => esc_html__('Approve', 'revisionary'),
				'approving' => esc_html__('Approving Changes...', 'revisionary'),
				'publish' => esc_html__('Publish Changes', 'revisionary'),
				'save' => esc_html__('Save Revision', 'revisionary'), 
				'update' => esc_html__('Update Revision', 'revisionary'), 
				'plural' => esc_html__('Working Copies', 'revisionary'), 
				'short' => esc_html__('Working Copy', 'revisionary'),
				'count' => _n_noop('Working Copies <span class="count">(%d)</span>', 'Working Copies <span class="count">(%d)</span>', 'revisionary'),  // @todo: confirm API will support a fixed string
				'basic' => 'Copy',
			],
		
			'pending-revision' => [
				'name' => esc_html__('Change Request', 'revisionary'),
				'submit' => esc_html__('Submit Change Request', 'revisionary'),
				'submit_short' => esc_html__('Submit', 'revisionary'),
				'submitting' => esc_html__('Submitting Changes...', 'revisionary'),
				'submitted' => esc_html__('Changes Submitted', 'revisionary'),
				'approve' => esc_html__('Approve Changes', 'revisionary'),
				'approve_short' => esc_html__('Approve', 'revisionary'),
				'approving' => esc_html__('Approving Changes...', 'revisionary'),
				'publish' => esc_html__('Publish Changes', 'revisionary'), 
				'save' => esc_html__('Save Revision', 'revisionary'), 
				'update' => esc_html__('Update Revision', 'revisionary'), 
				'plural' => esc_html__('Change Requests', 'revisionary'), 
				'short' => esc_html__('Change Request', 'revisionary'),
				'count' => _n_noop('Change Requests <span class="count">(%d)</span>', 'Change Requests <span class="count">(%d)</span>', 'revisionary'),
				'enable' => esc_html__('Enable Change Requests', 'revisionary'),
				'basic' => 'Change Request',
			],

			'future-revision' => [
				'name' => esc_html__('Scheduled Change', 'revisionary'),
				'submit' => esc_html__('Schedule Changes', 'revisionary'),
				'submit_short' => esc_html__('Schedule Changes', 'revisionary'),
				'submitting' => esc_html__('Scheduling Changes...', 'revisionary'),
				'submitted' => esc_html__('Changes are Scheduled.', 'revisionary'),
				'approve' => esc_html__('Schedule Changes', 'revisionary'), 
				'approve_short' => esc_html__('Schedule Changes', 'revisionary'), 
				'publish' => esc_html__('Publish Changes', 'revisionary'), 
				'save' => esc_html__('Save Revision', 'revisionary'), 
				'update' => esc_html__('Update Revision', 'revisionary'), 
				'plural' => esc_html__('Scheduled Changes', 'revisionary'), 
				'short' => esc_html__('Scheduled Change', 'revisionary'),
				'count' => _n_noop('Scheduled <span class="count">(%d)</span>', 'Scheduled <span class="count">(%d)</span>', 'revisionary'),
				'basic' => 'Scheduled Change',
			],
		]

		:
		[
			'draft-revision' => [
				'name' => esc_html__('Unsubmitted Revision', 'revisionary'),
				'submit' => esc_html__('New Revision', 'revisionary'), 
				'submit_short' => esc_html__('New Revision', 'revisionary'), 
				'submitting' => esc_html__('Creating Revision...', 'revisionary'),
				'submitted' => ($block_editor) ? esc_html__('The Revision is ready to edit.', 'revisionary') : esc_html__('Revision ready to edit.', 'revisionary'),
				'approve' => esc_html__('Approve Revision', 'revisionary'),
				'approve_short' => esc_html__('Approve', 'revisionary'),
				'publish' => esc_html__('Publish Revision', 'revisionary'),
				'save' => esc_html__('Save Revision', 'revisionary'), 
				'update' => esc_html__('Update Revision', 'revisionary'), 
				'plural' => esc_html__('Unsubmitted Revisions', 'revisionary'), 
				'short' => esc_html__('Not Submitted', 'revisionary'),
				'count' => _n_noop('Not Submitted <span class="count">(%s)</span>', 'Not Submitted <span class="count">(%s)</span>', 'revisionary'),   // @todo: confirm API will support a fixed string
				'basic' => 'Revision',
			],
		
			'pending-revision' => [
				'name' => esc_html__('Submitted Revision', 'revisionary'),
				'submit' => esc_html__('Submit Revision', 'revisionary'),
				'submit_short' => esc_html__('Submit', 'revisionary'), 
				'submitting' => esc_html__('Submitting Revision...', 'revisionary'),
				'submitted' => ($block_editor) ? esc_html__('The Revision is Submitted', 'revisionary') : esc_html__('Revision Submitted', 'revisionary'),
				'approve' => esc_html__('Approve Revision', 'revisionary'),
				'approve_short' => esc_html__('Approve', 'revisionary'),
				'publish' => esc_html__('Publish Revision', 'revisionary'), 
				'save' => esc_html__('Save Revision', 'revisionary'), 
				'update' => esc_html__('Update Revision', 'revisionary'), 
				'plural' => esc_html__('Submitted Revisions', 'revisionary'), 
				'short' => esc_html__('Submitted', 'revisionary'),
				'count' => _n_noop('Submitted <span class="count">(%s)</span>', 'Submitted <span class="count">(%s)</span>', 'revisionary'),
				'basic' => 'Revision',
			],

			'future-revision' => [
				'name' => esc_html__('Scheduled Revision', 'revisionary'),
				'submit' => esc_html__('Schedule Revision', 'revisionary'), 
				'submit_short' => esc_html__('Schedule', 'revisionary'), 
				'submitting' => esc_html__('Scheduling Revision...', 'revisionary'),
				'submitted' => ($block_editor) ? esc_html__('The Revision is Scheduled', 'revisionary') :  esc_html__('Revision Scheduled', 'revisionary'),
				'approve' => esc_html__('Approve Revision', 'revisionary'), 
				'approve_short' => esc_html__('Approve', 'revisionary'), 
				'publish' => esc_html__('Publish Revision', 'revisionary'), 
				'save' => esc_html__('Save Revision', 'revisionary'), 
				'update' => esc_html__('Update Revision', 'revisionary'), 
				'plural' => esc_html__('Scheduled Revisions', 'revisionary'), 
				'short' => esc_html__('Scheduled', 'revisionary'),
				'count' => _n_noop('Scheduled <span class="count">(%s)</span>', 'Scheduled <span class="count">(%s)</span>', 'revisionary'),
				'basic' => 'Scheduled Revision',
			],
		]
	);

	$compat_mode = rvy_get_option('permissions_compat_mode');

	register_post_status('draft-revision', array(
		'label' => $labels['draft-revision']['name'],
		'labels' => (object) $labels['draft-revision'],
		'protected' => true,
		'internal' => true,
		'label_count' => $labels['draft-revision']['count'],
		'exclude_from_search' => false,
		'show_in_admin_all_list' => $compat_mode,
		'show_in_admin_status_list' => false,
	));
	
	register_post_status('pending-revision', array(
		'label' => $labels['pending-revision']['name'],
		'labels' => (object) $labels['pending-revision'],
		'protected' => true,
		'internal' => true,
		'label_count' => $labels['pending-revision']['count'],
		'exclude_from_search' => false,
		'show_in_admin_all_list' => $compat_mode,
		'show_in_admin_status_list' => false,
	));

	register_post_status('future-revision', array(
		'label' => $labels['future-revision']['name'],
		'labels' => (object) $labels['future-revision'],
		'protected' => true,
		'internal' => true,
		'label_count' => $labels['future-revision']['count'],
		'exclude_from_search' => false,
		'show_in_admin_all_list' => $compat_mode,
		'show_in_admin_status_list' => false,
	));

	foreach(rvy_get_manageable_types() as $post_type) {
		add_filter("rest_{$post_type}_collection_params", function($query_params, $post_type = '') 
			{
				$query_params['status']['items']['enum'] []= 'draft-revision';
				$query_params['status']['items']['enum'] []= 'pending-revision';
				$query_params['status']['items']['enum'] []= 'future-revision';
				return $query_params;
			}, 999, 2 
		);
	}

	// WP > 5.3: Don't allow revision statuses to be blocked at the REST API level. Our own filters are sufficient to regulate their usage.
	add_action( 'rest_api_init', function() {
			global $wp_post_statuses;

			foreach(rvy_revision_statuses() as $status) {
				if (isset($wp_post_statuses[$status])) {
					$wp_post_statuses[$status]->internal = false;
				}
			}
		}, 97 
	);

	add_action( 'rest_api_init', function() {
		global $wp_post_statuses;

		foreach(rvy_revision_statuses() as $status) {
			if (isset($wp_post_statuses[$status])) {
				$wp_post_statuses[$status]->internal = true;
			}
		}
	}, 99
);
}

function pp_revisions_status_label($status_name, $label_property) {
	global $wp_post_statuses;

	if (!empty($wp_post_statuses[$status_name]) && !empty($wp_post_statuses[$status_name]->labels->$label_property)) {
		return $wp_post_statuses[$status_name]->labels->$label_property;
	
	} elseif (!empty($wp_post_statuses[$status_name]) && !empty($wp_post_statuses[$status_name]->label)) {
		return $wp_post_statuses[$status_name]->label;
	
	} else {
		return '';
	}
}

function pp_revisions_label($label_name) {
	static $labels;

	if (empty($labels)) {
		$labels = apply_filters('revisionary_labels',
		[
			'my_revisions' => (rvy_get_option('revision_statuses_noun_labels')) 
			? 							_n_noop('%sMy Copies & Changes%s(%s)</span>', '%sMy Copies & Changes%s(%s)</span>', 'revisionary')
			: 							_n_noop('%sMy Revisions%s(%s)</span>', '%sMy Revisions%s(%s)</span>', 'revisionary'),
			
			'my_published_posts'		=> _n_noop('%sRevisions to My Posts%s(%s)</span>', '%sRevisions to My Posts%s(%s)', 'revisionary'),

			'queue_col_revision' 		=> esc_html__('Revision', 'revisionary'),
			'queue_col_revised_by' 		=> esc_html__('Revised By', 'revisionary'),
			'queue_col_revision_date' 	=> esc_html__('Revision Date', 'revisionary'),
			'queue_col_post_author' 	=> esc_html__('Post Author', 'revisionary'),
			'queue_col_published_post' 	=> esc_html__('Published Post', 'revisionary'),
			'update_revision' 			=> esc_html__('Update Revision', 'revisionary'),

			'submit_revision' => (rvy_get_option('revision_statuses_noun_labels'))
			?							esc_html__('Submit Revision', 'revisionary')
			:							esc_html__('Submit Changes', 'revisionary')
		]);
	}

	return (isset($labels[$label_name])) ? $labels[$label_name] : '';
}

// WP function is_plugin_active_for_network() is defined in admin
function rvy_plugin_active_for_network( $plugin ) {
	if ( ! is_multisite() ) {
		return false;
	}

	$plugins = get_site_option( 'active_sitewide_plugins' );
	if ( isset( $plugins[ $plugin ] ) ) {
		return true;
	}

	return false;
}

function rvy_is_plugin_active($check_plugin_file) {
	$plugins = (array)get_option('active_plugins');
	foreach ($plugins as $plugin_file) {
		if (false !== strpos($plugin_file, $check_plugin_file)) {
			return $plugin_file;
		}
	}

	if (is_multisite()) {
		$plugins = (array)get_site_option('active_sitewide_plugins');

		// network activated plugin names are array keys
		foreach (array_keys($plugins) as $plugin_file) {
			if (false !== strpos($plugin_file, $check_plugin_file)) {
				return $plugin_file;
			}
		}
	}
}

function rvy_configuration_late_init() {
	global $revisionary;

	if (!empty($revisionary)) {
		$revisionary->configurationLateInit();
	}
}

// auto-define the Revisor role to include custom post type capabilities equivalent to those added for post, page in rvy_add_revisor_role()
function rvy_add_revisor_custom_caps() {
	if ( ! rvy_get_option( 'revisor_role_add_custom_rolecaps' ) )
		return;

	global $wp_roles, $revisionary;

	if (empty($revisionary)) {
		return;
	}

	$custom_types = array_intersect_key(
		get_post_types(['_builtin' => false], 'object'),
		$revisionary->enabled_post_types
	);

	if ( isset( $wp_roles->roles['revisor'] ) ) {
		if ($custom_types) {
			foreach( $custom_types as $post_type => $type_obj ) {
				$cap = $type_obj->cap;	
				$custom_caps = array_fill_keys( array( $cap->read_private_posts, $cap->edit_posts, $cap->edit_others_posts, "delete_{$post_type}s" ), true );

				if (!empty($type_obj->cap->edit_published_posts)) {
					$list_published_cap = str_replace('edit_', 'list_', $type_obj->cap->edit_published_posts);
					$custom_caps[$list_published_cap] = true;
				}

				if (!empty($type_obj->cap->edit_private_posts)) {
					$list_private_cap = str_replace('edit_', 'list_', $type_obj->cap->edit_private_posts);
					$custom_caps[$list_private_cap] = true;
				}

				$wp_roles->roles['revisor']['capabilities'] = array_merge( $wp_roles->roles['revisor']['capabilities'], $custom_caps );
				$wp_roles->role_objects['revisor']->capabilities = array_merge( $wp_roles->role_objects['revisor']->capabilities, $custom_caps );
			}
		}
	}

	if ( isset( $wp_roles->roles['contributor'] ) ) {
		if ($custom_types) {
			foreach( $custom_types as $post_type => $type_obj ) {
				$cap = $type_obj->cap;	
				$custom_caps = [];
				
				if (!empty($type_obj->cap->edit_published_posts)) {
					$list_published_cap = str_replace('edit_', 'list_', $type_obj->cap->edit_published_posts);
					$custom_caps[$list_published_cap] = true;
				}

				if (!empty($type_obj->cap->edit_private_posts)) {
					$list_private_cap = str_replace('edit_', 'list_', $type_obj->cap->edit_private_posts);
					$custom_caps[$list_private_cap] = true;
				}

				$wp_roles->roles['contributor']['capabilities'] = array_merge( $wp_roles->roles['contributor']['capabilities'], $custom_caps );
				$wp_roles->role_objects['contributor']->capabilities = array_merge( $wp_roles->role_objects['contributor']->capabilities, $custom_caps );
			}
		}
	}

	global $current_user;

	foreach(['contributor', 'revisor'] as $role_name) {
		if (in_array($role_name, $current_user->roles)) {
			$current_user->allcaps = array_merge($current_user->allcaps, $wp_roles->role_objects[$role_name]->capabilities);
		}
	}

	if (function_exists('presspermit')) {
		$user = presspermit()->getUser();
		$user->allcaps = $current_user->allcaps;
	}
}

function rvy_detect_post_type() {
	global $revisionary;
	
	if ( isset($revisionary) && $revisionary->doing_rest && $revisionary->rest->is_posts_request )
		return $revisionary->rest->post_type;
	else
		return awp_post_type_from_uri();
}

function rvy_detect_post_id() {
	global $revisionary;
	
	if ( isset($revisionary) && $revisionary->doing_rest && $revisionary->rest->is_posts_request ) {
		$post_id = $revisionary->rest->post_id;

	} elseif ( ! empty( $_GET['post'] ) ) {			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = (int) $_GET['post'];				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

	} elseif ( ! empty( $_POST['post_ID'] ) ) {		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = (int) $_POST['post_ID'];			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

	} elseif ( ! empty( $_REQUEST['post_id'] ) ) {	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = (int) $_REQUEST['post_id'];		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

	} elseif ( ! empty( $_GET['p'] ) ) {			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = (int) $_GET['p'];				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

	} elseif ( ! empty( $_GET['id'] ) ) {			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = (int) $_GET['id'];				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

													// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	} elseif ( ! empty( $_REQUEST['fl_builder_data'] ) && is_array( $_REQUEST['fl_builder_data'] ) && ! empty( $_REQUEST['fl_builder_data']['post_id'] ) ) {

		$post_id = (int) $_REQUEST['fl_builder_data']['post_id'];	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

	} elseif ( ! empty( $_GET['page_id'] ) ) {		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		$post_id = (int) $_GET['page_id'];			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

													// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	} elseif (defined('REST_REQUEST') && REST_REQUEST && isset($_SERVER['REQUEST_URI']) && strpos(esc_url_raw($_SERVER['REQUEST_URI']), 'autosaves')) {
		require_once( dirname(__FILE__).'/rest_rvy.php' );
		$post_id = Revisionary_REST::get_id_element(esc_url_raw($_SERVER['REQUEST_URI']), 1);	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

	} elseif (defined('DOING_AJAX') && DOING_AJAX) {
		$post_id = apply_filters('revisionary_detect_id', 0, ['is_ajax' => true]);

	} else {
		$post_id = 0;
	}

	return $post_id;	
}

function rvy_add_revisor_role( $requested_blog_id = '' ) {
	global $wp_roles;
	
	if (defined('REVISIONARY_NO_REVISOR_ROLE')) {
		return;
	}

	$wp_role_caps = array(
		'read' => true,
		'read_private_posts' => true,
		'read_private_pages' => true,
		'edit_posts' => true,
		'delete_posts' => true,
		'edit_others_posts' => true,
		'edit_pages' => true,
		'delete_pages' => true,
		'edit_others_pages' => true,
		'list_published_posts' => true,
		'list_published_pages' => true,
		'list_private_posts' => true,
		'list_private_pages' => true,
		'upload_files' => true,
		'level_3' => true,
		'level_2' => true,
		'level_1' => true,
		'level_0' => true
	);

	$wp_roles->add_role( 'revisor', esc_html__( 'Revisor', 'revisionary' ), $wp_role_caps );
}

function rvy_apply_role_translation($translations, $text, $context, $domain) {
	if (('User role' === $context) && ('Revisor' == $text) && ($domain !== 'revisionary')) {
		return translate_with_gettext_context($text, $context, 'revisionary');
	}

	return $translations;
}

function rvy_role_translation_support() {
	_x('Revisor', 'User role', 'revisionary');
	add_filter('gettext_with_context', 'rvy_apply_role_translation', 10, 4);
}

// wrapper function for use with wp_cron hook
function revisionary_publish_scheduled($args = []) {
	require_once( dirname(__FILE__).'/admin/revision-action_rvy.php');
	rvy_publish_scheduled_revisions($args);
}

function revisionary_count_revisions($post_id, $args = []) {
	global $wpdb;

	$ignore_revisions = (!empty($args['ignore_revisions'])) ? $args['ignore_revisions'] : [];
	$ignore_clause = ($ignore_revisions) ? " AND ID NOT IN (" . implode(",", array_map('intval', $ignore_revisions)) . ")" : '';

	if (defined('REVISIONARY_LIMIT_IGNORE_UNSUBMITTED')) {
		$ignore_clause .= " AND post_mime_type != 'draft-revision'";
	}

	$revision_status_csv = implode("','", array_map('sanitize_key', rvy_revision_statuses()));

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$has_revisions = $wpdb->get_var(
		// account for post deletion
		$wpdb->prepare(
			"SELECT COUNT(ID) FROM $wpdb->posts WHERE post_mime_type IN ('$revision_status_csv') $ignore_clause AND post_status != 'trash' AND comment_count = %d",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$post_id
		)
	);

	return $has_revisions;
}

function revisionary_refresh_postmeta($post_id, $args = []) {
	if (isset($args['num_revisions'])) {
		$num_revisions = $args['num_revisions'];
	} else {
		$num_revisions = revisionary_count_revisions($post_id, $args);
	}

	$set_value = !empty($num_revisions);

	$_post = get_post($post_id);

	if ($set_value && (empty($_post) || empty($_post->post_mime_type) || !rvy_is_revision_status($_post->post_mime_type))) {
		rvy_update_post_meta($post_id, '_rvy_has_revisions', $set_value);

	} elseif (empty($args['insert_only'])) { // avoid redundant deletions
		delete_post_meta($post_id, '_rvy_has_revisions');
	}
}

function rvy_post_revision_supported($post, $args = []) {
	if ($post) {
		if (rvy_post_revision_blocked($post, $args)) {
			return false;
		}
	}

	return true;
}

function rvy_post_revision_blocked($post, $args = []) {
	static $unfiltered_html;

	$revision_unfiltered_html_check = rvy_get_option('revision_unfiltered_html_check');

	if ($revision_unfiltered_html_check && !isset($unfiltered_html)) {
		$unfiltered_html = current_user_can('unfiltered_html');
	}

	$post_id = (is_scalar($post)) ? $post : $post->ID;

	if (1 === intval(rvy_get_option('revision_limit_per_post'))) {
		if (rvy_get_post_meta($post_id, '_rvy_has_revisions')) {
			return [
				'code' => 'blocked_revision_limit',
				'description' => __('The post already has a revision in process.', 'revisionary')
			];
		}
	}

	if ($revision_unfiltered_html_check && !$unfiltered_html && (empty($args['context']) || ('admin_posts' != $args['context']))) {
		if (is_scalar($post)) {
			$post = get_post($post);
		}

		if (!empty($post) && is_object($post) && !empty($post->post_content && (wp_filter_post_kses($post->post_content) != $post->post_content))) {
			return [
				'code' => 'blocked_unfiltered',
				'description' => __('The unfiltered_html capability is required to create a revision of this post.', 'revisionary')
			];
		}
	}

	return false;
}

if (!empty($_REQUEST['rvy_flush_flags'])) {
	check_admin_referer('flush-flags');
	revisionary_refresh_revision_flags();
}

function revisionary_refresh_revision_flags($published_post_id = 0, $args = []) {
	global $wpdb;

	$ignore_revision_ids = (!empty($args['ignore_revision_ids'])) ? (array) $args['ignore_revision_ids'] : [];

	$status_csv = implode("','", array_map('sanitize_key', rvy_filtered_statuses()));
	$revision_base_status_csv = implode("','", array_map('sanitize_key', rvy_revision_base_statuses()));
	
	$revision_statuses = rvy_revision_statuses();
	
	if (defined('REVISIONARY_LIMIT_IGNORE_UNSUBMITTED')) {
		$revision_statuses = array_diff($revision_statuses, ['draft-revision']);
	}
	
	$revision_status_csv = implode("','", array_map('sanitize_key', $revision_statuses));

	$query = "SELECT r.comment_count FROM $wpdb->posts r INNER JOIN $wpdb->posts p ON r.comment_count = p.ID"
	. " WHERE p.post_status IN ('$status_csv') AND r.post_status IN ('$revision_base_status_csv')"
	. " AND r.post_mime_type IN ('$revision_status_csv') AND p.post_mime_type NOT IN ('$revision_status_csv')";

	if ($published_post_id) {
		$query = $wpdb->prepare("$query AND p.ID = %d", $published_post_id);		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	if ($ignore_revision_ids) {
		$ignore_revisions_csv = implode("','", array_map('sanitize_key', $ignore_revision_ids));
		$query .= " AND r.ID NOT IN ('$ignore_revisions_csv')";
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$arr_have_revisions = $wpdb->get_col($query);
	
	$have_revisions = implode("','", array_map('intval', array_unique($arr_have_revisions)));

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	if ($ids = $wpdb->get_col("SELECT meta_id FROM $wpdb->postmeta WHERE meta_key = '_rvy_has_revisions' AND post_id NOT IN ('$have_revisions')")) {
		foreach ($ids as $post_id) {
			rvy_delete_post_meta($post_id, '_rvy_has_revisions');
		}
	}

	$query = "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_rvy_has_revisions'";

	if ($published_post_id) {
		$query = $wpdb->prepare("$query AND post_id = %d", $published_post_id);		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
	$have_flag_ids = $wpdb->get_col($query);

	if ($posts_missing_flag = array_diff($arr_have_revisions, $have_flag_ids)) {
		foreach($posts_missing_flag as $post_id) {
			rvy_update_post_meta($post_id, '_rvy_has_revisions', true);
		}
	}

	if ($posts_invalid_flag = array_diff($have_flag_ids, $arr_have_revisions)) {
		foreach($posts_missing_flag as $post_id) {
			rvy_delete_post_meta($post_id, '_rvy_has_revisions');
		}
	}
}

function rvy_refresh_options() {
	rvy_retrieve_options(true);
	rvy_retrieve_options(false);
	
	rvy_refresh_default_options();
	rvy_refresh_options_sitewide();
}

function rvy_refresh_options_sitewide() {
	if ( ! RVY_NETWORK )
		return;
		
	global $rvy_options_sitewide;
	$rvy_options_sitewide = apply_filters( 'options_sitewide_rvy', rvy_default_options_sitewide() );	// establishes which options are set site-wide

	if ( $options_sitewide_reviewed =  rvy_get_option( 'options_sitewide_reviewed', true ) ) {
		$custom_options_sitewide = (array) rvy_get_option( 'options_sitewide', true );
		
		$unreviewed_default_sitewide = array_diff( array_keys($rvy_options_sitewide), $options_sitewide_reviewed );

		$rvy_options_sitewide = array_fill_keys( array_merge( $custom_options_sitewide, $unreviewed_default_sitewide ), true );
	}

	// Settings which are always controlled network-wide
	$rvy_options_sitewide['delete_settings_on_uninstall'] = true;

	$rvy_options_sitewide = array_filter( $rvy_options_sitewide );
}

function rvy_refresh_default_options() {
	global $rvy_default_options;

	$rvy_default_options = apply_filters( 'default_options_rvy', rvy_default_options() );
	
	if ( RVY_NETWORK )
		rvy_apply_custom_default_options();
}

function rvy_apply_custom_default_options() {
	global $wpdb, $rvy_default_options, $rvy_options_sitewide;
	
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	if ( $results = $wpdb->get_results( 
		$wpdb->prepare(
			"SELECT meta_key, meta_value FROM $wpdb->sitemeta WHERE site_id = %d AND meta_key LIKE 'rvy_default_%'", 
			$wpdb->siteid
		)	
	) ) {
		foreach ( $results as $row ) {
			$option_basename = str_replace( 'rvy_default_', '', $row->meta_key );

			if ( ! empty( $rvy_options_sitewide[$option_basename] ) )
				continue;	// custom defaults are only for site-specific options

			if( isset( $rvy_default_options[$option_basename] ) )
				$rvy_default_options[$option_basename] = maybe_unserialize( $row->meta_value );
		}
	}
}

function rvy_delete_option( $option_basename, $sitewide = -1 ) {
	
	// allow explicit selection of sitewide / non-sitewide scope for better performance and update security
	if ( -1 === $sitewide ) {
		global $rvy_options_sitewide;
		$sitewide = isset( $rvy_options_sitewide ) && ! empty( $rvy_options_sitewide[$option_basename] );
	}

	if ( $sitewide ) {
		global $wpdb;
		$wpdb->query( 															// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"DELETE FROM {$wpdb->sitemeta} WHERE site_id = %s AND meta_key = %s",
				$wpdb->siteid,
				"rvy_$option_basename"
			)
		);
	} else 
		delete_option( "rvy_$option_basename" );
}

function rvy_update_option( $option_basename, $option_val, $sitewide = -1 ) {
	
	// allow explicit selection of sitewide / non-sitewide scope for better performance and update security
	if ( -1 === $sitewide ) {
		global $rvy_options_sitewide;
		$sitewide = isset( $rvy_options_sitewide ) && ! empty( $rvy_options_sitewide[$option_basename] );
	}
		
	if ($sitewide) {
		update_site_option("rvy_$option_basename", $option_val);
	} else { 
		update_option("rvy_$option_basename", $option_val);
	}
}

function rvy_retrieve_options( $sitewide = false ) {
	global $wpdb;
	
	if ( $sitewide ) {
		if ( ! RVY_NETWORK )
			return;

		global $rvy_site_options;
		
		$rvy_site_options = array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $results = $wpdb->get_results( 
			$wpdb->prepare(
				"SELECT meta_key, meta_value FROM $wpdb->sitemeta WHERE site_id = %d AND meta_key LIKE 'rvy_%'",
				$wpdb->siteid
			) 	
		) ) {
			foreach ( $results as $row ) {
				$rvy_site_options[$row->meta_key] = $row->meta_value;
			}
		}

		$rvy_site_options = apply_filters( 'site_options_rvy', $rvy_site_options );
		return $rvy_site_options;

	} else {
		global $rvy_blog_options;
		
		$rvy_blog_options = array();
		
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $results = $wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE 'rvy_%'") ) {
			foreach ( $results as $row ) {
				$rvy_blog_options[$row->option_name] = $row->option_value;
			}
		}
				
		$rvy_blog_options = apply_filters( 'options_rvy', $rvy_blog_options );
		return $rvy_blog_options;
	}
}

function rvy_filter_option($option_basename, $args) {
	return apply_filters("pp_revisions_option_{$option_basename}", rvy_get_option($option_basename), $args);
}

function rvy_get_option($option_basename, $sitewide = -1, $get_default = false, $args = []) {
	if (('async_scheduled_publish' == $option_basename) && function_exists('relevanssi_query')) {
		return false;
	}

	if (('scheduled_revisions' == $option_basename) && empty($args['bypass_condition_check']) 
	&& defined('DISABLE_WP_CRON') && DISABLE_WP_CRON && rvy_get_option('scheduled_publish_cron') && !rvy_get_option('wp_cron_usage_detected') && apply_filters('revisionary_wp_cron_disabled', true)
	) {
		return false;
	}
	
	if ( ! $get_default ) {
		// allow explicit selection of sitewide / non-sitewide scope for better performance and update security
		if ( -1 === $sitewide ) {
			global $rvy_options_sitewide;
			$sitewide = isset( $rvy_options_sitewide ) && ! empty( $rvy_options_sitewide[$option_basename] );
		}
	
		if ( $sitewide ) {
			// this option is set site-wide
			global $rvy_site_options;
			
			if ( ! isset($rvy_site_options) )
				$rvy_site_options = rvy_retrieve_options( true );	
				
			if ( isset($rvy_site_options["rvy_{$option_basename}"]) )
				$optval = $rvy_site_options["rvy_{$option_basename}"];
			
		} else {	
			global $rvy_blog_options;
			
			if ( ! isset($rvy_blog_options) )
				$rvy_blog_options = rvy_retrieve_options( false );	
				
			if ( isset($rvy_blog_options["rvy_$option_basename"]) )
				$optval = $rvy_blog_options["rvy_$option_basename"];
		}
	}

	if ( ! isset( $optval ) ) {
		global $rvy_default_options;
			
		if ( empty( $rvy_default_options ) ) {
			if ( did_action( 'rvy_init' ) )	// Make sure other plugins have had a chance to apply any filters to default options
				rvy_refresh_default_options();
			else {
				$hardcode_defaults = rvy_default_options();
				if ( isset($hardcode_defaults[$option_basename]) )
					$optval = $hardcode_defaults[$option_basename];	
			}
		}
		
		if ( ! empty($rvy_default_options) && ! empty( $rvy_default_options[$option_basename] ) )
			$optval = $rvy_default_options[$option_basename];
			
		if ( ! isset($optval) )
			return '';
	}

	return maybe_unserialize($optval);
}

function rvy_log_async_request($action) {						
	// the function which performs requested action will clear this entry to confirm that the asynchronous call was effective 
	$requested_actions = get_option( 'requested_remote_actions_rvy' );
	if ( ! is_array($requested_actions) )
		$requested_actions = array();
		
	$requested_actions[$action] = true;
	update_option( 'requested_remote_actions_rvy', $requested_actions );
}

function rvy_confirm_async_execution($action) {
	$requested_actions = get_option( 'requested_remote_actions_rvy' );
	if ( is_array($requested_actions) && isset($requested_actions[$action]) ) {
		unset( $requested_actions[$action] );
		update_option( 'requested_remote_actions_rvy', $requested_actions );
	} else {
		exit;
	}
}

function is_content_administrator_rvy() {
	$cap_name = defined( 'SCOPER_CONTENT_ADMIN_CAP' ) ? SCOPER_CONTENT_ADMIN_CAP : 'activate_plugins';
	return current_user_can( $cap_name );
}

function rvy_notice( $message, $class = 'error fade' ) {
	include_once( dirname(__FILE__).'/lib/error_rvy.php');
	$rvy_err = new RvyError();
	return $rvy_err->add_notice( $message, compact( 'class' ) );
}

function rvy_error( $err_slug, $arg2 = '' ) {
	include_once( dirname(__FILE__).'/lib/error_rvy.php');
	$rvy_err = new RvyError();
	$rvy_err->error_notice( $err_slug );
}

function rvy_check_duplicate_mail($new_msg, $sent_mail, $buffer) {
	foreach([$sent_mail, $buffer] as $compare_set) {
		foreach($compare_set as $sent) {
			foreach(['address', 'title', 'message'] as $field) {
				if (!isset($new_msg[$field]) 
				|| !isset($sent[$field])
				|| ($new_msg[$field] != $sent[$field])
				) {
					continue 2;
				}
			}

			$min_seconds = (defined('ET_BUILDER_PLUGIN_VERSION') || (false !== stripos(get_template(), 'divi'))) ? 20 : 5;

			// If an identical message was sent or queued to the same recipient less than 5 seconds ago, don't send another
			if (abs($new_msg['time_gmt'] - $sent['time_gmt']) <= $min_seconds) {
				return true;
			}
		}
	}
}

/*
 * wp_mail() wrapper, also implements mail buffer
 * 
 * args: 
 * 		revision_id : revision ID
 * 		post_id : published post ID
 *  	notification_type : notification type
 * 		notification_class : notification class
 */
function rvy_mail( $address, $title, $message, $args ) {
	/*
	 * [wp-cron action checks wp_option revisionary_mail_buffer. If wait time has elapsed, send buffered emails (up to limit per minute)]
	 * 
	 * If mail is already buffered to wp_option revisionary_mail_buffer, add this email to buffer
	 * 
	 * 	- or -
	 * 
	 * Check wp_option array revisionary_sent_mail
	 *   - If exceeding daily, hourly or minute limit, add this email to buffer
	 * 	 - If sending, add current timestamp to wp_option array revisionary_sent_mail
	 */
	
	$headers = '';

	$send = apply_filters('revisionary_mail', compact('address', 'title', 'message', 'headers'), $args);

	if (empty($send['address'])) {
		return;
	}

	$new_msg = array_merge($send, ['time' => strtotime(current_time( 'mysql' )), 'time_gmt' => time()], $args);

	if (!$buffer_status = rvy_mail_check_buffer($new_msg)) {
		$buffer_status = (object)[];
	}

	if (!empty($buffer_status->new_msg_buffered)) {
		return;
	}

	$sent_mail = (!empty($buffer_status->sent_mail)) ? $buffer_status->sent_mail : [];
	$buffer = (!empty($buffer_status->buffer)) ? $buffer_status->buffer : [];
	if (rvy_check_duplicate_mail($new_msg, $sent_mail, $buffer)) {
		return;
	}

	if ( defined( 'RS_DEBUG' ) )
		$success = wp_mail( $new_msg['address'], $new_msg['title'], $new_msg['message'], $new_msg['headers'] );		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail
	else
		$success = @wp_mail( $new_msg['address'], $new_msg['title'], $new_msg['message'], $new_msg['headers'] );	// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_mail_wp_mail

	if ($success || !defined('REVISIONARY_MAIL_RETRY')) {
		if (!defined('REVISIONARY_DISABLE_MAIL_LOG')) {
			if (!isset($buffer_status->sent_mail)) {
				$buffer_status->sent_mail = [];
			}

			$buffer_status->sent_mail[]= $new_msg;
			update_option('revisionary_sent_mail', $buffer_status->sent_mail);
		}
	}
}

function rvy_settings_scripts() {
	if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
		$suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '.dev' : '';
		wp_enqueue_script('revisionary-pro-settings', plugins_url('', REVISIONARY_PRO_FILE) . "/includes-pro/settings-pro{$suffix}.js", ['jquery', 'jquery-form'], PUBLISHPRESS_REVISIONS_VERSION, true);
	}
}

function rvy_omit_site_options() {
	rvy_settings_scripts();
	add_thickbox();
	include_once( RVY_ABSPATH . '/admin/options.php' );
	rvy_options( false );
}

function rvy_wp_api_request() {
	return ( function_exists('wp_api_request') ) ? wp_api_request() : false;
}

function rvy_is_status_public( $status ) {
	if ( $post_status_obj = get_post_status_object( $status ) ) {
		return ! empty( $post_status_obj->public );
	}

	return false;
}

function rvy_is_status_private( $status ) {
	if ( $post_status_obj = get_post_status_object( $status ) ) {
		return ! empty( $post_status_obj->private );
	}

	return false;
}

function rvy_is_status_published( $status ) {
	if ( $post_status_obj = get_post_status_object( $status ) ) {
		return ! empty( $post_status_obj->public ) || ! empty( $post_status_obj->private );
	}

	return false;
}

function rvy_halt( $msg, $title = '' ) {
	if ( ! $title ) {
		$title = esc_html__( 'Revision Workflow', 'revisionary' );
	}
	wp_die( esc_html($msg), esc_html($title), array( 'response' => 200 ) );
}

function _revisionary_dashboard_dismiss_msg() {
	$dismissals = get_option( 'revisionary_dismissals' );
	if ( ! is_array( $dismissals ) )
		$dismissals = array();

	$msg_id = ( isset( $_REQUEST['msg_id'] ) ) ? sanitize_key($_REQUEST['msg_id']) : 'intro_revisor_role';	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
	$dismissals[$msg_id] = true;
	update_option( 'rvy_dismissals', $dismissals );
}

function rvy_is_supported_post_type($post_type) {
	global $revisionary;

	if (empty($revisionary->enabled_post_types[$post_type]) && $revisionary->config_loaded) {
		return false;
	}

	$types = rvy_get_manageable_types();
	return !empty($types[$post_type]);
}

function rvy_get_manageable_types() {
	$types = array();
	
	global $current_user, $revisionary;
	
	if (empty($revisionary)) {
		return [];
	}

	foreach(array_keys($revisionary->enabled_post_types) as $post_type) {
		$types[$post_type]= $post_type;
	}
	
	$types = array_diff_key(
		$types, 
		array_fill_keys(
			['attachment', 'psppnotif_workflow', 'tablepress_table', 'acf-field-group', 'acf-field', 'nav_menu_item', 'custom_css', 'customize_changeset', 'wp_block', 'wp_template', 'wp_template_part', 'wp_global_styles', 'wp_navigation'], 
			true
		)
	);

	return apply_filters('revisionary_supported_post_types', $types);
}

// thanks to GravityForms for the nifty dismissal script
if (isset($_SERVER['PHP_SELF']) && in_array( basename($_SERVER['PHP_SELF']), array('admin.php', 'admin-ajax.php') ) ) {
	add_action( 'wp_ajax_rvy_dismiss_msg', '_revisionary_dashboard_dismiss_msg' );
}

function rvy_is_network_activated($plugin_file = '')
{
	if (!$plugin_file && defined('REVISIONARY_FILE')) {
		$plugin_file = plugin_basename(REVISIONARY_FILE);
	}

	return (array_key_exists($plugin_file, (array)maybe_unserialize(get_site_option('active_sitewide_plugins'))));
}

function rvy_init() {
	global $wp_roles;

	if ( ! isset( $wp_roles->roles['revisor'] ) ) {
		rvy_add_revisor_role();
	} else {
		if (!get_site_transient('revisionary_previous_install')) {
			set_site_transient('revisionary_previous_install', true, 86400);
		}
	}

	rvy_role_translation_support();

	if ( is_admin() ) {
		require_once(dirname(__FILE__).'/admin/admin-init_rvy.php');

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if (defined('REVISIONARY_BULK_ACTION_EARLY_EXECUTION') || (!isset($_REQUEST['action2']) && (empty($_REQUEST['action']) || ('decline_revision' != $_REQUEST['action'])))) {
			rvy_admin_init();
		} else {
			// bulk approval fails on some sites due to post types not registered early enough
			add_action('wp_loaded', 'rvy_admin_init');
		}
	} else {		// @todo: fix links instead
		// fill in the missing args for Pending / Scheduled revision preview link from Edit Posts / Pages
		if ( isset($_SERVER['HTTP_REFERER']) 																// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		&& ( false !== strpos( urldecode(esc_url_raw($_SERVER['HTTP_REFERER'])),'p-admin/edit-pages.php') 	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		|| false !== strpos( urldecode(esc_url_raw($_SERVER['HTTP_REFERER'])),'p-admin/edit.php') ) ) {		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

			if ( ! empty($_GET['p']) ) {																	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
				if ( rvy_get_option( 'scheduled_revisions' ) || rvy_get_option( 'pending_revisions' ) ) {
					if ( $post = get_post( sanitize_text_field($_GET['p']) ) ) {							// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
						if (rvy_in_revision_workflow($post)) {
							$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';
							$_GET[$preview_arg] = 1;
						}
					}
				}
			}
		// Is this an asynchronous request to publish scheduled revisions?
		} elseif (!empty($_GET['action']) && ('publish_scheduled_revisions' == $_GET['action']) && rvy_get_option('scheduled_revisions') 
		&& !rvy_get_option('scheduled_publish_cron')) {
			check_admin_referer('publish-scheduled-revisions');
			require_once( dirname(__FILE__).'/admin/revision-action_rvy.php');
			add_action( 'rvy_init', '_rvy_publish_scheduled_revisions' );
		}
	}
	
	if (empty($_GET['action']) || (isset($_GET['action']) && ('publish_scheduled_revisions' != $_GET['action']))) {
		if (isset($_SERVER['REQUEST_URI']) && ! strpos( esc_url_raw($_SERVER['REQUEST_URI']), 'login.php' ) && rvy_get_option( 'scheduled_revisions' ) 
		&& !rvy_get_option('scheduled_publish_cron')) {
		
			// If a previously requested asynchronous request was ineffective, perform the actions now
			// (this is not executed if the current URI is from a manual publication request with action=publish_scheduled_revisions)
			if (defined('RVY_SCHEDULED_PUBLISH_FALLBACK')) {
				$requested_actions = get_option( 'requested_remote_actions_rvy' );
				if ( is_array( $requested_actions) && ! empty($requested_actions) ) {
					if ( ! empty($requested_actions['publish_scheduled_revisions']) ) {
						require_once( dirname(__FILE__).'/admin/revision-action_rvy.php');
						rvy_publish_scheduled_revisions();
						unset( $requested_actions['publish_scheduled_revisions'] );
					}
		
					update_option( 'requested_remote_actions_rvy', $requested_actions );
				}
			}
			
			$next_publish = get_option( 'rvy_next_rev_publish_gmt' );
			
			// automatically publish any scheduled revisions whose time has come
			if ( ! $next_publish || ( agp_time_gmt() >= strtotime( $next_publish ) ) ) {
				update_option('rvy_next_rev_publish_gmt', '2035-01-01 00:00:00');

				if ( ini_get( 'allow_url_fopen' ) && rvy_get_option('async_scheduled_publish') ) {
					// asynchronous secondary site call to avoid delays // TODO: pass site key here
					rvy_log_async_request('publish_scheduled_revisions');
					$url = wp_nonce_url(site_url( 'index.php?action=publish_scheduled_revisions' ), 'publish_scheduled_revisions');
					wp_remote_post( $url, array('blocking' => false, 'sslverify' => apply_filters('https_local_ssl_verify', true)) );
				} else {
					// publish scheduled revision now
					if ( ! defined('DOING_CRON') ) {
						define( 'DOING_CRON', true );
					}
					require_once( dirname(__FILE__).'/admin/revision-action_rvy.php');
					rvy_publish_scheduled_revisions();
				}
			}	
		}
	}

	require_once( dirname(__FILE__).'/revisionary_main.php');

	global $revisionary;
	$revisionary = new Revisionary();
	$revisionary->init();
}

function rvy_is_full_editor($post, $args = []) {
	global $current_user, $revisionary;
	
	if (is_numeric($post)) {
		$post = get_post($post);
	}

	if (empty($post) || !is_object($post)) {
		return false;
	}

	if (!$type_obj = get_post_type_object($post->post_type)) {
		return false;
	}

	$cap = (!empty($type_obj->cap->edit_others_posts)) ? $type_obj->cap->edit_others_posts : $type_obj->cap->edit_posts;

	if (empty($current_user->allcaps[$cap])) {
		return false;
	}

	if (!empty($args['check_publish_caps'])) {
		if (!empty($type_obj->cap->edit_published_posts) && empty($current_user->allcaps[$type_obj->cap->edit_published_posts])) {
			return false;
		}
	} else {
		if (empty($revisionary)) {
			return false;
		}

		return $revisionary->canEditPost($post, ['simple_cap_check' => true]);
	}

	return true;
}

function rvy_is_post_author($post, $user = false) {
	if (!is_object($post)) {
		if (!$post = get_post($post)) {
			return false;
		}
	}

	if (false === $user) {
		global $current_user;
		$user_id = $current_user->ID;
	} else {
		$user_id = (is_object($user)) ? $user->ID : $user;
	}

	if (!empty($post->post_author) && ($post->post_author == $user_id)) {
		return true;

	} elseif (function_exists('is_multiple_author_for_post') && is_multiple_author_for_post($user_id, $post->ID)) {
		return true;
	}

	return false;
}

function rvy_preview_url($revision, $args = []) {
	if (is_scalar($revision)) {
		$revision = get_post($revision);
	}

	$defaults = ['post_type' => $revision->post_type];  // support preview url for past revisions, which are stored with post_type = 'revision'
	foreach(array_keys($defaults) as $var) {
		$$var = (!empty($args[$var])) ? $args[$var] : $defaults[$var]; 
	}

	if ('revision' == $post_type) {
		$post_type = get_post_field('post_type', $revision->post_parent);
	} else {
		if ($post_type_obj = get_post_type_object($revision->post_type)) {
			if (empty($post_type_obj->public) && !defined('FL_BUILDER_VERSION') && !apply_filters('revisionary_private_type_use_preview_url', false, $revision)) { // For non-public types, preview is not available so default to Compare Revisions screen
				return apply_filters('revisionary_preview_url', rvy_admin_url("revision.php?revision=$revision->ID"), $revision, $args);
			}
		}
	}
	$post_type = sanitize_key($post_type);

	$link_type = apply_filters(
		'revisionary_preview_link_type',
		rvy_get_option('preview_link_type'),
		$revision
	);

	$status_obj = get_post_status_object(get_post_field('post_status', rvy_post_id($revision->ID)));
	$post_is_published = $status_obj && (!empty($status_obj->public) || !empty($status_obj->private));

	$preview_arg = (defined('RVY_PREVIEW_ARG')) ? sanitize_key(constant('RVY_PREVIEW_ARG')) : 'rv_preview';

	if ('id_only' == $link_type) {
		// support using ids only if theme or plugins do not tolerate published post url and do not require standard format with revision slug
		$preview_url = add_query_arg($preview_arg, true, get_post_permalink($revision));

		if ('page' == $post_type) {
			$preview_url = str_replace('p=', "page_id=", $preview_url);
			$id_arg = 'page_id';
		} else {
			$id_arg = 'p';
		}
	} elseif (('revision_slug' == $link_type) || !$post_is_published) {
		// support using actual revision slug in case theme or plugins do not tolerate published post url
		$preview_url = add_query_arg($preview_arg, true, get_permalink($revision));

		if ('page' == $post_type) {
			$preview_url = str_replace('p=', "page_id=", $preview_url);
			$id_arg = 'page_id';
		} else {
			$id_arg = 'p';
		}
	} else { // 'published_slug'
		$published_post_id = rvy_post_id($revision->ID);
		
		if (('page' === get_option('show_on_front')) && in_array(get_option('page_on_front'), [$published_post_id, $revision->ID])) {
			$id_arg = 'page__id';
		} else {
			$id_arg = 'page_id';
		}

		// default to published post url, appended with 'preview' and page_id args
		$preview_url = add_query_arg($preview_arg, true, get_permalink($published_post_id));
	}

	if (strpos($preview_url, "{$id_arg}=")) {
		$preview_url = remove_query_arg($id_arg, $preview_url);
	}
	
	$preview_url = add_query_arg($id_arg, $revision->ID, $preview_url);

	if (!strpos($preview_url, "post_type=")) {
		$preview_url = add_query_arg('post_type', $post_type, $preview_url);
	}

	if (!defined('REVISIONARY_PREVIEW_NO_CACHEBUST')) {
		$preview_url = rvy_nc_url($preview_url);
	}

	$preview_url = apply_filters('revisionary_preview_url', $preview_url, $revision, $args);
	$preview_url = remove_query_arg('preview_id', $preview_url);
	
	return $preview_url;
}

function rvy_set_ma_post_authors($post_id, $authors)
{
	require_once( dirname(__FILE__).'/multiple-authors_rvy.php');
	_rvy_set_ma_post_authors_custom_field($post_id, $authors);

	$authors = wp_list_pluck($authors, 'term_id');
	wp_set_object_terms($post_id, $authors, 'author');
}

function rvy_filtered_statuses($args = []) {
	$defaults = ['output' => 'names', 'return' => 'array'];
	$args = array_merge($defaults, $args);
	foreach (array_keys($defaults) as $var) {
		$$var = $args[$var];
	}

	$arr = apply_filters(
		'revisionary_main_post_statuses', 
		get_post_stati( ['public' => true, 'private' => true], $output, 'or' ),
		$output
	);

	return ('csv' == $return) ? "'" . implode("','", $arr) . "'" : $arr;
}

// REST API Cache plugin compat
add_action('init', 'rvy_rest_cache_compat', 9999);

function rvy_rest_cache_compat() {
	global $wp_post_types;

	if (!isset($_SERVER['REQUEST_URI'])) {
		return;
	}

	$uri = esc_url_raw($_SERVER['REQUEST_URI']);

	$rest_cache_active = false;
	foreach(['rvy_ajax_field', 'rvy_ajax_value'] as $param) {
		if (strpos($uri, $param)) {
			$rest_cache_active = true;
			break;
		}
	}

	$rest_cache_active = $rest_cache_active 
	|| (strpos($uri, '_locale=user') && strpos($uri, 'wp-json') && strpos($uri, '/posts/') && rvy_is_plugin_active('wp-rest-cache/wp-rest-cache.php'));

	if ($rest_cache_active) {
		foreach(array_keys($wp_post_types) as $key) {
			if ((!empty($wp_post_types[$key]->rest_controller_class) && is_string($wp_post_types[$key]->rest_controller_class)) && false !== strpos('WP_Rest_Cache_Plugin', $wp_post_types[$key]->rest_controller_class)) {
				$wp_post_types[$key]->rest_controller_class = ('attachment' == $key) ? 'WP_REST_Attachments_Controller' : 'WP_REST_Posts_Controller';
			}
		}
	}
}

// REST API Cache plugin compat
add_filter('wp_rest_cache/skip_caching', 'rvy_rest_cache_skip');

function rvy_rest_cache_skip($skip) {
	if (!isset($_SERVER['REQUEST_URI'])) {
		return $skip;
	}

	$uri = esc_url_raw($_SERVER['REQUEST_URI']);
	$uncached_params = array_merge($uncached_params, ['rvy_ajax_field', 'rvy_ajax_value']);

	foreach($uncached_params as $param) {
		if (strpos($uri, $param)) {
			$skip = true;
			break;
		}
	}

	return $skip;
}

function pp_revisions_body_class() {
	$classes = body_class();

	if (function_exists('rvy_post_id')
	&& ('page' === get_option('show_on_front'))
	&& (rvy_post_id(get_the_ID()) == get_option('page_on_front'))) {
		$classes []= 'home';
	}

	return $classes;
}

function rvy_create_revision($post_id, $revision_status, $args = [], $revision_data = []) {
	require_once( dirname(REVISIONARY_FILE).'/revision-creation_rvy.php' );
	$rvy_creation = new PublishPress\Revisions\RevisionCreation();
	return $rvy_creation->createRevision($post_id, $revision_status, ['redirect_url' => false], $revision_data);
}
