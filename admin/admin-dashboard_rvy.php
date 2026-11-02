<?php
defined('ABSPATH') || exit;

class RevisionaryDashboard {
	public function __construct() {
	}

	public function recentPostsQueryArgs($query_args) {
		add_filter('posts_clauses_request', [&$this, 'fltDashboardQueryClauses']);

		return $query_args;
	}

	public function fltDashboardQueryClauses( $clauses ) {
		global $wpdb, $revisionary;

		$types = array_keys($revisionary->enabled_post_types);
		$post_types_csv = implode( "','", array_map('sanitize_key', $types));
		$clauses['where'] = str_replace( "$wpdb->posts.post_type = 'post'", "$wpdb->posts.post_type IN ('$post_types_csv')", $clauses['where'] );

		$clauses['where'] = str_replace( "$wpdb->posts.post_status = 'future'", "($wpdb->posts.post_status = 'future' OR ($wpdb->posts.post_status = 'pending' AND $wpdb->posts.post_mime_type = 'future-revision'))", $clauses['where'] );

		$revisionary->is_revisions_query = false;

		remove_filter('posts_clauses_request', [&$this, 'fltDashboardQueryClauses']);
		return $clauses;
	}

	public static function glancePending($items = []) {
		return $items;

		// @todo: modify this function for post_mime_type schema
	}
}
