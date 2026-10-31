<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Explicit Permissions creation exceptions also authorize reading their source.
 * Reuse the active integration's resolver, without registering duplicate hooks.
 */
function rvy_permissions_source_read_caps($caps, $cap, $user_id, $args) {
    global $wp_filter, $revisionary;
    static $busy = false;

    if ($busy || 'read_post' !== $cap || empty($args[0])
        || (int) $user_id !== get_current_user_id()
        || !function_exists('presspermit')) {
        return $caps;
    }

    $post_id = is_object($args[0]) ? (int) $args[0]->ID : (int) $args[0];
    $post = get_post($post_id);
    if (!$post || 'revision' === $post->post_type || rvy_in_revision_workflow($post)
        || empty($revisionary->enabled_post_types[$post->post_type])) {
        return $caps;
    }
    $status = get_post_status_object($post->post_status);
    if (!$status || (!$status->public && !$status->private && !rvy_get_option('pending_revision_unpublished'))) {
        return $caps;
    }

    $integration = null;
    if (isset($wp_filter['revisionary_can_copy']->callbacks)) {
        foreach ($wp_filter['revisionary_can_copy']->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $function = $callback['function'];
                if (is_array($function) && is_object($function[0])
                    && $function[0] instanceof \PublishPress\Permissions\Collab\Revisions\CapabilityFilters) {
                    $integration = $function[0];
                    break 2;
                }
            }
        }
    }
    if (!$integration) {
        return $caps;
    }

    $busy = true;
    try {
        // Starting false asks specifically for Enable exceptions, not role caps.
        $enabled = $integration->fltPostAccessApplyExceptions(false, 'copy', $post->post_type, $post_id)
            || $integration->fltPostAccessApplyExceptions(false, 'revise', $post->post_type, $post_id);
        // Preserve effective reading restrictions using Permissions' own rules.
        if ($enabled && $integration->fltPostAccessApplyExceptions(true, 'read', $post->post_type, $post_id)) {
            return ['read'];
        }
    } finally {
        $busy = false;
    }
    return $caps;
}
add_filter('map_meta_cap', 'rvy_permissions_source_read_caps', PHP_INT_MAX, 4);

/**
 * Permissions 4.9.1 merges both revision operations when either is present.
 * Supply the absent counterpart before its priority-10 listing callback runs.
 */
function rvy_permissions_prepare_revision_listing_exceptions($items, $operation, $mod_type, $post_type, $args = []) {
    if ('edit' !== $operation || 'include' === $mod_type || !function_exists('presspermit')
        || (!empty($args['via_item_source']) && 'post' !== $args['via_item_source'])) {
        return $items;
    }

    $user = presspermit()->getUser();
    foreach (['copy', 'revise'] as $op) {
        if (!isset($user->except[$op . '_post'])) {
            $user->retrieveExceptions($op, 'post');
        }
    }

    $source_type = $args['via_item_type'] ?? '';
    $copy = $user->except['copy_post']['post'][$source_type][$mod_type][$post_type] ?? null;
    $revise = $user->except['revise_post']['post'][$source_type][$mod_type][$post_type] ?? null;
    if (is_array($copy) && null === $revise) {
        $user->except['revise_post']['post'][$source_type][$mod_type][$post_type] = [];
    } elseif (is_array($revise) && null === $copy) {
        $user->except['copy_post']['post'][$source_type][$mod_type][$post_type] = [];
    }

    return $items;
}
add_filter('presspermit_get_exception_items', 'rvy_permissions_prepare_revision_listing_exceptions', 9, 5);

// Permissions also evaluates the original read_post request in user_has_cap,
// after map_meta_cap. Apply the same explicit-exception decision at that stage.
function rvy_permissions_source_read_user_caps($allcaps, $required, $args) {
    if (empty($args[0]) || 'read_post' !== $args[0] || empty($args[2])) {
        return $allcaps;
    }
    if (['read'] === rvy_permissions_source_read_caps(['rvy_source_read_denied'], 'read_post', (int) $args[1], [$args[2]])) {
        foreach ($required as $cap) {
            $allcaps[$cap] = true;
        }
    }
    return $allcaps;
}
add_filter('user_has_cap', 'rvy_permissions_source_read_user_caps', PHP_INT_MAX, 3);
