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
					'showDiff'        => [
						'type'    => 'boolean',
						'default' => false,
					],
					'hideUnchanged'   => [
						'type'    => 'boolean',
						'default' => true,
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
				'showDiff'        => false,
				'hideUnchanged'   => true,
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

		$count          = max( 1, min( 20, (int) $attributes['count'] ) );
		$hide_unchanged = ! array_key_exists( 'hideUnchanged', $attributes ) || ! empty( $attributes['hideUnchanged'] );
		$revisions      = self::get_recent_revisions( $post_id, $count, ! empty( $attributes['includeWorkflow'] ), $hide_unchanged );

		if ( ! $revisions ) {
			return self::render_empty_notice(
				$hide_unchanged
					? __( 'No revisions with tracked field changes are available yet.', 'revisionary' )
					: __( 'No revisions are available yet.', 'revisionary' )
			);
		}

		$heading = ! empty( $attributes['heading'] )
			? $attributes['heading']
			: __( 'Recent Revisions', 'revisionary' );

		$items         = '';
		$display_count = 0;

		foreach ( $revisions as $index => $revision ) {
			$previous = isset( $revisions[ $index + 1 ] ) ? $revisions[ $index + 1 ] : null;
			$changes  = self::get_revision_changes( $revision, $previous );

			if ( $hide_unchanged && ! $changes ) {
				continue;
			}

			$items .= self::render_revision_item( $revision, $previous, $post, $attributes, $changes );
			$display_count++;

			if ( $display_count >= $count ) {
				break;
			}
		}

		if ( '' === $items ) {
			return self::render_empty_notice( __( 'No revisions with tracked field changes are available yet.', 'revisionary' ) );
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

	private static function get_recent_revisions( $post_id, $count, $include_workflow, $hide_unchanged = true ) {
		$revision_limit = $hide_unchanged ? min( 80, max( $count + 1, ( $count * 4 ) + 1 ) ) : $count + 1;
		$revisions = wp_get_post_revisions(
			$post_id,
			[
				'numberposts' => $revision_limit,
				'orderby'     => 'post_modified_gmt',
				'order'       => 'DESC',
			]
		);

		if ( $include_workflow && current_user_can( 'edit_post', $post_id ) ) {
			$workflow_revisions = self::get_workflow_revisions( $post_id, $revision_limit );
			$revisions          = array_merge( $workflow_revisions, $revisions );
			$revisions          = self::sort_revisions( $revisions );
		}

		$revisions = array_filter(
			$revisions,
			function ( $revision ) use ( $post_id ) {
				return self::can_show_revision( $revision, $post_id );
			}
		);

		return array_values( $revisions );
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

	private static function render_revision_item( \WP_Post $revision, $previous, \WP_Post $post, $attributes, array $changes = [] ) {
		$author          = get_userdata( $revision->post_author );
		$can_show_diffs  = ! empty( $attributes['showDiff'] ) && current_user_can( 'edit_post', $post->ID );
		$revision_title  = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $revision->post_modified );
		$title           = '<time datetime="' . esc_attr( mysql_to_rfc3339( $revision->post_modified ) ) . '">' . esc_html( $revision_title ) . '</time>';
		$meta            = '';

		if ( ! empty( $attributes['showAuthor'] ) && $author ) {
			$meta .= '<span class="rvy-recent-revisions__author">' . esc_html( sprintf( __( 'by %s', 'revisionary' ), $author->display_name ) ) . '</span>';
		}

		$change_summary = self::render_change_summary( $changes, $previous );

		$diffs = '';
		if ( $can_show_diffs ) {
			foreach ( $changes as $change ) {
				if ( ! empty( $change['diff'] ) ) {
					$diffs .= '<details class="rvy-recent-revisions__diff"><summary>' . esc_html( $change['label'] ) . '</summary>' . wp_kses_post( $change['diff'] ) . '</details>';
				}
			}
		}

		return sprintf(
			'<li class="rvy-recent-revisions__item"><div class="rvy-recent-revisions__revision">%1$s</div>%2$s%3$s%4$s</li>',
			$title,
			$meta ? '<div class="rvy-recent-revisions__meta">' . $meta . '</div>' : '',
			$change_summary,
			$diffs
		);
	}

	private static function get_revision_changes( \WP_Post $revision, $previous ) {
		$fields = [
			'post_title'   => __( 'Title', 'revisionary' ),
			'post_content' => __( 'Content', 'revisionary' ),
			'post_excerpt' => __( 'Excerpt', 'revisionary' ),
		];

		$changes = [];
		foreach ( $fields as $field => $label ) {
			$before = $previous instanceof \WP_Post && isset( $previous->$field ) ? (string) $previous->$field : '';
			$after  = isset( $revision->$field ) ? (string) $revision->$field : '';

			if ( self::normalize_comparison_text( $before ) === self::normalize_comparison_text( $after ) ) {
				continue;
			}

			$fragments = self::get_change_fragments( $before, $after );

			$changes[] = [
				'field'   => $field,
				'label'   => $label,
				'added'   => $fragments['added'],
				'removed' => $fragments['removed'],
				'diff'    => self::get_text_diff( $before, $after ),
			];
		}

		return $changes;
	}

	private static function render_change_summary( array $changes, $previous ) {
		if ( ! $changes ) {
			return sprintf(
				'<div class="rvy-recent-revisions__changes rvy-recent-revisions__changes--empty"><span>%s</span></div>',
				esc_html( self::get_empty_changes_label( $previous ) )
			);
		}

		$added_items   = self::render_change_fragment_items( $changes, 'added' );
		$removed_items = self::render_change_fragment_items( $changes, 'removed' );
		$output        = '';

		if ( $added_items ) {
			$output .= sprintf(
				'<div class="rvy-recent-revisions__changes rvy-recent-revisions__changes--added"><ul>%s</ul></div>',
				$added_items
			);
		}

		if ( $removed_items ) {
			$output .= sprintf(
				'<div class="rvy-recent-revisions__changes rvy-recent-revisions__changes--removed"><span>%1$s</span><ul>%2$s</ul></div>',
				esc_html__( 'Removed:', 'revisionary' ),
				$removed_items
			);
		}

		if ( ! $output ) {
			return sprintf(
				'<div class="rvy-recent-revisions__changes rvy-recent-revisions__changes--empty"><span>%s</span></div>',
				esc_html__( 'Only formatting or block markup changed.', 'revisionary' )
			);
		}

		return $output;
	}

	private static function render_change_fragment_items( array $changes, $type ) {
		$items = '';

		foreach ( $changes as $change ) {
			if ( empty( $change[ $type ] ) || ! is_array( $change[ $type ] ) ) {
				continue;
			}

			foreach ( $change[ $type ] as $fragment ) {
				$items .= sprintf(
					'<li><span class="rvy-recent-revisions__field">%1$s</span> <span class="rvy-recent-revisions__fragment">%2$s</span></li>',
					esc_html( $change['label'] ),
					esc_html( $fragment )
				);
			}
		}

		return $items;
	}

	private static function get_empty_changes_label( $previous ) {
		if ( $previous instanceof \WP_Post ) {
			return __( 'No tracked field changes', 'revisionary' );
		}

		return __( 'Initial revision', 'revisionary' );
	}

	private static function get_change_fragments( $before, $after ) {
		$before_words = self::get_comparison_words( $before );
		$after_words  = self::get_comparison_words( $after );

		if ( ! $before_words && ! $after_words ) {
			return [
				'added'   => [],
				'removed' => [],
			];
		}

		if ( count( $before_words ) > 180 || count( $after_words ) > 180 ) {
			return self::get_bounded_change_fragments( $before_words, $after_words );
		}

		return self::get_word_diff_fragments( $before_words, $after_words );
	}

	private static function get_comparison_words( $text ) {
		$text = self::normalize_comparison_text( $text );
		if ( '' === $text ) {
			return [];
		}

		return preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
	}

	private static function get_bounded_change_fragments( array $before_words, array $after_words ) {
		$prefix_length = 0;
		$before_count  = count( $before_words );
		$after_count   = count( $after_words );

		while (
			$prefix_length < $before_count
			&& $prefix_length < $after_count
			&& $before_words[ $prefix_length ] === $after_words[ $prefix_length ]
		) {
			$prefix_length++;
		}

		$suffix_length = 0;
		while (
			$suffix_length < ( $before_count - $prefix_length )
			&& $suffix_length < ( $after_count - $prefix_length )
			&& $before_words[ $before_count - 1 - $suffix_length ] === $after_words[ $after_count - 1 - $suffix_length ]
		) {
			$suffix_length++;
		}

		$removed = array_slice( $before_words, $prefix_length, $before_count - $prefix_length - $suffix_length );
		$added   = array_slice( $after_words, $prefix_length, $after_count - $prefix_length - $suffix_length );

		return [
			'added'   => self::prepare_change_fragments( [$added] ),
			'removed' => self::prepare_change_fragments( [$removed] ),
		];
	}

	private static function get_word_diff_fragments( array $before_words, array $after_words ) {
		$matrix       = self::get_lcs_matrix( $before_words, $after_words );
		$before_index = 0;
		$after_index  = 0;
		$added        = [];
		$removed      = [];
		$current_add  = [];
		$current_del  = [];

		while ( $before_index < count( $before_words ) && $after_index < count( $after_words ) ) {
			if ( $before_words[ $before_index ] === $after_words[ $after_index ] ) {
				self::store_change_run( $added, $current_add );
				self::store_change_run( $removed, $current_del );
				$before_index++;
				$after_index++;
				continue;
			}

			if ( $matrix[ $before_index + 1 ][ $after_index ] >= $matrix[ $before_index ][ $after_index + 1 ] ) {
				$current_del[] = $before_words[ $before_index ];
				$before_index++;
			} else {
				$current_add[] = $after_words[ $after_index ];
				$after_index++;
			}
		}

		while ( $before_index < count( $before_words ) ) {
			$current_del[] = $before_words[ $before_index ];
			$before_index++;
		}

		while ( $after_index < count( $after_words ) ) {
			$current_add[] = $after_words[ $after_index ];
			$after_index++;
		}

		self::store_change_run( $added, $current_add );
		self::store_change_run( $removed, $current_del );

		return [
			'added'   => self::prepare_change_fragments( $added ),
			'removed' => self::prepare_change_fragments( $removed ),
		];
	}

	private static function get_lcs_matrix( array $before_words, array $after_words ) {
		$before_count = count( $before_words );
		$after_count  = count( $after_words );
		$matrix       = array_fill( 0, $before_count + 1, array_fill( 0, $after_count + 1, 0 ) );

		for ( $before_index = $before_count - 1; $before_index >= 0; $before_index-- ) {
			for ( $after_index = $after_count - 1; $after_index >= 0; $after_index-- ) {
				$matrix[ $before_index ][ $after_index ] = $before_words[ $before_index ] === $after_words[ $after_index ]
					? $matrix[ $before_index + 1 ][ $after_index + 1 ] + 1
					: max( $matrix[ $before_index + 1 ][ $after_index ], $matrix[ $before_index ][ $after_index + 1 ] );
			}
		}

		return $matrix;
	}

	private static function store_change_run( array &$runs, array &$current_run ) {
		if ( $current_run ) {
			$runs[]      = $current_run;
			$current_run = [];
		}
	}

	private static function prepare_change_fragments( array $runs ) {
		$fragments = [];

		foreach ( $runs as $run ) {
			if ( ! $run ) {
				continue;
			}

			$fragment = self::trim_change_fragment( implode( ' ', $run ) );
			if ( '' === $fragment ) {
				continue;
			}

			$fragments[] = $fragment;

			if ( count( $fragments ) >= 4 ) {
				break;
			}
		}

		return $fragments;
	}

	private static function trim_change_fragment( $fragment ) {
		$fragment = trim( preg_replace( '/\s+/', ' ', (string) $fragment ) );

		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $fragment ) > 140 ? mb_substr( $fragment, 0, 137 ) . '...' : $fragment;
		}

		return strlen( $fragment ) > 140 ? substr( $fragment, 0, 137 ) . '...' : $fragment;
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
