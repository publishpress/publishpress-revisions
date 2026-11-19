<?php

/**
 * Applies compatibility adjustments to database schema queries.
 *
 * @package     PublishPress\Revisions
 * @author      PublishPress
 * @copyright   Copyright (c) 2026, PublishPress
 * @license     GPLv2 or later
 */

namespace PublishPress\Revisions;

\defined('ABSPATH') || exit;

final class DatabaseSchemaCompatibility
{
    /**
     * Use database defaults when WordPress charset/collation placeholders are unresolved.
     *
     * @param string $query SQL submitted through wpdb.
     * @return string SQL with only the known placeholder options removed.
     */
    public static function filterSchemaCharset(string $query): string
    {
        // Other plugins (including Elementor) receive the same unresolved hosting
        // placeholders. Restrict changes to CREATE TABLE options, never data queries.
        if (!preg_match('/^\s*CREATE\s+TABLE\s+/i', $query)) {
            return $query;
        }

        // Anchor to the table-options suffix so column definitions and data are untouched.
        return preg_replace_callback('/(\)\s*)([^)]*)$/s',
            static function ($matches) {
                $options = preg_replace('/\b(?:DEFAULT\s+)?(?:CHARACTER\s+SET|CHARSET)\s*(?:=\s*)?WORDPRESS_DB_CHARSET\b/i', '', $matches[2]);
                $options = preg_replace('/\bCOLLATE\s*(?:=\s*)?WORDPRESS_DB_COLLATE\b/i', '', $options);
                return $matches[1] . $options;
            }, $query);
    }
}
