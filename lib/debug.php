<?php
/**
 * @phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_error_log, WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
 * @phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_var_dump, PublishPressStandards.Debug.DisallowDebugFunctions.FoundVarDumpFunction
 * @phpcs:disable PublishPressStandards.Debug.DisallowDebugFunctions.FoundDumpFunction
 * @phpcs:disable WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_is_writable
 */

if (!empty($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw(wp_unslash($_SERVER['SCRIPT_FILENAME']))) )
	die();
	
	
if ( ! function_exists('d_echo') ) {
function d_echo($str) {
	return;
}
}

if ( ! function_exists('rvy_errlog') ) {
	function rvy_errlog($message, $line_break = true) {
		if ( ! defined('RS_DEBUG') )
			return;

		$append = ( $line_break ) ? "\r\n" : '';
		
		if ( defined('RVY_DEBUG_LOGFILE') )
			error_log($message . $append, 3, RVY_DEBUG_LOGFILE);
	}
}

// RS < 1.1 was missing the function_exists check for agp_bt_die()
if ( ! function_exists('rvy_bt_die') ) {
function rvy_bt_die( $die = true ) {
	if ( ! defined('RS_DEBUG') )
		return;

    if (defined('REVISIONARY_NO_DUMP_FUNCTION')) {
        $bt = debug_backtrace();
        var_dump($bt);
    } else {
	    dump(debug_backtrace(),false,false);
    }
	
	if ( $die )
		die;
}
}


if ( ! function_exists('rvy_memory_new_usage') ) {
function rvy_memory_new_usage () {
	if ( ! defined('RS_DEBUG') || defined('SCOPER_SKIP_MEMORY_LOG') )
		return;
	
	static $last_mem_usage;
	
	if ( ! isset($last_mem_usage) )
		$last_mem_usage = 0;
	
	$current_mem_usage = memory_get_usage(true);
	$new_mem_usage = $current_mem_usage - $last_mem_usage;
	$last_mem_usage = $current_mem_usage;
	
	return $new_mem_usage;
}
}

if ( ! function_exists('rvy_log_mem_usage') ) {
function rvy_log_mem_usage( $label, $display_total = true ) {
	if ( ! defined('RS_DEBUG') || defined('SCOPER_SKIP_MEMORY_LOG') )
		return;
		
	$total = $display_total ? " (" . memory_get_usage(true) . ")" : '';
		
	rvy_errlog($label);
	rvy_errlog( rvy_memory_new_usage() . $total );
	rvy_errlog( '' );
}
}


////////////////////////////////////////////////////////
// Function:         dump
// Inspired from:     PHP.net Contributions
// Description: Helps with php debugging
//
// Revision by PublishPress
//		* display_objects optional arg 
//		* htmlspecialchars filtering if variable is a string containing '<'
//
// highstrike at gmail dot com
// http://us2.php.net/manual/en/function.print-r.php#80289
if ( ! function_exists('dump') && !defined('REVISIONARY_NO_DUMP_FUNCTION') ) {
function dump(&$var, $info = FALSE, $display_objects = true)
{	
	return var_dump($var);
}
}

////////////////////////////////////////////////////////
// Function:         do_dump
// Inspired from:     PHP.net Contributions
// Description: Better GI than print_r or var_dump

if ( ! function_exists('do_dump') && !defined('REVISIONARY_NO_DUMP_FUNCTION') ) {
function do_dump(&$var, $display_objects = true, $var_name = NULL, $indent = NULL, $reference = NULL)
{
}
}

function rvy_usage_message( $translate = true ) {
	if ( function_exists('memory_get_usage') ) {
		if ( $translate )
			return sprintf( esc_html__('%1$s queries in %2$s seconds. %3$s MB used.', 'revisionary'), get_num_queries(), round(timer_stop(0), 1), round( memory_get_usage() / (1024 * 1024), 3) ) . ' ';
		else
			return get_num_queries() . ' queries in ' . round(timer_stop(0), 1) . ' seconds. ' . round( memory_get_usage() / (1024 * 1024), 3) . ' MB used. ';
	}
}

function rvy_echo_usage_message( $translate = true ) {
	if ( ! defined( 'AGP_USAGE_MESSAGE_DONE' )  && ! defined( 'AGP_NO_USAGE_MSG' ) ) {  // Revisionary outputs its own message
		echo esc_html(rvy_usage_message( $translate ));
		define( 'AGP_USAGE_MESSAGE_DONE', true );
	}
}
