<?php
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die();
	

class Revisionary_Submittee {

	function handle_submission($action, $sitewide = false, $customize_defaults = false) {
		if ( ( $sitewide || $customize_defaults ) ) {
			if ( ! is_super_admin() )
				wp_die('');
		
		} elseif ( ! current_user_can( 'manage_options' ) )
			 wp_die('');

		if ( $customize_defaults )
			$sitewide = true;		// default customization is only for per-site options, but is network-wide in terms of DB storage in sitemeta table
		
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
		if (isset($_GET["page"]) && false === strpos( sanitize_key($_GET["page"]), 'revisionary-' ) && false === strpos( sanitize_key($_GET["page"]), 'rvy-' ) )
			return;
		
		if ( empty($_POST['rvy_submission_topic']) )			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			return;
		
		if ( 'options' == $_POST['rvy_submission_topic'] ) {	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			rvy_refresh_default_options();

			$method = "{$action}_options";
			if ( method_exists( $this, $method ) )
				call_user_func( array($this, $method), $sitewide, $customize_defaults );

			if ( $sitewide && ! $customize_defaults ) {
				$method = "{$action}_sitewide";
				if ( method_exists( $this, $method ) )
					call_user_func( array($this, $method) );
			}
		}

		rvy_refresh_options();
	}
	
	function update_options( $sitewide = false, $customize_defaults = false ) {
		global $wpdb, $rvy_options_sitewide;
		
		check_admin_referer( 'rvy-update-options' );

		$default_prefix = ( $customize_defaults ) ? 'default_' : '';
		
		if (!empty($_POST['all_options'])) {
			$reviewed_options = array_map('sanitize_key', explode(',', sanitize_text_field($_POST['all_options'])));

			foreach ( $reviewed_options as $option_basename ) {
				if (isset($_POST[$option_basename])) {
					if (is_array($_POST[$option_basename])) {
						$value = array_map('sanitize_key', $_POST[$option_basename]);
					} else {
						if (is_numeric($_POST[$option_basename])) {
							$value = intval($_POST[$option_basename]);
						} else {
							$value = sanitize_key($_POST[$option_basename]);
						}
					}
				} else {
					$value = '';
				}

				if ('permissions_compat_mode' == $option_basename) {
					$current_val = rvy_get_option('permissions_compat_mode');

					if ($current_val != $value) {
						add_action(
							'init',
							function() use ($value) {
								global $wpdb, $rvy_options_sitewide;

								$orig_site_id = get_current_blog_id();

								if (is_multisite() && defined('RVY_NETWORK') && RVY_NETWORK) {
									if (!isset($rvy_options_sitewide)) {
										rvy_refresh_options_sitewide();
									}

									$network_setting = isset($rvy_options_sitewide) && ! empty($rvy_options_sitewide['permissions_compat_mode']);

									$site_ids = ($network_setting && function_exists('get_sites')) ? get_sites(['fields' => 'ids']) : (array) $orig_site_id;
								} else {
									$site_ids = (array) $orig_site_id;
								}

								// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

								foreach ($site_ids as $_blog_id) {
									if (count($site_ids) > 1) {
										switch_to_blog($_blog_id);
									}

									$revision_statuses = rvy_revision_statuses();
									
									foreach ($revision_statuses as $revision_status) {
										$base_status = ('draft-revision' == $revision_status) ? 'draft' : 'pending';

										if ($value) {
											// switching to Enhanced Revision access control (store revision status to post_status column)
											$wpdb->query(	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
												$wpdb->prepare(
													"UPDATE $wpdb->posts SET post_status = %s WHERE (comment_count != 0 AND post_mime_type = %s)",
													$revision_status,
													$revision_status
												)
											);
										} else {
											// switching to Broadest Compat mode (store base status to post_status column)
											$wpdb->query(	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
												$wpdb->prepare(
													"UPDATE $wpdb->posts SET post_status = %s WHERE (comment_count != 0 AND post_mime_type = %s)",
													$base_status,
													$revision_status
												)
											);
										}
									}
								}

								if (count($site_ids) > 1) {
									switch_to_blog($orig_site_id);
								}
							}
						, 9999);
					}
				}

				rvy_update_option( $default_prefix . $option_basename, $value, $sitewide );
			}
		}

		$wpdb->query( 											// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			"UPDATE $wpdb->options SET autoload = 'no' WHERE (option_name LIKE 'rvy_%' OR option_name LIKE 'revisionary_%') AND option_name != 'rvy_next_rev_publish_gmt'" 
		);
	}
	
	function default_options( $sitewide = false, $customize_defaults = false ) {
		check_admin_referer( 'rvy-update-options' );
	
		$default_prefix = ( $customize_defaults ) ? 'default_' : '';

		if (!empty($_POST['all_options'])) {
			$reviewed_options = array_map('sanitize_key', explode(',', sanitize_text_field($_POST['all_options'])));
			foreach ( $reviewed_options as $option_name ) {
				rvy_delete_option($default_prefix . $option_name, $sitewide );
			}
		}
	}
	
	function update_sitewide() {
		check_admin_referer( 'rvy-update-options' );
		
		//phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$reviewed_options = isset($_POST['rvy_all_movable_options']) ? array_map('sanitize_key', explode(',', $_POST['rvy_all_movable_options'])) : array();
		

		$options_sitewide = isset($_POST['rvy_options_sitewide']) ? array_map('sanitize_key', (array) $_POST['rvy_options_sitewide']) : array();

		update_site_option( "rvy_options_sitewide_reviewed", $reviewed_options );
		update_site_option( "rvy_options_sitewide", $options_sitewide );
	}
	
	function default_sitewide() {
		check_admin_referer( 'rvy-update-options' );

		rvy_delete_option( 'options_sitewide', true );
		rvy_delete_option( 'options_sitewide_reviewed', true );
	}
	
	function update_page_options( $sitewide = false, $customize_defaults = false ) {
		// deprecated (moved into calling function)
	}
}
