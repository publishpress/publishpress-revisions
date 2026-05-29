<?php
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die();

// separated these functions into separate module for use by RS extension plugins

// TODO: move these function to core-admin_lib.php, update extensions accordingly

if ( ! function_exists('awp_is_mu') ) {
function awp_is_mu() {
	global $wpdb, $wpmu_version;
	
	return ( ( defined('MULTISITE') && MULTISITE ) || function_exists('get_current_site_name') || ! empty($wpmu_version) || ( ! empty( $wpdb->base_prefix ) && ( $wpdb->base_prefix != $wpdb->prefix ) ) );
}
}

// returns true GMT timestamp
if ( ! function_exists('agp_time_gmt') ) {
function agp_time_gmt() {	
	return strtotime( gmdate("Y-m-d H:i:s") );
}
}

// date_i18n does not support pre-1970 dates
if ( ! function_exists('agp_date_i18n') ) {
function agp_date_i18n( $datef, $timestamp ) {
	if ( $timestamp >= 0 )
		return date_i18n( $datef, $timestamp );
	else
		return gmdate( $datef, $timestamp );
}
}

// legacy support for obsolete wrapper function
if ( ! function_exists('agp_user_can') ) {
function agp_user_can($reqd_caps, $object_id = 0, $user_id = 0, $args = array() ) {
	return current_user_can($reqd_caps, $object_id);
}
}

if ( ! function_exists('awp_post_type_from_uri') ) {
function awp_post_type_from_uri() {
	if (!empty($_SERVER['SCRIPT_NAME'])) {
		$script_name = esc_url_raw($_SERVER['SCRIPT_NAME']);
	} else {
		$script_name = '';
	}
	
	if ( strpos( $script_name, 'post-new.php' ) || strpos( $script_name, 'edit.php' ) ) {
		$object_type = ! empty( $_GET['post_type'] ) ? sanitize_key($_GET['post_type']) : 'post';	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		
	} elseif ( ! empty( $_GET['post'] ) ) {	 														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( $_post = get_post((int) $_GET['post'] ) )												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$object_type = $_post->post_type;
	}

	if ( ! empty($object_type) )
		return $object_type;
	else {
		global $post;
		if ( ! empty($post->post_type) )
			return $post->post_type;
		else
			return 'post';
	}
}
}
