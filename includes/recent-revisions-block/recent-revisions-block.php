<?php
namespace PublishPress\Revisions;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Recent_Revisions_Block {
	const BLOCK_NAME = 'revisionary/recent-revisions';

	public static function register_hooks() {
		add_action( 'init', [__CLASS__, 'register_block'] );
		add_action( 'enqueue_block_editor_assets', [__CLASS__, 'enqueue_editor_assets'] );
	}

	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		$base_url = plugins_url( '', REVISIONARY_FILE ) . '/includes/recent-revisions-block';

		wp_register_script(
			'revisionary-recent-revisions-block-editor',
			$base_url . '/editor.js',
			['wp-blocks', 'wp-block-editor', 'wp-components', 'wp-element', 'wp-i18n', 'wp-server-side-render'],
			PUBLISHPRESS_REVISIONS_VERSION,
			true
		);

		wp_register_style(
			'revisionary-recent-revisions-block',
			$base_url . '/style.css',
			[],
			PUBLISHPRESS_REVISIONS_VERSION
		);

		register_block_type(
			self::BLOCK_NAME,
			[
				'api_version'     => 2,
				'title'           => __( 'Recent Revisions', 'revisionary' ),
				'description'     => __( 'Display recent revisions and a summary of the fields changed in each revision.', 'revisionary' ),
				'category'        => 'widgets',
				'icon'            => 'backup',
				'keywords'        => [
					__( 'revisions', 'revisionary' ),
					__( 'history', 'revisionary' ),
					__( 'changes', 'revisionary' ),
				],
				'editor_script'   => 'revisionary-recent-revisions-block-editor',
				'editor_style'    => 'revisionary-recent-revisions-block',
				'style'           => 'revisionary-recent-revisions-block',
				'render_callback' => [__CLASS__, 'render'],
				'uses_context'    => ['postId'],
				'attributes'      => [
					'heading'         => [
						'type'    => 'string',
						'default' => '',
					],
					'postId'          => [
						'type'    => 'number',
						'default' => 0,
					],
					'count'           => [
						'type'    => 'number',
						'default' => 5,
					],
					'showAuthor'      => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showDate'        => [
						'type'    => 'boolean',
						'default' => true,
					],
					'showDiff'        => [
						'type'    => 'boolean',
						'default' => false,
					],
					'includeWorkflow' => [
						'type'    => 'boolean',
						'default' => false,
					],
				],
			]
		);
	}

	public static function enqueue_editor_assets() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! method_exists( $screen, 'is_block_editor' ) || ! $screen->is_block_editor() ) {
			return;
		}

		if ( wp_script_is( 'revisionary-recent-revisions-block-editor', 'registered' ) ) {
			wp_enqueue_script( 'revisionary-recent-revisions-block-editor' );
		}

		if ( wp_style_is( 'revisionary-recent-revisions-block', 'registered' ) ) {
			wp_enqueue_style( 'revisionary-recent-revisions-block' );
		}
	}

	public static function render( $attributes, $content = '', $block = null ) {
		$attributes = wp_parse_args(
			(array) $attributes,
			[
				'heading'         => '',
				'postId'          => 0,
				'count'           => 5,
				'showAuthor'      => true,
				'showDate'        => true,
				'showDiff'        => false,
				'includeWorkflow' => false,
			]
		);

		$post_id = self::resolve_post_id( $attributes, $block );
		if ( ! $post_id ) {
			return self::render_empty_notice( __( 'No post selected for revision history.', 'revisionary' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return self::render_empty_notice( __( 'The selected post could not be found.', 'revisionary' ) );
		}

		if ( ! self::is_post_publicly_viewable( $post ) && ! current_user_can( 'read_post', $post_id ) ) {
			return '';
		}

		$count     = max( 1, min( 20, (int) $attributes['count'] ) );
		$revisions = self::get_recent_revisions( $post_id, $count, ! empty( $attributes['includeWorkflow'] ) );

		if ( ! $revisions ) {
			return self::render_empty_notice( __( 'No revisions are available yet.', 'revisionary' ) );
		}

		$heading = ! empty( $attributes['heading'] )
			? $attributes['heading']
			: __( 'Recent Revisions', 'revisionary' );

		$items             = '';
		$display_revisions = array_slice( $revisions, 0, $count );

		foreach ( $display_revisions as $index => $revision ) {
			$previous = isset( $revisions[ $index + 1 ] ) ? $revisions[ $index + 1 ] : null;
			$items   .= self::render_revision_item( $revision, $previous, $post, $attributes );
		}

		$wrapper_attributes = self::get_wrapper_attributes( ['class' => 'rvy-recent-revisions'] );

		return sprintf(
			'<section %1$s><h2 class="rvy-recent-revisions__heading">%2$s</h2><ol class="rvy-recent-revisions__list">%3$s</ol></section>',
			$wrapper_attributes,
			esc_html( $heading ),
			$items
		);
	}

	private static function resolve_post_id( $attributes, $block ) {
		if ( ! empty( $attributes['postId'] ) ) {
			return absint( $attributes['postId'] );
		}

		if ( is_object( $block ) && ! empty( $block->context['postId'] ) ) {
			return absint( $block->context['postId'] );
		}

		return absint( get_the_ID() );
	}

	private static function get_recent_revisions( $post_id, $count, $include_workflow ) {
		$revisions = wp_get_post_revisions(
			$post_id,
			[
				'numberposts' => $count + 1,
				'orderby'     => 'post_modified_gmt',
				'order'       => 'DESC',
			]
		);

		if ( $include_workflow && current_user_can( 'edit_post', $post_id ) ) {
			$workflow_revisions = self::get_workflow_revisions( $post_id, $count );
			$revisions          = array_merge( $workflow_revisions, $revisions );
			$revisions          = self::sort_revisions( $revisions );
		}

		$revisions = array_filter(
			$revisions,
			function ( $revision ) use ( $post_id ) {
				return self::can_show_revision( $revision, $post_id );
			}
		);

		return array_slice( array_values( $revisions ), 0, $count + 1 );
	}

	private static function get_workflow_revisions( $post_id, $count ) {
		global $wpdb;

		$revision_statuses = array_map( 'sanitize_key', rvy_revision_statuses() );
		if ( ! $revision_statuses ) {
			return [];
		}

		$placeholders = implode( ', ', array_fill( 0, count( $revision_statuses ), '%s' ) );
		$query_args   = array_merge( $revision_statuses, [$post_id, $count] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM $wpdb->posts WHERE post_mime_type IN ($placeholders) AND comment_count = %d ORDER BY post_modified_gmt DESC LIMIT %d",
				$query_args
			)
		);
	}

	private static function sort_revisions( $revisions ) {
		usort(
			$revisions,
			function ( $a, $b ) {
				$modified_a = strtotime( $a->post_modified_gmt );
				$modified_b = strtotime( $b->post_modified_gmt );

				if ( $modified_a === $modified_b ) {
					return $b->ID <=> $a->ID;
				}

				return $modified_b <=> $modified_a;
			}
		);

		return $revisions;
	}

	private static function can_show_revision( $revision, $post_id ) {
		if ( ! $revision instanceof \WP_Post ) {
			return false;
		}

		if ( rvy_in_revision_workflow( $revision ) ) {
			return current_user_can( 'edit_post', $post_id );
		}

		return 'revision' === $revision->post_type && 'inherit' === $revision->post_status;
	}

	private static function render_revision_item( \WP_Post $revision, $previous, \WP_Post $post, $attributes ) {
		$changes         = self::get_revision_changes( $revision, $previous );
		$changed_labels  = $changes ? wp_list_pluck( $changes, 'label' ) : [self::get_empty_changes_label( $previous )];
		$author          = get_userdata( $revision->post_author );
		$can_show_diffs  = ! empty( $attributes['showDiff'] ) && current_user_can( 'edit_post', $post->ID );
		$compare_url     = self::get_compare_url( $revision, $post );
		$status_label    = self::get_revision_status_label( $revision );
		$revision_title  = sprintf(
			/* translators: %s: revision date. */
			__( 'Revision from %s', 'revisionary' ),
			mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $revision->post_modified )
		);

		$title = $compare_url
			? '<a class="rvy-recent-revisions__revision-link" href="' . esc_url( $compare_url ) . '">' . esc_html( $revision_title ) . '</a>'
			: esc_html( $revision_title );

		$meta = '<span class="rvy-recent-revisions__status">' . esc_html( $status_label ) . '</span>';

		if ( ! empty( $attributes['showDate'] ) ) {
			$meta .= '<time datetime="' . esc_attr( mysql_to_rfc3339( $revision->post_modified ) ) . '">' . esc_html( mysql2date( get_option( 'date_format' ), $revision->post_modified ) ) . '</time>';
		}

		if ( ! empty( $attributes['showAuthor'] ) && $author ) {
			$meta .= '<span class="rvy-recent-revisions__author">' . esc_html( sprintf( __( 'by %s', 'revisionary' ), $author->display_name ) ) . '</span>';
		}

		$change_items = '';
		foreach ( $changed_labels as $label ) {
			$change_items .= '<li>' . esc_html( $label ) . '</li>';
		}

		$diffs = '';
		if ( $can_show_diffs ) {
			foreach ( $changes as $change ) {
				if ( ! empty( $change['diff'] ) ) {
					$diffs .= '<details class="rvy-recent-revisions__diff"><summary>' . esc_html( $change['label'] ) . '</summary>' . wp_kses_post( $change['diff'] ) . '</details>';
				}
			}
		}

		return sprintf(
			'<li class="rvy-recent-revisions__item"><div class="rvy-recent-revisions__revision">%1$s</div><div class="rvy-recent-revisions__meta">%2$s</div><div class="rvy-recent-revisions__changes"><span>%3$s</span><ul>%4$s</ul></div>%5$s</li>',
			$title,
			$meta,
			esc_html__( 'Changed:', 'revisionary' ),
			$change_items,
			$diffs
		);
	}

	private static function get_revision_changes( \WP_Post $revision, $previous ) {
		if ( ! $previous instanceof \WP_Post ) {
			return [];
		}

		$fields = [
			'post_title'   => __( 'Title', 'revisionary' ),
			'post_content' => __( 'Content', 'revisionary' ),
			'post_excerpt' => __( 'Excerpt', 'revisionary' ),
		];

		$changes = [];
		foreach ( $fields as $field => $label ) {
			$before = isset( $previous->$field ) ? (string) $previous->$field : '';
			$after  = isset( $revision->$field ) ? (string) $revision->$field : '';

			if ( self::normalize_comparison_text( $before ) === self::normalize_comparison_text( $after ) ) {
				continue;
			}

			$changes[] = [
				'field' => $field,
				'label' => $label,
				'diff'  => self::get_text_diff( $before, $after ),
			];
		}

		return $changes;
	}

	private static function get_empty_changes_label( $previous ) {
		if ( $previous instanceof \WP_Post ) {
			return __( 'No tracked field changes', 'revisionary' );
		}

		return __( 'Initial revision', 'revisionary' );
	}

	private static function normalize_comparison_text( $text ) {
		$text = wp_strip_all_tags( (string) $text );
		$text = preg_replace( '/\s+/', ' ', $text );

		return trim( $text );
	}

	private static function get_text_diff( $before, $after ) {
		if ( ! function_exists( 'wp_text_diff' ) && file_exists( ABSPATH . WPINC . '/wp-diff.php' ) ) {
			require_once ABSPATH . WPINC . '/wp-diff.php';
		}

		if ( ! function_exists( 'wp_text_diff' ) ) {
			return '';
		}

		return wp_text_diff(
			self::normalize_comparison_text( $before ),
			self::normalize_comparison_text( $after ),
			[
				'show_split_view' => false,
				'title'           => '',
			]
		);
	}

	private static function get_compare_url( \WP_Post $revision, \WP_Post $post ) {
		if ( ! current_user_can( 'read_post', $revision->ID ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return '';
		}

		if ( function_exists( 'rvy_compare_url' ) ) {
			return rvy_compare_url( $revision->ID, ['post_id' => $post->ID] );
		}

		return '';
	}

	private static function get_revision_status_label( \WP_Post $revision ) {
		if ( rvy_in_revision_workflow( $revision ) ) {
			$status_obj = get_post_status_object( $revision->post_mime_type );

			return $status_obj && ! empty( $status_obj->label ) ? $status_obj->label : $revision->post_mime_type;
		}

		return __( 'Past Revision', 'revisionary' );
	}

	private static function render_empty_notice( $message ) {
		if ( is_admin() || current_user_can( 'edit_posts' ) ) {
			$wrapper_attributes = self::get_wrapper_attributes( ['class' => 'rvy-recent-revisions is-empty'] );

			return '<div ' . $wrapper_attributes . '>' . esc_html( $message ) . '</div>';
		}

		return '';
	}

	private static function get_wrapper_attributes( $extra_attributes ) {
		if ( function_exists( 'get_block_wrapper_attributes' ) ) {
			return get_block_wrapper_attributes( $extra_attributes );
		}

		$classes = isset( $extra_attributes['class'] ) ? $extra_attributes['class'] : '';

		return 'class="' . esc_attr( $classes ) . '"';
	}

	private static function is_post_publicly_viewable( \WP_Post $post ) {
		$post_type = get_post_type_object( $post->post_type );
		$status    = get_post_status_object( $post->post_status );

		return $post_type && is_post_type_viewable( $post_type ) && $status && ! empty( $status->public );
	}
}
