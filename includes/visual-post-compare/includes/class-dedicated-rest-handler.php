<?php
namespace PublishPress;

/**
 * Registers and handles REST endpoints for the dedicated compare_only screen.
 *
 * Loaded lazily only when the current REST URL targets this plugin namespace.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Visual_Post_Compare_Dedicated_REST_Handler {
	public static function register_routes() {
		register_rest_route(
			Visual_Post_Compare::REST_NS,
			'/comparison',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'permission_callback' => array( __CLASS__, 'comparison_permissions' ),
				'callback'            => array( __CLASS__, 'comparison_response' ),
				'args'                => array(
					'from'       => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'to'         => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'comparison' => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);

		register_rest_route(
			Visual_Post_Compare::REST_NS,
			'/approve',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'approve_permissions' ),
				'callback'            => array( __CLASS__, 'approve_response' ),
				'args'                => array(
					'from'       => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'to'         => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'comparison' => array( 'required' => false, 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	public static function comparison_permissions( \WP_REST_Request $request ) {
		$from = absint( $request['from'] );
		$to   = absint( $request['to'] );

		if ( ! $from || ! $to || $from === $to ) {
			return new \WP_Error( 'vpc_invalid_pair', __( 'Invalid comparison post IDs.', 'revisionary' ), array( 'status' => 400 ) );
		}

		if ( ! get_post( $from ) || ! get_post( $to ) ) {
			return new \WP_Error( 'vpc_missing_post', __( 'One of the comparison posts could not be found.', 'revisionary' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'read_post', $from ) || ! current_user_can( 'read_post', $to ) ) {
			return new \WP_Error( 'vpc_forbidden', __( 'You are not allowed to read both comparison posts.', 'revisionary' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public static function comparison_response( \WP_REST_Request $request ) {
		$from = get_post( absint( $request['from'] ) );
		$to   = get_post( absint( $request['to'] ) );

		if ( ! $from || ! $to ) {
			return new \WP_Error( 'vpc_missing_post', __( 'One of the comparison posts could not be found.', 'revisionary' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'read_post', $from ) || ! current_user_can( 'read_post', $to ) ) {
			return new \WP_Error( 'vpc_forbidden', __( 'You are not allowed to read both comparison posts.', 'revisionary' ), array( 'status' => 403 ) );
		}

		return rest_ensure_response(
			Visual_Post_Compare_Dedicated_Payload_Builder::build(
				$from,
				$to,
				sanitize_key( (string) $request['comparison'] )
			)
		);
	}

	public static function approve_permissions( \WP_REST_Request $request ) {
		$from = absint( $request['from'] );
		$to   = absint( $request['to'] );

		if ( ! $from || ! $to || $from === $to ) {
			return new \WP_Error( 'vpc_invalid_pair', __( 'Invalid comparison post IDs.', 'revisionary' ), array( 'status' => 400 ) );
		}

		if ( ! get_post( $from ) || ! get_post( $to ) ) {
			return new \WP_Error( 'vpc_missing_post', __( 'One of the comparison posts could not be found.', 'revisionary' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'read_post', $to ) ) {
			return new \WP_Error( 'vpc_forbidden_source', __( 'You are not allowed to read the approved post.', 'revisionary' ), array( 'status' => 403 ) );
		}

		if ( ! current_user_can( 'edit_post', $from ) ) {
			return new \WP_Error( 'vpc_forbidden', __( 'You are not allowed to update the target post.', 'revisionary' ), array( 'status' => 403 ) );
		}

		$past_revision_parent = wp_is_post_revision($to);

		if ( ($past_revision_parent && !current_user_can( 'edit_post', $from )) || (!$past_revision_parent && !current_user_can( 'approve_revision', $to ))) {
			return new \WP_Error( 'vpc_forbidden', __('You are not allowed to approve the revision.', 'revisionary'), array( 'status' => 403 ) );
		}

		if (rvy_in_revision_workflow($to) && (rvy_post_id($to) != $from)) {
			return new \WP_Error( 'vpc_forbidden', __( 'Revision does not match target post.', 'revisionary' ), array( 'status' => 403 ) );
		}

		if ($past_revision_parent && ($past_revision_parent != $from)) {
			return new \WP_Error( 'vpc_forbidden', __( 'Revision does not match target post.', 'revisionary' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public static function approve_response( \WP_REST_Request $request ) {
		$from = get_post( absint( $request['from'] ) );
		$to   = get_post( absint( $request['to'] ) );

		if ( ! $from || ! $to ) {
			return new \WP_Error( 'vpc_missing_post', __( 'One of the comparison posts could not be found.', 'revisionary' ), array( 'status' => 404 ) );
		}

		if ( ! current_user_can( 'edit_post', $from->ID ) ) {
			return new \WP_Error( 'vpc_forbidden', __( 'You are not allowed to update the target post.', 'revisionary' ), array( 'status' => 403 ) );
		}
		
		$past_revision_parent = wp_is_post_revision($to->ID);

		if ( ($past_revision_parent && !current_user_can( 'edit_post', $from->ID )) || (!$past_revision_parent && !current_user_can( 'approve_revision', $to->ID ))) {
			return new \WP_Error( 'vpc_forbidden', __('You are not allowed to approve the revision.', 'revisionary'), array( 'status' => 403 ) );
		}

		require_once( dirname(REVISIONARY_FILE).'/admin/revision-action_rvy.php');
		$result = \rvy_apply_revision($to->ID);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$from     = get_post( $from->ID );
		$response = Visual_Post_Compare_Dedicated_Payload_Builder::build(
			$from,
			$to,
			sanitize_key( (string) $request['comparison'] )
		);
		$response['approved'] = true;

		return rest_ensure_response( $response );
	}
}
