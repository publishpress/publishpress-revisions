<?php

namespace PublishPress;

/**
 * Adds Pro integration cards to the Integrations settings screen catalog.
 *
 * This metadata is shared by Revisions Free and Pro. The Free plugin uses it
 * to advertise the integrations available with Pro; no Pro integration code
 * is loaded by this class.
 */
class IntegrationCards
{
    /** Sort currently active/available cards first, then alphabetically. */
    public static function sortByAvailability($integrations)
    {
        $integrations = array_values((array) $integrations);
        usort($integrations, static function ($a, $b) {
            $groupA = !empty($a['available']) ? 0 : 1;
            $groupB = !empty($b['available']) ? 0 : 1;
            if ($groupA !== $groupB) {
                return $groupA <=> $groupB;
            }

            return strnatcasecmp(
                wp_strip_all_tags((string) ($a['title'] ?? '')),
                wp_strip_all_tags((string) ($b['title'] ?? ''))
            );
        });

        return $integrations;
    }

    public static function filter($integrations)
    {
        $integrations = array_values((array) $integrations);

        if (!in_array('statuses_compatibility', array_column($integrations, 'id'), true)) {
            $integrations[] = self::statusesCard();
        }

        $remoteIcons = self::integrationIconUrls();

        foreach ($integrations as &$integration) {
            if (empty($integration['id'])) {
                continue;
            }

            if (!empty($remoteIcons[$integration['id']])) {
                $integration['icon_class'] = 'remote';
                $integration['icon_url'] = $remoteIcons[$integration['id']];
            }

            if (in_array($integration['id'], ['beaver_compatibility', 'elementor_compatibility'], true)) {


            } elseif ('planner_compatibility' === $integration['id']) {
                self::addCategory($integration, 'fields');
                self::addFeature($integration, esc_html__('Revise custom fields and display changes', 'revisionary'));
            } elseif ('woocommerce_compatibility' === $integration['id']) {
                self::addCategory($integration, 'fields');
                self::addFeature($integration, esc_html__('Revise custom fields and display changes', 'revisionary'));
            } elseif ('yoast_seo_compatibility' === $integration['id']) {
                self::addCategory($integration, 'fields');
                self::addFeature($integration, esc_html__('Revise custom fields and display changes', 'revisionary'));
            }
        }
        unset($integration);

        foreach (self::pluginCards() as $card) {
            if (self::cardExcluded($card['id'])) {
                continue;
            }

            $integrations[] = self::fieldCard($card);
        }

        foreach (self::cacheCards() as $card) {
            $integrations[] = self::cacheCard($card);
        }

        foreach (self::themeCards() as $card) {
            if (self::cardExcluded($card['id'])) {
                continue;
            }

            $integrations[] = self::fieldCard($card, true);
        }

        foreach (self::builderCards() as $card) {
            $integrations[] = self::workflowCard($card, 'builder');
        }

        foreach (self::blockCards() as $card) {
            $integrations[] = self::workflowCard($card, 'blocks');
        }

        // Resolve installation separately from runtime availability.
        $plugins = self::installedPlugins();
        $known = [
            'acf_compatibility' => ['advanced-custom-fields', 'advanced-custom-fields-pro'],
            'acfe_compatibility' => ['acf-extended', 'acf-extended-pro'],
            'beaver_compatibility' => ['bb-plugin', 'beaver-builder-lite-version'],
            'divi_compatibility' => ['divi-builder'],
            'elementor_compatibility' => ['elementor'],
            'nitropack_compatibility' => ['nitropack'],
            'pods_compatibility' => ['pods'],
            'polylang_compatibility' => ['polylang', 'polylang-pro'],
            'planner_compatibility' => ['publishpress', 'publishpress-pro'],
            'woocommerce_compatibility' => ['woocommerce'],
            'wpml_compatibility' => ['sitepress-multilingual-cms'],
            'yoast_seo_compatibility' => ['wordpress-seo', 'wordpress-seo-premium'],
            'rank_math' => ['seo-by-rank-math', 'seo-by-rank-math-pro'],
            'all_in_one_seo' => ['all-in-one-seo-pack', 'all-in-one-seo-pack-pro'],
            'capabilities' => ['capability-manager-enhanced', 'publishpress-capabilities-pro'],
            'authors' => ['publishpress-authors', 'publishpress-authors-pro'],
            'checklists' => ['publishpress-checklists', 'publishpress-checklists-pro'],
            'taxopress' => ['simple-tags', 'taxopress-pro'],
        ];
        foreach ($integrations as &$integration) {
            if (!isset($integration['installed'])) {
                $integration['installed'] = false;
                foreach ($known[$integration['id']] ?? [] as $directory) {
                    foreach (array_keys($plugins) as $basename) {
                        if (0 === strpos($basename, $directory . '/')) {
                            $integration['installed'] = true;
                            break 2;
                        }
                    }
                }
            }
        }
        unset($integration);

        // Keep Builder copy consistent across built-in and Pro-only cards.
        foreach ($integrations as &$integration) {
            if (in_array('builder', (array) ($integration['categories'] ?? []), true)) {
                $title = wp_strip_all_tags((string) ($integration['title'] ?? ''));
                $integration['description'] = 'divi_compatibility' === $integration['id']
                    ? esc_html__('Integration with Divi 4/5 editor and blocks', 'revisionary')
                    : sprintf(
                        esc_html__('Integration with %s\'s editor and blocks.', 'revisionary'),
                        $title
                    );
            }
        }
        unset($integration);
        return $integrations;
    }

    private static function statusesCard()
    {
        $pluginFile = self::statusesPluginFile();

        return [
            'id' => 'statuses_compatibility',
            'statuses_pro_card' => true,
            'title' => esc_html__('PublishPress Statuses', 'revisionary'),
            'description' => esc_html__('PublishPress Statuses Pro integration.', 'revisionary'),
            'icon_class' => 'publishpress-statuses',
            'categories' => ['all', 'workflow'],
            'features' => [
                esc_html__('Define custom statuses for revisions', 'revisionary'),
                esc_html__('Control editing access for each revision status', 'revisionary'),
            ],
            'enabled' => false,
            'available' => defined('PUBLISHPRESS_STATUSES_PRO_VERSION') && class_exists('PublishPress_Statuses'),
            'installed' => !empty($pluginFile),
            'learn_more_url' => 'https://publishpress.com/knowledge-base/revisions-statuses/',
            'upgrade_url' => 'https://publishpress.com/statuses/',
            'free' => false,
        ];
    }

    /**
     * Locate Statuses Pro without loading any of its runtime code.
     *
     * Only the Pro plugin directory is accepted. Matching a display name is
     * too broad because another PublishPress package can advertise Statuses
     * Pro without that plugin actually being installed.
     */
    private static function statusesPluginFile()
    {
        static $pluginFile = null;

        if (null !== $pluginFile) {
            return $pluginFile;
        }

        $pluginFile = '';

        if (!function_exists('get_plugins')) {
            $pluginFunctions = ABSPATH . 'wp-admin/includes/plugin.php';

            if (file_exists($pluginFunctions)) {
                require_once $pluginFunctions;
            }
        }

        if (!function_exists('get_plugins')) {
            return $pluginFile;
        }

        foreach (array_keys((array) get_plugins()) as $basename) {
            if (0 === strpos($basename, 'publishpress-statuses-pro/')) {
                $pluginFile = $basename;
                break;
            }
        }

        return $pluginFile;
    }

    /**
     * Providers whose field definitions remain available for comparison, but
     * whose editing or persistence workflow is not safe to advertise as fully
     * compatible with Revisionary's end-to-end revision lifecycle.
     */
    private static function cardExcluded($integrationId)
    {
        $excluded = [
            // Uses a dedicated form editor and a post type Revisionary hides.
            'contact_form_7_field_compatibility',

            // Uses a dedicated slide editor and related ml-slide entities.
            'metaslider_field_compatibility',

            // Gallery state is primarily maintained in proprietary tables.
            'nextgen_gallery_field_compatibility',

            // These block plugins have dedicated block-editor integration cards.
            'stackable_field_compatibility',
            'spectra_field_compatibility',
            'publishpress_blocks_field_compatibility',

            // Expiration changes require PublishPress Future to reschedule its
            // separate action; Revisionary currently disables this editor UI.
            'publishpress_future_field_compatibility',

            // The field registry remains useful for ordinary Elementor pages,
            // but the plugin also uses a separate Theme Builder post type and
            // AJAX-managed template conditions outside Elementor's save path.
            'essential_addons_elementor_field_compatibility',

            // Mega Menu settings are saved directly to nav-menu-item metadata,
            // separately from the Elementor document being revised.
            'premium_addons_elementor_field_compatibility',

            // Theme, popup, mega-menu, and custom-widget builders use several
            // proprietary post types and direct metadata persistence paths.
            'royal_addons_elementor_field_compatibility',

            // Header/footer templates and their display rules are maintained
            // through a dedicated post type, metabox, modal, and abilities API.
            'ultimate_addons_elementor_field_compatibility',

            // The theme routes page and template editing through Pagelayer,
            // for which Revisionary does not provide an editor integration.
            'theme_popularfx_field_compatibility',

            // Page-builder requests can update global front-page theme mods,
            // and page content is synchronized with Customizer storage.
            'theme_hestia_field_compatibility',

            // The template builder stores assignments in a global option and
            // edits published athemes_hf parts outside the revision workflow.
            'theme_sydney_field_compatibility',
        ];

        return in_array($integrationId, $excluded, true);
    }

    private static function addCategory(&$integration, $category)
    {
        $integration['categories'] = array_values(array_unique(array_merge(
            (array) ($integration['categories'] ?? []),
            [$category]
        )));
    }

    private static function addFeature(&$integration, $feature)
    {
        $integration['features'] = (array) ($integration['features'] ?? []);
        if (!in_array($feature, $integration['features'], true)) {
            $integration['features'][] = $feature;
        }
    }

    private static function fieldCard($card, $theme = false)
    {
        $title = $card['title'];
        $features = [
            esc_html__('Revise and compare custom fields', 'revisionary'),
            esc_html__('Display custom field changes', 'revisionary'),
            esc_html__('Update custom fields on revision publication', 'revisionary'),
        ];

        if (!empty($card['features'])) {
            $features = $card['features'];
        }

        return [
            'id' => $card['id'],
            'title' => $title,
            'description' => !empty($card['description'])
                ? $card['description']
                : ($theme
                    ? sprintf(esc_html__('Compatibility with %s theme custom fields.', 'revisionary'), $title)
                    : sprintf(esc_html__('Compatibility with %s custom fields.', 'revisionary'), $title)),
            'icon_class' => $theme ? 'remote theme' : (!empty($card['icon_class']) ? $card['icon_class'] : 'remote'),
            'icon_url' => $card['icon_url'] ?? '',
            'categories' => array_values(array_unique(array_merge(
                $theme ? ['all'] : ['all', 'fields'],
                (array) ($card['categories'] ?? [])
            ))),
            'features' => $features,
            'enabled' => false,
            'installed' => !$theme && isset(self::installedPlugins()[$card['plugin']]),
            'available' => $theme
                ? self::themeActive($card['slug'])
                : self::pluginActive(
                    $card['plugin'],
                    $card['constant'] ?? '',
                    $card['function'] ?? '',
                    $card['class'] ?? ''
                ),
            'learn_more_url' => $card['learn_more_url'] ?? '',
            'free' => false,
        ];
    }

    private static function cacheCard($card)
    {
        $title = $card['title'];
        $description = preg_match('/cache$/i', wp_strip_all_tags($title))
            ? sprintf(esc_html__('Compatibility with %s.', 'revisionary'), $title)
            : sprintf(esc_html__('Compatibility with %s cache.', 'revisionary'), $title);

        return [
            'id' => $card['id'],
            'title' => $title,
            'description' => $description,
            'icon_class' => $card['icon_class'] ?? 'remote',
            'icon_url' => $card['icon_url'] ?? '',
            'categories' => ['all', 'cache'],
            'features' => [
                esc_html__('Clear cache on revision publication', 'revisionary'),
                esc_html__('Support immediate and scheduled publication', 'revisionary'),
            ],
            'enabled' => false,
            'installed' => isset(self::installedPlugins()[$card['plugin']]),
            'available' => self::pluginActive(
                $card['plugin'],
                $card['constant'] ?? '',
                $card['function'] ?? '',
                $card['class'] ?? ''
            ),
            'learn_more_url' => $card['learn_more_url'] ?? '',
            'free' => false,
        ];
    }

    private static function builderCards()
    {
        return [
            ['id' => 'avada_builder_integration', 'title' => esc_html__('Avada Builder', 'revisionary'), 'theme' => 'avada-one', 'icon_url' => plugins_url('common/img/avada.svg', REVISIONARY_FILE), 'icon_class' => 'remote'],
            ['id' => 'wpbakery_builder_integration', 'title' => esc_html__('WPBakery Page Builder', 'revisionary'), 'plugin' => 'js_composer/js_composer.php', 'constant' => 'WPB_VC_VERSION', 'function' => 'wpbakery', 'icon_url' => 'https://rankmath.com/wp-content/uploads/2022/02/WPBakery.jpg', 'features' => [esc_html__('Frontend revision editing, submission', 'revisionary'), esc_html__('Visually compare changes', 'revisionary'), esc_html__('Revision preview with builder styling', 'revisionary'), esc_html__('Revise and compare custom fields', 'revisionary')]],
            ['id' => 'siteorigin_builder_integration', 'title' => esc_html__('SiteOrigin Page Builder', 'revisionary'), 'plugin' => 'siteorigin-panels/siteorigin-panels.php', 'constant' => 'SITEORIGIN_PANELS_VERSION', 'class' => 'SiteOrigin\\Panels\\Plugin', 'icon_url' => 'https://ps.w.org/siteorigin-panels/assets/icon-128x128.png'],
            ['id' => 'seedprod_builder_integration', 'title' => esc_html__('SeedProd', 'revisionary'), 'plugin' => 'coming-soon/coming-soon.php', 'constant' => 'SEEDPROD_VERSION', 'icon_url' => 'https://ps.w.org/coming-soon/assets/icon-128x128.png'],
            ['id' => 'breakdance_builder_integration', 'title' => esc_html__('Breakdance', 'revisionary'), 'plugin' => 'breakdance/plugin.php', 'constant' => 'BREAKDANCE_VERSION', 'class' => 'Breakdance\\Plugin', 'icon_url' => plugins_url('common/img/breakdance.svg', REVISIONARY_FILE)],
            ['id' => 'bricks_builder_integration', 'title' => esc_html__('Bricks', 'revisionary'), 'constant' => 'BRICKS_VERSION', 'theme' => 'bricks', 'icon_url' => 'https://accessally.com/wp-content/uploads/2023/06/bricks-favicon-512x512-1.png', 'features' => [esc_html__('Frontend revision editing, submission', 'revisionary'), esc_html__('Visually compare changes', 'revisionary'), esc_html__('Revision preview with builder styling', 'revisionary'), esc_html__('Revise and compare custom fields', 'revisionary')]],
        ];
    }

    private static function blockCards()
    {
        $features = [
            esc_html__('Submit revisions to blocks', 'revisionary'),
            esc_html__('Visually compare changes', 'revisionary'),
            esc_html__('Revision preview with block styling', 'revisionary'),
        ];

        return [
            ['id' => 'kadence_blocks_integration', 'title' => esc_html__('Kadence Blocks', 'revisionary'), 'plugin' => 'kadence-blocks/kadence-blocks.php', 'constant' => 'KADENCE_BLOCKS_VERSION', 'icon_url' => 'https://ps.w.org/kadence-blocks/assets/icon-128x128.png', 'features' => $features],
            ['id' => 'spectra_blocks_integration', 'title' => esc_html__('Spectra Legacy', 'revisionary'), 'plugin' => 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php', 'constant' => 'UAGB_VER', 'icon_url' => 'https://ps.w.org/ultimate-addons-for-gutenberg/assets/icon-256x256.gif?rev=3240412', 'features' => array_merge($features, [esc_html__('Revise and compare custom fields', 'revisionary')])],
            ['id' => 'otter_blocks_integration', 'title' => esc_html__('Otter Blocks', 'revisionary'), 'plugin' => 'otter-blocks/otter-blocks.php', 'constant' => 'OTTER_VERSION', 'icon_url' => 'https://ps.w.org/otter-blocks/assets/icon-256x256.gif?rev=3452114', 'features' => $features],
            ['id' => 'essential_blocks_integration', 'title' => esc_html__('Essential Blocks', 'revisionary'), 'plugin' => 'essential-blocks/essential-blocks.php', 'constant' => 'EB_VERSION', 'icon_url' => 'https://ps.w.org/essential-blocks/assets/icon-256x256.gif?rev=2964049', 'features' => $features],
            ['id' => 'stackable_blocks_integration', 'title' => esc_html__('Stackable', 'revisionary'), 'plugin' => 'stackable-ultimate-gutenberg-blocks/plugin.php', 'constant' => 'STACKABLE_VERSION', 'icon_url' => 'https://ps.w.org/stackable-ultimate-gutenberg-blocks/assets/icon-128x128.png', 'features' => array_merge($features, [esc_html__('Revise and compare custom fields', 'revisionary')])],
            ['id' => 'ultimate_blocks_integration', 'title' => esc_html__('Ultimate Blocks', 'revisionary'), 'plugin' => 'ultimate-blocks/ultimate-blocks.php', 'constant' => 'ULTIMATE_BLOCKS_VERSION', 'icon_url' => 'https://ps.w.org/ultimate-blocks/assets/icon-128x128.png', 'features' => $features],
            ['id' => 'publishpress_blocks_integration', 'title' => esc_html__('PublishPress Blocks', 'revisionary'), 'plugin' => 'advanced-gutenberg/advanced-gutenberg.php', 'constant' => 'ADVANCED_GUTENBERG_VERSION', 'icon_url' => 'https://ps.w.org/advanced-gutenberg/assets/icon-128x128.png', 'features' => array_merge($features, [esc_html__('Revise and compare custom fields', 'revisionary')])],
        ];
    }

    private static function workflowCard($card, $category)
    {
        $theme = !empty($card['theme']);
        $plugins = self::installedPlugins();
        $themes = $theme && function_exists('wp_get_themes') ? wp_get_themes() : [];
        $installed = $theme ? isset($themes[$card['theme']]) : isset($plugins[$card['plugin'] ?? '']);
        $available = $theme
            ? self::themeActive($card['theme'])
            : self::pluginActive($card['plugin'] ?? '', $card['constant'] ?? '', $card['function'] ?? '', $card['class'] ?? '');

        return [
            'id' => $card['id'],
            'title' => $card['title'],
            'description' => sprintf(esc_html__('Integration with %s.', 'revisionary'), $card['title']),
            'icon_class' => $card['icon_class'] ?? 'remote',
            'icon_url' => $card['icon_url'],
            'categories' => ['all', $category],
            'features' => $card['features'] ?? [
                esc_html__('Frontend revision editing, submission', 'revisionary'),
                esc_html__('Visually compare changes', 'revisionary'),
                esc_html__('Revision preview with builder styling', 'revisionary'),
            ],
            'enabled' => false,
            'installed' => $installed,
            'available' => $available,
            'learn_more_url' => '',
            'free' => false,
        ];
    }

    private static function cacheCards()
    {
        return [
            ['id' => 'litespeed_compatibility', 'title' => esc_html__('LiteSpeed Cache', 'revisionary'), 'plugin' => 'litespeed-cache/litespeed-cache.php', 'constant' => 'LSCWP_V', 'icon_url' => 'https://ps.w.org/litespeed-cache/assets/icon-128x128.png'],
            ['id' => 'wp_fastest_cache_compatibility', 'title' => esc_html__('WP Fastest Cache', 'revisionary'), 'plugin' => 'wp-fastest-cache/wpFastestCache.php', 'function' => 'wpfc_clear_post_cache_by_id', 'icon_url' => 'https://ps.w.org/wp-fastest-cache/assets/icon-128x128.png'],
            ['id' => 'wp_super_cache_compatibility', 'title' => esc_html__('WP Super Cache', 'revisionary'), 'plugin' => 'wp-super-cache/wp-cache.php', 'function' => 'wp_cache_post_change', 'icon_url' => 'https://ps.w.org/wp-super-cache/assets/icon-128x128.png'],
            ['id' => 'wp_optimize_cache_compatibility', 'title' => esc_html__('WP-Optimize', 'revisionary'), 'plugin' => 'wp-optimize/wp-optimize.php', 'constant' => 'WPO_VERSION', 'icon_url' => 'https://ps.w.org/wp-optimize/assets/icon-128x128.png'],
            ['id' => 'w3_total_cache_compatibility', 'title' => esc_html__('W3 Total Cache', 'revisionary'), 'plugin' => 'w3-total-cache/w3-total-cache.php', 'constant' => 'W3TC_VERSION', 'icon_url' => 'https://ps.w.org/w3-total-cache/assets/icon-128x128.png'],
            ['id' => 'breeze_cache_compatibility', 'title' => esc_html__('Breeze', 'revisionary'), 'plugin' => 'breeze/breeze.php', 'constant' => 'BREEZE_VERSION', 'icon_url' => 'https://s.w.org/plugins/geopattern-icon/breeze.svg'],
            ['id' => 'speedycache_compatibility', 'title' => esc_html__('SpeedyCache', 'revisionary'), 'plugin' => 'speedycache/speedycache.php', 'constant' => 'SPEEDYCACHE_VERSION', 'icon_url' => 'https://ps.w.org/speedycache/assets/icon-128x128.png'],
        ];
    }

    private static function integrationIconUrls()
    {
        return [
            'acf_compatibility' => 'https://ps.w.org/advanced-custom-fields/assets/icon.svg?rev=3207824',
            'acfe_compatibility' => 'https://ps.w.org/acf-extended/assets/icon-128x128.png?rev=2071550',
            'beaver_compatibility' => 'https://ps.w.org/beaver-builder-lite-version/assets/icon-128x128.png?rev=2361183',
            'elementor_compatibility' => 'https://ps.w.org/elementor/assets/icon-128x128.gif?rev=3444228',
            'nitropack_compatibility' => 'https://ps.w.org/nitropack/assets/icon-128x128.gif?rev=2936590',
            'pods_compatibility' => 'https://ps.w.org/pods/assets/icon.svg?rev=3286397',
            'polylang_compatibility' => 'https://ps.w.org/polylang/assets/icon-128x128.png?rev=3433336',
            'statuses_compatibility' => 'https://ps.w.org/publishpress-statuses/assets/icon-128x128.png',
            'woocommerce_compatibility' => 'https://ps.w.org/woocommerce/assets/icon.svg?rev=3640790',
            'yoast_seo_compatibility' => 'https://ps.w.org/wordpress-seo/assets/icon-128x128.gif?rev=3419908',
        ];
    }

    private static function pluginCards()
    {
        return [
            [
                'id' => 'publishpress_cart_field_compatibility',
                'title' => esc_html__('PublishPress Cart', 'revisionary'),
                'plugin' => 'publishpress-cart/publishpress-cart.php',
                'constant' => 'PPCART_VERSION',
                'icon_class' => 'publishpress-cart',
                'categories' => ['ecommerce'],
                'description' => esc_html__('Revision submission and approval for products.', 'revisionary'),
                'features' => [
                    esc_html__('Product revisions', 'revisionary'),
                    esc_html__('Priority on ongoing compatibility', 'revisionary'),
                    esc_html__('Revise custom fields and display changes', 'revisionary'),
                ],
            ],
            ['id' => 'cptui_field_compatibility', 'title' => 'Custom Post Type UI', 'plugin' => 'custom-post-type-ui/custom-post-type-ui.php', 'constant' => 'CPTUI_VERSION', 'function' => 'cptui_get_post_type_data', 'icon_url' => 'https://ps.w.org/custom-post-type-ui/assets/icon-128x128.png?rev=2744389'],
            ['id' => 'contact_form_7_field_compatibility', 'title' => 'Contact Form 7', 'plugin' => 'contact-form-7/wp-contact-form-7.php', 'constant' => 'WPCF7_VERSION', 'categories' => ['form'], 'icon_url' => 'https://ps.w.org/contact-form-7/assets/icon.svg?rev=2339255'],
            ['id' => 'site_kit_field_compatibility', 'title' => 'Site Kit by Google', 'plugin' => 'google-site-kit/google-site-kit.php', 'constant' => 'GOOGLESITEKIT_VERSION', 'icon_url' => 'https://ps.w.org/google-site-kit/assets/icon-128x128.png?rev=3606666'],
            ['id' => 'rank_math_field_compatibility', 'title' => 'Rank Math SEO', 'plugin' => 'seo-by-rank-math/rank-math.php', 'constant' => 'RANK_MATH_VERSION', 'categories' => ['seo'], 'icon_url' => 'https://ps.w.org/seo-by-rank-math/assets/icon.svg?rev=3438330'],
            ['id' => 'jetpack_field_compatibility', 'title' => 'Jetpack', 'plugin' => 'jetpack/jetpack.php', 'constant' => 'JETPACK__VERSION', 'icon_url' => 'https://ps.w.org/jetpack/assets/icon.svg?rev=2819237'],
            ['id' => 'essential_addons_elementor_field_compatibility', 'title' => 'Essential Addons for Elementor', 'plugin' => 'essential-addons-for-elementor-lite/essential_adons_elementor.php', 'constant' => 'EAEL_PLUGIN_VERSION', 'categories' => ['builder'], 'icon_url' => 'https://ps.w.org/essential-addons-for-elementor-lite/assets/icon-128x128.gif?rev=3182943'],
            ['id' => 'stackable_field_compatibility', 'title' => 'Stackable', 'plugin' => 'stackable-ultimate-gutenberg-blocks/plugin.php', 'constant' => 'STACKABLE_VERSION', 'categories' => ['builder'], 'icon_url' => 'https://ps.w.org/stackable-ultimate-gutenberg-blocks/assets/icon-128x128.png?rev=2749547'],
            ['id' => 'spectra_field_compatibility', 'title' => 'Spectra Legacy', 'plugin' => 'ultimate-addons-for-gutenberg/ultimate-addons-for-gutenberg.php', 'constant' => 'UAGB_VER', 'icon_url' => 'https://ps.w.org/ultimate-addons-for-gutenberg/assets/icon-128x128.gif?rev=3240412'],
            ['id' => 'premium_addons_elementor_field_compatibility', 'title' => 'Premium Addons for Elementor', 'plugin' => 'premium-addons-for-elementor/premium-addons-for-elementor.php', 'constant' => 'PREMIUM_ADDONS_VERSION', 'categories' => ['builder'], 'icon_url' => 'https://ps.w.org/premium-addons-for-elementor/assets/icon.svg?rev=3705658'],
            ['id' => 'events_calendar_field_compatibility', 'title' => 'The Events Calendar', 'plugin' => 'the-events-calendar/the-events-calendar.php', 'class' => 'Tribe__Events__Main', 'icon_url' => 'https://ps.w.org/the-events-calendar/assets/icon-128x128.gif?rev=2516440'],
            ['id' => 'page_links_to_field_compatibility', 'title' => 'Page Links To', 'plugin' => 'page-links-to/page-links-to.php', 'class' => 'CWS_PageLinksTo', 'icon_url' => 'https://s.w.org/plugins/geopattern-icon/page-links-to_fafafa.svg'],
            ['id' => 'taxopress_field_compatibility', 'title' => 'TaxoPress', 'plugin' => 'simple-tags/simple-tags.php', 'constant' => 'STAGS_VERSION', 'icon_url' => 'https://ps.w.org/simple-tags/assets/icon-128x128.png?rev=2853049'],
            ['id' => 'publishpress_authors_field_compatibility', 'title' => 'PublishPress Authors', 'plugin' => 'publishpress-authors/publishpress-authors.php', 'constant' => 'PP_AUTHORS_VERSION', 'categories' => ['workflow'], 'icon_url' => 'https://ps.w.org/publishpress-authors/assets/icon-128x128.png?rev=3391318'],
            ['id' => 'publishpress_future_field_compatibility', 'title' => 'PublishPress Future', 'plugin' => 'post-expirator/post-expirator.php', 'constant' => 'PUBLISHPRESS_FUTURE_VERSION', 'categories' => ['workflow'], 'icon_url' => 'https://ps.w.org/post-expirator/assets/icon-128x128.png?rev=3569472'],
            ['id' => 'publishpress_blocks_field_compatibility', 'title' => 'PublishPress Blocks', 'plugin' => 'advanced-gutenberg/advanced-gutenberg.php', 'constant' => 'ADVANCED_GUTENBERG_VERSION', 'icon_url' => 'https://ps.w.org/advanced-gutenberg/assets/icon-128x128.png?rev=3393761'],
            ['id' => 'metaslider_field_compatibility', 'title' => 'MetaSlider', 'plugin' => 'ml-slider/ml-slider.php', 'constant' => 'METASLIDER_VERSION', 'categories' => ['slider'], 'icon_url' => 'https://ps.w.org/ml-slider/assets/icon.svg?rev=3646237'],
            ['id' => 'nextgen_gallery_field_compatibility', 'title' => 'NextGEN Gallery', 'plugin' => 'nextgen-gallery/nggallery.php', 'constant' => 'NGG_PLUGIN_VERSION', 'icon_url' => 'https://ps.w.org/nextgen-gallery/assets/icon-128x128.png?rev=2083961'],
            ['id' => 'royal_addons_elementor_field_compatibility', 'title' => 'Royal Addons for Elementor', 'plugin' => 'royal-elementor-addons/wpr-addons.php', 'constant' => 'WPR_ADDONS_VERSION', 'categories' => ['builder'], 'icon_url' => 'https://ps.w.org/royal-elementor-addons/assets/icon-128x128.gif?rev=2604295'],
            ['id' => 'ultimate_addons_elementor_field_compatibility', 'title' => 'Ultimate Addons for Elementor', 'plugin' => 'header-footer-elementor/header-footer-elementor.php', 'constant' => 'HFE_VER', 'categories' => ['builder'], 'icon_url' => 'https://ps.w.org/header-footer-elementor/assets/icon-128x128.gif?rev=3278750'],
        ];
    }

    private static function themeCards()
    {
        $themes = [
            'astra' => ['Astra', 'jpg'],
            'kadence' => ['Kadence', 'png'],
            'oceanwp' => ['OceanWP', 'png'],
            'blocksy' => ['Blocksy', 'jpg'],
            'generatepress' => ['GeneratePress', 'png'],
            'neve' => ['Neve', 'png'],
            'popularfx' => ['PopularFX', 'jpg'],
            'envo-royal' => ['Envo Royal', 'png'],
            'inspiro' => ['Inspiro', 'png'],
            'envo-one' => ['Envo One', 'png'],
            'lightning' => ['Lightning', 'jpg'],
            'go' => ['Go', 'png'],
            'bloghash' => ['BlogHash', 'jpg'],
        ];
        $cards = [];

        foreach ($themes as $slug => $theme) {
            $cards[] = [
                'id' => 'theme_' . str_replace('-', '_', $slug) . '_field_compatibility',
                'title' => esc_html($theme[0]),
                'slug' => $slug,
                'categories' => ['themes'],
                'icon_url' => sprintf('https://ts.w.org/wp-content/themes/%1$s/screenshot.%2$s', $slug, $theme[1]),
            ];
        }

        return $cards;
    }

    private static function installedPlugins()
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return (array) get_plugins();
    }

    private static function pluginActive($plugin, $constant = '', $function = '', $class = '')
    {
        static $active_plugins = null;

        if (null === $active_plugins) {
            $active_plugins = array_fill_keys((array) get_option('active_plugins', []), true);
            if (is_multisite()) {
                $active_plugins += (array) get_site_option('active_sitewide_plugins', []);
            }
        }

        return isset($active_plugins[$plugin])
            || ($constant && defined($constant))
            || ($function && function_exists($function))
            || ($class && class_exists($class, false));
    }

    private static function themeActive($slug)
    {
        return in_array($slug, array_unique(array_filter([get_stylesheet(), get_template()])), true);
    }
}
