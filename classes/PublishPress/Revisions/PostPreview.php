<?php
namespace PublishPress\Revisions;

\defined('ABSPATH') || exit;

class PostPreview {
    function __construct() {
		add_filter('the_author', [$this, 'fltAuthor'], 15);
	}

    // If this preview of unsaved changes is for a revision, show published post author
	public function fltAuthor($display_name) {
		if ($_post = get_post(rvy_detect_post_id())) {           
            if (rvy_in_revision_workflow($_post)) {
                remove_filter('the_author', [$this, 'fltAuthor'], 15);

                if ($published_author = get_post_field('post_author', rvy_post_id($_post->ID))) {
                    $display_name = get_the_author_meta('display_name', $published_author);
                }

                add_filter('the_author', [$this, 'fltAuthor'], 15);
            }
		}

		return $display_name;
	}
}
