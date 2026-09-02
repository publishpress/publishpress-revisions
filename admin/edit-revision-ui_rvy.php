<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw(wp_unslash($_SERVER['SCRIPT_FILENAME']))) )
	die();

/*
 * Revision Edit: UI modifications for Classic Editor
 */
class RevisionaryEditRevisionUI {
	function __construct () {
		add_action('admin_head', [$this, 'add_js']);

		// Prevent submission / scheduling of Post Expirator settings, for now
		add_filter('pre_option_expirationdateGutenbergSupport', [$this, 'fltExpiratorBlockGutenbergMetabox'], 10, 3);
	}

	function fltExpiratorBlockGutenbergMetabox($option_val, $option_name, $default_val) {
		if ('expirationdateGutenbergSupport' == $option_name) {
			$option_val = 0;
		}

		return $option_val;
	}

	function add_js() {
		wp_enqueue_style('rvy-revision-edit', RVY_URLPATH . '/admin/rvy-revision-edit.css', [], PUBLISHPRESS_REVISIONS_VERSION);

		if (!rvy_get_option('scheduled_revisions')) {
			?>
			<style>
			#misc-publishing-actions div.curtime {display:none;}
			</style>
			<?php
		}

		if (!class_exists('DS_Public_Post_Preview')) {
			?>
			<style>
			<?php
			global $wp_version;

			if (!empty($wp_version) && version_compare($wp_version, '6.5-beta', '>=')) :?>
				div.edit-post-post-status div.rvy-current-status {
					padding-left: 20px;
				}

				button.edit-post-post-visibility__toggle, div.editor-post-url__panel-dropdown, div.edit-post-post-status div.components-checkbox-control {
					display: none;
				}
			<?php else:?>
				div.edit-post-post-visibility, div.edit-post-post-status div {
					display: none;
				}

				div.edit-post-post-status div.rvy-creation-ui,
				div.edit-post-post-status div.rvy-creation-ui div
				{
					display: inline;
				}
			<?php endif;?>

			div.edit-post-post-status div.rvy-creation-ui div.revision-created {
				display: block;
			}
			</style>
			<?php
		}

		?>
		<style>
			div.edit-post-revision-status span {
				width: 45%;
				display: inline-block;
				text-align: left;
			}
			div.edit-post-post-status div.rvy-current-status {
				/*width: 100%;*/
				display: inline-flex !important;
				text-align: right;
				white-space: nowrap;
			}
		</style>
		<?php
	}
}
