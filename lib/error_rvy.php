<?php
defined('ABSPATH') || exit;

class RvyError {
	private $notices = array();

	function error_notice( $err ) {
		return true;
	}

	function error_box( $msg ) {
		global $pagenow;
		
		if ( isset($pagenow) && ( 'update.php' != $pagenow ) ) {
			$this->add_notice( $msg );
		}
	}

	function add_notice( $body, $args = array() ) {
		if ( ! $this->notices ) {
			add_action( 'all_admin_notices', array( &$this, 'do_notices'), 5 );
		}

		$this->notices[]= (object) array_merge( compact( 'body' ), $args );
	}

	function do_notices() {
		foreach( $this->notices as $msg ) {
			$style = ( ! empty( $msg->style ) ) ? $msg->style : "color:black";
			$class = ( ! empty( $msg->class ) ) ? $msg->class : '';

			echo "<div id='message' class='error fade' style='" . esc_attr($style) . "' class='" . esc_attr($class) . "'>" 
			. $msg->body 	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			. '</div>';
		}
	}
} // end class
