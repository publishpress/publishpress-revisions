<?php
namespace PublishPress;

/**
 * Builds the lightweight block-editor sidebar launcher payload.
 *
 * Loaded lazily only for block-editor pages. Comparisons themselves are
 * rendered exclusively by the dedicated comparison screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Visual_Post_Compare_Editor_Sidebar_Builder {
	/**
	 * Returns comparison definitions prepared for the current editor user.
	 *
	 * @param int $current_post_id Current editor post ID.
	 * @return array[]
	 */
	public static function comparison_sidebars_for_editor( $current_post_id ) {
		$sidebars = array();

		foreach ( Visual_Post_Compare::comparison_sidebar_definitions() as $definition ) {
			$visible_posts = self::readable_comparison_posts( $definition, $current_post_id );
			$items         = array();

			// Preserve the existing editor-sidebar display direction while making
			// every item a direct link to the dedicated comparison screen.
			foreach ( array_reverse( $visible_posts ) as $post ) {
				$author = get_userdata( $post->post_author );
				$url    = add_query_arg(
					array(
						'page'         => Visual_Post_Compare::PAGE_SLUG,
						'from'         => (int) $current_post_id,
						'to'           => (int) $post->ID,
						'comparison'   => $definition['key'],
					),
					admin_url( 'admin.php' )
				);

				$items[] = array(
					'id'           => (int) $post->ID,
					'postDate'     => mysql2date( 'M j, Y g:i a', $post->post_date, true ),
					'author'       => $author ? $author->display_name : '',
					'authorAvatar' => esc_url_raw( get_avatar_url( $post->post_author, array( 'size' => 24 ) ) ),
					'url'          => esc_url_raw( $url ),
				);
			}

			$sidebars[] = array(
				'key'   => $definition['key'],
				'label' => $definition['label'],
				'posts' => $items,
			);
		}

		return $sidebars;
	}

	/**
	 * Returns readable configured posts for one editor sidebar.
	 *
	 * @param array $definition      Sidebar definition.
	 * @param int   $current_post_id Current editor post ID.
	 * @return WP_Post[]
	 */
	private static function readable_comparison_posts( array $definition, $current_post_id ) {
		$posts = array();

		foreach ( $definition['posts'] as $post ) {
			if ( (int) $post->ID === (int) $current_post_id || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$posts[] = $post;
		}

		return Visual_Post_Compare::sort_comparison_posts(
			$posts,
			$definition['sortBy'],
			$definition['sortOrder']
		);
	}
}
