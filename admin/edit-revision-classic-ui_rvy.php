<?php
if (isset($_SERVER['SCRIPT_FILENAME']) && basename(__FILE__) == basename(esc_url_raw($_SERVER['SCRIPT_FILENAME'])) )
    die();

/*
 * Revision Edit: UI modifications for Classic Editor
 */
class RevisionaryEditRevisionClassicUI {
	function __construct () {
		add_action('admin_head', [$this, 'hide_admin_divs']);
		add_action('admin_head', [$this, 'actDeleteDuplicateRevision']);

		add_filter('post_updated_messages', [$this, 'fltPostUpdatedMessage']);

		add_action('add_meta_boxes', [$this, 'act_replace_publish_metabox'], 10, 2);

		add_filter('presspermit_editor_ui_status', [$this, 'fltEditorUIstatus'], 10, 3);
		add_filter('presspermit_post_editor_immediate_caption', [$this, 'fltImmediateCaption'], 10, 2);

		add_action('post_submitbox_misc_actions', [$this, 'actSubmitboxActions'], 1);

		if (class_exists('RankMath')) :
			add_action(
				'admin_print_footer_scripts', 
				function() {
				?>
					<script type="text/javascript">
					/* <![CDATA[ */
					jQuery(document).on('click', '#save-post', function() {
						setTimeout(
							function() {
								jQuery('#save-post').trigger('click');
							},
							500
						);
					});
					/* ]]> */
					</script>
				<?php
				}
			);
		endif;
	}

	function actDeleteDuplicateRevision() {
		global $wpdb, $post;

		if ($post) {
			$last_id = $post->ID - 1;

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$last_post = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT * FROM $wpdb->posts WHERE ID = %d",
					$last_id
				)
			);
			
			if ($last_post 
			&& ($last_post->post_author == $post->post_author) 
			&& rvy_is_revision_status($last_post->post_mime_type) 
			&& ($last_post->comment_count == $post->comment_count)
			) {
				wp_delete_post($last_id);
			}
		}
	}

	function hide_admin_divs() {
		// Hide unrevisionable elements if editing for revisions, regardless of Limited Editing Element settings
		//
		if ( rvy_get_option( 'pending_revisions' ) ) {
			global $post;

			$object_type = (!empty($post->post_type)) ? $post->post_type : awp_post_type_from_uri();

			$unrevisable_css_ids = apply_filters('rvy_hidden_meta_boxes', ['authordiv', 'visibility']);

			if (rvy_in_revision_workflow($post)) {
				$unrevisable_css_ids = array_merge($unrevisable_css_ids, ['publish', 'slugdiv', 'edit-slug-box']);
			}

			echo( "\n<style type='text/css'>\n<!--\n" );

			foreach ( $unrevisable_css_ids as $id ) {
				// thanks to piemanek for tip on using remove_meta_box for any core admin div
				remove_meta_box($id, $object_type, 'normal');
				remove_meta_box($id, $object_type, 'advanced');

				// also hide via CSS in case the element is not a metabox
				echo "#" . esc_attr($id) . "{ display: none !important; }\n";  // this line adapted from Clutter Free plugin by Mark Jaquith
			}

			echo "-->\n</style>\n";

			// display the current status, but hide edit link
			echo "\n<style type='text/css'>\n<!--\n.edit-post-status { display: none !important; }\n-->\n</style>\n";  // this line adapted from Clutter Free plugin by Mark Jaquith
		}
	}

	public function fltEditorUIstatus($status, $post, $args) {
		if (rvy_in_revision_workflow($post)) {
			$status = $post->post_mime_type;
		}

		return $status;
	}

	public function fltImmediateCaption($caption, $post) {
		if (rvy_in_revision_workflow($post)) {
			$caption = esc_html__('Publish <b>on approval</b>', 'revisionary');
		}

		return $caption;
	}

	public function post_submit_meta_box($post, $args = [])
    {
		if (rvy_is_revision_status($post->post_mime_type)) {
            require_once(dirname(__FILE__) . '/RevisionEditSubmitMetabox.php');
			RvyRevisionEditSubmitMetabox::post_submit_meta_box($post, $args);
        }
    }

	public function act_replace_publish_metabox($post_type, $post)
    {
        global $wp_meta_boxes;

        if ('attachment' != $post_type) {
            if (!empty($wp_meta_boxes[$post_type]['side']['core']['submitdiv'])) {
				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				$wp_meta_boxes[$post_type]['side']['core']['submitdiv']['callback'] = [$this, 'post_submit_meta_box'];
            }
        }
	}

	function fltPostUpdatedMessage($messages) {
		global $post;

		if (rvy_get_option('revision_preview_links') || current_user_can('administrator') || is_super_admin()) {
			$preview_url = rvy_preview_url($post);
			$preview_msg = sprintf(esc_html__('Revision updated. %sView Preview%s', 'revisionary'), "<a href='$preview_url'>", '</a>');
		} else {
			$preview_msg = esc_html__('Revision updated.', 'revisionary');
		}

		$messages['post'][1] = $preview_msg;
		$messages['page'][1] = $preview_msg;
		$messages[$post->post_type][1] = $preview_msg;

        return $messages;
	}

	function actSubmitboxActions($post) {
		global $action;

		$post_type_object = get_post_type_object($post->post_type);

		if ($post_type_object && !current_user_can($post_type_object->cap->publish_posts)) : // Contributors don't get to choose the date of publish.
			/* translators: Publish box date string. 1: Date, 2: Time. See https://www.php.net/manual/datetime.format.php */
			$date_string = __( '%1$s at %2$s' );
			/* translators: Publish box date format, see https://www.php.net/manual/datetime.format.php */
			$date_format = _x( 'M j, Y', 'publish box date format' );
			/* translators: Publish box time format, see https://www.php.net/manual/datetime.format.php */
			$time_format = _x( 'H:i', 'publish box time format' );

			if ( 0 !== $post->ID ) {
				if ( 'future' === $post->post_status ) { // Scheduled for publishing at a future date.
					/* translators: Post date information. %s: Date on which the post is currently scheduled to be published. */
					$stamp = __( 'Scheduled for: %s' );
				} elseif ( 'publish' === $post->post_status || 'private' === $post->post_status ) { // Already published.
					/* translators: Post date information. %s: Date on which the post was published. */
					$stamp = __( 'Published on: %s' );
				} elseif ( '0000-00-00 00:00:00' === $post->post_date_gmt ) { // Draft, 1 or more saves, no date specified.
					$stamp = __( 'Publish <b>immediately</b>' );
				} elseif ( time() < strtotime( $post->post_date_gmt . ' +0000' ) ) { // Draft, 1 or more saves, future date specified.
					/* translators: Post date information. %s: Date on which the post is to be published. */
					$stamp = __( 'Schedule for: %s' );
				} else { // Draft, 1 or more saves, date specified.
					/* translators: Post date information. %s: Date on which the post is to be published. */
					$stamp = __( 'Publish on: %s' );
				}
				$date = sprintf(
					$date_string,
					date_i18n( $date_format, strtotime( $post->post_date ) ),
					date_i18n( $time_format, strtotime( $post->post_date ) )
				);
			} else { // Draft (no saves, and thus no date specified).
				$stamp = __( 'Publish <b>immediately</b>' );
				$date  = sprintf(
					$date_string,
					date_i18n( $date_format, strtotime( current_time( 'mysql' ) ) ),
					date_i18n( $time_format, strtotime( current_time( 'mysql' ) ) )
				);
			}
			
			?>
			<div class="misc-pub-section curtime misc-pub-curtime">
				<span id="timestamp">
					<?php printf( $stamp, '<b>' . $date . '</b>' );		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</span>
				<a href="#edit_timestamp" class="edit-timestamp hide-if-no-js" role="button">
					<span aria-hidden="true"><?php _e( 'Edit' ); ?></span>
					<span class="screen-reader-text">
						<?php
						/* translators: Hidden accessibility text. */
						_e( 'Edit date and time' );
						?>
					</span>
				</a>
				<fieldset id="timestampdiv" class="hide-if-js">
					<legend class="screen-reader-text">
						<?php
						/* translators: Hidden accessibility text. */
						_e( 'Date and time' );
						?>
					</legend>
					<?php touch_time( ( 'edit' === $action ), 1 ); ?>
				</fieldset>
			</div>
			<?php
		endif;
	}
}
