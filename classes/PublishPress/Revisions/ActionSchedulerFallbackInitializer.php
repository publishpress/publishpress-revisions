<?php

/**
 * Initializes Action Scheduler when its classes load without the procedural API.
 *
 * @package     PublishPress\Revisions
 * @author      PublishPress
 * @copyright   Copyright (c) 2026, PublishPress
 * @license     GPLv2 or later
 */

namespace PublishPress\Revisions;

\defined('ABSPATH') || exit;

/**
 * Loads Action Scheduler functions when another plugin autoloaded the classes first.
 *
 * Action Scheduler 4.1 defines as_* functions only inside ActionScheduler::init().
 * Its version initializer skips init() when the class is already loaded, so the
 * store classes exist while as_get_scheduled_actions() does not.
 *
 * @since 4.11.0
 */
class ActionSchedulerFallbackInitializer
{
    /**
     * Bootstrap filename relative to an Action Scheduler library root.
     *
     * @since 4.11.0
     */
    private const BOOTSTRAP_FILENAME = 'action-scheduler.php';

    /**
     * Parent directories to search above the loaded ActionScheduler class file.
     *
     * The stock layout is classes/abstracts/ActionScheduler.php, three levels
     * below the library root. A small extra margin covers a slightly deeper nest.
     *
     * @since 4.11.0
     */
    private const MAX_BOOTSTRAP_DEPTH = 5;

    /**
     * Ensures the Action Scheduler procedural API is loaded.
     *
     * No-op when as_get_scheduled_actions() already exists. A second
     * ActionScheduler::init() would redeclare those functions and fatal.
     *
     * @return void
     * @since 4.11.0
     */
    public static function ensureInitialized(): void
    {
        if (function_exists('as_get_scheduled_actions')) {
            return;
        }

        // Do not autoload. Only initialize a class that is already in memory.
        if (! class_exists('ActionScheduler', false)) {
            return;
        }

        $classFile = (new \ReflectionClass('ActionScheduler'))->getFileName();

        if (! is_string($classFile) || $classFile === '') {
            return;
        }

        $bootstrapFile = self::resolveBootstrapFile($classFile);

        if ($bootstrapFile === null) {
            return;
        }

        \ActionScheduler::init($bootstrapFile);
    }

    /**
     * Use database defaults for unresolved hosting placeholders in our schema SQL.
     *
     * @param string $query SQL submitted through wpdb.
     * @return string SQL with only the known placeholder options removed.
     */
    public static function filterSchemaCharset(string $query): string
    {
        global $wpdb;

        $prefix = preg_quote($wpdb->prefix, '/');

        if (!preg_match('/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?`?'
            . $prefix . 'actionscheduler_(?:actions|claims|groups|logs)`?\s*\(/i', $query)) {
            return $query;
        }

        // Anchor to the table-options suffix so column definitions and data are untouched.
        return preg_replace_callback('/(\)\s*)([^)]*)$/s',
            static function ($matches) {
                $options = preg_replace('/\b(?:DEFAULT\s+)?CHARACTER\s+SET\s+WORDPRESS_DB_CHARSET\b/i', '', $matches[2]);
                $options = preg_replace('/\bCOLLATE\s+WORDPRESS_DB_COLLATE\b/i', '', $options);
                return $matches[1] . $options;
            }, $query);
    }

    /**
     * Repair missing tables before wp_loaded migration queries on the Plugins screen.
     */
    public static function ensureTablesOnPluginsScreen(): void
    {
        global $pagenow;

        if (is_admin() && 'plugins.php' === $pagenow && current_user_can('activate_plugins')) {
            self::ensureTables();
        }
    }

    /**
     * Recreate missing schemas even when their stored version is already current.
     *
     * Uses the selected Action Scheduler version's API; never drops existing tables.
     *
     * @return bool Whether both schemas are available after the repair attempt.
     */
    public static function ensureTables(): bool
    {
        if (!class_exists('ActionScheduler', false)) {
            return false;
        }

        $complete = true;

        foreach (['ActionScheduler_StoreSchema', 'ActionScheduler_LoggerSchema'] as $schemaClass) {
            if (!class_exists($schemaClass)
                || !method_exists($schemaClass, 'tables_exist')
                || !method_exists($schemaClass, 'register_tables')
            ) {
                $complete = false;
                continue;
            }

            $schema = new $schemaClass();

            if (!$schema->tables_exist()) {
                $schema->init();
                $schema->register_tables(true);

                if (!$schema->tables_exist()) {
                    $complete = false;
                }
            }
        }

        if (!$complete) {
            add_action('admin_notices', [self::class, 'tableCreationNotice']);
        }

        return $complete;
    }

    /** Report a failed repair instead of treating the schema-version option as proof. */
    public static function tableCreationNotice(): void
    {
        if (current_user_can('activate_plugins')) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('PublishPress Revisions could not create the required Action Scheduler database tables. Check the database error log and the database user\'s CREATE and ALTER permissions.', 'revisionary')
                . '</p></div>';
        }
    }

    /**
     * Locates a readable action-scheduler.php by walking up from a class file.
     *
     * @param string $classFile Absolute path to the loaded ActionScheduler class file.
     * @return string|null Readable bootstrap path, or null when none is found.
     * @since 4.11.0
     */
    public static function resolveBootstrapFile(string $classFile): ?string
    {
        $directory = dirname($classFile);

        for ($depth = 0; $depth < self::MAX_BOOTSTRAP_DEPTH; $depth++) {
            $candidate = $directory . '/' . self::BOOTSTRAP_FILENAME;

            if (is_readable($candidate)) {
                return $candidate;
            }

            $parent = dirname($directory);

            if ($parent === $directory) {
                break;
            }

            $directory = $parent;
        }

        return null;
    }
}
