<?php
defined('ABSPATH') || exit;

function rvy_duplicate_capability_names($type) {
    $names = [];
    foreach (['edit_posts', 'edit_others_posts'] as $property) {
        $base = $type->cap->$property ?? '';
        if (0 === strpos($base, 'edit_')) {
            $names[] = preg_replace('/^edit_/', 'duplicate_', $base);
        } else {
            $suffix = 'page' === $type->name ? 'pages' : 'posts';
            $names[] = ('edit_others_posts' === $property ? 'duplicate_others_' : 'duplicate_') . $suffix;
        }
    }
    return $names;
}

add_filter('pp_capabilities_extra_post_capability_tabs', function ($tabs) {
    if (rvy_get_option('duplicate_posts_capability')) {
        $tabs['duplicate'] = __('Duplicate Posts', 'revisionary');
    }
    return $tabs;
});

add_filter('publishpress_caps_manager_typecaps', function ($caps) {
    if (rvy_get_option('duplicate_posts_capability')) {
        foreach (get_post_types(['show_ui' => true], 'objects') as $type) {
            foreach (rvy_duplicate_capability_names($type) as $name) {
                $caps[$name] = true;
            }
        }
    }
    return $caps;
});

add_action('publishpress-caps_manager_postcaps_section', function ($args) {
    if (!rvy_get_option('duplicate_posts_capability')) {
        return;
    }
    global $revisionary;
    $active = 'cme-cap-type-tables-duplicate' === ($args['active_tab_id'] ?? '');
    echo '<div id="cme-cap-type-tables-duplicate" role="tabpanel" aria-labelledby="ppc-tab-duplicate" aria-hidden="' . ($active ? 'false' : 'true') . '" style="display:' . ($active ? 'block' : 'none') . '">';
    echo '<h2>' . esc_html__('Duplicate Posts Capabilities', 'revisionary') . '</h2>';
    echo '<div class="ppc-filter-wrapper"><label class="screen-reader-text" for="ppc-filter-select-duplicate">' . esc_html__('Filter by post type', 'revisionary') . '</label>';
    echo '<select id="ppc-filter-select-duplicate" class="ppc-filter-select"><option value="">' . esc_html__('Filter by post type', 'revisionary') . '</option></select> ';
    echo '<button class="button secondary-button ppc-filter-select-reset" type="button">' . esc_html__('Clear', 'revisionary') . '</button></div>';
    echo '<table class="widefat striped cme-typecaps cme-typecaps-basic cme-typecaps-duplicate"><thead><tr><th>' . esc_html__('Post Type', 'revisionary') . '</th><th>' . esc_html__('Duplicate', 'revisionary') . '</th><th>' . esc_html__('Duplicate Others', 'revisionary') . '</th></tr></thead><tbody>';
    $seen = [];
    foreach (get_post_types(['show_ui' => true], 'objects') as $type) {
        if (empty($revisionary->enabled_post_types_copy[$type->name])) {
            continue;
        }
        echo '<tr class="' . esc_attr(sanitize_html_class($type->name)) . '"><td><span class="cap_type">' . esc_html($type->labels->name) . '</span></td>';
        foreach (rvy_duplicate_capability_names($type) as $name) {
            echo '<td class="post-cap">';
            if (!isset($seen[$name])) {
                $seen[$name] = true;
                $allowed = !empty($args['is_administrator']) || current_user_can($name);
                echo '<div class="ppc-tool-tip disabled"><input type="checkbox" aria-label="' . esc_attr($name) . '" data-capability-label="' . esc_attr(str_replace('_', ' ', $name)) . '" name="caps[' . esc_attr($name) . ']" value="1" autocomplete="off" ' . checked(!empty($args['rcaps'][$name]), true, false) . disabled(!$allowed, true, false) . '><div class="tool-tip-text"><p>' . sprintf(esc_html__('This capability is %s', 'revisionary'), '<strong>' . esc_html($name) . '</strong>') . '</p><i></i></div></div>';
            } else {
                echo '<span class="ppc-tool-tip disabled" tabindex="0" aria-label="' . esc_attr($name) . '">&mdash;<span class="tool-tip-text"><p>' . sprintf(esc_html__('This capability is %s', 'revisionary'), '<strong>' . esc_html($name) . '</strong>') . '</p><i></i></span></span>';
            }
            echo '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div>';
});

add_action('admin_footer', function () {
    if (!defined('PUBLISHPRESS_CAPS_VERSION') || !rvy_get_option('duplicate_posts_capability')) {
        return;
    }
    ?>
    <script>
    jQuery(function ($) {
        var duplicate = $('#ppc-tab-duplicate'), copy = $('#ppc-tab-copy');
        if (duplicate.length && copy.length) {
            duplicate.insertBefore(copy);
        }
    });
    </script>
    <?php
});
