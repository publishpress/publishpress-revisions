<?php
class RevisionaryHistory
{
    private $published_post_ids = [];
    private $revision_status = [];
    private $revision_id = 0;
    private $authors = [];

	function __construct() {
        $this->revision_status = array_diff(
            rvy_revision_statuses(),
            ['future-revision']
        );

        add_action('load-revision.php', [$this, 'actLoadRevision']);

        add_action('admin_enqueue_scripts', [$this, 'actEnqueueScripts'], 10, 1);
        add_action('admin_head', [$this,'actRevisionDiffScripts']);
        add_action('admin_head', [$this, 'actPastRevisionDiffScripts'], 10, 1);
        add_action('admin_print_scripts', [$this, 'actCompareRevisionsTweakUI']);
        add_action('admin_print_footer_scripts', [$this, 'actCopyButtons']);

        add_filter('wp_prepare_revision_for_js', [$this, 'fltPrepareRevisionsForJS'], 10, 3);

        add_filter('wp_get_revision_ui_diff', [$this, 'fltGetRevisionUIDiff'], 10, 3);
        add_action('wp_ajax_get-revision-diffs', [$this, 'actAjaxRevisionDiffs'], 1);

        add_action('parse_query', [$this, 'actDisableProblemQueries'], 5);

        // Thin Out Revisions plugin breaks View / Approve button on Compare Pending Revisions screen
        if (class_exists('HM_TOR_Plugin_Loader')) {
            global $hm_tor_plugin_loader;
            if (!empty($hm_tor_plugin_loader)) {
                remove_action( 'admin_enqueue_scripts',  array( $hm_tor_plugin_loader, 'admin_enqueue_scripts' ), 20 );
            }
        }

	   if (did_action('load-revision.php')) {
		$this->actLoadRevision();
	   }
    }

    function actCompareRevisionsTweakUI() {
        global $revisionary;

        if (!empty($_REQUEST['revision'])) {                                            //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = (int) $_REQUEST['revision'];                                 //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } elseif (isset($_REQUEST['to'])) {                                             //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $revision_id = (int) $_REQUEST['to'];                                       //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        } else {
            return;
        }

        // Hide Restore button if user does not have permission
        if ($_post = get_post($revision_id)) {
            if (!rvy_in_revision_workflow($_post)) {
                $parent_post = get_post($_post->post_parent);

                if (($parent_post && !$revisionary->canEditPost($parent_post))
                || (rvy_get_option('revision_restore_require_cap') && !is_content_administrator_rvy() && !current_user_can('restore_revisions'))
                ) :
        ?>
                    <style type='text/css'>
                    input.restore-revision {display:none !important;}
                    </style>
                    <?php

                endif;
            }
        }
    }

    function actCopyButtons() {
        global $revisionary;

        if (rvy_get_option('compare_revisions_hide_copy_buttons')) {
            return;
        }

        ?>
        <script type="text/javascript">
        /* <![CDATA[ */
        jQuery(document).ready( function($) {
            setTimeout(() => {
                $('div.revisions-diff div.diff h2:nth(1)').css('display', 'inline-block').css('margin-right', '10px').after(
                    '<div style="display:inline-block;width:45%"><button id="rvy_copy_new_content_top" class="rvy-copy">'
                    + '<?php echo $revisionary->admin->tooltipText(__('Copy', 'revisionary'), __('Copy content to the clipboard.', 'revisionary'), false);?>'
                    + '</button></div>'
                ).after(
                    '<div style="display:inline-block;width:45%"><button id="rvy_copy_old_content_top" class="rvy-copy">'
                    + '<?php echo $revisionary->admin->tooltipText(__('Copy', 'revisionary'), __('Copy content to the clipboard.', 'revisionary'), false);?>'
                    + '</button></div>'
                );

                $('div.revisions-diff div.diff').find('table.diff').siblings('table.diff:nth(0)').after(
                    '<div class="rvy-copy"><button id="rvy_copy_old_content" class="rvy-copy">'
                    + '<?php echo $revisionary->admin->tooltipText(__('Copy', 'revisionary'), __('Copy the above content to the clipboard.', 'revisionary'), false);?>'
                    + '</button></div>'
                    + '<div class="rvy-copy"><button id="rvy_copy_new_content" class="rvy-copy">'
                    + '<?php echo $revisionary->admin->tooltipText(__('Copy', 'revisionary'), __('Copy the above content to the clipboard.', 'revisionary'), false);?>'
                    + '</button></div>'
                );
            }, 500);

            $(document).on('click', '#rvy_copy_old_content', function (e) {
                var content = '';

                $(this).closest('div.diff').find('table.diff:nth(1)').find('tbody tr').each(function() {
                    var e = $(this).find('td:nth(0)').clone();
                    $(e).find('span').remove();
                    content = content + '\r\n\r\n' + $(e).html();
                })
                
                navigator.clipboard.writeText(content);
            });

            $(document).on('click', '#rvy_copy_new_content', function (e) {
                var content = '';

                $(this).closest('div.diff').find('table.diff:nth(1)').find('tbody tr').each(function() {
                    var e = $(this).find('td:nth(1)').clone();
                    $(e).find('span').remove();
                    content = content + '\r\n\r\n' + $(e).html();
                })
                
                navigator.clipboard.writeText(content);
            });

            $(document).on('click', '#rvy_copy_old_content_top', function (e) {
                $('#rvy_copy_old_content').trigger('click');
            });

            $(document).on('click', '#rvy_copy_new_content_top', function (e) {
                $('#rvy_copy_new_content').trigger('click');
            });
        });
        /* ]]> */
        </script>

        <style>
        div.rvy-copy {
            display: inline-block;
            width: 50%;
            margin-top: 15px;
        }

        button.rvy-copy {
            padding: 5px;
            margin-bottom: 20px;
        }
        </style>
        <?php
    }

    function actDisableProblemQueries(WP_Query $query) {
        $query->set('tribe_suppress_query_filters', true);
    }

    public function actLoadRevision() {
        global $wpdb, $post, $title;

        //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!empty($_REQUEST['revision']) && is_scalar($_REQUEST['revision']) && !empty($_REQUEST['post_id']) && !is_numeric($_REQUEST['revision']) && rvy_is_revision_status(sanitize_key($_REQUEST['revision']))) {
            
            $revision_status = sanitize_key($_REQUEST['revision']);                     //phpcs:ignore WordPress.Security.NonceVerification.Recommended

            $orderby = ('future-revision' == $revision_status) ? 'post_date' : 'ID';
            $order =   ('future-revision' == $revision_status) ? 'DESC' : 'ASC';

            $_revisions = rvy_get_post_revisions(
                intval($_REQUEST['post_id']),                                           //phpcs:ignore WordPress.Security.NonceVerification.Recommended
                $revision_status, 
                ['orderby' => $orderby, 'order' => $order]
            );

            // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInTernaryCondition
            $revision_id = ($revision = array_pop($_revisions)) ? $revision->ID : 0;

            $_REQUEST['revision'] = $revision_id;
        } else {
            $revision_id = (isset($_REQUEST['revision'])) ? (int) $_REQUEST['revision'] : '';   //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        $from = (isset($_REQUEST['from'])) ? (int) $_REQUEST['from'] : '';              //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to = (isset($_REQUEST['to'])) ? (int) $_REQUEST['to'] : '';                    //phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $from = is_numeric( $from ) ? absint( $from ) : null;
        if ( ! $revision_id ) {
            $revision_id = absint( $to );
        }

        $this->revision_id = $revision_id;

        $redirect = 'edit.php';

        if (empty($action)) {
            $action = '';
        }

        switch ( $action ) {
            case 'view':
            case 'edit':
            default:
                if ( ! $revision = wp_get_post_revision( $revision_id ) ) {
                    if (!$revision = get_post($revision_id)) {
                        return;
                    }
                }

                // global variable used by WP core
                $post = $revision;                                                          // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited

                if (rvy_in_revision_workflow($revision)) {
                    if ( ! $published_post = get_post( rvy_post_id($revision->ID) ) ) {
                        return;
                    }
                } else {
                    if ($from && $from_revision = get_post($from)) {
                        if (rvy_in_revision_workflow($from_revision)) {
                            $_revision_id = $revision_id;   // @todo: eliminate this?
                            $revision = $from_revision;
                            $revision_id = $from;

                            if ( ! $published_post = get_post( rvy_post_id($revision->ID) ) ) {
                                return;
                            }
                        }
                    }
                }

                if (!rvy_in_revision_workflow($revision)) {
                    return;
                }

                if (!$published_post && !rvy_in_revision_workflow($from_revision)) {
                    if (!$published_post = get_post($revision->post_parent)) {
                        return;
                    }
                }

                if ((!current_user_can('read_post', $revision->ID) && !current_user_can('edit_post', $revision->ID))) {
                    return;
                }

                // Revisions disabled and we're not looking at an autosave
                if ( apply_filters('revisionary_revisions_disabled', false, $revision) && ! wp_is_post_autosave( $revision ) ) {
                    return;
                }

                $this->revision_status = $revision->post_mime_type;

                $status_obj = get_post_status_object($revision->post_mime_type);

                /* translators: %s: post title */
                $do_h1 = true;

                if (isset($status_obj->labels->plural)) {
                    $title = $status_obj->labels->plural;                             // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                }

                $redirect = false;
                break;
        }

        do_action('rvy_compare_revisions');

        require ABSPATH . 'wp-admin/includes/revision.php';

        // This is so that the correct "Edit" menu item is selected.
        if ( ! empty( $published_post->post_type ) && 'post' != $published_post->post_type ) {
            $parent_file = $submenu_file = 'edit.php?post_type=' . $published_post->post_type;
        } else {
            $parent_file = $submenu_file = 'edit.php';
        }

        wp_enqueue_script( 'revisions' );

        $this->actEnqueueScripts();

        if (!isset($title)) {
            $title = sprintf(                                                         // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
                esc_html__( 'Compare %s of "%s"', 'revisionary' ), 
                __('Revisions'),
                esc_html(_draft_or_post_title($published_post))
            );
        }

        require_once( ABSPATH . 'wp-admin/admin-header.php' );

        ?>

        <div class="wrap">
            <h1 class="long-header"><?php 
            if (!empty($do_h1)) {
                $status_plural = (!empty($status_obj->labels->plural)) ? $status_obj->labels->plural : __('Revisions');

                if (!$url = get_edit_post_link($published_post)) {
                    $url = '';
                }

                printf( 
                    esc_html__( 'Compare %s of "%s"', 'revisionary' ), 
                    esc_html($status_plural), 
                    '<a href="' . esc_url($url) . '">' . esc_html(_draft_or_post_title($published_post)) . '</a>'
                );
            }
            ?>
            </h1>
            <?php
            if (get_edit_post_link($published_post)) {
                echo '<a href="' . esc_url(get_edit_post_link($published_post)) . '">' . esc_html__( 'Return to editor' ) . '</a>';
            }
            ?>
        </div>
        <?php
        wp_print_revision_templates();

        require_once( ABSPATH . 'wp-admin/admin-footer.php' );
        exit;
    }

    public function fltFixMimeTypeClause($where) {
		return preg_replace("/post_mime_type LIKE '([a-z0-9_\-]+)\/\%'/", "post_mime_type LIKE '$1'", $where);


	}

    private function queryRevisions($post, $paged = false) {
        $this->published_post_ids = [$post->ID];

        $q = [];
        $q['post_type'] = $post->post_type;
		$q['orderby'] = 'id'; // 'modified';
		$q['order'] = 'ASC';
		$q['post_mime_type'] = ('future-revision' == $this->revision_status) ? $this->revision_status : array_diff(rvy_revision_statuses(), ['future-revision']);
        $q['posts_per_page'] = 99;
        $q['is_revisions_query'] = true;

        $q = apply_filters('revisionary_compare_vars', $q);

        add_filter('posts_clauses', [$this, 'fltRevisionClauses'], 5, 2);

        add_filter('posts_where', [$this, 'fltFixMimeTypeClause']);
		$rvy_query = new WP_Query($q);
		remove_filter('posts_where', [$this, 'fltFixMimeTypeClause']);

        remove_filter('posts_clauses', [$this, 'fltRevisionClauses'], 5, 2);

        $posts = [];

        // key by ID for js array prep
        foreach($rvy_query->posts as $_post) {
            $posts[$_post->ID] = $_post;
        }

        return $posts;
    }

    public function actEnqueueScripts($hook_suffix='') {
        if (!did_action('rvy_compare_revisions')) {
            return;
        }

        $revision_id = (isset($_REQUEST['revision'])) ? absint($_REQUEST['revision']) : '';     //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $from = (isset($_REQUEST['from'])) ? (int) $_REQUEST['from'] : '';                      //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to = (isset($_REQUEST['to'])) ? (int) $_REQUEST['to'] : '';                            //phpcs:ignore WordPress.Security.NonceVerification.Recommended

        $from = is_numeric( $from ) ? absint( $from ) : null;
        if ( ! $revision_id ) {
            $revision_id = absint( $to );
        }

        if ( !$revision = wp_get_post_revision( $revision_id ) ) {
            if (!$revision = get_post($revision_id)) {
                return;
            }
        }

        $post_id = (rvy_in_revision_workflow($revision)) ? rvy_post_id($revision->ID) : $revision->post_parent;

        if (!$post = get_post($post_id)) {
            return;
        }

        if (!$from) {
            $from = $post->ID;
        }

        $rvy_revisions = $this->queryRevisions($post);

        $revisions = $this->prepare_revisions_for_js( $post, $revision_id, $from, $rvy_revisions );

        add_filter('posts_clauses', [$this, 'fltRevisionClauses'], 5, 2);

        wp_localize_script( 'revisions', '_wpRevisionsSettings', $revisions );
    }

    public function fltRevisionQueryWhere($where, $args = []) {
        global $wpdb;

        $p = (!empty($args['alias'])) ? sanitize_text_field($args['alias']) : $wpdb->posts;

		$post_id_csv = implode("','", array_map('intval', $this->published_post_ids));

		$where .= " AND $p.comment_count IN ('$post_id_csv')";

        return $where;
    }

    function fltRevisionClauses($clauses, $_wp_query = false) {
		$clauses['where'] = $this->fltRevisionQueryWhere($clauses['where']);
		return $clauses;
	}

    public function fltPrepareRevisionsForJS($revisions_data, $revision, $post) {
        return $revisions_data;
    }

    public static function fltOrderRevisionsByModified($qry) {
        global $wpdb;

        $qry = str_replace("ORDER BY $wpdb->posts.post_date", "ORDER BY $wpdb->posts.post_modified", $qry);

        return $qry;
    }

    // port wp_ajax_get_revision_diffs() to support pending, scheduled revisions
    public function actAjaxRevisionDiffs() {
        if (!isset($_REQUEST['post_id'])) {                                                     //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return; 
        }

        if ( ! $post = get_post( (int) $_REQUEST['post_id'] ) ) {                               //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }

        if ( ! current_user_can( 'read_post', $post->ID ) ) {
            return;
        }

        $revision_id = (isset($_REQUEST['revision'])) ? absint($_REQUEST['revision']) : '';     //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $from = (isset($_REQUEST['from'])) ? (int) $_REQUEST['from'] : '';                      //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $to = (isset($_REQUEST['to'])) ? (int) $_REQUEST['to'] : '';                            //phpcs:ignore WordPress.Security.NonceVerification.Recommended

        if (!$revision_id && !$to && !empty($_REQUEST['compare'])) {                            //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $compare = sanitize_text_field(
                is_array($_REQUEST['compare'])                                                  //phpcs:ignore WordPress.Security.NonceVerification.Recommended
                ? reset($_REQUEST['compare'])    //phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
                : $_REQUEST['compare']                                                          //phpcs:ignore WordPress.Security.NonceVerification.Recommended
            );
            
            list( $from, $to ) = explode( ':', $compare); // from:to
        }

        $from = is_numeric( $from ) ? absint( $from ) : null;
        if ( ! $revision_id ) {
            $revision_id = absint( $to );
        }

        if (!$revision = get_post($revision_id)) {
            return;
        }

        if (!rvy_in_revision_workflow($revision)) {
            return;
        }

        $this->revision_status = $revision->post_mime_type;

        if (!$rvy_revisions = $this->queryRevisions($post)) {
            return;
        }

        if (!current_user_can('edit_post', $revision_id) && !current_user_can('read_post', $revision_id)) {
            return;
        }

        $return = array();
        @set_time_limit( 0 );

        $current_revision_id  = $post->ID;

        $return[] = [
            'id'     => "0:{$current_revision_id}",
            'fields' => $this->getRevisionUIDiff( $post, $post->ID, $current_revision_id ),
        ];

        foreach($rvy_revisions as $rvy_revision) {
            $return[] = [
                'id'     => "{$current_revision_id}:{$rvy_revision->ID}",
                'fields' => $this->getRevisionUIDiff( $post, $current_revision_id, $rvy_revision->ID ),
            ];
        }

        $rvy_revisions_copy = array_values($rvy_revisions);

        foreach($rvy_revisions_copy as $revision_copy) {
            foreach($rvy_revisions as $rvy_revision) {
                if ($revision_copy->ID != $rvy_revision->ID) {
                    $return[] = [
                        'id'     => "{$revision_copy->ID}:{$rvy_revision->ID}",
                        'fields' => $this->getRevisionUIDiff( $post, $revision_copy->ID, $rvy_revision->ID ),
                    ];
                }
            }
        }

        wp_send_json_success( $return );
    }

    public function fltGetRevisionUIDiff($return, $compare_from, $compare_to) {
        if ( $compare_from ) {
            if ( ! $compare_from = get_post( $compare_from ) ) {
                return $return;
            }
        } else {
            // If we're dealing with the first revision...
            $compare_from = false;
        }

        if ( ! $compare_to = get_post( $compare_to ) ) {
            return $return;
        }

        if (!rvy_in_revision_workflow($compare_from) && !rvy_in_revision_workflow($compare_to)) {
            return $return;
        }

        return $this->getRevisionUIDiff(rvy_post_id($compare_to), $compare_from, $compare_to);
    }

    // port of core wp_get_revision_ui_diff() to allow comparison of pending, future revisions (published post ID stored in comment_count instead of post_parent)
    public function getRevisionUIDiff($post, $compare_from, $compare_to) {
        if ( ! $post ) {
            return false;
        }

        if ( $compare_from ) {
            if ( ! $compare_from = get_post( $compare_from ) ) {
                return false;
            }
        } else {
            // If we're dealing with the first revision...
            $compare_from = false;
        }

        if ( ! $compare_to = get_post( $compare_to ) ) {
            return false;
        }

        $strip_tags = rvy_get_option('diff_display_strip_tags');

        // Add default title if title field is empty
        if ( $compare_from && empty( $compare_from->post_title ) ) {
            $compare_from->post_title = esc_html__( '(no title)' );
        }
        if ( empty( $compare_to->post_title ) ) {
            $compare_to->post_title = esc_html__( '(no title)' );
        }

        $return = array();

        $acf_active = function_exists('acf_get_setting');

        foreach ( $all_meta_fields = _wp_post_revision_fields( $compare_to ) as $field => $name ) {
            /**
             * Contextually filter a post revision field.
             *
             * The dynamic portion of the hook name, `$field`, corresponds to each of the post
             * fields of the revision object being iterated over in a foreach statement.
             *
             * @since 3.6.0
             *
             * @param string  $compare_from->$field The current revision field to compare to or from.
             * @param string  $field                The current revision field.
             * @param WP_Post $compare_from         The revision post object to compare to or from.
             * @param string  null                  The context of whether the current revision is the old
             *                                      or the new one. Values are 'to' or 'from'.
             */
            $content_from = $compare_from ? apply_filters( "_wp_post_revision_field_{$field}", $compare_from->$field, $field, $compare_from, 'from' ) : '';

            /** This filter is documented in wp-admin/includes/revision.php */
            $content_to = apply_filters( "_wp_post_revision_field_{$field}", $compare_to->$field, $field, $compare_to, 'to' );

            if ($acf_active && is_scalar($content_to) && (0 === strpos($content_to, 'field_'))) {
                continue;
            }

            $args = array(
                'show_split_view' => true,
            );

            /**
             * Filters revisions text diff options.
             *
             * Filters the options passed to wp_text_diff() when viewing a post revision.
             *
             * @since 4.1.0
             *
             * @param array   $args {
             *     Associative array of options to pass to wp_text_diff().
             *
             *     @type bool $show_split_view True for split view (two columns), false for
             *                                 un-split view (single column). Default true.
             * }
             * @param string  $field        The current revision field.
             * @param WP_Post $compare_from The revision post to compare from.
             * @param WP_Post $compare_to   The revision post to compare to.
             */
            $args = apply_filters( 'revision_text_diff_options', $args, $field, $compare_from, $compare_to );

            if ($strip_tags) {
                if (is_string($content_from)) {
                    $content_from = wp_strip_all_tags($content_from);
                }

                if (is_string($content_to)) {
                    $content_to = wp_strip_all_tags($content_to);
                }
            }

            $diff = wp_text_diff( $content_from, $content_to, $args );

            if ( ! $diff && 'post_title' === $field ) {
                // It's a better user experience to still show the Title, even if it didn't change.
                // No, you didn't see this.
                $diff = '<table class="diff"><colgroup><col class="content diffsplit left"><col class="content diffsplit middle"><col class="content diffsplit right"></colgroup><tbody><tr>';

                // In split screen mode, show the title before/after side by side.
                if ( true === $args['show_split_view'] ) {
                    $diff .= '<td>' . esc_html( $compare_from->post_title ) . '</td><td></td><td>' . esc_html( $compare_to->post_title ) . '</td>';
                } else {
                    $diff .= '<td>' . esc_html( $compare_from->post_title ) . '</td>';

                    // In single column mode, only show the title once if unchanged.
                    if ( $compare_from->post_title !== $compare_to->post_title ) {
                        $diff .= '</tr><tr><td>' . esc_html( $compare_to->post_title ) . '</td>';
                    }
                }

                $diff .= '</tr></tbody>';
                $diff .= '</table>';
            }

            if ( $diff ) {
                $return[] = array(
                    'id'   => $field,
                    'name' => $name,
                    'diff' => $diff,
                );
            }
        }

        $compare_fields = [
            'post_date' =>      esc_html__('Post Date', 'revisionary'),
            'post_parent' =>    esc_html__('Post Parent', 'revisionary'),
            'menu_order' =>     esc_html__('Menu Order', 'revisionary'),
            'comment_status' => esc_html__('Comment Status', 'revisionary'),
            'ping_status' =>    esc_html__('Ping Status', 'revisionary'),
        ];

        $revision_statuses = array_diff(
            rvy_revision_statuses(),
            ['future-revision']
        );

        if (
        ((('future-revision' == $compare_from->post_mime_type) || ('future-revision' == $compare_to->post_mime_type)) && !rvy_get_option('scheduled_revision_update_post_date'))
        || ((in_array($compare_from->post_mime_type, $revision_statuses) || in_array($compare_to->post_mime_type, $revision_statuses)) && !rvy_get_option('pending_revision_update_post_date'))
        ) {
            unset($compare_fields['post_date']);
        }

        // === Add core post fields which cannot normally be versioned
        foreach( apply_filters('revisionary_compare_post_fields', $compare_fields) as $field => $name
        ) {
            // don't display post date difference when it's set to a future date for scheduling
            if (strtotime($compare_to->post_date_gmt) > agp_time_gmt() || ('future-revision' == $compare_to->post_mime_type)) {
                continue;
            }

            if (('post_parent' == $field) && ($compare_from->$field != $compare_to->$field)) {
                if (!$parent_post = get_post($compare_from->$field)) {
                    $from_val = $compare_from->$field;
                } else {
                    $from_val = $parent_post->post_title . " (id: $parent_post->ID)";
                }

                if (!$parent_post = get_post($compare_to->$field)) {
                    $to_val = $compare_from->$field;
                } else {
                    $to_val = $parent_post->post_title . " (id: $parent_post->ID)";
                }

                $content_from = $compare_from ? apply_filters( "_wp_post_revision_field_{$field}", $from_val, $field, $compare_from, 'from' ) : '';
                $content_to = apply_filters( "_wp_post_revision_field_{$field}", $to_val, $field, $compare_to, 'to' );
            } else {
                $content_from = $compare_from ? apply_filters( "_wp_post_revision_field_{$field}", $compare_from->$field, $field, $compare_from, 'from' ) : '';
                $content_to = apply_filters( "_wp_post_revision_field_{$field}", $compare_to->$field, $field, $compare_to, 'to' );
            }

            $args = array(
                'show_split_view' => true,
            );

            $args = apply_filters( 'revision_text_diff_options', $args, $field, $compare_from, $compare_to );

            if ($strip_tags) {
                $content_from = wp_strip_all_tags($content_from);
                $content_to = wp_strip_all_tags($content_to);
            }

            if ($diff = wp_text_diff( $content_from, $content_to, $args )) {
                $return[] = array(
                    'id'   => $field,
                    'name' => $name,
                    'diff' => $diff,
                );
            }
        }

        // === Add taxonomies
        $taxonomies = [];
        $_taxonomies = get_taxonomies(['public' => true], 'objects');

        foreach($_taxonomies as $taxonomy => $tx_obj) {
            if (in_array($compare_to->post_type, (array)$tx_obj->object_type)) {
                $taxonomies[$taxonomy] = $tx_obj->labels->name;
            }
        }

        $published_id = rvy_post_id($compare_from->ID);
		$is_beaver = defined('FL_BUILDER_VERSION') && get_post_meta($published_id, '_fl_builder_data', true);

        foreach( apply_filters('revisionary_compare_taxonomies', $taxonomies) as $taxonomy => $name) {
            $field = $taxonomy;

            if (!$terms = get_the_terms($compare_from, $taxonomy)) {
                $terms = [];
            }
            $term_names = [];
            foreach($terms as $term) {
                $term_names []= $term->name;
            }
            sort($term_names);
            $val = implode(", ", $term_names);
            $content_from = apply_filters( "_wp_post_revision_field_{$field}", $val, $field, $compare_from, 'from' );

            if (!$terms = get_the_terms($compare_to, $taxonomy)) {
                $terms = [];
            }

            $other_term_names = $term_names;

            $term_names = [];
            foreach($terms as $term) {
                $term_names []= $term->name;
            }
            sort($term_names);
            $val = implode(", ", $term_names);
            $content_to = apply_filters( "_wp_post_revision_field_{$field}", $val, $field, $compare_to, 'to' );

            $args = array(
                'show_split_view' => true,
            );

            if ($is_beaver
            && (!$other_term_names && !rvy_in_revision_workflow($compare_from))
            || (!$term_names && !rvy_in_revision_workflow($compare_to))
            ) {
                continue;
            }

            $args = apply_filters( 'revision_text_diff_options', $args, $field, $compare_from, $compare_to );

            if ($diff = wp_text_diff( $content_from, $content_to, $args )) {
                $return[] = array(
                    'id'   => $field,
                    'name' => $name,
                    'diff' => $diff,
                );
            }
        }

        // === Add select postmeta fields
        if ($compare_from) {
            $from_meta = get_post_meta($compare_from->ID);
        }

        $to_meta = get_post_meta($compare_to->ID);

        if (defined('ELEMENTOR_VERSION')) {
            unset($to_meta['_elementor_data']);
            unset($from_meta['_elementor_data']);
        }

        $meta_fields = [];

        $meta_fields['_wp_page_template'] = esc_html__('Page Template', 'revisionary');

        if (post_type_supports($compare_to->post_type, 'thumbnail')) {
            $meta_fields ['_thumbnail_id'] = esc_html__('Featured Image', 'revisionary');
        }

        if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION') && defined('FL_BUILDER_VERSION') && defined('REVISIONARY_BEAVER_BUILDER_DIFF')) {  // todo: move to filter
            $meta_fields['_fl_builder_data'] = esc_html__('Beaver Builder Data', 'revisionary');
            $meta_fields['_fl_builder_data_settings'] = esc_html__('Beaver Builder Settings', 'revisionary');
        }

        $native_fields = _wp_post_revision_fields( $to_meta, $compare_to );

        $meta_fields = array_diff_key( apply_filters('revisionary_compare_meta_fields', $meta_fields), $native_fields );

        foreach($meta_fields as $field => $name) {
            if ($compare_from) {
                $val = get_post_meta($compare_from->ID, $field, true);
                $content_from = maybe_serialize(apply_filters( "_wp_post_revision_field_{$field}", $val, $field, $compare_from, 'from' ));
            } else {
                $content_from = '';
            }

            $val = get_post_meta($compare_to->ID, $field, true);
            $content_to = maybe_serialize(apply_filters( "_wp_post_revision_field_{$field}", $val, $field, $compare_to, 'to' ));

            if ('_thumbnail_id' == $field) {
                $content_from = ($content_from) ? "$content_from (" . wp_get_attachment_image_url($content_from, 'full') . ')' : '';
                $content_to = ($content_to) ? "$content_to (" . wp_get_attachment_image_url($content_to, 'full') . ')' : '';

                // suppress false alarm for featured image clearance
                if ($content_from && !$content_to) {
                    continue;
                }

            } elseif(('_requested_slug' == $field)) {
                if ($content_to && !rvy_in_revision_workflow($compare_to)) {
                    $content_to = '';
                }

                if ($content_from && !rvy_in_revision_workflow($compare_from)) {
                    $content_from = '';
                }

                if ($content_to && !$content_from) {
	                if ($parent_post = get_post($published_id)) {
	                    $content_from = $parent_post->post_name;
	                }
	            }
            }

            if ($is_beaver && !$content_to) {
                continue;
            }

            $args = array(
                'show_split_view' => true,
            );

            $args = apply_filters( 'revision_text_diff_options', $args, $field, $compare_from, $compare_to );

            if ($strip_tags) {
                $content_from = wp_strip_all_tags($content_from);
                $content_to = wp_strip_all_tags($content_to);
            }

            if ($diff = wp_text_diff( $content_from, $content_to, $args )) {
                $return[] = array(
                    'id'   => $field,
                    'name' => $name,
                    'diff' => $diff,
                );
            }
        }

        $args = compact('to_meta', 'native_fields', 'meta_fields', 'strip_tags');
        $return = apply_filters('revisionary_diff_ui', $return, $compare_from, $compare_to, $args);

        return $return;
    }

    private function loadAuthorInfo($revision, $use_multiple_authors, $args = []) {
        $show_avatars = !empty($args['show_avatars']);

        if ($use_multiple_authors = $use_multiple_authors && function_exists('get_multiple_authors')) {
            $author_ids = [];
            $authors = get_multiple_authors($revision);
            foreach($authors as $_author) {
                $author_ids []= $_author->ID;
            }
        }

        if (!$use_multiple_authors || !$author_ids) {
            $author_ids = [$revision->post_author];

            if ($_author = new WP_User($revision->post_author)) {
                $authors = [$_author];
            }
        }

        sort($author_ids);
        $author_key = implode(",", $author_ids);

        if ( ! isset( $this->authors[ $author_key ] ) ) {
            $author_captions = [];
            $avatars = '';
            foreach($authors as $_author) {
                $author_captions []= ($use_multiple_authors) ? esc_html($_author->display_name) : htmlspecialchars_decode(get_the_author_meta('display_name', $_author->ID));
                $avatars .= $show_avatars ? get_avatar( $_author->ID, 32 ) : '';
            }

            if (empty($author_captions)) {
                $author_captions[] =  esc_html__('No author', 'revisionary');
            }

            $this->authors[ $author_key ] = array(
                'id'     => (int) $revision->post_author,
                'avatar' => $avatars,
                'name'   => implode(', ', $author_captions),
            );
        }

        return $author_key;
    }

    /**
     * Prepare revisions for JavaScript.

     * @param object|int $post                 The post object. Also accepts a post ID.
     * @param int        $selected_revision_id The selected revision ID.
     * @param int        $from                 Optional. The revision ID to compare from.
     *
     * @return array An associative array of revision data and related settings.
     */

    // Port of wp_prepare_revisions_for_js() to support pending, scheduled revisions
    private function prepare_revisions_for_js( $post, $selected_revision_id, $from = null, $revisions = null ) {
        $post    = get_post( $post );
        $now_gmt = time();

        if (is_null($revisions)) {
            $revisions = wp_get_post_revisions(
                $post->ID,
                array(
                    'order'         => 'ASC',
                    'check_enabled' => false,
                )
            );
        }

        // If revisions are disabled, we only want autosaves and the current post.
        if ( $revisions_disabled = apply_filters('revisionary_revisions_disabled', false, $post) ) {
            foreach ( $revisions as $revision_id => $revision ) {
                if ( ! wp_is_post_autosave( $revision ) ) {
                    unset( $revisions[ $revision_id ] );
                }
            }

            $revisions = [$post->ID => $post] + $revisions;
        }

        $show_avatars = get_option( 'show_avatars' );

        cache_users( wp_list_pluck( $revisions, 'post_author' ) );

        $type_obj = get_post_type_object($post->post_type);

        $can_restore = current_user_can('edit_post', $post->ID);

        $current_id  = false;

        $revisions =  [$post->ID => $post] + $revisions;

        $is_administrator = is_content_administrator_rvy();

        foreach ( $revisions as $k => $revision ) {
            if (!$is_administrator && !current_user_can('edit_post', $revision->ID) && !current_user_can('read_post', $revision->ID)) {
                unset($revisions[$k]);
                continue;
            }

            $modified     = strtotime( $revision->post_modified );
            $modified_gmt = strtotime( $revision->post_modified_gmt . ' +0000' );

            $current = ($revision->ID == $post->ID);

            if ( $current && ! empty( $current_id ) ) {
                // If multiple revisions have the same post_modified_gmt, highest ID is current.
                if ( $current_id < $revision->ID ) {
                    $revisions[ $current_id ]['current'] = false;
                    $current_id                          = $revision->ID;
                } else {
                    $current = false;
                }
            } elseif ( $current ) {
                $current_id = $revision->ID;
            }

            // Without this step, "Current Revision" shows stored post_author (or Multiple Authors), regardless of user(s) who made the last update
            if ($current && !$revisions_disabled && !defined('RVY_LEGACY_COMPARE_REVISIONS_AUTHOR_DISPLAY')) {
                if ($past_revisions = wp_get_post_revisions($post->ID, ['orderby' => 'ID', 'order' => 'DESC'])) {

                    // Ignore autosaves.
                    foreach($past_revisions as $id => $past_revision) {
                        if ( false !== strpos( $past_revision->post_name, "{$past_revision->post_parent}-autosave" ) ) {
                            unset($past_revisions[$id]);
                        }
                    }

                    if ($last_revision = array_shift($past_revisions)) {
                        if ($last_revision->ID > $post->session_id) {
                            $revision->post_author = $last_revision->post_author;
                        }
                    }
                }
            }

            $edit_url = false;

            // Until Reject button is implemented, just route to Preview screen so revision can be edited / deleted if necessary
            if ($current || rvy_in_revision_workflow($revision)) {
                $restore_link = rvy_preview_url($revision);  // default to revision preview link

                if ($can_restore) {
                    $published_post_id = rvy_post_id($revision->ID);

                    // For non-public types, force direct approval because preview is not available
	                if ((($type_obj && !is_post_type_viewable($type_obj)) || rvy_get_option('compare_revisions_direct_approval')) && current_user_can( 'edit_post', $published_post_id) ) {
                        
                                                                                                    //phpcs:ignore WordPress.Security.NonceVerification.Recommended
                        $redirect_arg = ( ! empty($_REQUEST['rvy_redirect']) ) ? "&rvy_redirect=" . esc_url_raw($_REQUEST['rvy_redirect']) : '';

                        $revision_statuses = array_diff(
                            rvy_revision_statuses(),
                            ['future-revision']
                        );

                        if (in_array($revision->post_mime_type, $revision_statuses)) {
                            $restore_link = wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&revision={$revision->ID}&action=approve$redirect_arg"), "approve-post_$published_post_id|{$revision->ID}" );

                        } elseif (in_array($revision->post_mime_type, ['future-revision'])) {
                            $restore_link = wp_nonce_url( rvy_admin_url("admin.php?page=rvy-revisions&revision={$revision->ID}&action=publish$redirect_arg"), "publish-post_$published_post_id|{$revision->ID}" );
                        }

                        if (current_user_can('edit_post', $revision->ID)) {
                            $edit_url = rvy_admin_url("post.php?action=edit&post=$revision->ID");
                        }
	                }
                }
            } else {
                $restore_link = '';
            }

            $revision_statuses = array_diff(
                rvy_revision_statuses(),
                ['future-revision']
            );

            if ('future-revision' == $revision->post_mime_type) {
                $date_prefix = esc_html__('Scheduled for ', 'revisionary');
                $modified     = strtotime( $revision->post_date );
		        $modified_gmt = strtotime( $revision->post_date_gmt . ' +0000' );

            } elseif (in_array($revision->post_mime_type, $revision_statuses) && (strtotime($revision->post_date_gmt) > $now_gmt ) ) {
                $date_prefix = esc_html__('Requested for ', 'revisionary');
                $modified     = strtotime( $revision->post_date );
		        $modified_gmt = strtotime( $revision->post_date_gmt . ' +0000' );

            } else {
                $date_prefix = esc_html__('Modified ', 'revisionary');
                $modified     = strtotime( $revision->post_modified );
		        $modified_gmt = strtotime( $revision->post_modified_gmt . ' +0000' );
            }

            $time_diff_label = ($now_gmt > $modified_gmt) ? esc_html__( '%s%s ago' ) : esc_html__( '%s%s from now', 'revisionary');

            $use_multiple_authors = function_exists('get_multiple_authors') && !rvy_in_revision_workflow($revision);

            // Just track single post_author for revision.  Changes to Authors taxonomy will be applied to published post.
            $author_key = $this->loadAuthorInfo($revision, $use_multiple_authors, compact('show_avatars'));

            $revisions_data = [
                'id'         => $revision->ID,
                'title'      => get_the_title( $revision->ID ),
                'author'     => $this->authors[ $author_key ],
                'date'       => sprintf('%s%s', $date_prefix, date_i18n( esc_html__( 'M j, Y @ g:i a', 'revisionary' ), $modified )),
                'dateShort'  => date_i18n( _x( 'j M @ g:i a', 'revision date short format' ), $modified ),
                'timeAgo'    => sprintf( $time_diff_label, $date_prefix, human_time_diff( $modified_gmt, $now_gmt ) ),
                'autosave'   => false,
                'current'    => $current,
                'restoreUrl' => $restore_link,
                'editUrl'    => $edit_url,
            ];

            /**
             * Filters the array of revisions used on the revisions screen.
             *
             * @since 4.4.0
             *
             * @param array   $revisions_data {
             *     The bootstrapped data for the revisions screen.
             *
             *     @type int        $id         Revision ID.
             *     @type string     $title      Title for the revision's parent WP_Post object.
             *     @type int        $author     Revision post author ID.
             *     @type string     $date       Date the revision was modified.
             *     @type string     $dateShort  Short-form version of the date the revision was modified.
             *     @type string     $timeAgo    GMT-aware amount of time ago the revision was modified.
             *     @type bool       $autosave   Whether the revision is an autosave.
             *     @type bool       $current    Whether the revision is both not an autosave and the post
             *                                  modified date matches the revision modified date (GMT-aware).
             *     @type bool|false $restoreUrl URL if the revision can be restored, false otherwise.
             * }
             * @param WP_Post $revision       The revision's WP_Post object.
             * @param WP_Post $post           The revision's parent WP_Post object.
             */
            $revisions[ $revision->ID ] = apply_filters( 'wp_prepare_revision_for_js', $revisions_data, $revision, $post );
        }

        /**
         * If we only have one revision, the initial revision is missing; This happens
         * when we have an autsosave and the user has clicked 'View the Autosave'
         */
        if ( 1 === sizeof( $revisions ) ) {
            $author_key = $this->loadAuthorInfo($post, true, compact('show_avatars'));

            $revisions[ $post->ID ] = array(
                'id'         => $post->ID,
                'title'      => get_the_title( $post->ID ),
                'author'     => $this->authors[ $author_key ],
                'date'       => date_i18n( esc_html__( 'M j, Y @ H:i', 'revisionary' ), strtotime( $post->post_modified ) ),
                'dateShort'  => date_i18n( _x( 'j M @ H:i', 'revision date short format', 'revisionary' ), strtotime( $post->post_modified ) ),
                'timeAgo'    => sprintf( esc_html__( '%s ago' ), human_time_diff( strtotime( $post->post_modified_gmt ), $now_gmt ) ),
                'autosave'   => false,
                'current'    => true,
                'restoreUrl' => false,
            );
            $current_id             = $post->ID;
        }

        /*
        * If a post has been saved since the last revision (no revisioned fields
        * were changed), we may not have a "current" revision. Mark the latest
        * revision as "current".
        */
        if ( empty( $current_id ) ) {
            if ( $revisions[ $revision->ID ]['autosave'] ) {
                $revision = end( $revisions );
                while ( $revision['autosave'] ) {
                    $revision = prev( $revisions );
                }
                $current_id = $revision['id'];
            } else {
                $current_id = $revision->ID;
            }
            $revisions[ $current_id ]['current'] = true;
        }

        // Now, grab the initial diff.
        $compare_two_mode = is_numeric( $from );
        if ( ! $compare_two_mode ) {
            $found = array_search( $selected_revision_id, array_keys( $revisions ) );
            if ( $found ) {
                $from = array_keys( array_slice( $revisions, $found - 1, 1, true ) );
                $from = reset( $from );
            } else {
                $from = 0;
            }
        }

        $from = absint( $from );

        $diffs = [
            [
                'id'     => $from . ':' . $selected_revision_id,
                'fields' => $this->getRevisionUIDiff( $post->ID, $from, $selected_revision_id ),
            ],
        ];

        return [
            'postId'         => $post->ID,
            'nonce'          => wp_create_nonce( 'revisions-ajax-nonce' ),
            'revisionData'   => array_values( $revisions ),
            'to'             => $selected_revision_id,
            'from'           => $from,
            'diffData'       => $diffs,
            'baseUrl'        => wp_parse_url( rvy_admin_url( 'revision.php' ), PHP_URL_PATH ),
            'compareTwoMode' => absint( $compare_two_mode ), // Apparently booleans are not allowed
            'revisionIds'    => array_keys( $revisions ),
        ];
    }

    function actRevisionDiffScripts() {
        if (!did_action('rvy_compare_revisions')) {
            return;
        }

        $post_id = (isset($_REQUEST['revision'])) ? (int) $_REQUEST['revision'] : 0;    //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$post_id) {
            $post_id = (isset($_REQUEST['to'])) ? (int) $_REQUEST['to'] : 0;            //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ($post_type = get_post_field('post_type', $post_id)) {
            $type_obj = get_post_type_object($post_type);
        }

        // For non-public types, force direct approval because preview is not available
        $direct_approval = (($type_obj && !is_post_type_viewable($type_obj)) || rvy_get_option('compare_revisions_direct_approval'))
        && current_user_can('approve_revision', $post_id);

        if ($post_id) {
            $can_approve = current_user_can('approve_revision', $post_id);
        } else {
            $can_approve = isset($type_obj->cap->edit_published_posts) && current_user_can($type_obj->cap->edit_published_posts);
        }

        if (empty($type_obj) || $can_approve) {
            $button_label = $direct_approval ? esc_html__('Approve', 'revisionary') : esc_html__('Preview / Approve', 'revisionary');
        } else {
            $button_label = esc_html__('Preview', 'revisionary');
        }
        ?>
        <script type="text/javascript">
        /* <![CDATA[ */
        jQuery(document).ready( function($) {
            var rvyEditURL = '';
            var rvySearchParams = '';
            var rvyRevisionID = '';
            var rvyLastID = 0;
            var rvyCanEdit = false;

            var RvyDiffUI = function() {
                if( $('input.restore-revision:not(.rvy-recaption)').length) {
                    $('input.restore-revision').attr('value', '<?php echo esc_html($button_label);?>').addClass('rvy-recaption');

                    $('h1').next('a').hide();
                }
            }
            var RvyDiffUIinterval = setInterval(RvyDiffUI, 50);

            var RvyEditButton = function() {
                if(!$('input.edit-revision').length) {
                    setTimeout(function() {
                        rvySearchParams = new URLSearchParams(window.location.search);

                        rvyRevisionID = rvySearchParams.get('to');

                        if (!rvyRevisionID) {
                            rvyRevisionID = rvySearchParams.get('revision');
                        }

                        if (!Number(rvyRevisionID)) {
                            rvyRevisionID = <?php echo esc_attr($this->revision_id);?>;
                        }

                        if (rvyRevisionID != rvyLastID) {
                            var rselected = parseInt(_wpRevisionsSettings.to);
                            var rkey;
                            for (rkey = 0; rkey < _wpRevisionsSettings.revisionData.length; rkey++) {
                                if (_wpRevisionsSettings.revisionData[rkey].id == rselected) {
                                    if (_wpRevisionsSettings.revisionData[rkey].editUrl) {
                                        $('input.restore-revision').after('<a href="' + _wpRevisionsSettings.revisionData[rkey].editUrl + '"><input type="button" class="edit-revision button button-primary" style="float:right" value="<?php echo esc_attr__('Edit');?>"></a>');
                                    }
                                }
                            }

                            rvyLastID = rvyRevisionID;
                        }
                    }, 100);
                }
            }
            var RvyEditButtonInterval = setInterval(RvyEditButton, 250);
        });
        /* ]]> */
        </script>
        <?php
    }

    function actPastRevisionDiffScripts() {
        global $revisionary;

        if (did_action('rvy_compare_revisions')) {
            return;
        }

        $post_id = (isset($_REQUEST['revision'])) ? (int) $_REQUEST['revision'] : 0;    //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if (!$post_id) {
            $post_id = (isset($_REQUEST['to'])) ? (int) $_REQUEST['to'] : 0;            //phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }

        if ($post = get_post($post_id)) {
            $type_obj = get_post_type_object($post->post_type);
        } else {
            return;
        }

        if ($main_post_id = rvy_post_id($post_id)) {
            if ($main_post = get_post($main_post_id)) {
                $main_post_status = get_post_status_object($main_post->post_status);
            }
        }

        if (!empty($main_post) && !empty($main_post_status) && (empty($main_post_status->public) && empty($main_post_status->private))) {
            $can_edit = $revisionary->canEditPost($main_post);
        } else {
            $edit_published_cap = isset($type_obj->cap->edit_published_posts) ? $type_obj->cap->edit_published_posts : 'do_not_allow';
            $can_edit = current_user_can($edit_published_cap);
        }

        $show_preview_link = rvy_get_option('revision_preview_links') || is_content_administrator_rvy();

        if ($show_preview_link) {
            $preview_label = (empty($type_obj) || $can_edit)
            ?  esc_html__('Preview / Restore', 'revisionary')
            : esc_html__('Preview');

            $preview_url = rvy_preview_url($post);
        }

        $manage_label = (empty($type_obj) || $can_edit)
        ?  esc_html__('Manage', 'revisionary')
        : esc_html__('List', 'revisionary');

        $manage_url = rvy_admin_url("admin.php?page=revisionary-archive&origin_post=$main_post_id");
        ?>
        <script type="text/javascript">
        /* <![CDATA[ */
        jQuery(document).ready( function($) {
            var rvyLastID = 0;

            var RvyDiffUI = function() {
                rvySearchParams = new URLSearchParams(window.location.search);

                var rvyRevisionID = rvySearchParams.get('to');

                if (!rvyRevisionID) {
                    rvyRevisionID = rvySearchParams.get('revision');
                }

                if (!Number(rvyRevisionID)) {
                    rvyRevisionID = <?php echo esc_attr($this->revision_id);?>;
                }

                if (rvyRevisionID != rvyLastID) {
                    <?php if($show_preview_link):?>
                    var rvyPreviewURL = '<?php echo esc_url_raw($preview_url);?>';
                    rvyPreviewURL = rvyPreviewURL.replace("page_id=" + <?php echo esc_attr($post_id);?>, "page_id=" + rvyRevisionID);
                    rvyPreviewURL = rvyPreviewURL.replace("p=" + <?php echo esc_attr($post_id);?>, "p=" + rvyRevisionID);
                    <?php endif;?>

                    var rvyManageURL = '<?php echo esc_url_raw($manage_url);?>';
                    rvyManageURL = rvyManageURL.replace("revision=" + <?php echo esc_attr($post_id);?>, "revision=" + rvyRevisionID);

                    if(!$('span.rvy-compare-preview').length) {
                        <?php if($show_preview_link):?>
                        $('h1').append('<span class="rvy-compare-preview" style="margin-left:20px"><a class="rvy_preview_linkspan" href="<?php echo esc_url($preview_url);?>" target="_revision_preview"><input class="button" type="button" value="<?php echo esc_attr($preview_label);?>"></a></span>');
                        <?php endif;?>

                        $('h1').append('<span class="rvy-compare-list" style="margin-left:10px"><a class="rvy_preview_linkspan" href="<?php echo esc_url($manage_url);?>" target="_revision_list"><input class="button" type="button" value="<?php echo esc_attr($manage_label);?>"></a></span>');
                    } else {
                        <?php if($show_preview_link):?>
                        $('span.rvy-compare-preview a').attr('href', rvyPreviewURL);
                        <?php endif;?>

                        $('span.rvy-compare-list a').attr('href', rvyManageURL);
                    }

                    rvyLastID = rvyRevisionID;
                }
            }
            var RvyDiffUIinterval = setInterval(RvyDiffUI, 50);
        });
        /* ]]> */
        </script>
        <?php
    }
}
