<?php
namespace PublishPress;

/**
 * Builds payloads used by the dedicated compare_only screen.
 *
 * Loaded lazily only for Visual Post Compare REST requests.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Visual_Post_Compare_Dedicated_Payload_Builder {
	/**
	 * Builds the dedicated compare_only comparison set.
	 *
	 * @param WP_Post $from           Target/current post.
	 * @param WP_Post $to             URL-selected comparison post.
	 * @param string  $comparison_key Optional sidebar definition key.
	 * @return array
	 */
	public static function build( \WP_Post $from, \WP_Post $to, $comparison_key = '' ) {
		/**
		 * Filters additional posts available on the compare_only selection slider.
		 *
		 * @param array<int|WP_Post> $posts Additional post IDs and/or WP_Post objects.
		 * @param WP_Post            $from  Fixed target/current post.
		 * @param WP_Post            $to    URL-selected comparison post.
		 */
		$arr = (array) apply_filters( 'visual_post_compare_compare_only_posts', array(), $from, $to );

		if (!empty($arr['posts'])) {
			$additional = $arr['posts'];
		}

		if (!empty($arr['comparison_key'])) {
			$comparison_key = $arr['comparison_key'];
		}

		$comparison_posts = array( $to );
		$seen             = array( $from->ID => true, $to->ID => true );

		foreach ( $additional as $post_item ) {
			$post = $post_item instanceof \WP_Post ? $post_item : get_post( absint( $post_item ) );
			if ( ! $post || isset( $seen[ $post->ID ] ) || ! current_user_can( 'read_post', $post->ID ) ) {
				continue;
			}
			$seen[ $post->ID ]  = true;
			$comparison_posts[] = $post;
		}

		$comparison_posts = Visual_Post_Compare::sort_comparison_posts( $comparison_posts, 'post_modified', 'DESC' );

		// Core's revisions selector reverses the REST collection visually. Mirror
		// that behavior here: fixed current post first, then reversed comparison order.
		$slider_posts = array_merge( array( $from ), array_reverse( $comparison_posts ) );
		$slider_data  = array_map( array( __CLASS__, 'post_data' ), $slider_posts );

		return array(
			'presentation' => self::presentation_options( $comparison_key, $comparison_posts ),
			'from'         => self::post_data( $from ),
			'to'           => self::post_data( $to ),
			'posts'        => $slider_data,
			'selectedId'   => (int) $to->ID,
			'canApprove'   => (wp_is_post_revision($to)) ?  (bool) current_user_can( 'edit_post', $from->ID ) : (bool) current_user_can( 'approve_revision', $to->ID ),
		);
	}

	/**
	 * Returns presentation options for the dedicated comparison screen.
	 *
	 * @param string $key Optional comparison sidebar key.
	 * @return array
	 */
	private static function presentation_options( $key = '', $posts = [] ) {
		$definition = $key ? Visual_Post_Compare::comparison_sidebar_by_key( $key, $posts ) : null;
		if ( ! $definition ) {
			$definitions = Visual_Post_Compare::comparison_sidebar_definitions();
			$definition  = ! empty( $definitions ) ? reset( $definitions ) : array();
		}

		return array(
			'showRightStatus'  => isset( $definition['showRightStatus'] ) ? (bool) $definition['showRightStatus'] : true,
			'mimeTypeStatus' => isset( $definition['mimeTypeStatus'] ) ? (bool) $definition['mimeTypeStatus'] : false,
			'showModified'     => isset( $definition['showModified'] ) ? (bool) $definition['showModified'] : true,
			'showPostDate'     => isset( $definition['showPostDate'] ) ? (bool) $definition['showPostDate'] : false,
			'modifiedPrefix'   => __( 'Modified: ', 'revisionary' ),
			'postDatePrefix'   => isset( $definition['postDatePrefix'] ) ? (string) $definition['postDatePrefix'] : __( 'Post Date: ', 'revisionary' ),
			'showAuthor'       => isset( $definition['showAuthor'] ) ? (bool) $definition['showAuthor'] : true,
		);
	}

	/**
	 * Serializes one post for the standalone JavaScript application.
	 *
	 * @param WP_Post $post Post object.
	 * @return array
	 */
	public static function post_data( \WP_Post $post ) {
		$author   = get_userdata( $post->post_author );
		$can_edit = current_user_can( 'edit_post', $post->ID );
		$url      = $can_edit ? get_edit_post_link( $post->ID, 'raw' ) : get_permalink( $post );

		$status_obj = get_post_status_object($post->post_status);
		$mime_type_status_obj = get_post_status_object($post->post_mime_type);

		return array(
			'id'                  => (int) $post->ID,
			'type'                => $post->post_type,
			'title'               => (string) $post->post_title,
			'content'             => $post->post_content,
			'statusLabel'		  => ('inherit' == $post->post_status) ? __('Past Revision', 'revisionary') : ((!empty($status_obj) && !empty($status_obj->label)) ? $status_obj->label : $post->post_status),
			'mimeTypeStatusLabel' => ('inherit' == $post->post_status) ? __('Past Revision', 'revisionary') : ((!empty($mime_type_status_obj) && !empty($mime_type_status_obj->label)) ? $mime_type_status_obj->label : $post->post_mime_type),
			'modified'            => mysql_to_rfc3339( $post->post_modified ),
			'modifiedLabel'       => mysql2date( 'M j, Y g:i a', $post->post_modified, true ),
			'postDate'            => mysql_to_rfc3339( $post->post_date ),
			'postDateLabel'       => mysql2date( 'M j, Y g:i a', $post->post_date, true ),
			'author'              => (int) $post->post_author,
			'authorName'          => $author ? $author->display_name : '',
			'authorAvatar'        => get_option('show_avatars') ? esc_url_raw( get_avatar_url( $post->post_author, array( 'size' => 32 ) ) ) : '',
			'sliderModifiedLabel' => mysql2date( 'F j, Y g:i a', $post->post_modified, true ),
			'sliderModifiedTitle' => mysql2date( 'F j, Y g:i a', $post->post_modified, true ),
			'sliderPostDateLabel' => mysql2date( 'F j, Y g:i a', $post->post_date, true ),
			'sliderPostDateTitle' => mysql2date( 'F j, Y g:i a', $post->post_date, true ),
			'canEdit'             => (bool) $can_edit,
			'url'                 => $url ? esc_url_raw( $url ) : '',
		);
	}
}
