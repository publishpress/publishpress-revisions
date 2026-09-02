<?php
namespace PublishPress\Revisions;

class Utils {
	public static function isRESTurl() {
		static $arr_url;
	
		if (!isset($arr_url)) {
			$arr_url = wp_parse_url(get_option('siteurl'));
		}
	
		if ($arr_url) {
			$path = isset($arr_url['path']) ? $arr_url['path'] : '';
	
			if (!isset($_SERVER['REQUEST_URI'])) {
				return false;
			}

			if (0 === strpos(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), $path . '/wp-json/oembed/')) {
				return false;	
			}
	
			if (0 === strpos(esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])), $path . '/wp-json/')) {
				return true;
			}
		}
	
		return false;
	}

		/**
     * Returns true if is a beta or stable version of WP 5.
     *
     * @return bool
     */
    public static function isWp5()
    {
        global $wp_version;

        return version_compare($wp_version, '5.0', '>=') || substr($wp_version, 0, 2) === '5.';
    }
	
	/**
	 * Based on Edit Flow's \Block_Editor_Compatible::should_apply_compat method.
	 *
	 * @return bool
	 */
	public static function isBlockEditorActive($post_type = '', $args = []) {
		global $current_user, $wp_version;

        $defaults = ['force' => false, 'suppress_filter' => false, 'force_refresh' => false];
        $args = array_merge($defaults, $args);
        $suppress_filter = $args['suppress_filter'];

        if (isset($args['force'])) {
            if ('classic' === $args['force']) {
                return false;
            }

            if ('gutenberg' === $args['force']) {
                return true;
            }
        }

        // If the editor is being accessed in this request, we have an easy and reliable test
        if ((did_action('load-post.php') || did_action('load-post-new.php')) && did_action('admin_enqueue_scripts')) {
            if (did_action('enqueue_block_editor_assets')) {
                return true;
            }

			$is_gutenberg_edit = true;
        }

        // For other requests (or if the decision needs to be made prior to admin_enqueue_scripts action), proceed with other logic...

        static $buffer;
        if (!isset($buffer)) {
            $buffer = [];
        }

        if (!$post_type) {
            if (!$post_type = rvy_detect_post_type()) {
                $post_type = 'page';
            }
        }

        if ($post_type_obj = get_post_type_object($post_type)) {
            if (!$post_type_obj->show_in_rest) {
                return false;
            }
        }

		if (empty($is_gutenberg_edit) && class_exists('acf_pro') && empty($post_type_obj->_builtin) && !post_type_supports($post_type, 'editor') && !defined('REVISIONARY_STANDARD_CLASSIC_EDITOR_DETECTION')) {
			return false;
		}

        if (isset($buffer[$post_type]) && empty($args['force_refresh']) && !$suppress_filter) {
            return $buffer[$post_type];
        }

        if (class_exists('Classic_Editor')) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			if (isset($_REQUEST['classic-editor__forget']) && (isset($_REQUEST['classic']) || isset($_REQUEST['classic-editor']))) {
				return false;

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
			} elseif (isset($_REQUEST['classic-editor__forget']) && !isset($_REQUEST['classic']) && !isset($_REQUEST['classic-editor'])) {
				return true;
			} elseif (get_option('classic-editor-allow-users') === 'allow') {
				if ($post_id = rvy_detect_post_id()) {
					$which = get_post_meta( $post_id, 'classic-editor-remember', true );

					if ('block-editor' == $which) {
						return true;
					} elseif ('classic-editor' == $which) {
						return false;
					}
				
				} else {
                    $use_block = ('block' == get_user_meta($current_user->ID, 'wp_classic-editor-settings'));

                    if (version_compare($wp_version, '5.9-beta', '>=')) {
                    	if ($has_nav_action = has_action('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type')) {
                    		remove_action('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type');
                    	}
                    	
                    	if ($has_nav_filter = has_filter('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type')) {
                    		remove_filter('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type');
                    	}
                    }

                    $use_block = $use_block && apply_filters('use_block_editor_for_post_type', $use_block, $post_type, PHP_INT_MAX);

                    if (version_compare($wp_version, '5.9-beta', '>=') && !empty($has_nav_filter)) {
                        add_filter('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type', 10, 2 );
                    }

                    return $use_block;
				}
			}
		}

		// Divi: Classic Editor option
		if (function_exists('et_get_option') && ( 'on' == et_get_option( 'et_enable_classic_editor', 'off' ))) {
			return false;
		}

		$pluginsState = array(
			'classic-editor' => class_exists( 'Classic_Editor' ),
			'gutenberg'      => function_exists( 'the_gutenberg_project' ),
			'gutenberg-ramp' => class_exists('Gutenberg_Ramp'),
			'disable-gutenberg' => class_exists('DisableGutenberg'),
		);
		
		$conditions = [];

        if ($suppress_filter) remove_filter('use_block_editor_for_post_type', $suppress_filter, 10, 2);

		/**
		 * 5.0:
		 *
		 * Classic editor either disabled or enabled (either via an option or with GET argument).
		 * It's a hairy conditional :(
		 */

        if (version_compare($wp_version, '5.9-beta', '>=')) {
            if ($has_nav_action = has_action('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type')) {
        		remove_action('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type');
        	}
        	
        	if ($has_nav_filter = has_filter('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type')) {
        		remove_filter('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type');
        	}
        }

		if ($val = (self::isWp5() || $pluginsState['gutenberg'])
		&& ! $pluginsState['classic-editor']
		&& ! $pluginsState['gutenberg-ramp']
		&& ! $pluginsState['disable-gutenberg']
		&& apply_filters('use_block_editor_for_post_type', true, $post_type, PHP_INT_MAX)) {
			$_post = get_post(rvy_detect_post_id());

			if (!empty($_post) && !is_null($_post)) {
				$val = $val && apply_filters('use_block_editor_for_post', true, $_post, PHP_INT_MAX);
			}
		}

		$conditions[] = $val;

		$conditions[] = self::isWp5()
                        && $pluginsState['classic-editor']
                        && (get_option('classic-editor-replace') === 'block'
							&& ! isset($_GET['classic-editor__forget']));	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

        $conditions[] = self::isWp5()
                        && $pluginsState['classic-editor']
                        && (get_option('classic-editor-replace') === 'classic'
							&& isset($_GET['classic-editor__forget']));		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing

		if ($val = $pluginsState['gutenberg-ramp']) {
			$_post = get_post(rvy_detect_post_id());

			if (!empty($_post) && !is_null($_post)) {
				$val = $val && apply_filters('use_block_editor_for_post', true, $_post, PHP_INT_MAX);
			}
		}

        $conditions[] = $val;

		$conditions[] = $pluginsState['disable-gutenberg'] 
                        && !self::disableGutenberg(rvy_detect_post_id());

        if (version_compare($wp_version, '5.9-beta', '>=') && !empty($has_nav_filter)) {
            add_filter('use_block_editor_for_post_type', '_disable_block_editor_for_navigation_post_type', 10, 2 );
        }

		// Returns true if at least one condition is true.
		$result = count(
				   array_filter($conditions,
					   function ($c) {
						   return (bool)$c;
					   }
				   )
               ) > 0;
        
        if (!$suppress_filter) {
            $buffer[$post_type] = $result;
        }

        // Returns true if at least one condition is true.
        return $result;
	}
	
	// Port function from Disable Gutenberg plugin due to problematic early is_plugin_active() function call
    private static function disableGutenberg($post_id = false) {

        if (function_exists('disable_gutenberg_whitelist_id') && disable_gutenberg_whitelist_id($post_id)) return false;
        
        if (function_exists('disable_gutenberg_whitelist_slug') && disable_gutenberg_whitelist_slug($post_id)) return false;
        
        if (function_exists('disable_gutenberg_whitelist_title') && disable_gutenberg_whitelist_title($post_id)) return false;

        if (isset($_GET['block-editor'])) return false;		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        
        if (isset($_GET['classic-editor'])) return true;	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        
        if (isset($_POST['classic-editor'])) return true;  	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        
        if (function_exists('disable_gutenberg_disable_all') && disable_gutenberg_disable_all()) return true;
        
        if (function_exists('disable_gutenberg_disable_user_role') && disable_gutenberg_disable_user_role()) return true;
        
        if (function_exists('disable_gutenberg_disable_post_type') && disable_gutenberg_disable_post_type()) return true;
        
        if (function_exists('disable_gutenberg_disable_templates') && disable_gutenberg_disable_templates()) return true;
        
        if (function_exists('disable_gutenberg_disable_ids') && disable_gutenberg_disable_ids($post_id)) return true;

        return false;
    }

	/**
	 * Adds slashes only to strings.
	 *
	 * @param mixed $value Value to slash only if string.
	 *
	 * @return string|mixed
	 */
	public static function addslashes_to_strings_only( $value ) {
		return \is_string( $value ) ? \addslashes( $value ) : $value;
	}

	/**
	 * Replaces faulty core wp_slash().
	 *
	 * Until WP 5.5 wp_slash() recursively added slashes not just to strings in array/objects, leading to errors.
	 *
	 * @param mixed $value What to add slashes to.
	 *
	 * @return mixed
	 */
	public static function recursively_slash_strings( $value ) {
		return self::map_deep( $value, [ self::class, 'addslashes_to_strings_only' ] );
	}
	
	public static function map_deep( $value, $callback ) {
		if ( is_array( $value ) ) {
			foreach ( $value as $index => $item ) {
				$value[ $index ] = self::map_deep( $item, $callback );
			}
		} elseif ( is_object( $value ) ) {
			if ( get_class($value) === "__PHP_Incomplete_Class" ) { 
				return $value;
			}

			$object_vars = get_object_vars( $value );
			foreach ( $object_vars as $property_name => $property_value ) {
				$value->$property_name = self::map_deep( $property_value, $callback );
			}
		} else {
			$value = call_user_func( $callback, $value );
		}
	
		return $value;
	}

	public static function get_post_autosave($post_id, $user_id) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autosave = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT *
				FROM $wpdb->posts
				WHERE post_parent = %d
				AND post_type = 'revision'
				AND post_status = 'inherit'
				AND post_name LIKE %s
				AND post_author = %d
				ORDER BY post_date DESC
				LIMIT 1",
				$post_id,
				$wpdb->prepare(intval($post_id) . '-autosave') . '%',
				$user_id
			)
		);
	
		if ( ! $autosave ) {
			return false;
		}
	
		return get_post($autosave);
	}
}
