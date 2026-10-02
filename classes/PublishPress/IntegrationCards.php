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

            // Custom block styles use the plugin's stackable_temp_post store.
            'stackable_field_compatibility',

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
            esc_html__('Store custom fields with revisions', 'revisionary'),
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
