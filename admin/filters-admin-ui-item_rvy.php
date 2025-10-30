<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die();

/*
 * Post Edit UI: main post (not revision) editor filters which apply for both Gutenberg and Classic Editor
 */
class RevisionaryPostEditorMetaboxes {
	private $pending_revisions = array();
	private $future_revisions = array();
	
	function __construct () {
		add_action('admin_head', [$this, 'actAdminBarPreventPostClobber'], 5);
		add_action('admin_head', [$this, 'add_meta_boxes']);
		add_action('admin_head', [$this, 'act_tweak_metaboxes'], 11);

		add_filter('presspermit_disable_exception_ui', [$this, 'fltDisableExceptionUI'], 10, 4);
	}
	
	function add_meta_boxes() {
		$object_type = rvy_detect_post_type();
		
		if ( rvy_get_option( 'pending_revisions' ) ) {
			require_once( dirname(__FILE__).'/revision-ui_rvy.php' );
			
			add_meta_box( 'pending_revisions', pp_revisions_status_label('pending-revision', 'plural'), [$this, 'rvy_metabox_revisions_pending'], $object_type, 'advanced', 'high' );
		}
			
		if ( rvy_get_option( 'scheduled_revisions' ) ) {
			require_once( dirname(__FILE__).'/revision-ui_rvy.php' );

			add_meta_box( 'future_revisions', pp_revisions_status_label('future-revision', 'plural'), [$this, 'rvy_metabox_revisions_future'], $object_type );
		}
	}

	function rvy_metabox_revisions( $status ) {
		$property_name = $status . '_revisions';
		if ( ! empty( $this->$property_name ) ) {
			echo esc_html($this->$property_name);
		
		} elseif ( ! empty( $_GET['post'] ) ) {									//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$args = array( 'format' => 'list', 'parent' => false );
			rvy_list_post_revisions( (int) $_GET['post'], $status, $args );		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
	
	function rvy_metabox_revisions_pending() {
		self::rvy_metabox_revisions( 'pending-revision' );
	}
	
	function rvy_metabox_revisions_future() {
		self::rvy_metabox_revisions( 'future-revision' );
	}
	
	function act_tweak_metaboxes() {
		static $been_here;
		
		if ( isset($been_here) )
			return;

		$been_here = true;
		
		global $wp_meta_boxes;
		
		if ( empty($wp_meta_boxes) )
			return;
		
		$object_type = awp_post_type_from_uri();
		
		if ( empty($wp_meta_boxes[$object_type]) )
			return;

		$object_id = rvy_detect_post_id();

		// This block will be moved to separate class
		foreach ( $wp_meta_boxes[$object_type] as $context => $priorities ) {
			foreach ( $priorities as $priority => $boxes ) {
				foreach ( array_keys($boxes) as $box_id ) {
					// Remove Scheduled / Pending Revisions metabox if none will be listed
					// If a listing does exist, buffer it for subsequent display
					if ( 'pending_revisions' == $box_id ) {
						if ( ! $object_id || ! $this->pending_revisions = rvy_list_post_revisions( $object_id, 'pending-revision', array( 'format' => 'list', 'parent' => false, 'echo' => false ) ) )
							unset( $wp_meta_boxes[$object_type][$context][$priority][$box_id] );
					
					} elseif ( 'future_revisions' == $box_id ) {
						if ( ! $object_id || ! $this->future_revisions = rvy_list_post_revisions( $object_id, 'future-revision', array( 'format' => 'list', 'parent' => false, 'echo' => false ) ) )
							unset( $wp_meta_boxes[$object_type][$context][$priority][$box_id] );
					}
				}
			}
		}		
	}

	public function fltDisableExceptionUI($disable, $src_name, $post_id, $post_type = '') {
		if (!$post_id) {
			// Permissions version < 3.1.4 always passes zero value $post_id
			$post_id = rvy_detect_post_id();
		}

		if ($post_id && rvy_in_revision_workflow($post_id)) {
			return true;
		}

		return $disable;
	}

	function actAdminBarPreventPostClobber() {
        global $post;

        // prevent PHP Notice from Multiple Authors code:
        // Notice: Trying to get property of non-object in F:\www\wp50\wp-content\plugins\publishpress-multiple-authors\core\Classes\Utils.php on line 309
        // @todo: address within MA
        if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION') && !empty($_REQUEST['post'])) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$post = get_post((int) $_REQUEST['post']);											//phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        }
    }

} // end class
