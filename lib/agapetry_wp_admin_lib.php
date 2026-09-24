<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Decipher the ever-changing meta/advanced action names into a version-insensitive question:
// "Has metabox drawing been initiated?"
function rvy_metaboxes_started() {
	return did_action('edit_form_advanced') || did_action('edit_page_form');
}

function rvy_include_admin_revisions() {
	include_once( RVY_ABSPATH . '/admin/revisions.php' );
}
