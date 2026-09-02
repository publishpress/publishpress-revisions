<?php
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw(wp_unslash($_SERVER['SCRIPT_FILENAME']))) )
	die();

// Decipher the ever-changing meta/advanced action names into a version-insensitive question:
// "Has metabox drawing been initiated?"
function rvy_metaboxes_started() {
	return did_action('edit_form_advanced') || did_action('edit_page_form');
}

function rvy_include_admin_revisions() {
	include_once( RVY_ABSPATH . '/admin/revisions.php' );
}
