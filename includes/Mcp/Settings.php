<?php

/** Native settings discovery and typed patches, with network scope and read-back. */

namespace PublishPress\Revisions\Mcp;

defined('ABSPATH') || exit;
class Settings
{
    public static function register(): void
    {
        $access = static fn() => current_user_can('manage_options');
        $output = Registry::object(['settings' => ['type' => 'object'], 'stored' => ['type' => 'object'], 'schema' => ['type' => 'object'], 'expected_hash' => Registry::revision(), 'pro_active' => ['type' => 'boolean'], 'dry_run' => ['type' => 'boolean'], 'completed_keys' => Registry::listOf(Registry::text()), 'complete' => ['type' => 'boolean']]);
        Registry::add('get-plugin-settings', __('Read Revisions settings', 'revisionary'), __('Read native setting identifiers, exact JSON types, defaults, choices, storage and revision. Network-controlled settings require actual network permission to change. Credentials and database maintenance are excluded.', 'revisionary'), Registry::object(), [self::class, 'read'], $access, false, false, $output);
        Registry::add('update-plugin-settings', __('Update Revisions settings', 'revisionary'), __('Patch discovered settings only, using expected_hash. JSON booleans are not strings. Native sanitizers, network scope and edition/dependency gates apply. Omitted values remain. dry_run validates without saving or converting revision storage.', 'revisionary'), Registry::object(['settings' => ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 100], 'expected_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean']], ['settings', 'expected_hash']), [self::class, 'update'], $access, true, false, $output);
    }
    public static function fields(): array
    {
        do_action('publishpress_revisions_mcp_before_settings');
        require_once RVY_ABSPATH . '/admin/options.php';
        $definition = \RvyOptionUI::instance()->getNativeSettingsDefinition();
        $defaults = apply_filters('default_options_rvy', rvy_default_options());
        $keys = [];
        foreach ($definition['sections']['features'] as $section => $names) {
            foreach ($names as $name) {
                if (is_string($name)) {
                    $keys[$name] = true;
                }
            }
        }
        $fields = [];
        foreach ($keys as $key => $unused) {
            if (in_array($key, ['edd_key', 'add_revisions_index', 'permissions_compat_mode', 'wp_cron_usage_detected'], true)) {
                continue;
            }
            if (isset($defaults[$key]) && in_array($defaults[$key], [0, 1, false, true], true)) {
                $fields[$key] = ['type' => 'boolean'];
            }
        }
        $fields['auto_submit_revisions_any_user'] = ['type' => 'boolean'];
        foreach (['pending_rev_notify_admin', 'pending_rev_notify_author'] as $key) {
            $fields[$key] = ['type' => 'string', 'enum' => ['0', '1', 'always']];
        }
        $fields['preview_link_type'] = ['type' => 'string', 'enum' => ['', 'revision_slug', 'id_only']];
        $fields['past_revisions_order_by'] = ['type' => 'string', 'enum' => ['', 'modified']];
        $fields['num_revisions'] = ['type' => ['integer', 'null'], 'minimum' => -1, 'maximum' => 100000];
        $fields['revision_limit_per_post'] = ['type' => 'string', 'enum' => ['0', '1', 'submitted']];
        $fields['revision_editor_bg_color'] = ['type' => 'string', 'pattern' => '^(|#[a-fA-F0-9]{6})$'];
        $types = get_post_types(['public' => true, 'show_ui' => true], 'objects', 'or');
        foreach (['enabled_post_types', 'enabled_post_types_archive', 'enabled_post_types_copy'] as $key) {
            $hidden = match ($key) {
                'enabled_post_types_archive' => $GLOBALS['revisionary']->getHiddenPostTypesArchive(), 'enabled_post_types_copy' => $GLOBALS['revisionary']->getHiddenPostTypesCopy(), default => $GLOBALS['revisionary']->getHiddenPostTypes()
            };
            $availableTypes = $key === 'enabled_post_types_archive' ? $types : array_merge($types, $GLOBALS['revisionary']->getAvailablePrivatePostTypes());
            $fields[$key] = Registry::object(array_fill_keys(array_keys(array_diff_key($availableTypes, $hidden)), ['type' => 'boolean']));
        }
        foreach (['enabled_fields' => false, 'enabled_fields_copy' => true] as $key => $copy) {
            $properties = [];
            foreach (\RvyOptionUI::instance()->getNativeFieldChoices($copy) as $name => $label) {
                $properties[$name] = ['type' => 'boolean', 'title' => wp_strip_all_tags($label)];
            }
            $fields[$key] = Registry::object($properties);
        }
        if (!Registry::pro()) {
            foreach (['deletion_queue', 'archive_postmeta_on_edit', 'revision_restore_require_cap', 'pending_revision_unpublished', 'publish_by_revision', 'unfiltered_preview_links', 'use_publishpress_notifications'] as $key) {
                unset($fields[$key]);
            }
        }
        if (!defined('PUBLISHPRESS_VERSION') || version_compare(PUBLISHPRESS_VERSION, '4.6-beta', '<')) {
            unset($fields['use_publishpress_notifications']);
        }
        if (function_exists('relevanssi_query') && isset($fields['async_scheduled_publish'])) {
            $fields['async_scheduled_publish']['enum'] = [false];
            $fields['async_scheduled_publish']['description'] = __('Native asynchronous publication is unavailable while Relevanssi is active.', 'revisionary');
        }
        if (rvy_visual_compare_disabled() && isset($fields['visual_compare'])) {
            $fields['visual_compare']['enum'] = [false];
            $fields['visual_compare']['description'] = __('Visual comparison is disabled by the native compatibility configuration.', 'revisionary');
        }
        foreach ($fields as $key => &$field) {
            $field['title'] = wp_strip_all_tags($definition['captions'][$key] ?? $key);
        }
        unset($field);
        return apply_filters('publishpress_revisions_mcp_setting_fields', $fields);
    }
    private static function native(string $key)
    {
        if ($key === 'visual_compare' && rvy_visual_compare_disabled()) {
            return false;
        }
        if (in_array($key, ['enabled_post_types', 'enabled_post_types_archive', 'enabled_post_types_copy', 'enabled_fields', 'enabled_fields_copy'], true)) {
            return get_option('rvy_' . $key, $GLOBALS['revisionary']->$key);
        }
        return rvy_get_option($key);
    }
    private static function value(string $key, $value, array $field)
    {
        if ($field['type'] === 'boolean') {
            return in_array($value, [true, 1, '1', 'on'], true);
        }
        if ($field['type'] === 'integer') {
            return (int) $value;
        }
        if (is_array($field['type'])) {
            return $value === '' || $value === true || $value === null ? null : (int) $value;
        }
        if ($field['type'] === 'object') {
            $map = [];
            foreach ($field['properties'] as $name => $schema) {
                $map[$name] = $value === true || !empty(((array) $value)[$name]);
            }
            return (object) $map;
        }
        if ($key === 'preview_link_type' && $value === 'published_slug') {
            return '';
        }
        if (in_array($key, ['pending_rev_notify_admin', 'pending_rev_notify_author', 'revision_limit_per_post'], true) && !$value) {
            return '0';
        }
        return is_scalar($value) ? (string) $value : '';
    }
    private static function state(array $fields): array
    {
        $state = [];
        global $rvy_options_sitewide;
        foreach ($fields as $key => $field) {
            $network = defined('RVY_NETWORK') && RVY_NETWORK && !empty($rvy_options_sitewide[$key]);
            $state[$key] = ['effective' => self::native($key), 'stored' => $network ? get_site_option('rvy_' . $key, null) : get_option('rvy_' . $key, null), 'network' => $network];
        }
        return $state;
    }
    public static function read(): array
    {
        rvy_refresh_options();
        $fields = self::fields();
        $state = self::state($fields);
        $values = $stored = [];
        foreach ($fields as $key => &$field) {
            $values[$key] = self::value($key, $state[$key]['effective'], $field);
            $stored[$key] = $state[$key]['stored'];
            $default = match ($key) {
                'enabled_fields', 'enabled_fields_copy' => true,
                'enabled_post_types', 'enabled_post_types_archive', 'enabled_post_types_copy' => $GLOBALS['revisionary']->defaultPostTypes(),
                default => rvy_get_option($key, -1, true),
            };
            $field['default'] = self::value($key, $default, $field);
            if (isset($field['enum']) && !in_array($field['default'], $field['enum'], true)) {
                $field['default'] = reset($field['enum']);
            }
            $field['network_controlled'] = $state[$key]['network'];
        }
        unset($field);
        return ['settings' => (object) $values, 'stored' => (object) $stored, 'schema' => Registry::object($fields), 'expected_hash' => Registry::hash($state), 'pro_active' => Registry::pro(), 'dry_run' => false];
    }
    public static function update(array $input)
    {
        $before = self::read();
        if (!hash_equals($before['expected_hash'], $input['expected_hash'])) {
            return Registry::error(__('Settings changed. Read them again.', 'revisionary'), 409);
        }
        $patch = Registry::validate($input['settings'], $before['schema']);
        if (is_wp_error($patch)) {
            return $patch;
        }
        require_once RVY_ABSPATH . '/submittee_rvy.php';
        $sanitizer = new \Revisionary_Submittee();
        $values = [];
        $fields = $before['schema']['properties'];
        foreach ($patch as $key => $value) {
            if (!empty($fields[$key]['network_controlled']) && !current_user_can('manage_network_options')) {
                return Registry::denied();
            }
            if ($value && $key === 'use_publishpress_notifications' && (!Registry::pro() || !defined('PUBLISHPRESS_VERSION') || version_compare(PUBLISHPRESS_VERSION, '4.6-beta', '<'))) {
                return Registry::denied();
            }
            if ($value && $key === 'visual_compare' && rvy_visual_compare_disabled()) {
                return Registry::denied();
            }
            if ($key === 'enabled_post_types_archive') {
                foreach ($value as $name => $enabled) {
                    if ($enabled && !post_type_supports($name, 'revisions') && ($name !== 'product' || !Registry::pro())) {
                        return Registry::denied();
                    }
                }
            }
            $type = $fields[$key]['type'];
            if ($type === 'object' && !$value) {
                continue;
            }
            if ($type === 'object') {
                $old = self::native($key);
                $map = $old === true ? array_fill_keys(array_keys($fields[$key]['properties']), 1) : (array) $old;
                foreach ($value as $name => $enabled) {
                    $map[$name] = $enabled ? 1 : '';
                }
                $value = $map;
            } elseif ($type === 'boolean') {
                $value = $value ? 1 : '';
            } elseif ($value === null) {
                $value = '';
            }
            $values[$key] = $sanitizer->sanitizeSettingValue($key, $value);
        }
        // Enabling a custom type can add its edit/read capabilities to the native Revisor role.
        $roleCaps = $patch['revisor_role_add_custom_rolecaps'] ?? (bool) rvy_get_option('revisor_role_add_custom_rolecaps');
        if ($roleCaps && (isset($patch['enabled_post_types']) || isset($patch['revisor_role_add_custom_rolecaps']))) {
            $enabledTypes = (array) $before['settings']->enabled_post_types;
            foreach ($patch['enabled_post_types'] ?? [] as $name => $enabled) {
                $enabledTypes[$name] = $enabled;
            }
            foreach ($enabledTypes as $name => $enabled) {
                if (!$enabled || !($typeObject = get_post_type_object($name)) || $typeObject->_builtin) {
                    continue;
                }
                $newGrant = empty($before['settings']->enabled_post_types->$name) || empty($before['settings']->revisor_role_add_custom_rolecaps);
                foreach (array_keys(rvy_custom_type_rolecaps($name, $typeObject)) as $cap) {
                    if ($newGrant && self::protectedCapability($cap)) {
                        return Registry::denied();
                    }
                }
            }
        }
        if (!empty($input['dry_run'])) {
            foreach ($values as $key => $unused) {
                $before['settings']->$key = self::value($key, $values[$key], $fields[$key]);
            } $before['dry_run'] = true;
            return $before;
        }
        if (!hash_equals($before['expected_hash'], self::read()['expected_hash'])) {
            return Registry::error(__('Settings changed during validation.', 'revisionary'), 409);
        }
        $completed = [];
        foreach ($values as $key => $value) {
            if (!current_user_can('manage_options') || (!empty($fields[$key]['network_controlled']) && !current_user_can('manage_network_options'))) {
                return ['completed_keys' => $completed, 'complete' => false];
            }
            rvy_update_option($key, $value, !empty($fields[$key]['network_controlled']));
            rvy_refresh_options();
            $stored = !empty($fields[$key]['network_controlled']) ? get_site_option('rvy_' . $key) : get_option('rvy_' . $key);
            if ($stored !== $value && $stored != $value) {
                return self::read() + ['completed_keys' => $completed, 'complete' => false];
            }
            $completed[] = $key;
            $engine = $GLOBALS['revisionary'];
            foreach (['enabled_post_types' => 'setPostTypes', 'enabled_post_types_archive' => 'setPostTypesArchive', 'enabled_post_types_copy' => 'setPostTypesCopy'] as $property => $method) {
                if ($key === $property) {
                    $engine->$property = [];
                    $engine->$method();
                }
            }
            if ($key === 'enabled_fields') {
                $engine->setFields();
            }
            if ($key === 'enabled_fields_copy') {
                $engine->setFieldsCopy();
            }
        }
        return self::read() + ['completed_keys' => $completed, 'complete' => true];
    }
    /** MCP grant policy; native browser role management remains independent. */
    private static function protectedCapability(string $cap): bool
    {
        return str_starts_with($cap, 'manage_capabilities') || in_array($cap, [
            'administrator', 'manage_options', 'edit_users', 'promote_users', 'create_users', 'delete_users', 'remove_users',
            'activate_plugins', 'install_plugins', 'update_plugins', 'delete_plugins', 'edit_plugins', 'resume_plugins',
            'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes', 'resume_themes',
            'edit_files', 'update_core', 'unfiltered_html', 'unfiltered_upload', 'manage_network', 'manage_sites',
            'create_sites', 'delete_sites', 'upgrade_network', 'setup_network', 'manage_network_users',
            'manage_network_plugins', 'manage_network_themes', 'manage_network_options', 'add_users', 'promote_user',
            'edit_user', 'delete_user', 'deactivate_plugins', 'activate_plugin', 'deactivate_plugin', 'resume_plugin',
            'resume_theme', 'upload_plugins', 'upload_themes', 'install_languages', 'update_languages', 'edit_css',
            'customize', 'edit_theme_options', 'delete_site', 'update_php', 'update_https', 'manage_privacy_options',
            'export_others_personal_data', 'erase_others_personal_data',
        ], true);
    }
}
