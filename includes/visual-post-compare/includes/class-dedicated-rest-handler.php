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
					'select_current' => array( 'required' => false, 'sanitize_callback' => 'rest_sanitize_boolean' ),
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

		register_rest_route(
			Visual_Post_Compare::REST_NS,
			'/comparison-mode',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'permission_callback' => array( __CLASS__, 'comparison_permissions' ),
				'callback'            => array( __CLASS__, 'comparison_mode_response' ),
				'args'                => array(
					'revision'          => array( 'required' => true, 'sanitize_callback' => 'absint' ),
					'compare_to_current' => array( 'required' => true, 'sanitize_callback' => 'rest_sanitize_boolean' ),
				),
			)
		);
	}

	public static function comparison_permissions( \WP_REST_Request $request ) {
		$context = self::authorize_revision_request( $request );

		if ( ! is_wp_error( $context ) ) {
			return true;
		}

		return $context;
	}

	public static function comparison_response( \WP_REST_Request $request ) {
		$revision = get_post( $request['revision'] );

		if ( is_wp_error( $revision ) ) {
			return $revision;
		}

		if ( $revision ) {
			return rest_ensure_response(
				Visual_Post_Compare_Dedicated_Payload_Builder::build(
					$revision,
					'',
					array( 'select_current' => rest_sanitize_boolean( $request['select_current'] ) )
				)
			);
		} else {
			return new \WP_Error( 'vpc_invalid_revision', esc_html__( 'Invalid revision ID.', 'revisionary' ), array( 'status' => 400 ) );
		}
	}

	public static function comparison_mode_response( \WP_REST_Request $request ) {
		$revision = get_post( $request['revision'] );
		if ( ! $revision || ! wp_is_post_revision( $revision ) ) {
			return new \WP_Error( 'vpc_invalid_revision', esc_html__( 'Invalid revision ID.', 'revisionary' ), array( 'status' => 400 ) );
		}

		update_user_meta(
			get_current_user_id(),
			Visual_Post_Compare_Dedicated_Payload_Builder::PAST_COMPARE_USER_OPTION,
			rest_sanitize_boolean( $request['compare_to_current'] ) ? '1' : '0'
		);

		return rest_ensure_response( Visual_Post_Compare_Dedicated_Payload_Builder::build( $revision ) );
	}

	public static function approve_permissions( \WP_REST_Request $request ) {
		$context = self::authorize_revision_request( $request );

		if (
			! is_wp_error( $context )
			&& (
				current_user_can( 'approve_revision', $context['revision']->ID )
				|| current_user_can( 'edit_post', $context['current_post']->ID )
			)
			&& Visual_Post_Compare::can_apply_revision( $context['revision'] )
		) {
			return true;
		}

		return new \WP_Error( 'vpc_forbidden', esc_html__('You are not allowed to approve the revision.', 'revisionary'), array( 'status' => 403 ) );
	}

	private static function authorize_revision_request( \WP_REST_Request $request ) {
		if ( empty( $request['revision'] ) ) {
			return new \WP_Error( 'vpc_invalid_revision', esc_html__( 'Invalid revision ID.', 'revisionary' ), array( 'status' => 400 ) );
		}

		$context = Visual_Post_Compare::authorized_comparison( $request['revision'] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}

		if (
			! current_user_can( 'read_post', $context['revision']->ID )
			|| ! current_user_can( 'read_post', $context['current_post']->ID )
		) {
			return new \WP_Error( 'vpc_forbidden', esc_html__( 'You are not allowed to view the revision.', 'revisionary' ), array( 'status' => 403 ) );
		}

		return $context;
	}

	public static function approve_response( \WP_REST_Request $request ) {
		$revision = get_post( $request['revision'] );

		if ( is_wp_error( $revision ) ) {
			return $revision;
		}
		
		if ( $revision && ! Visual_Post_Compare::can_apply_revision( $revision ) ) {
			return new \WP_Error( 'vpc_forbidden', esc_html__('You are not allowed to approve the revision.', 'revisionary'), array( 'status' => 403 ) );
		}

		if ( $revision ) {
			require_once( dirname(REVISIONARY_FILE).'/admin/revision-action_rvy.php' );
			$result = \rvy_apply_revision($revision->ID);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			clean_post_cache( $revision->ID );
			$updated_revision = get_post( $revision->ID );
			$response = Visual_Post_Compare_Dedicated_Payload_Builder::build(
				$updated_revision ?: $revision

			);

			$response['approved'] = true;
			$response['selectCurrentPost'] = (bool) wp_is_post_revision( $updated_revision ?: $revision );

			return rest_ensure_response( $response );
		} else {
			return new \WP_Error( 'vpc_invalid_revision', esc_html__( 'Invalid revision ID.', 'revisionary' ), array( 'status' => 400 ) );
		}
	}
}
