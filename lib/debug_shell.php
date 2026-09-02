<?php // avoid bombing out if the actual debug file is not loaded
if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw(wp_unslash($_SERVER['SCRIPT_FILENAME']))) )
	die();

if ( ! function_exists('d_echo') ) {
function d_echo($str) {
	return;
}
}

if ( ! function_exists('rvy_errlog') ) {
	function rvy_errlog($message, $line_break = true) {
		return;
	}
}

if ( ! function_exists('agp_bt_die') ) {
function agp_bt_die() {
	return;
}
}

if ( ! function_exists('rvy_memory_new_usage') ) {
function rvy_memory_new_usage () {
	return;
}
}

if ( ! function_exists('rvy_log_mem_usage') ) {
function rvy_log_mem_usage( $label, $display_total = true ) {
	return;
}
}

if ( ! function_exists('dump') && !defined('REVISIONARY_NO_DUMP_FUNCTION') ) {
function dump(&$var, $info = FALSE, $display_objects = true) { 		// phpcs:ignore PublishPressStandards.Debug.DisallowDebugFunctions.FoundDumpFunction
	return; 
}
}
