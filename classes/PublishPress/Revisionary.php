<?php
namespace PublishPress;

class Revisions {
	private static $instance = null;

	public static function instance($args = [])
    {
        if (is_null(self::$instance)) {
            self::$instance = new Revisions();
            self::$instance->load();
        }

        return self::$instance;
    }

    private function __construct()
    {

    }

	private function load($args = [])
    {
		if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
			add_action('admin_init', [$this, 'load_updater']);
		}
	}

	public function load_updater() {
        if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
		    require_once(REVISIONARY_PRO_ABSPATH . '/includes-pro/library/Factory.php');
            $container = \PublishPress\Revisions\Factory::get_container();

            return $container['edd_container']['update_manager'];
        }
	}

	public function keyStatus($refresh = false)
    {
        if (defined('PUBLISHPRESS_REVISIONS_PRO_VERSION')) {
            require_once(REVISIONARY_PRO_ABSPATH . '/includes-pro/pro-key.php');
            return _revisionary_key_status($refresh);
        }
    }

    public function keyActive($refresh = false)
    {
        return in_array($this->keyStatus($refresh), [true, 'valid', 'expired'], true);                
    }

	public function getOption($option_basename) {
		return ('edd_key' == $option_basename) ? get_site_option('rvy_edd_key') : rvy_get_option($option_basename);
	}

	public function updateOption($option_basename, $option_val, $args = [])
    {
        if ('edd_key' == $option_basename) {
            return update_site_option('rvy_edd_key', $option_val);
        } else {
            $args = (array) $args;
            $sitewide = (isset($args['sitewide'])) ? $args['sitewide'] : -1;
            return rvy_update_option($option_basename, $option_val, $sitewide);
        }
	}
	
	public function deleteOption($option_basename, $args = []) {
        if ('edd_key' == $option_basename) {
            return delete_site_option('rvy_edd_key');
        } else {
            $args = (array) $args;
            $sitewide = (isset($args['sitewide'])) ? $args['sitewide'] : -1;
            return rvy_delete_option($option_basename, $sitewide);
        }
	}
    
    public function getUserRevision($post_id, $args = []) {
        global $wpdb, $current_user;

        $args = (array) $args;
        $user_id = (!empty($args['user_id'])) ? $args['user_id'] : $current_user->ID;

        if (empty($args['force_query']) && !rvy_get_post_meta($post_id, '_rvy_has_revisions')) {
            return false;
        }

        $revision_status_csv = array_diff(
            implode("','", array_map('sanitize_key', rvy_revision_statuses())),
            ['draft-revision']
        );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $revision_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE comment_count = %d AND post_author = %d AND post_status IN ('$revision_status_csv') ORDER BY ID DESC LIMIT 1",  // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $post_id,
                $user_id
            )
        );

        return $revision_id;
    }

    public static function getDefinedIntegrations() {
        $integrations = [
            [
                'id' => 'acf_compatibility',
                'title' => esc_html__('Advanced Custom Fields', 'revisionary'),
                'description' => esc_html__('Compatibility with ACF custom fields.', 'revisionary'),
                'icon_class' => 'acf',
                'categories' => ['all', 'fields'],
                'features' => [
                    esc_html__('Store custom fields with revisions', 'revisionary'),
                    esc_html__('Display custom field changes', 'revisionary'),
                    esc_html__('Update custom fields on revision publication', 'revisionary'),
                ],
                'enabled' => false,
                'available' => function_exists('acf'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/revisionary-acf/',
            ],
            [
                'id' => 'acfe_compatibility',
                'title' => esc_html__('ACF Extended', 'revisionary'),
                'description' => esc_html__('Support ACFE data model.', 'revisionary'),
                'icon_class' => 'acf',
                'categories' => ['all', 'fields'],
                'features' => [
                    esc_html__('Support ACFE single_meta data type', 'revisionary'),
                    esc_html__('Correct field display on Compare screen', 'revisionary'),
                ],
                'enabled' => false,
                'available' => class_exists('ACFE'),
                'learn_more_url' => '',
            ],
            [
                'id' => 'beaver_compatibility',
                'title' => esc_html__('Beaver Builder', 'revisionary'),
                'description' => esc_html__('Integration with Beaver Builder\'s front end editor.', 'revisionary'),
                'icon_class' => 'beaver-builder',
                'categories' => ['all', 'builder'],
                'features' => [
                    esc_html__('Front end Revision submission', 'revisionary'),
                    esc_html__('Front end Revision editing', 'revisionary'),
                    esc_html__('Revision preview and redirects', 'revisionary'),
                ],
                'enabled' => false,
                'available' => defined('FL_BUILDER_VERSION'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/revisionary-beaver-builder/'
            ],
            [
                'id' => 'divi_compatibility',
                'title' => esc_html__('Divi', 'revisionary'),
                'description' => esc_html__('Integration with Divi Builder and Divi Theme.', 'revisionary'),
                'icon_class' => 'divi',
                'categories' => ['all', 'builder'],
                'features' => [
                    esc_html__('Front end Revision submission', 'revisionary'),
                    esc_html__('Front end Revision editing', 'revisionary'),
                    esc_html__('Revision preview and redirects', 'revisionary'),
                ],
                'enabled' => false,
                'available' => defined('ET_BUILDER_PLUGIN_VERSION') || (false !== stripos(get_template(), 'divi')),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/divi-theme/',
            ],
            [
                'id' => 'elementor_compatibility',
                'title' => esc_html__('Elementor', 'revisionary'),
                'description' => esc_html__('Integration with Elementor\'s front end editor.', 'revisionary'),
                'icon_class' => 'elementor',
                'categories' => ['all', 'builder'],
                'features' => [
                    esc_html__('Front end Revision submission', 'revisionary'),
                    esc_html__('Front end Revision editing', 'revisionary'),
                    esc_html__('Revision preview and redirects', 'revisionary'),
                ],
                'enabled' => false,
                'available' => defined('ELEMENTOR_VERSION'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/publishpress-revisions-elementor/',
            ],
            [
                'id' => 'nitropack_compatibility',
                'title' => esc_html__('Nitro Pack', 'revisionary'),
                'description' => esc_html__('Compatibility with Nitro Pack cache.', 'revisionary'),
                'icon_class' => 'nitropack',
                'categories' => ['all', 'cache'],
                'features' => [
                    esc_html__('Clear cache on revision creation', 'revisionary'),
                    esc_html__('Trigger update on revision approval', 'revisionary'),
                ],
                'enabled' => false,
                'available' => defined('NITROPACK_VERSION'),
                'learn_more_url' => ''
            ],
            [
                'id' => 'pods_compatibility',
                'title' => esc_html__('Pods', 'revisionary'),
                'description' => esc_html__('Compatibility with Pods custom fields.', 'revisionary'),
                'icon_class' => 'pods',
                'categories' => ['all', 'fields'],
                'features' => [
                    esc_html__('Store custom fields with revisions', 'revisionary'),
                    esc_html__('Display custom field changes', 'revisionary'),
                    esc_html__('Update custom fields on revision publication', 'revisionary'),
                ],
                'enabled' => false,
                'available' => defined('PODS_VERSION'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/publishpress-revisions-pods/'
            ],
            [
                'id' => 'polylang_compatibility',
                'title' => esc_html__('Polylang', 'revisionary'),
                'description' => esc_html__('Compatibility with Polylang translation.', 'revisionary'),
                'icon_class' => 'polylang',
                'categories' => ['all', 'multilingual'],
                'features' => [
                    esc_html__('Revisions carry over Polylang data', 'revisionary'),
                    esc_html__('Translation retained on Revision approval', 'revisionary'),
                ],
                'enabled' => false,
                'available' => defined('POLYLANG_VERSION'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/publishpress-revisions-polylang/'
            ],
            [
                'id' => 'planner_compatibility',
                'title' => esc_html__('PublishPress Planner', 'revisionary'),
                'description' => esc_html__('PublishPress Planner Integration.', 'revisionary'),
                'icon_class' => 'planner',
                'categories' => ['all', 'workflow'],
                'features' => [
                    esc_html__('Planner Notifications for revision actions', 'revisionary'),
                    esc_html__('Revision schedule shown in Calendar', 'revisionary'),
                ],
                'enabled' => false,
                'available' => defined('PUBLISHPRESS_VERSION'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/show-revisions-on-the-content-calendar/'
            ],
            [
                'id' => 'woocommerce_compatibility',
                'title' => esc_html__('WooCommerce', 'revisionary'),
                'description' => esc_html__('Revision submission and approval for products.', 'revisionary'),
                'icon_class' => 'woocommerce',
                'categories' => ['all', 'ecommerce'],
                'features' => [
                    esc_html__('Product revisions', 'revisionary'),
                    esc_html__('Compatible with Product Variations', 'revisionary'),
                    esc_html__('Priority on ongoing compatibility', 'revisionary'),
                ],
                'enabled' => false,
                'available' => class_exists('WooCommerce'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/revisions-woocommerce/'
            ],
            [
                'id' => 'wpml_compatibility',
                'title' => esc_html__('WPML', 'revisionary'),
                'description' => esc_html__('Multilingual revisioning with WPML.', 'revisionary'),
                'icon_class' => 'wpml',
                'categories' => ['all', 'multilingual'],
                'features' => [
                    esc_html__('Language-specific revisions', 'revisionary'),
                    esc_html__('Translation Management integration', 'revisionary')
                ],
                'enabled' => false,
                'available' => defined('ICL_SITEPRESS_VERSION'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/revisionary-wpml/'
            ],
            [
                'id' => 'yoast_seo_compatibility',
                'title' => esc_html__('Yoast SEO', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'yoast',
                'categories' => ['all', 'seo'],
                'features' => [
                    esc_html__('Prevent indexing of revisions', 'revisionary'),
                    esc_html__('Compatibility for Yoast SEO + Elementor', 'revisionary'),
                    esc_html__('Compare revisions to Yoast SEO fields', 'revisionary'),
                ],
                'enabled' => false,
                'available' => defined('WPSEO_VERSION'),
                'learn_more_url' => 'https://publishpress.com/knowledge-base/revisions-yoast-seo/'
            ],

            /*
            [
                'id' => 'litespeed_compatibility',
                'title' => esc_html__('Litespeed Cache', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'litespeed',
                'categories' => ['all', 'cache'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('LSCWP_V'),
                'learn_more_url' => '',
                'free' => true
            ],
            [
                'id' => 'w3tc_compatibility',
                'title' => esc_html__('W3 Total Cache', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'w3tc',
                'categories' => ['all', 'cache'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('W3TC_VERSION'),
                'learn_more_url' => '',
                'free' => true
            ],
            [
                'id' => 'wp_optimize',
                'title' => esc_html__('WP Optimize', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'wp-optimize',
                'categories' => ['all', 'cache'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('WPO_VERSION'),
                'learn_more_url' => '',
                'free' => true
            ],
            [
                'id' => 'wp_super_cache',
                'title' => esc_html__('WP Super Cache', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'wp-super-cache',
                'categories' => ['all', 'cache'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('WPSC_VERSION_ID'),
                'learn_more_url' => '',
                'free' => true
            ],
            [
                'id' => 'wp_fastest_cache',
                'title' => esc_html__('WP Fastest Cache', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'wp-fastest-cache',
                'categories' => ['all', 'cache'],
                'features' => [
                ],
                'enabled' => false,
                'available' => class_exists('WpFastestCache'),
                'learn_more_url' => '',
                'free' => true
            ],
            [
                'id' => 'rank_math',
                'title' => esc_html__('Rank Math SEO', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'rank-math',
                'categories' => ['all', 'seo'],
                'features' => [
                ],
                'enabled' => false,
                'available' => class_exists('RankMath'),
                'learn_more_url' => '',
                'free' => true
            ],
            [
                'id' => 'all_in_one_seo',
                'title' => esc_html__('All in One SEO', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'all-in-one-seo',
                'categories' => ['all', 'seo'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('AIOSEO_FILE'),
                'learn_more_url' => '',
                'free' => true
            ],
            [
                'id' => 'capabilities',
                'title' => esc_html__('PublishPress Capabilities', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'capabilities',
                'categories' => ['all', 'admin'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('PUBLISHPRESS_CAPS_VERSION'),
                'learn_more_url' => 'https://publishpress.com/capabilities/',
                'free' => true
            ],
            [
                'id' => 'authors',
                'title' => esc_html__('PublishPress Authors', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'authors',
                'categories' => ['all', 'workflow'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('PUBLISHPRESS_MULTIPLE_AUTHORS_VERSION'),
                'learn_more_url' => 'https://publishpress.com/authors/',
                'free' => true
            ],
            [
                'id' => 'revisions',
                'title' => esc_html__('PublishPress Revisions', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'revisions',
                'categories' => ['all', 'workflow'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('PUBLISHPRESS_REVISONS_VERSION'),
                'learn_more_url' => 'https://publishpress.com/revisions/',
                'free' => true
            ],
            [
                'id' => 'checklists',
                'title' => esc_html__('PublishPress Checklists', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'checklists',
                'categories' => ['all', 'workflow'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('PUBLISHPRESS_CHECKLISTS_VERSION'),
                'learn_more_url' => 'https://publishpress.com/checklists/',
                'free' => true
            ],
            [
                'id' => 'taxopress',
                'title' => esc_html__('Taxopress', 'revisionary'),
                'description' => esc_html__('.', 'revisionary'),
                'icon_class' => 'taxopress',
                'categories' => ['all', 'admin'],
                'features' => [
                ],
                'enabled' => false,
                'available' => defined('STAGS_VERSION'),
                'learn_more_url' => 'https://taxopress.com/',
                'free' => true
            ],
            */
        ];

        foreach (array_keys($integrations) as $i) {
            if (!isset($integrations[$i]['free'])) {
                $integrations[$i]['free'] = false;
            }
        }

        usort($integrations, function($a, $b) {
            return $a['title'] <=> $b['title'];
        });

        return $integrations;
    }
}
