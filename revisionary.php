<?php
/**
 * Plugin Name: PublishPress Revisions
 * Plugin URI: https://publishpress.com/revisionary/
 * Description: Maintain published content with teamwork and precision using the Revisions model to submit, approve and schedule changes.
 * Author: PublishPress
 * Author URI: https://publishpress.com
 * Version: 3.7.9
 * Text Domain: revisionary
 * Domain Path: /languages/
 * Min WP Version: 5.5
 * Requires PHP: 7.2.5
 * 
 * Copyright (c) 2025 PublishPress
 *
 * GNU General Public License, Free Software Foundation <https://www.gnu.org/licenses/gpl-3.0.html>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 * @package     PublishPress\Revisions
 * @author      PublishPress
 * @copyright   Copyright (C) 2025 PublishPress. All rights reserved.
 *
 **/

 if (!defined('ABSPATH')) exit; // Exit if accessed directly

// Temporary usage within this module only; avoids multiple instances of version string
global $pp_revisions_version;

$pp_revisions_version = '3.7.9';

global $wp_version;

$min_php_version = '7.2.5';
$min_wp_version  = '5.5';

$invalid_php_version = version_compare(phpversion(), $min_php_version, '<');
$invalid_wp_version = version_compare($wp_version, $min_wp_version, '<');

// If the PHP version is not compatible, terminate the plugin execution and show an admin notice.
if (is_admin() && $invalid_php_version) {
    add_action(
        'admin_notices',
        function () use ($min_php_version) {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>';
                printf(
                    'PublishPress Revisions requires PHP version %s or higher.',
                    esc_html($min_php_version)
                );
                echo '</p></div>';
            }
        }
    );
}

// If the WP version is not compatible, terminate the plugin execution and show an admin notice.
if (is_admin() && $invalid_wp_version) {
    add_action(
        'admin_notices',
        function () use ($min_wp_version) {
            if (current_user_can('activate_plugins')) {
                echo '<div class="notice notice-error"><p>';
                printf(
                    'PublishPress Revisions requires WordPress version %s or higher.',
                    esc_html($min_wp_version)
                );
                echo '</p></div>';
            }
        }
    );
}

if ($invalid_php_version || $invalid_wp_version) {
    return;
}

$revisionary_pro_active = false;

global $revisionary_loaded_by_pro;

$revisionary_loaded_by_pro = strpos(str_replace('\\', '/', __FILE__), 'vendor/publishpress/');

// Detect separate Pro plugin activation, but not self-activation (this file loaded in vendor library by Pro)
if (false === $revisionary_loaded_by_pro) {
    foreach ((array)get_option('active_plugins') as $plugin_file) {
        if (false !== strpos($plugin_file, 'revisionary-pro.php')) {
            $revisionary_pro_active = true;
            break;
        }
    }

    if (!$revisionary_pro_active && is_multisite()) {
        foreach (array_keys((array)get_site_option('active_sitewide_plugins')) as $plugin_file) {
            if (false !== strpos($plugin_file, 'revisionary-pro.php')) {
                $revisionary_pro_active = true;
                break;
            }
        }
    }

    if ($revisionary_pro_active) {
        add_filter(
            'plugin_row_meta',
            function($links, $file)
            {
                if ($file == plugin_basename(__FILE__)) {
                    $links[]= __('<strong>This plugin can be deleted.</strong>', 'revisionary');
                }

                return $links;
            },
            10, 2
        );

		add_action(
			'admin_notices',
			function () {
				if (current_user_can('activate_plugins')) {
					echo '<div class="notice notice-error"><p>'
					. 'Revisions Pro requires the free plugin (PublishPress Revisions) to be deactivated.'
					. '</p></div>';
				}
			}
		);

        return;
    }
}

if ( isset($_SERVER['SCRIPT_NAME']) && strpos( esc_url_raw($_SERVER['SCRIPT_NAME']), 'p-admin/index-extra.php' ) || strpos( esc_url_raw($_SERVER['SCRIPT_NAME']), 'p-admin/update.php' ) ) {
	return;
}

if (! defined('REVISIONS_INTERNAL_VENDORPATH')) {
	define('REVISIONS_INTERNAL_VENDORPATH', __DIR__ . '/lib/vendor');
}

if (!defined('REVISIONARY_FILE') && !$revisionary_loaded_by_pro) {
	$includeFileRelativePath = REVISIONS_INTERNAL_VENDORPATH . '/publishpress/publishpress-instance-protection/include.php';
	if (file_exists($includeFileRelativePath)) {
		require_once $includeFileRelativePath;
	}

	if (class_exists('PublishPressInstanceProtection\\Config')) {
		$pluginCheckerConfig = new PublishPressInstanceProtection\Config();
		$pluginCheckerConfig->pluginSlug    = 'revisionary';
		$pluginCheckerConfig->pluginFolder  = 'revisionary';
		$pluginCheckerConfig->pluginName    = 'PublishPress Revisions';

		$pluginChecker = new PublishPressInstanceProtection\InstanceChecker($pluginCheckerConfig);
	}

	if (! class_exists('ComposerAutoloaderInitRevisionary')
        && file_exists(REVISIONS_INTERNAL_VENDORPATH . '/autoload.php')
    ) {
        require_once REVISIONS_INTERNAL_VENDORPATH . '/autoload.php';
    }

	include_once REVISIONS_INTERNAL_VENDORPATH . '/publishpress/wordpress-version-notices/src/include.php';
}

if (!defined('REVISIONARY_FILE') && (!$revisionary_pro_active || $revisionary_loaded_by_pro)) {
	define('REVISIONARY_FILE', __FILE__);

	add_action(
		'init', 
		function() {
			global $pp_revisions_version;

			if (!function_exists('revisionary')) {
				require_once(dirname(__FILE__).'/functions.php');
			}

			pp_revisions_plugin_updated($pp_revisions_version);
		},
		2
	);

	// register these functions before any early exits so normal activation/deactivation can still run with RS_DEBUG
	register_activation_hook(__FILE__, function() 
		{
			global $pp_revisions_version;

			if (!function_exists('revisionary')) {
				require_once(dirname(__FILE__).'/functions.php');
			}

			pp_revisions_plugin_updated($pp_revisions_version);
			pp_revisions_plugin_activation();
		}
	);

	register_deactivation_hook(__FILE__, function()
		{
			if (!function_exists('rvy_init')) {
				require_once( dirname(__FILE__).'/rvy_init.php');
			}

			if (!rvy_is_plugin_active('revisionary-pro/revisionary-pro.php')) {
				pp_revisions_plugin_deactivation();
			}
		}
	);

	// negative priority to precede any default WP action handlers
	function revisionary_load() {
		global $pp_revisions_version;
		
		define('PUBLISHPRESS_REVISIONS_VERSION', $pp_revisions_version);

		if ( ! defined( 'RVY_VERSION' ) ) {
			define( 'RVY_VERSION', PUBLISHPRESS_REVISIONS_VERSION );  // back compat
		}

		define ('COLS_ALL_RVY', 0);
		define ('COL_ID_RVY', 1);

		if ( defined('RS_DEBUG') ) {
			include_once( dirname(__FILE__).'/lib/debug.php');
			add_action( 'admin_footer', 'rvy_echo_usage_message' );
		} else
			include_once( dirname(__FILE__).'/lib/debug_shell.php');

		require_once( dirname(__FILE__).'/defaults_rvy.php');

		// === awp_is_mu() function definition and usage: must be executed in this order, and before any checks of IS_MU_RVY constant ===
		require_once( dirname(__FILE__).'/lib/agapetry_wp_core_lib.php');
		define( 'IS_MU_RVY', awp_is_mu() );
		// -------------------------------------------

		require_once( dirname(__FILE__).'/content-roles_rvy.php');

		if ( is_admin() || defined('XMLRPC_REQUEST') ) {
			require_once( dirname(__FILE__).'/lib/agapetry_wp_admin_lib.php');
				
			// skip WP version check and init operations when a WP plugin auto-update is in progress
			if (isset($_SERVER['SCRIPT_NAME']) && false !== strpos(esc_url_raw($_SERVER['SCRIPT_NAME']), 'update.php') )
				return;
		}

		require_once( dirname(__FILE__).'/classes/PublishPress/Revisionary.php');
		require_once( dirname(__FILE__).'/rvy_init.php');	// Contains activate, deactivate, init functions. Adds mod_rewrite_rules.
		require_once( dirname(__FILE__).'/functions.php');

		// avoid lockout in case of editing plugin via wp-admin
		if ( defined('RS_DEBUG') && is_admin() && isset($_SERVER['REQUEST_URI']) && ( strpos( urldecode(esc_url_raw($_SERVER['REQUEST_URI'])), 'p-admin/plugin-editor.php' ) || strpos( urldecode(esc_url_raw($_SERVER['REQUEST_URI'])), 'p-admin/plugins.php' ) ) && false === strpos( esc_url_raw($_SERVER['REQUEST_URI']), 'activate' ) )
			return;

		define('RVY_ABSPATH', __DIR__);

		if (is_admin() && !defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
			require_once(__DIR__ . '/includes/CoreAdmin.php');
			new \PublishPress\Revisions\CoreAdmin();
		}

		rvy_refresh_options_sitewide();

		// since sequence of set_current_user and init actions seems unreliable, make sure our current_user is loaded first
		add_action('init', 'rvy_init', 1);
		
		if (!defined('IFRAME_REQUEST')) {
			add_action('init', 'rvy_add_revisor_custom_caps', 99);
			add_action('wp_loaded', 'rvy_add_revisor_custom_caps', 99);
		}

		add_action('init', 'rvy_configuration_late_init', PHP_INT_MAX - 1);

		revisionary();
	}

	// negative priority to precede any default WP action handlers
    if ($revisionary_loaded_by_pro) {
        revisionary_load();	// Pro support
    } else {
        add_action('plugins_loaded', 'revisionary_load', -10);
    }
}
