<?php
namespace PublishPress\Revisions;

\defined('ABSPATH') || exit;

/**
 * Protect global revision workflow definitions from generic taxonomy handlers.
 */
class RevisionStatusTaxonomySecurity
{
    const TAXONOMY = 'pp_revision_status';
    const CAPABILITY = 'rvy_manage_revision_status_terms';

    public static function register()
    {
        add_filter('register_taxonomy_args', [__CLASS__, 'filterTaxonomyArgs'], 100, 2);
        add_filter('map_meta_cap', [__CLASS__, 'mapMetaCap'], 10, 4);
        add_action('admin_init', [__CLASS__, 'secureRegisteredTaxonomy'], 0);

        foreach (['wp_ajax_add-tag', 'wp_ajax_inline-save-tax', 'wp_ajax_delete-tag', 'load-edit-tags.php', 'load-term.php'] as $hook) {
            add_action($hook, [__CLASS__, 'blockCoreTermEditor'], 0);
        }
    }

    private static function isTarget($taxonomy)
    {
        return self::TAXONOMY === $taxonomy;
    }

    private static function termCapabilities()
    {
        return array_fill_keys(['manage_terms', 'edit_terms', 'delete_terms', 'assign_terms'], self::CAPABILITY);
    }

    public static function filterTaxonomyArgs($args, $taxonomy)
    {
        if (self::isTarget($taxonomy)) {
            $args['capabilities'] = array_merge(
                isset($args['capabilities']) ? (array) $args['capabilities'] : [],
                self::termCapabilities()
            );
        }

        return $args;
    }

    public static function secureRegisteredTaxonomy()
    {
        if ($object = get_taxonomy(self::TAXONOMY)) {
            foreach (self::termCapabilities() as $operation => $capability) {
                $object->cap->$operation = $capability;
            }
        }
    }

    public static function mapMetaCap($caps, $cap, $user_id, $args)
    {
        if (self::CAPABILITY === $cap) {
            return user_can($user_id, 'manage_options') ? ['manage_options'] : ['pp_manage_statuses'];
        }

        return $caps;
    }

    public static function blockCoreTermEditor()
    {
        // Generic WordPress term nonces are not sufficient for managing workflow definitions.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        foreach ([$_GET, $_POST, $_REQUEST] as $request) {
            $taxonomy = isset($request['taxonomy']) && is_string($request['taxonomy'])
                ? sanitize_key(wp_unslash($request['taxonomy'])) : '';

            if (self::isTarget($taxonomy)) {
                wp_die(
                    esc_html__('Please use the PublishPress workflow settings to manage revision statuses.', 'publishpress-revisions'),
                    '',
                    ['response' => 403]
                );
            }
        }
    }
}
