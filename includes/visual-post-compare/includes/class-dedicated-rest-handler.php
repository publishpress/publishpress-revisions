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
					'revision'   => array( 'required' => true, 'sanitize_callback' => 'absint' ),

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
					'revision'   => array( 'required' => true, 'sanitize_callback' => 'absint' ),

				),
			)
		);
	}

	public static function comparison_permissions( \WP_REST_Request $request ) {
		if ( $request['revision'] ) {
			if (!$current_post_id = wp_is_post_revision($request['revision'])) {
				if (rvy_in_revision_workflow($request['revision'])) {
					$current_post_id = rvy_post_id($request['revision']);
				}
			}

			if ( $current_post_id && current_user_can( 'read_post', $current_post_id ) && current_user_can( 'read_post', $request['revision'] ) ) {
				return true;
			}
		}

		return new \WP_Error( 'vpc_forbidden', __( 'You are not allowed to view the revision.', 'revisionary' ), array( 'status' => 403 ) );
	}

	public static function comparison_response( \WP_REST_Request $request ) {
		$revision = get_post( $request['revision'] );

		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		if ( $revision ) {
			return rest_ensure_response(
				Visual_Post_Compare_Dedicated_Payload_Builder::build(
					$revision

				)
			);
		} else {
			return new \WP_Error( 'vpc_invalid_revision', __( 'Invalid revision ID.', 'revisionary' ), array( 'status' => 400 ) );
		}
	}

	public static function approve_permissions( \WP_REST_Request $request ) {
		if ( $request['revision'] ) {
			if ($past_revision_parent = wp_is_post_revision($request['revision'])) {
				if ( current_user_can( 'read_post', $request['revision'] ) && current_user_can( 'edit_post', $past_revision_parent ) ) {
					return true;
				}
			} elseif ( rvy_in_revision_workflow($request['revision']) ) {
				if ( current_user_can( 'approve_revision', $request['revision'] ) ) {
					return true;
				}
			}
		}
			
		return new \WP_Error( 'vpc_forbidden', __('You are not allowed to approve the revision.', 'revisionary'), array( 'status' => 403 ) );
	}

	public static function approve_response( \WP_REST_Request $request ) {
		$revision = get_post( $request['revision'] );

		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		
		if ( $revision ) {
			require_once( dirname(REVISIONARY_FILE).'/admin/revision-action_rvy.php' );
			$result = \rvy_apply_revision($revision->ID);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$response = Visual_Post_Compare_Dedicated_Payload_Builder::build(
				$revision

			);

			$response['approved'] = true;

			return rest_ensure_response( $response );
		} else {
			return new \WP_Error( 'vpc_invalid_revision', __( 'Invalid revision ID.', 'revisionary' ), array( 'status' => 400 ) );
		}
	}
}
