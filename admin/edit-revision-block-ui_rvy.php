<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
	die();

/*
 * Revision Edit: UI modifications for Gutenberg Editor
 */
class RevisionaryEditRevisionBlockUI {
	function __construct () {
        add_action('admin_print_scripts', [$this, 'admin_print_scripts'], 99);

		$this->applyEditorRestrictions();
    }
    
    function admin_print_scripts() {
		if (class_exists('DS_Public_Post_Preview')) {
			?>
				<script type="text/javascript">
				/* <![CDATA[ */
				jQuery(document).ready( function($) {
					setInterval(function() {
						$("div.edit-post-post-status label:not(:contains('<?php esc_html_e('Enable public preview');?>')):not('[for=public-post-preview-url]')").closest('div').closest('div.components-panel__row').hide();
					}, 100);
				});
				/* ]]> */
				</script>
			<?php
		}

		?>
		<style type='text/css'>
		input.restore-revision {display:none !important;}
		</style>
		<?php
	}

	private function applyEditorRestrictions() {
        global $pagenow;

        // Return if not a post editor request
        if (!in_array($pagenow, ['post.php', 'post-new.php'])) {
            return;
        }

		require_once (dirname(__FILE__) . '/restrict-editor-features.php');
		\PublishPress\Revisions\Editor_Features::applyRestrictions();
    }
}
