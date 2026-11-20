<?php

/** Optional private Revisions abilities; domain operations stay in Revisions. */

defined('ABSPATH') || exit;
if (!function_exists('wp_register_ability') || !function_exists('wp_register_ability_category') || defined('PUBLISHPRESS_REVISIONS_MCP_LOADED')) {
    return;
}
define('PUBLISHPRESS_REVISIONS_MCP_LOADED', true);
foreach (['Registry', 'Settings', 'Revisions'] as $name) {
    require_once __DIR__ . '/' . $name . '.php';
}
add_action('wp_abilities_api_categories_init', static function () {

    if (!wp_has_ability_category('publishpress')) {
        wp_register_ability_category('publishpress', ['label' => __('PublishPress', 'revisionary'), 'description' => __('Actions provided by PublishPress plugins.', 'revisionary')]);
    }
});
add_action('wp_abilities_api_init', static function () {

    \PublishPress\Revisions\Mcp\Settings::register();
    \PublishPress\Revisions\Mcp\Revisions::register();
    do_action('publishpress_revisions_mcp_register');
});
