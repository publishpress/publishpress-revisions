<?php
namespace PublishPress\Revisions;

class Editor_Features {
    /**
     * Gutenberg Editor: Hide UI elements for Revision Editor features configured as disabled
     */
    public static function applyRestrictions()
    {
        $fields = rvy_get_option('enabled_fields');

        if (!is_array($fields)) {
            return;
        }

        $disabled_fields = array_filter(
            $fields, 
            function($val) {
                return is_null($val) || !$val;
            }
        );

        $restrict_elements = [];

        foreach(array_keys($disabled_fields) as $features) {
            $restrict_elements = array_merge($restrict_elements, self::getElements($features));
        }

        // apply the stored restrictions by js and css
        if ($restrict_elements = array_unique($restrict_elements)) {
            /* @todo: review
            
            // script file
            wp_enqueue_script(
                'rvy-features-block-script',
                RVY_URLPATH . '/admin/rvy_editor-features.js',
                ['wp-blocks', 'wp-edit-post'],
                PUBLISHPRESS_REVISIONS_VERSION
            );

            wp_localize_script(
                'rvy-features-block-script', 
                'ppc_features', 
                [
                'disabled_panel' => implode(',', $restrict_elements),
                'taxonomies' => implode(",", get_taxonomies())
                ]
            );
            */

            self::addInlineStyle('' . implode(',', $restrict_elements) . ' {display:none !important;}');
        }
    }

    private static function addInlineStyle($custom_css, $handle = 'ppc-dummy-css-handle')
    {
        global $ppc_dummy_css_handle;

        if (!is_array($ppc_dummy_css_handle)) {
            $ppc_dummy_css_handle = [];
        }

        if (in_array($handle, $ppc_dummy_css_handle)) {
            // duplicate usage of this function with same handle won't work
            $handle .= '-' . time(); 
        }

        $ppc_dummy_css_handle[] = $handle;

        wp_register_style(esc_attr($handle), false);
        wp_enqueue_style(esc_attr($handle));
        wp_add_inline_style(esc_attr($handle), $custom_css);
    }

    private static function getElements($feature_names, $args = []) {
        $feature_names = (array) $feature_names;

        $arr = self::elementsLayout();

        $elements = [];

        foreach($arr as $_feature_name => $feature_info) {
            if (in_array($_feature_name, $feature_names)) {
                if (!empty($feature_info['elements'])) {
                    $elements = array_merge($elements, explode(',', $feature_info['elements']));
                } else {
                    $elements[]= $_feature_name;
                }
            }
        }

        return $elements;
    }

    public static function elementsLayout()
    {
        $elements = [
            'post_title' =>   [
                'label'       => esc_html__('Title'), 
                'elements'    => '.wp-block.editor-post-title__block, .wp-block.editor-post-title',
                'support_key' => 'title'
            ],

            'post_content' =>      [
                'label'       => esc_html__('Content'), 
                'elements'    => '.block-editor-block-list__layout',
                'support_key' => 'editor'
            ],

            'post_date' =>             [
                'label'        => esc_html__('Date'),
                'elements'     => '.editor-post-schedule__dialog-toggle',
                'support_key'  => 'post_date',
            ],

            'post_status' => ['label' => esc_html__('Status & visibility', 'revisionary'),   'elements' => 'post-status'],
            
            '_wp_page_template' => [
                'label'       => esc_html__('Template'),
                'elements'    => '.editor-post-panel__row:has(button[aria-label="Template options"])'
            ],

            'post_name' =>         ['label' => esc_html__('Permalink', 'revisionary'), 'elements' => '.editor-post-panel__row:has(.editor-post-url__panel-dropdown)'],
            
            'category' =>        [
                'label'        => esc_html__('Categories'), 
                'elements'     => 'taxonomy-panel-category',
                'support_key'  => 'category',
                'support_type' => 'taxonomy'
            ],

            'post_tag' =>              [
                'label'        => esc_html__('Tags'),
                'elements'     => 'taxonomy-panel-post_tag',
                'support_key'  => 'post_tag',
                'support_type' => 'taxonomy'
            ],

            'post_parent' =>             [
                'label'        => esc_html__('Parent'),
                'elements'     => '.editor-post-parent__panel-dropdown',
                'support_key'  => 'post_parent',
            ],

            'post_author' =>             [
                'label'        => esc_html__('Author'),
                'elements'     => '.rvy-author-selection',
                'support_key'  => 'post_author',
            ],

            '_thumbnail_id'  => [
                'label'       => esc_html__('Featured image', 'revisionary'),
                'elements'    => 'featured-image',
                'support_key' => 'thumbnail'
            ],

            'post_excerpt'         => [
                'label'       => esc_html__('Excerpt'),
                'elements'    => 'post-excerpt',
                'support_key' => 'excerpt'
            ],
        ];
        
        $elements['taxonomies'] = [
            'label'        => esc_html__('Taxonomies', 'revisionary'),
            'support_key'  => 'taxonomy',
            'support_type' => 'taxonomy'
        ];
        
        $elems = [];

        foreach (get_taxonomies(['show_ui' => true], 'names') as $taxonomy) {
            $elems []= "taxonomy-panel-$taxonomy";
        }

        $elements['taxonomies']['elements'] = implode(', ', $elems);

        // phpcs:ignore Squiz.PHP.CommentedOutCode.Found
        /*
        foreach (get_taxonomies(['show_ui' => true], 'object') as $taxonomy => $tx_obj) {
            if (!in_array($taxonomy, ['category', 'post_tag', 'link_category'])) {
                $elements[$tx_obj->name] = [
                    'label'        => $tx_obj->label, 
                    'elements'     => "taxonomy-panel-$taxonomy",
                    'support_key'  => $tx_obj->name,
                    'support_type' => 'taxonomy'
                ];
            }
        }
        */

        return apply_filters('revisionary_post_feature_elements', $elements);
    }
}
