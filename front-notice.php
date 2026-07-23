<?php
namespace PublishPress\Revisions;

class FrontNotice {
    var $post_id = 0;

    function __construct($args = []) {
        if (!empty($args) && is_array($args) && !empty($args['post_id'])) {
            $this->post_id = $args['post_id'];
        }

        add_action('wp_body_open', [$this, 'actNoticeOutput']);
    }

    function revisionsIndicator() {
        if (!$this->post_id) {
            return;
        }
        ?>
        <style>
        #rvyRevisionIndicator button {
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 999;
            position: fixed;
            bottom: 5px;
            left: 0;
            height: 40px;
            background-color: white;
            color: black;
            padding: 5px 10px 5px 5px;
            margin: 10px 15px 10px 10px;
            font-size: 15px;
            font-weight: 550;
            cursor: pointer;
            border-radius: 8px;
            border: 4px solid #5caf95;
        }
        #rvyRevisionIndicator a:hover, #rvyRevisionIndicator a:link, #rvyRevisionIndicator a:visited {
            text-decoration: none !important;
        }
        #rvyRevisionIndicator img {
            height: 1.5em;
            padding-right: 4px;
        }
        </style>
        <?php
        $post_id = $this->post_id;

        add_action(
            'wp_body_open',
            function() use ($post_id) {
                $url = admin_url("admin.php?page=revisionary-q&published_post=$post_id");
                ?>
                <span id="rvyRevisionIndicator">
                <a href="<?php echo esc_url($url);?>" class="button button-secondary"><button>
                <img src="<?php echo esc_url(plugins_url('', REVISIONARY_FILE) . '/common/img/dashicons-future.png');?>">
                <div><?php _e('Revisions', 'revisionary');?></div>
                </button></a></span>
                <?php
            }
        );
    }

    function enqueueScripts() {
        if ( is_admin() ) {
            return;
        }

        $this->revisionsIndicator();

        wp_register_style(
            'rvy-frontend-notice',
            false,
            [],
            PUBLISHPRESS_REVISIONS_VERSION
        );

        wp_enqueue_style( 'rvy-frontend-notice' );

        wp_add_inline_style(
            'rvy-frontend-notice',
            '
            .rvy-frontend-notice {
                position: fixed;
                left: 0;
                bottom: 55px;
                z-index: 999;
                margin: 10px 40px 10px 10px;
                padding: 10px 36px 10px 12px;
                background: #fff8e5;
                border: 1px solid #f0c36d;
                border-left: 4px solid #dba617;
                box-shadow: 0 4px 16px rgba(0,0,0,.12);
                font-size: 16px;
                line-height: 1.5;
            }

            .rvy-frontend-notice button {
                position: absolute;
                top: 2px;
                right: 5px;
                padding: 0;
                border: 0;
                background-color: inherit !important;
                color: black !important;
                font-size: 22px;
                font-weight: bold;
                line-height: 1;
                cursor: pointer;
            }
            '
        );

        wp_register_script(
            'rvy-frontend-notice',
            false,
            [],
            PUBLISHPRESS_REVISIONS_VERSION,
            true
        );

        wp_enqueue_script( 'rvy-frontend-notice' );

        wp_localize_script(
            'rvy-frontend-notice',
            'RvyFrontendNotice',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'rvy_dismiss_frontend_notice' ),
            )
        );

        wp_add_inline_script(
            'rvy-frontend-notice',
            '
            document.addEventListener("click", function(event) {
                var button = event.target.closest("[data-rvy-dismiss-notice]");

                if (!button) {
                    return;
                }

                var notice = button.closest(".rvy-frontend-notice");

                if (notice) {
                    notice.remove();
                }

                var formData = new FormData();
                formData.append("action", "rvy_dismiss_frontend_notice");
                formData.append("nonce", RvyFrontendNotice.nonce);

                fetch(RvyFrontendNotice.ajaxUrl, {
                    method: "POST",
                    credentials: "same-origin",
                    body: formData
                });
            });
            '
        );
    }

    function actNoticeOutput() {
        $notice_id = 'rvy_revisions_has_revisions_hint';

        if (!$this->post_id || is_admin() || $this->noticeIsDismissed($notice_id)) {
            return;
        }

        $type_obj = get_post_type_object(get_post_field('post_type', $this->post_id));

        if (!$type_obj || !isset($type_obj->labels) || empty($type_obj->labels->singular_name)) {
            return;
        }

        $notice_msg = sprintf(
            __('This %s has new revisions. Click the button below to review them.', 'revisionary'),
            strtolower($type_obj->labels->singular_name)
        );
        ?>
        <div class="rvy-frontend-notice" role="status" aria-live="polite">
            <div>
                <?php esc_html_e( $notice_msg, 'revisionary' ); ?>
            </div>

            <button type="button" data-rvy-dismiss-notice aria-label="<?php esc_attr_e( 'Dismiss notice', 'revisionary' ); ?>">
                &times;
            </button>
        </div>
        <?php
    }

    function noticeIsDismissed($notice_id) {
        if ( is_user_logged_in() ) {
            return (bool) get_user_meta( get_current_user_id(), $notice_id . '_dismissed', true );
        }
    }
}
?>