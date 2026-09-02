<?php
namespace PublishPress;

/**
 * Description: Compare posts using the WordPress 7.0+ visual revisions interface, including in-edit panel and read-only comparison screens.
 * 
 * Specification, Testing, Review, Debug and Optimization: PublishPress
 * Generator: ChatGPT 5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Visual_Post_Compare {
	const PAGE_SLUG       = 'rvy-visual-compare';
	const REST_NS         = 'rvy-visual-compare/v1';

	/**
	 * Defines the reusable comparison sidebar instances.
	 *
	 * Each instance accepts post IDs and/or WP_Post objects. Optional sorting and
	 * presentation settings are supplied through the fourth $args argument.
	 *
	 * @return array[]
	 */
	public static function comparison_sidebar_definitions($key = '', $posts = []) {
		static $sidebars;

		if (empty($current_post_id)) {
			$current_post_id = rvy_detect_post_id();
		}

		if (!empty($sidebars)) {
			return $sidebars;
		}

		$sidebars = [];
		
		return apply_filters('visual_post_compare_sidebars', $sidebars, $current_post_id, '\PublishPress\Visual_Post_Compare', compact('key', 'posts'));
	}

	/**
	 * Builds one reusable comparison sidebar definition.
	 *
	 * @param string             $key        Stable sidebar key.
	 * @param string             $label      Sidebar heading.
	 * @param array<int|WP_Post> $post_items Post IDs and/or WP_Post objects.
	 * @param array              $args       Optional sorting and presentation arguments.
	 * @return array
	 */
	public static function comparison_sidebar_definition( $key, $label, array $post_items, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'sort_by'                     => 'post_modified',
				'sort_order'                  => 'DESC',
				'show_right_status'           => true,
				'mime_type_status'			  => false,
				'show_modified'               => true,
				'show_post_date'              => false,
				'post_date_prefix'            => __('Post Date: ', 'revisionary'),
				'show_author'                 => true,
			)
		);

		$allowed_sort_fields = array( 'post_modified', 'post_date', 'id' );
		$sort_by             = in_array( $args['sort_by'], $allowed_sort_fields, true ) ? $args['sort_by'] : 'post_modified';
		$sort_order          = 'ASC' === strtoupper( (string) $args['sort_order'] ) ? 'ASC' : 'DESC';
		$posts               = array();
		$seen                = array();

		foreach ( $post_items as $post_item ) {
			$post = $post_item instanceof \WP_Post ? $post_item : get_post( absint( $post_item ) );
			if ( ! $post || isset( $seen[ $post->ID ] ) ) {
				continue;
			}
			$seen[ $post->ID ] = true;
			$posts[]           = $post;
		}

		$posts = self::sort_comparison_posts( $posts, $sort_by, $sort_order );

		return array(
			'key'              => sanitize_key( $key ),
			'label'            => (string) $label,
			'sortBy'           => $sort_by,
			'sortOrder'        => $sort_order,
			'posts'            => $posts,
			'showRightStatus'  => (bool) $args['show_right_status'],
			'mimeTypeStatus'   => (bool) $args['mime_type_status'],
			'showModified'     => (bool) $args['show_modified'],
			'showPostDate'     => (bool) $args['show_post_date'],
			'postDatePrefix'   => (string) $args['post_date_prefix'],
			'showAuthor'       => (bool) $args['show_author'],
		);
	}

	/**
	 * Sorts comparison posts using the configured display order.
	 *
	 * @param WP_Post[] $posts      Posts to sort.
	 * @param string    $sort_by    post_modified, post_date, or id.
	 * @param string    $sort_order ASC or DESC.
	 * @return WP_Post[]
	 */
	public static function sort_comparison_posts( array $posts, $sort_by = 'post_modified', $sort_order = 'DESC' ) {
		$allowed_sort_fields = array( 'post_modified', 'post_date', 'id' );
		$sort_by             = in_array( $sort_by, $allowed_sort_fields, true ) ? $sort_by : 'post_modified';
		$sort_order          = 'ASC' === strtoupper( $sort_order ) ? 'ASC' : 'DESC';

		usort(
			$posts,
			static function ( \WP_Post $a, \WP_Post $b ) use ( $sort_by, $sort_order ) {
				if ( 'id' === $sort_by ) {
					$comparison = $a->ID <=> $b->ID;
				} else {
					$comparison = strcmp( $a->{$sort_by}, $b->{$sort_by} );
				}

				return 'ASC' === $sort_order ? $comparison : -$comparison;
			}
		);

		return $posts;
	}

	/**
	 * Finds one sidebar definition by key.
	 *
	 * @param string $key Sidebar key.
	 * @return array|null
	 */
	public static function comparison_sidebar_by_key( $key, $posts = [] ) {
		$key = sanitize_key( $key );
		foreach ( self::comparison_sidebar_definitions($key, $posts) as $definition ) {
			if ( $definition['key'] === $key ) {
				return $definition;
			}
		}
		return null;
	}

	/**
	 * Parses and validates from/to query arguments.
	 *
	 * @return array|WP_Error
	 */
	private static function get_url_pair() {
		$from = isset( $_GET['from'] ) ? absint( $_GET['from'] ) : 0;	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$to   = isset( $_GET['to'] ) ? absint( $_GET['to'] ) : 0;		// phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $from || ! $to || $from === $to ) {
			return new \WP_Error(
				'vpc_invalid_pair',
				__( 'The comparison URL must contain two different valid post IDs in the from and to parameters.', 'revisionary' )
			);
		}

		if ( ! get_post( $from ) || ! get_post( $to ) ) {
			return new \WP_Error(
				'vpc_missing_post',
				__( 'One of the posts in the comparison URL could not be found.', 'revisionary' )
			);
		}

		return array( 'from' => $from, 'to' => $to );
	}

	/**
	 * Validates the dedicated comparison screen request.
	 *
	 * All plugin comparisons use this screen in 0.11+.
	 */
	public static function route_compare_screen() {
		$pair = self::get_url_pair();
		if ( is_wp_error( $pair ) ) {
			wp_die( esc_html( $pair->get_error_message() ), esc_html__( 'Compare Revisions', 'revisionary' ), array( 'response' => 400 ) );
		}

		global $title;
		$title = __('Compare Revisions', 'revisionary');	// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		
		if ( ! current_user_can( 'read_post', $pair['from'] ) || ! current_user_can( 'read_post', $pair['to'] ) ) {
			wp_die( esc_html__( 'You are not allowed to read both posts in this comparison.', 'revisionary' ), esc_html__( 'Compare Revisions', 'revisionary' ), array( 'response' => 403 ) );
		}

		if ( ! current_user_can( 'edit_post', $pair['to'] ) || ! current_user_can( 'read_post', $pair['from'] ) ) {
			wp_die( esc_html__( 'You are not allowed to open this comparison in the editor.', 'revisionary' ), esc_html__( 'Compare Revisions', 'revisionary' ), array( 'response' => 403 ) );
		}
	}

	public static function render_compare_screen() {
		$pair = self::get_url_pair();
		if ( is_wp_error( $pair ) ) {
			wp_die( esc_html( $pair->get_error_message() ) );
		}

		if ( ! current_user_can( 'read_post', $pair['from'] ) || ! current_user_can( 'read_post', $pair['to'] ) ) {
			wp_die( esc_html__( 'You are not allowed to read both posts in this comparison.', 'revisionary' ) );
		}
		?>
		<div class="wrap visual-post-compare-only-wrap">
			<div id="visual-post-compare-root">
			</div>
		</div>
		<?php
	}

	public static function enqueue_compare_screen_assets( $hook_suffix ) {
		$pair = self::get_url_pair();
		if ( is_wp_error( $pair ) ) {
			return;
		}

		if ( ! current_user_can( 'read_post', $pair['from'] ) || ! current_user_can( 'read_post', $pair['to'] ) ) {
			return;
		}

		$comparison_key = isset( $_GET['comparison'] ) ? sanitize_key( wp_unslash( $_GET['comparison'] ) ) : '';	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		
		$headline 		= apply_filters(
			'visual_post_compare_compare_screen_headline',
			__('Compare Revisions', 'revisionary'),
			$pair,
			$comparison_key
		);

		$approve_caption = apply_filters(
			'visual_post_compare_compare_screen_approve_caption',
			__('Approve', 'revisionary'),
			$pair,
			$comparison_key
		);

		$current_post_first = apply_filters(
			'visual_post_compare_compare_screen_current_post_first',
			true,
			$pair,
			$comparison_key
		);

		$to_post        = get_post( $pair['to'] );
		$styles         = array();

		if ( $to_post && class_exists( 'WP_Block_Editor_Context' ) && function_exists( 'get_block_editor_settings' ) ) {
			$context  = new \WP_Block_Editor_Context( array( 'post' => $to_post ) );
			$settings = get_block_editor_settings( array(), $context );
			if ( ! empty( $settings['styles'] ) && is_array( $settings['styles'] ) ) {
				$styles = $settings['styles'];
			}
		}

		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style( 'wp-block-editor' );
		wp_enqueue_style( 'wp-block-library' );
		wp_enqueue_style( 'wp-format-library' );

		$suffix = defined('SCRIPT_DEBUG') && SCRIPT_DEBUG ? '.dev' : '';

		$script_path = plugin_dir_path( __FILE__ ) . "visual-post-compare-standalone{$suffix}.js";
		$style_path  = plugin_dir_path( __FILE__ ) . 'visual-post-compare.css';

		wp_enqueue_script(
			'visual-post-compare-standalone',
			plugins_url( "visual-post-compare-standalone{$suffix}.js", __FILE__ ),
			array( 'wp-api-fetch', 'wp-block-editor', 'wp-block-library', 'wp-block-serialization-default-parser', 'wp-blocks', 'wp-components', 'wp-element', 'wp-hooks', 'wp-i18n', 'wp-private-apis', 'wp-rich-text' ),
			file_exists( $script_path ) ? filemtime( $script_path ) : '0.11.0',
			true
		);

		wp_enqueue_style(
			'rvy-visual-compare',
			plugins_url( 'visual-post-compare.css', __FILE__ ),
			array( 'wp-components', 'wp-block-editor' ),
			file_exists( $style_path ) ? filemtime( $style_path ) : '0.11.0'
		);

		wp_add_inline_script(
			'visual-post-compare-standalone',
			'window.VisualPostCompareStandalone=' . wp_json_encode(
				array(
					'from'            => $pair['from'],
					'to'              => $pair['to'],
					'comparisonKey'   => $comparison_key,
					'headline'		   => is_scalar($headline) ? (string) $headline : __('Compare Revisions', 'revisionary'),
					'approveCaption'   => is_scalar($approve_caption) ? (string) $approve_caption : __('Approve', 'revisionary'),
					'currentPostFirst' => (bool) $current_post_first,
					'restPath'         => '/' . self::REST_NS . '/comparison',
					'approveRestPath'  => '/' . self::REST_NS . '/approve',
					'styles'           => $styles,
				)
			) . ';',
			'before'
		);
	}

	public static function enqueue_editor_assets() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! method_exists( $screen, 'is_block_editor' ) || ! $screen->is_block_editor() ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || ( function_exists( 'use_block_editor_for_post' ) && ! use_block_editor_for_post( $post ) ) ) {
			return;
		}

		self::load_editor_sidebar_builder();
		$comparison_sidebars = Visual_Post_Compare_Editor_Sidebar_Builder::comparison_sidebars_for_editor( $post_id );

		$script_path = plugin_dir_path( __FILE__ ) . 'visual-post-compare.js';
		$style_path  = plugin_dir_path( __FILE__ ) . 'visual-post-compare-editor.css';

		wp_enqueue_style(
			'visual-post-compare-editor',
			plugins_url( 'visual-post-compare-editor.css', __FILE__ ),
			array( 'wp-components' ),
			file_exists( $style_path ) ? filemtime( $style_path ) : '0.11.0'
		);

		wp_enqueue_script(
			'rvy-visual-compare',
			plugins_url( 'visual-post-compare.js', __FILE__ ),
			array( 'wp-editor', 'wp-element', 'wp-i18n', 'wp-plugins' ),
			file_exists( $script_path ) ? filemtime( $script_path ) : '0.11.0',
			true
		);

		wp_add_inline_script(
			'rvy-visual-compare',
			'window.VisualPostCompare=' . wp_json_encode(
				array(
					'currentPostId'      => $post_id,
					'comparisonSidebars' => $comparison_sidebars,
					'debugMode'			 => defined('SCRIPT_DEBUG') && SCRIPT_DEBUG
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Loads only the REST stack needed by the current REST request.
	 */
	public static function maybe_load_rest_handler() {
		if ( (isset($_GET['rest_route']) && (false !== strpos( wp_unslash($_GET['rest_route']), self::REST_NS )))		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		|| (isset($_SERVER['REQUEST_URI']) && (false !== strpos( wp_unslash($_SERVER['REQUEST_URI']), self::REST_NS ))) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
		) {
			self::load_dedicated_rest_handler();
			Visual_Post_Compare_Dedicated_REST_Handler::register_routes();
		}
	}

	private static function load_editor_sidebar_builder() {
		if ( ! class_exists( 'PublishPress\Visual_Post_Compare_Editor_Sidebar_Builder', false ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-editor-sidebar-builder.php';
		}
	}

	private static function load_dedicated_payload_builder() {
		if ( ! class_exists( 'PublishPress\Visual_Post_Compare_Dedicated_Payload_Builder', false ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-dedicated-payload-builder.php';
		}
	}

	private static function load_dedicated_rest_handler() {
		self::load_dedicated_payload_builder();
		if ( ! class_exists( 'PublishPress\Visual_Post_Compare_Dedicated_REST_Handler', false ) ) {
			require_once plugin_dir_path( __FILE__ ) . 'includes/class-dedicated-rest-handler.php';
		}
	}
}
