<?php
namespace PublishPress\Revisions;

\defined('ABSPATH') || exit;

/** Save Statuses' block-editor submissions before performing the workflow action. */
final class StatusesRestSubmission
{
    private static $requests = [];

    public static function register()
    {
        // Normalize before Statuses binds its REST parameters (priority 10).
        add_filter('rest_request_before_callbacks', [__CLASS__, 'prepare'], 5, 3);
        add_filter('rest_request_after_callbacks', [__CLASS__, 'complete'], 5, 3);
    }

    public static function prepare($response, $handler, $request)
    {
        if (is_wp_error($response) || !class_exists('PublishPress_Statuses\\Revisions')
            || !class_exists('PublishPress_Statuses')
            || !in_array($request->get_method(), ['POST', 'PUT', 'PATCH'], true)
            || 'publish' !== $request->get_param('status')
            || true !== rest_sanitize_boolean($request->get_param('pp_statuses_selecting_workflow'))) {
            return $response;
        }

        $callback = $handler['callback'] ?? null;
        if (!is_array($callback) || !($callback[0] instanceof \WP_REST_Posts_Controller)
            || 'update_item' !== $callback[1]) {
            return $response;
        }

        // Use URL captures and the registered controller, never a body-supplied ID.
        $url = $request->get_url_params();
        $id = absint($url['id'] ?? 0);
        $post = $id ? get_post($id) : null;
        $type = $post ? get_post_type_object($post->post_type) : null;
        if (!$post || !$type || $type->get_rest_controller() !== $callback[0]
            || (string) $request->get_param('id') !== (string) $id
            || !rvy_in_revision_workflow($post)
            || 'draft-revision' !== $post->post_mime_type) {
            return $response;
        }

        if (!current_user_can('edit_post', $id)
            || (!is_content_administrator_rvy() && !current_user_can('set_revision_pending-revision', $id))) {
            return new \WP_Error('revisionary_cannot_submit', __('You are not allowed to submit this revision.', 'revisionary'), ['status' => 403]);
        }

        $statuses = \PublishPress_Statuses::instance();
        $action = $request->get_param('pp_workflow_action');
        // Only bridge Submit. Leave Current, manual choices and other workflow
        // destinations to their existing handlers and authorization checks.
        if ($statuses->workflow_disabled || !in_array($action, [null, '', 'next', 'max'], true)
            || $request->get_param('pp_status_selection')
            || !in_array($request->get_param('pp_post_mime_type'), [null, '', 'draft-revision', 'pending-revision'], true)) {
            return $response;
        }

        $sequence = ('next' === $action) || ($statuses->workflow_by_sequence
            && !\PublishPress_Statuses::canBypassStatusSequence($post->post_type));
        $target = \PublishPress_Statuses::defaultStatusProgression($id, [
            'return' => 'name', 'post_type' => $post->post_type,
            'default_by_sequence' => $sequence ? true : (('max' === $action) ? false : null),
        ]);
        $filtered = apply_filters('presspermit_selected_moderation_status', $target, $id);
        if ($filtered !== $target && \PublishPress_Statuses::haveStatusPermission('set_status', $post->post_type, $filtered)) {
            $target = $filtered;
        }
        if ('pending-revision' !== $target) {
            return $response;
        }

        self::$requests[spl_object_hash($request)] = ['id' => $id, 'controller' => $callback[0], 'saved' => false];
        add_action('rest_after_insert_' . $type->name, [__CLASS__, 'saved'], PHP_INT_MAX, 3);
        // Core authorizes and saves the content normally. Statuses sees a retain
        // request, so it cannot advance the status during wp_insert_post_data.
        $request->set_param('status', $post->post_status);
        $request->set_param('pp_post_mime_type', $post->post_mime_type);
        $request->set_param('pp_status_selection', $post->post_mime_type);
        $request->set_param('pp_statuses_selecting_workflow', false);
        $request->set_param('pp_workflow_action', 'current');
        return $response;
    }

    public static function saved($post, $request, $creating)
    {
        $key = spl_object_hash($request);
        if (!$creating && isset(self::$requests[$key]) && (int) $post->ID === self::$requests[$key]['id']) {
            self::$requests[$key]['saved'] = true;
        }
    }

    public static function complete($response, $handler, $request)
    {
        $key = spl_object_hash($request);
        if (!isset(self::$requests[$key])) {
            return $response;
        }
        $context = self::$requests[$key];
        unset(self::$requests[$key]);
        // This hook runs after Core saved content, terms, metadata and additional
        // fields. A failed save must never submit the old revision content.
        if (is_wp_error($response) || !($response instanceof \WP_REST_Response)
            || $response->get_status() < 200 || $response->get_status() >= 300) {
            return $response;
        }
        if (!$context['saved']) {
            return new \WP_Error('revisionary_submission_not_saved', __('The revision save could not be verified. Please save it before submitting.', 'revisionary'), ['status' => 500]);
        }

        $id = $context['id'];
        clean_post_cache($id);
        $post = get_post($id);
        if (!$post || !rvy_in_revision_workflow($post) || 'draft-revision' !== $post->post_mime_type
            || !current_user_can('edit_post', $id)
            || (!is_content_administrator_rvy() && !current_user_can('set_revision_pending-revision', $id))) {
            return new \WP_Error('revisionary_cannot_submit', __('The revision was saved, but could not be submitted.', 'revisionary'), ['status' => 403]);
        }

        require_once dirname(REVISIONARY_FILE) . '/admin/revision-action_rvy.php';
        $submitted = rvy_revision_submit($id);
        clean_post_cache($id);
        $post = get_post($id);
        $status = rvy_get_option('permissions_compat_mode') ? 'pending-revision' : 'pending';
        if (is_wp_error($submitted)) {
            return $submitted;
        }
        if (!$submitted || !$post || 'pending-revision' !== $post->post_mime_type || $status !== $post->post_status) {
            return new \WP_Error('revisionary_submission_failed', __('The revision was saved, but could not be submitted.', 'revisionary'), ['status' => 500]);
        }

        // Return the stored submission state to Gutenberg, including Statuses'
        // additional REST field, rather than the earlier draft-save response.
        return $context['controller']->prepare_item_for_response($post, $request);
    }
}
