<?php

class RevisionaryFront {
	var $orig_inherit_protected_value;

	function __construct() {
		global $revisionary;

		// This filter may be required in some configurations, but problematic in others
		if (!defined('PP_REVISIONS_NO_PAGE_ON_FRONT_FILTER')) {
			add_filter("option_page_on_front", [$this, 'fltOptionPageOnFront']);
		}

		add_action('parse_query', [$this, 'actSetQueriedObject'], 20);
		add_action('parse_query', [$this, 'actFlagHomeRevision'], 20);

		if (!defined('ET_BUILDER_VERSION') || defined('REVISIONARY_DIVI_HOME_PREVIEW_FILTER')) {
			add_filter('posts_clauses_request', [$this, 'fltHomePreviewRequest'], 99, 3);
		}

		add_filter('body_class', [$this, 'fltBodyClass'], 20, 2);

		add_filter('acf/load_value', [$this, 'fltACFLoadValue'], 10, 3);
		add_filter('get_post_metadata', [$this, 'fltGetPostMeta'], 10, 5);

		if ( ! defined('RVY_CONTENT_ROLES') || !$revisionary->content_roles->is_direct_file_access() ) {
			add_filter('posts_request', [$this, 'flt_view_revision'] );
			add_action('template_redirect', [$this, 'act_template_redirect'], 5 );
		}

		if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION')) {
			add_filter('the_author', [$this, 'fltAuthor'], 20);
		}

		if (!empty($_REQUEST['_ppp'])) {									//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_action('template_redirect', [$this, 'actRevisionPreviewRedirect'], 1);
		}

		add_filter('posts_results', [$this, 'inherit_status_workaround']);
		add_filter('the_posts', [$this, 'undo_inherit_status_workaround']);

		remove_action( 'init', 'register_block_core_post_comments' );

		add_action('init', [$this, 'actFixRevisionPreviewStatus'], 5);

		do_action('revisionary_front_init');
	}

	function fltHomePreviewRequest($clauses, $_wp_query = false, $args = []) {
		global $wpdb, $wp_query;

		$preview_page_id = (!empty($_REQUEST['page__id'])) ? $_REQUEST['page__id'] : 0;

		if (!$preview_page_id || empty($wp_query) || empty($wp_query->query_vars) || empty($wp_query->query_vars['p'])) {
			return $clauses;
		}

		$front_page_id = $wp_query->query_vars['p'];

		if (rvy_post_id($preview_page_id) == $front_page_id) {
			$clauses['where'] = str_replace("$wpdb->posts.ID = $front_page_id", "$wpdb->posts.ID = $preview_page_id", $clauses['where']);
			$clauses['where'] = str_replace("post_id = $front_page_id", "post_id = $preview_page_id", $clauses['where']);

			$clauses['join'] = str_replace("$wpdb->posts.ID = $front_page_id", "$wpdb->posts.ID = $preview_page_id", $clauses['join']);
			$clauses['join'] = str_replace("post_id = $front_page_id", "post_id = $preview_page_id", $clauses['join']);
		}

		return $clauses;
	}

	function actFixRevisionPreviewStatus() {
		global $wpdb;

		if ($post_id = rvy_detect_post_id()) {
			if ($_post = get_post($post_id)) {
				if (('revision' == $_post->post_type) && rvy_is_revision_status($_post->post_mime_type)) {
					if (rvy_get_option('permissions_compat_mode')) {
						return;
					}
					
					$post_status = str_replace('-revision', '', $_post->post_mime_type);

					if (!in_array($post_status, ['draft', 'pending', 'future'])) {
						$post_status = 'pending';
					}

					if ($_post->post_parent) {
						$post_type = get_post_field('post_type', $_post->post_parent);
						$comment_count = $_post->post_parent;

					} elseif ($_post->comment_count) {
						$post_type = get_post_field('post_type', $_post->comment_count);
						$comment_count = $_post->comment_count;
					}

					$post_parent = get_post_field('post_parent', $comment_count);

					if (!empty($post_type)) {

						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						$wpdb->update(
							$wpdb->posts, 
							compact('post_status', 'post_type', 'comment_count', 'post_parent'), 
							['ID' => $post_id]								//phpcs:ignore WordPress.Security.NonceVerification.Recommended
						);

						if (defined('ELEMENTOR_VERSION') && rvy_get_option('elementor_revision_ensure_css_file')) {
							global $rvy_site_options, $rvy_blog_options;

							if ( isset($rvy_site_options["rvy_elementor_revision_ensure_css_file"]) ) {
								$rvy_site_options["rvy_elementor_revision_ensure_css_file"] = 0;
							}

							if ( isset($rvy_blog_options["rvy_elementor_revision_ensure_css_file"]) ) {
								$rvy_blog_options["rvy_elementor_revision_ensure_css_file"] = 0;
							}
						}
					}
				}
			}
		}
	}

	function fltGetPostMeta($meta_val, $object_id, $meta_key, $single, $meta_type) {
		static $busy;

		if (!empty($busy) || ('post' != $meta_type)) {
			return $meta_val;
		}

		$busy = true;

		if (wp_is_post_revision($object_id)) {
			$unfiltered_meta_val = get_post_meta($object_id, $meta_key, $single);

			if (in_array($unfiltered_meta_val, [null, []])) {
				if ($published_post_id = get_post_field('post_parent', $object_id)) {
					$published_meta_val = get_post_meta($published_post_id, $meta_key, $single);

					if (null !== $published_meta_val) {
						$meta_val = $published_meta_val;
					}
				}
			}
		}

		$busy = false;

		return $meta_val;
	}

	function fltACFLoadValue($value, $post_id, $field) {
		if ((null === $value) 
		&& (rvy_in_revision_workflow($post_id) || wp_is_post_revision($post_id))
		&& function_exists('acf_get_value')
		) {
			if ($published_post_id = rvy_post_id($post_id)) {

				if (($published_post_id != $post_id) && !rvy_in_revision_workflow($published_post_id)) {
					return acf_get_value($published_post_id, $field);
				}
			}
		}

		return $value;
	}

	function fltOptionPageOnFront($front_page_id) {
		global $post;

		// extra caution and perf optimization for front end execution
		if (!empty($post) && is_object($post) && rvy_in_revision_workflow($post) && ($post->comment_count == $front_page_id)) {
			return $post->ID;
		}

		return $front_page_id;
	}

	function fltBodyClass($classes, $css_class) {
		if ($this->isHomeRevision()) {
			$classes = array_merge(['home'], $classes);
			$classes = array_diff($classes, ['blog']);
		}

		return $classes;
	}

	function actSetQueriedObject(&$query) {
		if ($post_id = rvy_detect_post_id()) {
			if (rvy_in_revision_workflow($post_id) || wp_is_post_revision($post_id)) {
				$query->queried_object = get_post($post_id);
				$query->queried_object_id = $post_id;
			}
		}
	}

    function actFlagHomeRevision(&$query) {
		if ($this->isHomeRevision() 
		&& (
			!defined('ELEMENTOR_VERSION') 
			|| (
				(empty($_REQUEST['action']) || ('elementor' != $_REQUEST['action']))		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&& empty($_REQUEST['elementor-preview'])									//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				&& empty($_REQUEST['elementor_ajax'])										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				)
			) 
		) {
			if (rvy_get_option('home_preview_set_home_flag')) {
				$query->is_home = true;
			}

			$query->is_front_page = true;
		}
	}

	private function isHomeRevision() {
		if ($post_id = rvy_detect_post_id()) {
			if ($published_post_id = rvy_post_id($post_id)) {
				if ($published_post_id != $post_id) {
					if ( ('page' === get_option( 'show_on_front' )) 
					&& in_array( get_option( 'page_on_front' ), [$published_post_id, $post_id] )
					) {
						return true;
					}
				}
			}
		}

		return false;
	}

	public function actRevisionPreviewRedirect() {
		if (!class_exists('DS_Public_Post_Preview')) {
			return;
		}

		if ($_post = get_post(rvy_detect_post_id())) {
			if (('revision' == $_post->post_type) && ('inherit' == $_post->post_status)) {
				if ($url = get_permalink(rvy_post_id($_post->ID))) {
					wp_redirect($url);
					exit;
				}
			}
		}
	}

	public function fltAuthor($display_name) {
		if ($_post = get_post(rvy_detect_post_id())) {
			if (rvy_in_revision_workflow($_post) || wp_is_post_revision($_post)) {
				// we only need this workaround when multiple authors were not successfully stored
				if ($authors = get_multiple_authors($_post->ID, false)) {
					return $display_name;
				}

				if ($authors = get_multiple_authors(rvy_post_id($_post->ID), false)) {
					$author_displays = [];
					foreach($authors as $author) {
						$author_displays []= $author->display_name;
					}

					if (in_array($display_name,$author_displays)) {
						return $display_name;
					}

					return implode(', ', $author_displays);
				}
			}
		}

		return $display_name;
	}

	function flt_revision_preview_url($redirect_url, $requested_url) {
		remove_filter('redirect_canonical', array($this, 'flt_revision_preview_url'), 10, 2);
		return $requested_url;
	}

	function flt_view_revision($request) {
		global $current_user;

		//WP post/page preview passes this arg
		if ( ! empty( $_GET['preview_id'] ) ) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$published_post_id = (int) $_GET['preview_id'];									//phpcs:ignore WordPress.Security.NonceVerification.Recommended

			remove_filter( 'posts_request', array( &$this, 'flt_view_revision' ) ); // no infinite recursion!

			if ( $preview = wp_get_post_autosave($published_post_id, $current_user->ID) )
				$request = str_replace( "ID = '$published_post_id'", "ID = '$preview->ID'", $request );

			add_filter( 'posts_request', array( &$this, 'flt_view_revision' ) );

		} else {
			$revision_id = (isset($_REQUEST['page_id'])) ? (int) $_REQUEST['page_id'] : 0;	//phpcs:ignore WordPress.Security.NonceVerification.Recommended

			if (!$revision_id) {
				$revision_id = rvy_detect_post_id();
			}

			if (!$revision = wp_get_post_revision($revision_id)) {
				return $request;
			}

			// rvy_list_post_revisions passes these args
			if($revision && ('revision' == $revision->post_type)) {
				if ($pub_post = get_post($revision->post_parent)) {
					if ( $type_obj = get_post_type_object( $pub_post->post_type ) ) {
						if (current_user_can('read_post', $revision_id ) || current_user_can('edit_post', $revision_id)) {
							$request = str_replace( "post_type = 'post'", "post_type = 'revision'", $request );
							$request = str_replace( "post_type = '{$pub_post->post_type}'", "post_type = 'revision'", $request );
						}
					}
				}
			}
		}

		return $request;
	}

	// work around WP query_posts behavior (won't allow preview on posts unless status is public, private or protected)
	function inherit_status_workaround( $results ) {
		global $wp_post_statuses;

		if ( isset( $this->orig_inherit_protected_value ) )
			return $results;

		$this->orig_inherit_protected_value = $wp_post_statuses['inherit']->protected;

		$wp_post_statuses['inherit']->protected = true;
		return $results;
	}

	function undo_inherit_status_workaround( $results ) {
		if ( ! empty( $this->orig_inherit_protected_value ) )
			$wp_post_statuses['inherit']->protected = $this->orig_inherit_protected_value;

		return $results;
	}

	// allows for front-end viewing of a revision by those who can edit the current revision (also needed for post preview by users editing for pending revision)
	function act_template_redirect() {
		if ( is_admin() ) {
			return;
		}

		if (defined('FL_BUILDER_VERSION') && isset($_REQUEST['fl_builder'])) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		global $wp_query, $revisionary;
		if ($wp_query->is_404) {
			if (!empty($_REQUEST['base_post'])) {											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				if ($post = get_post(intval($_REQUEST['base_post']))) {						//phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited, WordPress.Security.NonceVerification.Recommended
					$url = get_permalink((int) $_REQUEST['base_post']);						//phpcs:ignore WordPress.Security.NonceVerification.Recommended
					wp_redirect($url);
					exit;
				}
			}
		}

		if (!empty($_REQUEST['page__id'])) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$revision_id = (int) $_REQUEST['page__id'];										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif (!empty($_REQUEST['page_id'])) {											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$revision_id = (int) $_REQUEST['page_id'];										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} elseif (!empty($_REQUEST['p'])) {													//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$revision_id = (int) $_REQUEST['p'];											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		} else {
			if (!empty($post)) {
				$revision_id = $post->ID;
			} else {
				$revision_id = 0;
			}
		}

		if ($wp_query->is_404 && !empty($revision_id) && (in_array(get_post_field('post_status', $revision_id), ['future', 'publish']) || defined('REVISIONARY_FORCE_PUBLICATION_REDIRECT'))) {
			// Work around timing issue when scheduled revision publication is underway
			if ($published_id = get_post_meta($revision_id, '_rvy_base_post_id', true)) {
				if ($post = get_post($published_id)) {										// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited)
					if ($type_obj = get_post_type_object($post->post_type)) {
						$redirect = ($type_obj && empty($type_obj->public)) ? rvy_admin_url("post.php?action=edit&post=$post->ID") : add_query_arg('mark_current_revision', 1, get_permalink($post->ID)); // published URL
						wp_redirect($redirect);
						exit;	
					}
				}
			}
		}

		do_action('revisionary_front', $revision_id);

		global $wpdb;

		if (empty($_REQUEST['mark_current_revision'])) {				//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			
			if (!$post = $wpdb->get_row(								// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.WP.GlobalVariablesOverride.Prohibited
				$wpdb->prepare(
					"SELECT * FROM $wpdb->posts WHERE ID = %d",
					$revision_id
				))
			) {
				if (!$post = wp_get_post_revision($revision_id)) {		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					return;
				}
			}
		}
	
		if (empty($post) || !empty($_REQUEST['mark_current_revision'])) {	//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			global $post;													//phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.VariableRedeclaration
		}

		//phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ((!empty($_REQUEST['mark_current_revision']) || rvy_in_revision_workflow($post) || ('revision' == $post->post_type)) && !isset($_REQUEST['fl_builder'])) {
			add_filter('redirect_canonical', array($this, 'flt_revision_preview_url'), 10, 2);

			if (!empty($_REQUEST['mark_current_revision'])) {										//phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$published_post_id = (!empty($post)) ? $post->ID : 0;
				$revision_id = $published_post_id;
			} else {
				$published_post_id = rvy_post_id($revision_id);

				do_action('revisionary_preview_load', $revision_id, $published_post_id);

				if (!defined('REVISIONARY_PREVIEW_NO_META_MIRROR') && !class_exists('CWS_PageLinksTo')) {
					// For display integrity, copy any missing keys from published post. Note: Any fields missing from revision are left unmodified at revision approval.
					revisionary_copy_postmeta($published_post_id, $revision_id, ['empty_target_only' => true]);
				}

				if (!defined('REVISIONARY_PREVIEW_NO_TERM_MIRROR')) {
					revisionary_copy_terms($published_post_id, $revision_id, ['empty_target_only' => true]);
				}

				if (defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION') && !defined('REVISIONARY_DISABLE_MA_PREVIEW_CORRECTION') 
				&& (rvy_in_revision_workflow($post) || wp_is_post_revision($post))
				) {
					$_authors = get_multiple_authors($revision_id);

					if (count($_authors) == 1) {
						$_author = reset($_authors);

						if ($_author && empty($_author->ID)) { // @todo: is this still necessary?
							$_author = MultipleAuthors\Classes\Objects\Author::get_by_term_id($_author->term_id);
						}
					}

					// If revision does not have valid multiple authors stored, correct to published post values
					if (empty($_authors) || (!empty($_author) && $_author->ID == $post->post_author)) {
						if (!$published_authors = wp_get_object_terms($published_post_id, 'author')) {
							if ($published_post = get_post($published_post_id)) {
								if ($author = MultipleAuthors\Classes\Objects\Author::get_by_user_id((int) $published_post->post_author)) {
									$published_authors = [$author];
								}
							}
						}

						rvy_set_ma_post_authors($revision_id, $published_authors);
					}
				}
			}

			if (empty($post)) {
				return;
			}

			$datef = __awp( 'M j, Y @ g:i a' );
			$date = agp_date_i18n( $datef, strtotime( $post->post_date ) );

			$color = '#ccc';
			$class = '';
			$message = '';

			// This topbar is presently only for those with restore / approve / publish rights
			$type_obj = get_post_type_object( $post->post_type );

			$can_publish = current_user_can('edit_post', $published_post_id);

			$redirect_arg = ( ! empty($_REQUEST['rvy_redirect']) ) ? "&rvy_redirect=" . esc_url_raw($_REQUEST['rvy_redirect']) : '';  //phpcs:ignore WordPress.Security.NonceVerification.Recommended

			load_plugin_textdomain('revisionary', false, dirname(plugin_basename(REVISIONARY_FILE)) . '/languages');

			$published_url = ($published_post_id) ? get_permalink($published_post_id) : '';
			$diff_url = rvy_admin_url("revision.php?revision=$revision_id");
			$queue_url = rvy_admin_url("admin.php?page=revisionary-q&published_post={$published_post_id}&all=1");

			if (((!rvy_get_option('revisor_hide_others_revisions') || current_user_can('list_others_revisions')) && !empty($type_obj) && current_user_can($type_obj->cap->edit_posts)) || current_user_can('read_post', $revision_id)) {
				if ($published_url) {
					$view_published = sprintf(
						apply_filters(
							'revisionary_list_caption',
							esc_html__("%sView Queue%s", 'revisionary'),
							$post // revision
						),
						"<a href='$queue_url' class='rvy-preview-link' target='_revision_list'>",
						'</a> <span class="rvy-preview-link">&bull;</span> '
					);

					if (current_user_can('edit_post', $revision_id)) {
						$view_published .= sprintf(
							apply_filters(
								'revisionary_preview_compare_view_caption',
								esc_html__("%sCompare%s%sView Published Post%s", 'revisionary'),
								$post // revision
							),
							"<a href='$diff_url' class='rvy-preview-link' target='_revision_diff'>",
							'</a> <span class="rvy-preview-link">&bull;</span> ',
							"<a href='$published_url' class='rvy-preview-link rvy_has_empty_spacing'>",
							'</a> <span class="rvy-preview-link">&bull;</span> '
						);
					} else {
						$view_published .= sprintf(
							apply_filters(
								'revisionary_preview_view_caption',
								esc_html__("%sView Published Post%s", 'revisionary'),
								$post // revision
							),
							"<a href='$published_url' class='rvy-preview-link'>",
							"</a> <span class='rvy-preview-link'>&bull;</span> "
						);
					}
				} else {
					$view_published = '';
				}
			} else { // @todo
				$view_published = ($published_url)
				? sprintf(
					apply_filters(
						'revisionary_preview_view_caption',
						esc_html__("%sView Published Post%s", 'revisionary'),
						$post // revision
					),
					"<a href='$published_url' class='rvy-preview-link'>",
					"</a> <span class='rvy-preview-link'>&bull;</span> "
					)
				: '';
			}

			if (current_user_can('edit_post', $revision_id)) {
				$edit_url = apply_filters('revisionary_preview_edit_url', rvy_admin_url("post.php?action=edit&amp;post=$revision_id"), $revision_id);
				$edit_button = "<a href='$edit_url' class='rvy-preview-link rvy_has_empty_spacing'>" . esc_html__('Edit', 'revisionary') . '</a>';

				if (empty($_REQUEST['mark_current_revision'])) {
					$edit_button .= ' <span class="rvy-preview-link">&bull;</span> ';
				}
			} else {
				$edit_button = '';
			}

			if ( !in_array( $post->post_mime_type, array( 'pending-revision', 'revision-approved', 'future-revision', 'inherit' ) ) ) {
				if ($can_edit = current_user_can('edit_post', $revision_id)) {
					$submit_url = wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&revision=$revision_id&action=submit$redirect_arg"), "submit-post_$published_post_id|$revision_id" );
					$publish_url =  wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&revision=$revision_id&action=approve$redirect_arg"), "approve-post_$published_post_id|$revision_id" );
				}
			} elseif ($can_edit = current_user_can('approve_revision', $revision_id)) {
				if ( !in_array( $post->post_mime_type, array( 'future-revision', 'inherit' ) ) ) {
					$publish_url = wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&revision=$revision_id&action=approve$redirect_arg"), "approve-post_$published_post_id|$revision_id" );

				} elseif ( in_array( $post->post_mime_type, array( 'future-revision' ) ) ) {
					$publish_url = wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&revision=$revision_id&action=publish$redirect_arg"), "publish-post_$published_post_id|$revision_id" );

				} elseif ( in_array( $post->post_status, array( 'inherit' ) ) ) {
					$publish_url = wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&revision=$revision_id&action=restore$redirect_arg"), "restore-post_$published_post_id|$revision_id" );

				} else {
					$publish_url = '';
				}
			} else {
				$publish_url = '';
			}

			if (('inherit' != $post->post_status || rvy_get_option('revision_archive_deletion')) && (is_content_administrator_rvy() || current_user_can('delete_post', $post->ID)) && !defined('REVISIONARY_PREVIEW_NO_DELETE_BUTTON')) {
				$delete_url = ('inherit' == $post->post_status)
				? esc_url(wp_nonce_url(admin_url("admin.php?page=rvy-revisions&amp;action=delete&amp;revision={$post->ID}"), 'delete-revision_' . $post->ID ))
				: get_delete_post_link($post->ID, '', true);

				$delete_button = '<a href="' . $delete_url . '" class="button button-secondary button-delete">' . esc_html__('Delete', 'revisionary') . '</a>';
			} else {
				$delete_button = '';
			}

			if (('revision' == $post->post_type) 
			&& (
				get_post_field('post_modified_gmt', $post->post_parent) == get_post_meta($revision_id, '_rvy_published_gmt', true) 
				&& empty($_REQUEST['mark_current_revision'])														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
			)
			) {
				if ($post = get_post($post->post_parent)) {				// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
					if ('revision' != $post->post_type && !rvy_in_revision_workflow($post)) {
						$url = add_query_arg('mark_current_revision', 1, get_permalink($post->ID));
						wp_redirect($url);
						exit;
					}
				}
			} else {
				switch ( $post->post_status ) {
				case 'draft' :
				case 'draft-revision' :	 // If permissions_compat_mode setting is enabled, revision status is stored directly to post_status column
					$class = 'draft';
					$status_obj = get_post_status_object(get_post_field('post_status', rvy_post_id($revision_id)));

					$approve_caption = esc_html__( 'Approve', 'revisionary' );

					if (!empty($submit_url) && current_user_can("set_revision_pending-revision", $revision_id)) {
						$submit_caption = esc_html__( 'Submit', 'revisionary' );
						$publish_button = '<a href="' . $submit_url . '" class="button button-secondary rvy-submit-revision">' . $submit_caption . '</a>';
					} else {
						$publish_button = '';
					}

					if ($can_publish) {
						$publish_caption = (!empty($status_obj->public) || !empty($status_obj->private)) ? esc_html__('Approve', 'revisionary') : $approve_caption;
						$publish_button .= ($can_publish) ? '<a href="' . $publish_url . '" class="button button-primary rvy-approve-revision">' . $publish_caption . '</a>' : '';
					}
					
					if (!empty($_REQUEST['elementor-preview'])) {													//phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$message = sprintf( esc_html__('This is a %s. %s %s %s', 'revisionary'), pp_revisions_status_label($post->post_mime_type, 'name'), '', '', '');
					} else {
						$message = sprintf( esc_html__('This is a %s. %s %s %s', 'revisionary'), pp_revisions_status_label($post->post_mime_type, 'name'), $view_published, $edit_button, $publish_button . $delete_button );
					}

					break;

					// alternate: no break here; output hidden pending-revision top bar

				// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				//case 'pending-revision' :
				default :
				if (empty($_REQUEST['mark_current_revision']) && ('inherit' != $post->post_status)) {						// phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if ('future-revision' != $post->post_mime_type) {
						$approve_caption = esc_html__( 'Approve', 'revisionary' );

						if ($can_publish && !defined('REVISIONARY_PREVIEW_NO_DECLINE_BUTTON')) {
							$decline_url = wp_nonce_url(admin_url("post.php?post=$revision_id&action=decline_revision"), 'decline-revision');
							$decline_button = ($can_publish) ? '<a href="' . $decline_url . '" class="button button-secondary">' . esc_html__('Decline', 'revisionary') . '</a>' : '';
						} else {
							$decline_button = '';
						}

						$decline_button .= $delete_button;

						if ( strtotime( $post->post_date_gmt ) > agp_time_gmt() ) {
							$class = 'pending_future';
							$publish_button = ($can_publish) ? '<a href="' . $publish_url . '" class="button button-primary rvy-approve-revision">' . $approve_caption . '</a>' : '';
							
							if ('pending-revision' == $post->post_mime_type) {
								if (!empty($_REQUEST['elementor-preview'])) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
									$message = sprintf( esc_html__('This is a %s (requested publish date: %s). %s %s %s', 'revisionary'), pp_revisions_status_label('pending-revision', 'name'), $date, '', '', '');
								} else {
									$message = sprintf( esc_html__('This is a %s (requested publish date: %s). %s %s %s', 'revisionary'), pp_revisions_status_label('pending-revision', 'name'), $date, $view_published, $edit_button, $publish_button . $decline_button );
								}
							} else {
								if (!empty($_REQUEST['elementor-preview'])) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
									$message = sprintf( esc_html__('Revision status: %s (requested publish date: %s) %s %s %s', 'revisionary'), pp_revisions_status_label($post->post_mime_type, 'name'), $date, '', '', '');
								} else {
									$message = sprintf( esc_html__('Revision status: %s (requested publish date: %s) %s %s %s', 'revisionary'), pp_revisions_status_label($post->post_mime_type, 'name'), $date, $view_published, $edit_button, $publish_button . $decline_button );
								}
							}
						} else {
							$class = 'pending';
							$status_obj = get_post_status_object(get_post_field('post_status', rvy_post_id($revision_id)));
							$publish_caption = (!empty($status_obj->public) || !empty($status_obj->private)) ? esc_html__('Approve', 'revisionary') : $approve_caption;
							$publish_button = ($can_publish) ? '<a href="' . $publish_url . '" class="button button-primary rvy-approve-revision">' . $publish_caption . '</a>' : '';
							
							if ('pending-revision' == $post->post_mime_type) {
								if (!empty($_REQUEST['elementor-preview'])) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
									$message = sprintf( esc_html__('This is a %s. %s %s %s', 'revisionary'), pp_revisions_status_label('pending-revision', 'name'), '', '', '' );
								} else {
									$message = sprintf( esc_html__('This is a %s. %s %s %s', 'revisionary'), pp_revisions_status_label('pending-revision', 'name'), $view_published, $edit_button, $publish_button . $decline_button );
								}
							} else {
								if (!empty($_REQUEST['elementor-preview'])) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
									$message = sprintf( esc_html__('Revision status: %s %s %s %s', 'revisionary'), pp_revisions_status_label($post->post_mime_type, 'name'), '', '', '' );
								} else {
									$message = sprintf( esc_html__('Revision status: %s %s %s %s', 'revisionary'), pp_revisions_status_label($post->post_mime_type, 'name'), $view_published, $edit_button, $publish_button . $decline_button );
								}
							}
						}

						break;

					// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
					//case 'future-revision' :
					} else {
						$class = 'future';
						
						$edit_url = rvy_admin_url("post.php?action=edit&amp;post=$revision_id");
						$publish_button = ($can_publish) ? '<a href="' . $publish_url . '" class="button button-primary">' . esc_html__( 'Publish Now', 'revisionary' ) . '</a>' : '';
						
						if (!empty($_REQUEST['elementor-preview'])) {													//phpcs:ignore WordPress.Security.NonceVerification.Recommended
							$message = sprintf( esc_html__('This is a %s (for publication on %s). %s %s %s', 'revisionary'), pp_revisions_status_label('future-revision', 'name'), $date, '', '', '' );
						} else {
							$message = sprintf( esc_html__('This is a %s (for publication on %s). %s %s %s', 'revisionary'), pp_revisions_status_label('future-revision', 'name'), $date, $view_published, $edit_button, $publish_button );
						}

						break;
					}
				}

				// phpcs:ignore Squiz.PHP.CommentedOutCode.Found
				//case '' :
				//default:
					if (!empty($_REQUEST['mark_current_revision'])) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$class = 'published';

						if (!$can_edit) {
							$edit_button = '';
						}

						if (!empty($_REQUEST['elementor-preview'])) {												//phpcs:ignore WordPress.Security.NonceVerification.Recommended
							$message = sprintf( esc_html__('This is the Current Revision. %s', 'revisionary'), '' );
						} else {
							$message = sprintf( esc_html__('This is the Current Revision. %s', 'revisionary'), $edit_button );
						}

					} elseif ('inherit' == $post->post_status) {
						if ( current_user_can('edit_post', $revision_id ) ) {
							$class = 'past';
							$date = agp_date_i18n( $datef, strtotime( $post->post_modified ) );

							if (rvy_get_option('revision_restore_require_cap') 
							&& !current_user_can('administrator') && !is_super_admin() && !current_user_can('restore_revisions')) {
								$publish_button = '';
							} else {
								$publish_button = ($can_publish) ? '<a href="' . $publish_url . '" class="button button-secondary">' . esc_html__( 'Restore', 'revisionary' ) . '</a>' : '';
							}

							if (!empty($_REQUEST['elementor-preview'])) {											//phpcs:ignore WordPress.Security.NonceVerification.Recommended
								$message = sprintf( esc_html__('This is a Past Revision (from %s). %s %s', 'revisionary'), $date, '', '' );
							} else {
								$message = sprintf( esc_html__('This is a Past Revision (from %s). %s %s', 'revisionary'), $date, $view_published, $publish_button . $delete_button );
							}
						}
					}
				}

				if (defined('REVISIONARY_LEGACY_PREVIEW_OUTPUT')) {
					add_action('wp_head', [$this, 'rvyFrontCSS']);
				} else {
					add_action('wp_enqueue_scripts', [$this, 'rvyEnqueueStyle'], 50);
				}

				add_action('wp_enqueue_scripts', [$this, 'rvyEnqueuePreviewJS']);

				if (empty($_REQUEST['elementor-preview'])) {														//phpcs:ignore WordPress.Security.NonceVerification.Recommended
					if (apply_filters('revisionary_admin_bar_absolute', !defined('REVISIONARY_PREVIEW_BAR_RELATIVE'))) {
						add_action('wp_print_footer_scripts', [$this, 'rvyPreviewJS'], 50);
					}
				}

				$html = '<div id="pp_revisions_top_bar" class="' . esc_attr("rvy_view_revision rvy_view_" . $class) . '">' .
						'<div class="rvy_preview_msgspan">' . $message . '</div></div>';

				if (defined('REVISIONARY_LEGACY_PREVIEW_OUTPUT')) {
					new RvyScheduledHtml( $html, 'wp_head', 99 );  // this should be inserted at the top of <body> instead, but currently no way to do it
				} else {
					new RvyScheduledHtml( $html, 'wp_print_scripts', 99 );
				}
			}
		}
	}

	function rvyFrontCSS() {
		echo '<link rel="stylesheet" href="' . esc_url(plugins_url('', REVISIONARY_FILE)) . '/revisionary-front.css" type="text/css" />'."\n";
	}

	function rvyEnqueueStyle() {
		wp_enqueue_style('revisionary-front', esc_url(plugins_url('', REVISIONARY_FILE)) . '/revisionary-front.css', [], PUBLISHPRESS_REVISIONS_VERSION);
	}

	function rvyEnqueuePreviewJS() {
		wp_enqueue_script('jquery');
	}

	function rvyPreviewJS() {
		?>
		<script type="text/javascript">
		/* <![CDATA[ */
		jQuery(document).ready( function($) {
			var rvyAdminBarMenuZindex = 100001;

			if ($('#wpadminbar').length) {
				var rvyAdminBarHeight = $('#wpadminbar').height();
				var barZ = parseInt($('#wpadminbar').css('z-index'));
				if (barZ > rvyAdminBarMenuZindex) {
					rvyAdminBarMenuZindex = barZ;
				}
			} else {
				if (!empty($_REQUEST['et_fb']) && !defined('PP_REVISIONS_DIVI_NO_ADMIN_BAR') && function_exists('et_divi_replace_parent_stylesheet') && (!defined('ET_BUILDER_PRODUCT_VERSION') || version_compare(ET_BUILDER_PRODUCT_VERSION, '4.14.8', '<='))) {
					var rvyAdminBarHeight = '32px';
				} else {
					var rvyAdminBarHeight = 0;
				}
			}

			$('div.rvy_view_revision').css('position', 'fixed').css('top', '32px');

			var rvyTotalHeight = $('div.rvy_view_revision').height() + rvyAdminBarHeight;
			var rvyTopBarZindex = $('div.rvy_view_revision').css('z-index');
			var rvyOtherElemZindex = 0;

			$('div.rvy_view_revision').css('top', rvyAdminBarHeight);

			$('body').css('padding-top', $('div.rvy_view_revision').height());

			$('header,div').each(function(i,e) {
				if ($(this).css('position') == 'fixed' && ($(this).attr('id') != 'wpadminbar') && (!$(this).hasClass('rvy_view_revision'))) {
					if ($(this).position().top < rvyTotalHeight ) {
						rvyOtherElemZindex = parseInt($(this).css('z-index'));

						if (rvyOtherElemZindex >= rvyAdminBarMenuZindex) {
							rvyOtherElemZindex = rvyAdminBarMenuZindex - 1;
						}

						if (rvyOtherElemZindex >= rvyTopBarZindex) {
							rvyTopBarZindex = rvyOtherElemZindex + 1;
							$('div.rvy_view_revision').css('z-index', rvyTopBarZindex);
						}

						$(this).css('padding-top', rvyTotalHeight.toString() + 'px');

						return false;
					}
				}
			});
		});
		/* ]]> */
		</script>
		<?php
	}
}

class RvyScheduledHtml {
	private $html;
	private $action;
	private $priority;

	function __construct( $html, $action, $priority = 10 ) {
		$this->html = $html;
		$this->action = $action;
		$this->priority = $priority;

		add_action( $action, array( $this, 'echo_html' ), $priority );
	}

	function echo_html() {
		echo $this->html;		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		remove_action( $this->action, array( $this, 'echo_html' ), $this->priority );
	}
}
