<?php

/** Revision queue, editable copies, native transitions and archive operations. */

namespace PublishPress\Revisions\Mcp;

defined('ABSPATH') || exit;
class Revisions
{
    public static function register(): void
    {
        $access = static fn() => is_user_logged_in();
        $fields = ['id' => Registry::id(), 'post_id' => Registry::id(), 'title' => Registry::text(), 'content' => Registry::text(1000000), 'excerpt' => Registry::text(100000), 'status' => Registry::text(), 'slug' => Registry::text(200), 'parent' => ['type' => 'integer', 'minimum' => 0], 'post_type' => Registry::text(), 'date' => Registry::text(), 'date_gmt' => Registry::text(), 'author' => ['type' => 'integer'], 'revision_author' => ['type' => 'integer'], 'terms' => ['type' => 'object'], 'thumbnail_id' => ['type' => 'integer', 'minimum' => 0], 'template' => Registry::text(200), 'expected_hash' => Registry::revision(), 'parent_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean'], 'operation' => Registry::text(), 'complete' => ['type' => 'boolean'], 'applied_ids' => Registry::listOf(Registry::id()), 'failed_id' => Registry::id(), 'changes' => ['type' => 'object'], 'items' => ['type' => 'array', 'items' => ['type' => 'object']], 'has_more' => ['type' => 'boolean'], 'choices' => ['type' => 'object']];
        $output = Registry::object($fields);
        Registry::add('get-content-choices', __('Discover Revisions features and choices', 'revisionary'), __('Discover separate native revision, archive and duplication post types and fields, revision statuses and active Pro/integration state. Supply post_id for source permissions, available templates and taxonomy assignment choices. Revisions are separate from the published source post. Native capabilities and source read access are enforced.', 'revisionary'), Registry::object(['post_id' => Registry::id()]), [self::class, 'choices'], $access, false, false, Registry::object(['choices' => ['type' => 'object']]));
        Registry::add('list-revisions', __('Find revisions and past revisions', 'revisionary'), __('Browse a bounded native revision queue or WordPress archive. Filter by source post, status, author or title. Every returned revision and source requires native read access. Other users’ restricted revisions are excluded.', 'revisionary'), Registry::object(Registry::page() + ['post_id' => Registry::id(), 'status' => ['type' => 'string', 'enum' => array_merge(['archive', 'trash'], rvy_revision_statuses())], 'author' => Registry::id(), 'search' => Registry::text(200)]), [self::class, 'listing'], $access);
        Registry::add('get-source-post', __('Read a revision source post', 'revisionary'), __('Read one supported revision, archive or duplication source and its revision token before creating a new copy. Requires source read access and native copy_post or edit_post. Does not expose passwords, authentication metadata or signed preview links.', 'revisionary'), Registry::object(['id' => Registry::id()], ['id']), [self::class, 'readSource'], $access, false, false, $output);
        Registry::add('get-revision', __('Read a revision', 'revisionary'), __('Read revision content, its native scheduling dates and terms, plus revisions for both the copy and source. Authentication metadata, passwords and signed preview links are excluded.', 'revisionary'), Registry::object(['id' => Registry::id()], ['id']), [self::class, 'read'], $access, false, false, $output);
        $changes = Registry::object(['title' => Registry::text(1000), 'content' => Registry::text(1000000), 'excerpt' => Registry::text(100000), 'slug' => Registry::text(200), 'author' => Registry::id(), 'parent' => ['type' => 'integer', 'minimum' => 0], 'date' => ['type' => 'string', 'format' => 'date-time'], 'thumbnail_id' => ['type' => 'integer', 'minimum' => 0], 'template' => Registry::text(200), 'terms' => ['type' => 'object', 'maxProperties' => 30]]);
        Registry::add('create-revision', __('Create a new revision', 'revisionary'), __('Create a native unsubmitted revision of one supported readable source using copy_post permission and the source expected_hash. Native metadata/taxonomy cloning, revision limits, builder gates and hooks apply. Omit changes to copy content unchanged. dry_run never creates a copy.', 'revisionary'), Registry::object(['post_id' => Registry::id(), 'expected_hash' => Registry::revision(), 'changes' => $changes, 'dry_run' => ['type' => 'boolean']], ['post_id', 'expected_hash']), [self::class, 'create'], $access, true, false, $output);
        Registry::add('duplicate-post', __('Duplicate a source post', 'revisionary'), __('Create a native draft duplicate of an enabled readable source post. Requires duplicate_post permission, the source expected_hash and native duplication settings. Copies the native configured fields, taxonomy and metadata through RevisionCreation. dry_run creates nothing.', 'revisionary'), Registry::object(['post_id' => Registry::id(), 'expected_hash' => Registry::revision(), 'dry_run' => ['type' => 'boolean']], ['post_id', 'expected_hash']), [self::class, 'duplicate'], $access, true, false, $output);
        Registry::add('update-revision', __('Update revision content', 'revisionary'), __('Patch only supplied editable revision fields using expected_hash. Native allowed fields, locks, term/author permissions and sanitizers apply. The published source is unchanged. Submitted or scheduled copies require their native edit permission; all edits to scheduled revisions require approval permission. dry_run validates without saving.', 'revisionary'), Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'changes' => $changes, 'dry_run' => ['type' => 'boolean']], ['id', 'expected_hash', 'changes']), [self::class, 'update'], $access, true, false, $output);
        $item = Registry::object(['id' => Registry::id(), 'expected_hash' => Registry::revision(), 'parent_hash' => Registry::revision()], ['id', 'expected_hash']);
        Registry::add('transition-revisions', __('Submit, approve or manage revisions', 'revisionary'), __('Apply one native action to up to 50 explicit revision IDs: submit, decline, approve, publish a scheduled revision now, unschedule, trash, restore archive or delete an archived revision. Publishing/restoring requires parent_hash and native source edit and approval permissions. All targets are validated before writes; partial failures report confirmed IDs. Approval honors the saved date, scheduling future changes. dry_run never publishes or sends notifications.', 'revisionary'), Registry::object(['operation' => ['type' => 'string', 'enum' => ['submit', 'decline', 'approve', 'publish', 'unschedule', 'trash', 'untrash', 'restore', 'delete']], 'items' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'items' => $item], 'dry_run' => ['type' => 'boolean']], ['operation', 'items']), [self::class, 'transition'], $access, true, true, $output);
        Registry::add('compare-revision', __('Compare revision content', 'revisionary'), __('Compare standard fields and taxonomy assignments with the readable source, or supply compare_to to compare two authorized revisions of the same source. Missing historical fields are omitted, not treated as cleared. Returns plain source/revision values, not executable previews or signed URLs. Pro metadata providers are discovered separately; credentials are never exported.', 'revisionary'), Registry::object(['id' => Registry::id(), 'compare_to' => Registry::id()], ['id']), [self::class, 'compare'], $access, false, false, Registry::object(['id' => Registry::id(), 'post_id' => Registry::id(), 'changes' => ['type' => 'object'], 'expected_hash' => Registry::revision(), 'parent_hash' => Registry::revision(), 'comparison_id' => Registry::id(), 'comparison_hash' => Registry::revision()]));
    }
    public static function choices(array $input = [])
    {
        $engine = $GLOBALS['revisionary'];
        $choices = ['post_types' => self::typeChoices($engine->enabled_post_types),
            'archive_post_types' => self::typeChoices($engine->enabled_post_types_archive),
            'duplicate_post_types' => self::typeChoices($engine->enabled_post_types_copy),
            'statuses' => rvy_revision_statuses(), 'pro_active' => Registry::pro(),
            'enabled_fields' => $engine->enabled_fields, 'duplicate_fields' => $engine->enabled_fields_copy,
            'integrations' => self::integrations()];
        if (isset($input['post_id'])) {
            $source = self::readSource(['id' => $input['post_id']]);
            if (is_wp_error($source)) {
                return $source;
            }
            $post = get_post($input['post_id']);
            $taxonomies = [];
            foreach (get_object_taxonomies($post->post_type, 'objects') as $taxonomy) {
                if ((!$taxonomy->public && !$taxonomy->show_ui) || str_starts_with($taxonomy->name, 'pp_notify_')) {
                    continue;
                }
                $field = in_array($taxonomy->name, ['category', 'post_tag'], true) ? $taxonomy->name : 'taxonomies';
                $taxonomies[$taxonomy->name] = ['label' => $taxonomy->label,
                    'can_assign' => current_user_can($taxonomy->cap->assign_terms) && ($engine->enabled_fields === true || !empty($engine->enabled_fields[$field]))];
            }
            $choices['source'] = ['id' => $post->ID, 'expected_hash' => $source['expected_hash'],
                'can_create_revision' => self::canReviseStatus($post) && !empty($engine->enabled_post_types[$post->post_type]) && current_user_can('copy_post', $post->ID) && rvy_post_revision_supported($post),
                'can_duplicate' => !empty($engine->enabled_post_types_copy[$post->post_type]) && current_user_can('duplicate_post', $post->ID),
                'templates' => (object) (['default' => __('Default template', 'revisionary')] + wp_get_theme()->get_page_templates($post, $post->post_type)),
                'taxonomies' => (object) $taxonomies];
        }
        return ['choices' => (object) $choices];
    }
    private static function typeChoices(array $enabled): object
    {
        $types = [];
        foreach ($enabled as $type => $active) {
            if ($active && ($object = get_post_type_object($type))) {
                $types[$type] = ['label' => $object->label, 'capabilities' => (object) $object->cap];
            }
        }
        return (object) $types;
    }
    private static function integrations(): array
    {
        $items = [];
        foreach (\PublishPress\Revisions::getDefinedIntegrations() as $integration) {
            $items[] = ['id' => $integration['id'], 'label' => wp_strip_all_tags($integration['title']), 'available' => !empty($integration['available'])];
        }
        return $items;
    }
    public static function snapshot($post): array
    {
        $keys = ['ID', 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_mime_type', 'post_type', 'post_date', 'post_date_gmt', 'post_author', 'post_parent', 'post_name', 'post_modified_gmt', 'comment_count', 'menu_order'];
        $terms = [];
        foreach (get_object_taxonomies($post->post_type === 'revision' ? get_post_type($post->post_parent) : $post->post_type, 'objects') as $taxonomy) {
            if ((!$taxonomy->public && !$taxonomy->show_ui) || strpos($taxonomy->name, 'pp_notify_') === 0) {
                continue;
            }
            $ids = wp_get_object_terms($post->ID, $taxonomy->name, ['fields' => 'ids']);
            if (!is_wp_error($ids)) {
                $terms[$taxonomy->name] = array_map('intval', $ids);
            }
        }
        $meta = get_post_meta($post->ID);
        unset($meta['_edit_lock'], $meta['_edit_last']);
        return ['post' => array_intersect_key((array) $post, array_fill_keys($keys, true)), 'terms' => $terms, 'meta' => $meta];
    }
    public static function source($revision)
    {
        return get_post($revision->post_type === 'revision' ? $revision->post_parent : rvy_post_id($revision->ID));
    }
    private static function canViewArchive(): bool
    {
        $adminCap = defined('SCOPER_CONTENT_ADMIN_CAP') ? SCOPER_CONTENT_ADMIN_CAP : 'activate_plugins';
        return current_user_can($adminCap) || current_user_can('view_revision_archive') || current_user_can('restore_revisions');
    }
    private static function permitted($revision): bool
    {
        if (!$revision || ($revision->post_type !== 'revision' && !rvy_in_revision_workflow($revision))) {
            return false;
        }
        $source = self::source($revision);
        if (!$source || empty(($revision->post_type === 'revision' ? $GLOBALS['revisionary']->enabled_post_types_archive : $GLOBALS['revisionary']->enabled_post_types)[$source->post_type])) {
            return false;
        }
        if ($revision->post_type === 'revision' && !self::canViewArchive() && !current_user_can('edit_post', $source->ID)) {
            return false;
        }
        if ($revision->post_mime_type === 'draft-revision' && (int) $revision->post_author !== get_current_user_id() && rvy_get_option('manage_unsubmitted_capability') && !current_user_can('manage_unsubmitted_revisions')) {
            return false;
        }
        return $source && current_user_can('read_post', $source->ID) && current_user_can('read_post', $revision->ID);
    }
    public static function listing(array $input)
    {
        $archive = ($input['status'] ?? '') === 'archive';
        if ($archive) {
            if (!self::canViewArchive()) {
                return Registry::denied();
            }
        } else {
            if (rvy_get_option('revision_queue_capability') && !current_user_can('manage_revision_queue')) {
                return Registry::denied();
            }
            if (!rvy_get_option('pending_revisions') && !rvy_get_option('scheduled_revisions')) {
                return Registry::error(__('Revision workflows are disabled.', 'revisionary'), 409);
            }
        }
        $size = $input['per_page'] ?? 20;
        $args = ['is_revisions_query' => true, 'post_type' => $archive ? 'revision' : array_keys($GLOBALS['revisionary']->enabled_post_types), 'post_status' => $archive ? 'inherit' : array_merge(['draft', 'pending'], rvy_revision_statuses()), 'posts_per_page' => 1001, 'offset' => 0, 's' => $input['search'] ?? '', 'required_operation' => 'edit', 'orderby' => 'modified', 'order' => 'DESC'];
        if (!$archive) {
            $args['post_mime_type'] = isset($input['status']) && $input['status'] !== 'trash' ? $input['status'] : rvy_revision_statuses();
            if (($input['status'] ?? '') === 'trash') {
                $args['post_status'] = 'trash';
            }
        }
        if (isset($input['author'])) {
            $args['author'] = $input['author'];
        }
        if (isset($input['post_id'])) {
            if (!current_user_can('read_post', $input['post_id'])) {
                return ['items' => [], 'has_more' => false];
            }
            if ($archive) {
                $args['post_parent'] = $input['post_id'];
            } else {
                $args['comment_count'] = ['value' => $input['post_id'], 'compare' => '='];
            }
        }
        $items = [];
        add_filter('posts_where', 'rvy_revision_mime_where');
        try {
            $query = new \WP_Query($args);
        } finally {
            remove_filter('posts_where', 'rvy_revision_mime_where');
        }
        if (count($query->posts) > 1000) {
            return Registry::error(__('Narrow the filters or use native administration for more than 1,000 matching revisions.', 'revisionary'), 413);
        }
        foreach ($query->posts as $post) {
            if (!self::permitted($post) || (isset($input['post_id']) && self::source($post)->ID !== $input['post_id'])) {
                continue;
            }
            $items[] = ['id' => $post->ID, 'post_id' => self::source($post)->ID, 'title' => $post->post_title, 'status' => $archive ? 'archive' : $post->post_mime_type];
        }
        $offset = (($input['page'] ?? 1) - 1) * $size;
        return ['items' => array_slice($items, $offset, $size), 'has_more' => count($items) > $offset + $size];
    }
    public static function read(array $input)
    {
        $post = get_post($input['id']);
        if (!self::permitted($post)) {
            return Registry::denied();
        }
        $source = self::source($post);
        $state = self::snapshot($post);
        $read = ['id' => $post->ID, 'post_id' => $source->ID, 'post_type' => $source->post_type, 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt, 'slug' => (string) (get_post_meta($post->ID, '_requested_slug', true) ?: $source->post_name), 'parent' => (int) ($post->post_type === 'revision' ? self::source($post)->post_parent : $post->post_parent), 'revision_author' => (int) $post->post_author, 'author' => (int) (get_post_meta($post->ID, '_rvy_author_selection', true) ?: $source->post_author), 'status' => $post->post_status === 'trash' ? 'trash' : ($post->post_type === 'revision' ? 'archive' : $post->post_mime_type), 'date' => $post->post_date, 'date_gmt' => $post->post_date_gmt, 'terms' => (object) $state['terms'], 'thumbnail_id' => (int) get_post_thumbnail_id($post->ID), 'template' => (string) get_post_meta($post->ID, '_wp_page_template', true), 'expected_hash' => Registry::hash($state), 'parent_hash' => Registry::hash(self::snapshot($source))];
        if ($post->post_type === 'revision') {
            unset($read['parent'], $read['author']);
            if (!get_post_meta($post->ID, '_requested_slug', true)) {
                unset($read['slug']);
            }
            if (!get_post_meta($post->ID, '_rvy_base_post_id', true)) {
                unset($read['terms']);
            }
            if (!metadata_exists('post', $post->ID, '_thumbnail_id')) {
                unset($read['thumbnail_id']);
            }
            if (!metadata_exists('post', $post->ID, '_wp_page_template')) {
                unset($read['template']);
            }
        }
        return $read;
    }
    private static function prepare($post, array $changes)
    {
        $map = ['title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name', 'parent' => 'post_parent', 'author' => 'post_author', 'date' => 'post_date'];
        $data = [];
        $enabled = $GLOBALS['revisionary']->enabled_fields;
        foreach ($changes as $key => $value) {
            if ($key === 'terms') {
                continue;
            }
            if (in_array($key, ['thumbnail_id', 'template'], true)) {
                $meta = $key === 'thumbnail_id' ? '_thumbnail_id' : '_wp_page_template';
                if (($enabled !== true && empty($enabled[$meta]))) {
                    return Registry::denied();
                }
                if ($key === 'thumbnail_id' && $value && (get_post_type($value) !== 'attachment' || !wp_attachment_is_image($value) || !current_user_can('read_post', $value))) {
                    return Registry::denied();
                }
                if ($key === 'template' && !in_array($value, ['', 'default'], true) && !array_key_exists($value, wp_get_theme()->get_page_templates($post, $post->post_type))) {
                    return Registry::error();
                }
                continue;
            }
            $field = $map[$key];
            if ($enabled !== true && empty($enabled[$field])) {
                return Registry::denied();
            }
            if ($key === 'date') {
                try {
                    $date = new \DateTimeImmutable($value);
                    $errors = \DateTimeImmutable::getLastErrors();
                    if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                        return Registry::error();
                    }
                } catch (\Exception $error) {
                    return Registry::error();
                }
                $data['post_date_gmt'] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
                $value = $date->setTimezone(wp_timezone())->format('Y-m-d H:i:s');
            } elseif ($key === 'author') {
                $type = get_post_type_object($post->post_type);
                if (!rvy_get_option('allow_post_author_revision') || !get_userdata($value) || !is_user_member_of_blog($value) || !user_can($value, $type->cap->edit_posts) || ($value !== get_current_user_id() && !current_user_can($type->cap->edit_others_posts))) {
                    return Registry::denied();
                }
                continue;
            } elseif ($key === 'parent') {
                $parent = $value ? get_post($value) : null;
                if (!is_post_type_hierarchical($post->post_type)) {
                    return Registry::error();
                }
                if ($value && (!$parent || rvy_in_revision_workflow($parent) || in_array($parent->post_status, ['trash', 'auto-draft', 'inherit'], true) || $parent->post_type !== $post->post_type || !current_user_can('read_post', $value) || $value === $post->ID || in_array((int) rvy_post_id($post->ID), array_map('intval', get_post_ancestors($value)), true) || $value === (int) rvy_post_id($post->ID))) {
                    return Registry::error();
                }
            } elseif ($key === 'slug') {
                $value = sanitize_title($value);
            } elseif (in_array($key, ['content', 'excerpt'], true)) {
                $value = current_user_can('unfiltered_html') ? $value : wp_kses_post($value);
            } elseif ($key === 'title') {
                $value = sanitize_text_field($value);
            }
            $data[$field] = $value;
        }
        foreach ($changes['terms'] ?? [] as $taxonomy => $ids) {
            $object = get_taxonomy($taxonomy);
            $valid = Registry::validate($ids, Registry::listOf(Registry::id()));
            if (is_wp_error($valid)) {
                return $valid;
            }
            if (!$object || (!$object->public && !$object->show_ui) || strpos($taxonomy, 'pp_notify_') === 0 || !in_array($post->post_type, $object->object_type, true) || !current_user_can($object->cap->assign_terms) || ($enabled !== true && empty($enabled[in_array($taxonomy, ['category', 'post_tag'], true) ? $taxonomy : 'taxonomies']))) {
                return Registry::denied();
            }
            foreach ($ids as $id) {
                if (!get_term($id, $taxonomy) || is_wp_error(get_term($id, $taxonomy)) || !current_user_can('assign_term', $id)) {
                    return Registry::denied();
                }
            }
        }
        return $data;
    }
    public static function readSource(array $input)
    {
        $post = get_post($input['id']);
        if (!$post || rvy_in_revision_workflow($post) || (empty($GLOBALS['revisionary']->enabled_post_types[$post->post_type]) && empty($GLOBALS['revisionary']->enabled_post_types_copy[$post->post_type]) && empty($GLOBALS['revisionary']->enabled_post_types_archive[$post->post_type])) || !current_user_can('read_post', $post->ID) || (!current_user_can('copy_post', $post->ID) && !current_user_can('edit_post', $post->ID) && !current_user_can('duplicate_post', $post->ID))) {
            return Registry::denied();
        }
        return ['id' => $post->ID, 'post_id' => $post->ID, 'title' => $post->post_title, 'content' => $post->post_content, 'excerpt' => $post->post_excerpt,
            'post_type' => $post->post_type, 'status' => $post->post_status, 'date' => $post->post_date, 'date_gmt' => $post->post_date_gmt,
            'author' => (int) $post->post_author, 'slug' => $post->post_name, 'parent' => (int) $post->post_parent, 'thumbnail_id' => (int) get_post_thumbnail_id($post->ID), 'template' => (string) get_post_meta($post->ID, '_wp_page_template', true), 'terms' => (object) self::snapshot($post)['terms'], 'expected_hash' => Registry::hash(self::snapshot($post))];
    }
    private static function canReviseStatus($post): bool
    {
        $status = $post ? get_post_status_object($post->post_status) : null;
        return $status && ($status->public || $status->private || (Registry::pro() && rvy_get_option('pending_revision_unpublished')));
    }
    public static function create(array $input)
    {
        $source = get_post($input['post_id']);
        if (!rvy_get_option('pending_revisions') && !rvy_get_option('scheduled_revisions')) {
            return Registry::error(__('Revision workflows are disabled.', 'revisionary'), 409);
        }
        if (!$source || !self::canReviseStatus($source) || rvy_in_revision_workflow($source) || empty($GLOBALS['revisionary']->enabled_post_types[$source->post_type]) || !current_user_can('read_post', $source->ID) || !current_user_can('copy_post', $source->ID) || !rvy_post_revision_supported($source)) {
            return Registry::denied();
        }
        $fresh = Registry::stale($input, self::snapshot($source));
        if (is_wp_error($fresh)) {
            return $fresh;
        }
        $data = self::prepare($source, $input['changes'] ?? []);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!empty($input['dry_run'])) {
            return ['post_id' => $source->ID, 'changes' => (object) ($input['changes'] ?? []), 'dry_run' => true];
        }
        $fresh = Registry::stale($input, self::snapshot(get_post($source->ID)));
        if (is_wp_error($fresh)) {
            return $fresh;
        }
        require_once RVY_ABSPATH . '/revision-creation_rvy.php';
        $creator = new \PublishPress\Revisions\RevisionCreation();
        $id = $creator->createRevision($source->ID, 'draft-revision', ['suppress_redirect' => true], $data);
        if (is_wp_error($id)) {
            return $id;
        } if (!$id || !is_numeric($id)) {
            return Registry::unconfirmed();
        }
        if (!empty($input['changes'])) {
// Native draft creation resets dates, slug and author. Apply editor changes only after that lifecycle.
            $updated = self::update(['id' => (int) $id, 'expected_hash' => Registry::hash(self::snapshot(get_post($id))), 'changes' => $input['changes']]);
            if (is_wp_error($updated)) {
                return new \WP_Error('revisions_created_partial', __('The revision was created but its edits were not confirmed. Read this revision before retrying.', 'revisionary'), ['id' => (int) $id, 'status' => 500]);
            }
            return $updated;
        }
        return self::read(['id' => (int) $id]);
    }
    public static function duplicate(array $input)
    {
        $source = get_post($input['post_id']);
        if (!$source || rvy_in_revision_workflow($source) || empty($GLOBALS['revisionary']->enabled_post_types_copy[$source->post_type]) || !current_user_can('read_post', $source->ID) || !current_user_can('duplicate_post', $source->ID)) {
            return Registry::denied();
        }
        $fresh = Registry::stale($input, self::snapshot($source));
        if (is_wp_error($fresh)) {
            return $fresh;
        }
        if (!empty($input['dry_run'])) {
            return ['post_id' => $source->ID, 'dry_run' => true];
        }
        require_once RVY_ABSPATH . '/revision-creation_rvy.php';
        $creator = new \PublishPress\Revisions\RevisionCreation();
        $id = $creator->copyPost($source->ID, 'draft', ['suppress_redirect' => true]);
        if (!is_int($id) || !get_post($id) || get_post_status($id) !== 'draft') {
            return Registry::unconfirmed();
        }
        return ['id' => $id, 'post_id' => $source->ID, 'status' => 'draft', 'expected_hash' => Registry::hash(self::snapshot(get_post($id)))];
    }
    private static function writeTerms(int $id, array $terms)
    {
        foreach ($terms as $taxonomy => $ids) {
            $result = wp_set_object_terms($id, $ids, $taxonomy, false);
            $actual = wp_get_object_terms($id, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($result) || is_wp_error($actual) || array_diff($ids, $actual) || array_diff($actual, $ids)) {
                return new \WP_Error('revisions_terms_unconfirmed', __('Some revision assignments could not be confirmed. Inspect the revision before retrying.', 'revisionary'), ['status' => 500, 'id' => $id]);
            }
        }
        return true;
    }
    public static function update(array $input)
    {
        $post = get_post($input['id']);
        if (!self::permitted($post) || $post->post_type === 'revision' || !current_user_can('edit_post', $post->ID)) {
            return Registry::denied();
        }
        $fresh = Registry::stale($input, self::snapshot($post));
        if (is_wp_error($fresh)) {
            return $fresh;
        }
        require_once ABSPATH . 'wp-admin/includes/post.php';
        if (wp_check_post_lock($post->ID)) {
            return Registry::error(__('This revision is locked by another editor.', 'revisionary'), 423);
        }
        if ($post->post_mime_type === 'future-revision' && !current_user_can('approve_revision', $post->ID)) {
            return Registry::denied();
        }
        $data = self::prepare($post, $input['changes']);
        if (is_wp_error($data)) {
            return $data;
        }
        if (!$input['changes']) {
            return self::read($input);
        }
        if (!empty($input['dry_run'])) {
            return ['id' => $post->ID, 'changes' => (object) $input['changes'], 'dry_run' => true];
        }
        require_once RVY_ABSPATH . '/admin/revision-action_rvy.php';
        $fresh = Registry::stale($input, self::snapshot(get_post($post->ID)));
        if (is_wp_error($fresh)) {
            return $fresh;
        }
        $saved = wp_update_post(wp_slash(['ID' => $post->ID] + $data), true);
        if (!$saved || is_wp_error($saved)) {
            return Registry::unconfirmed();
        }
        if (array_key_exists('author', $input['changes']) && !rvy_set_revision_author($post->ID, $input['changes']['author'])) {
            return Registry::unconfirmed();
        }
        if (array_key_exists('slug', $input['changes'])) {
            update_post_meta($post->ID, '_requested_slug', wp_slash($data['post_name']));
            if (get_post_meta($post->ID, '_requested_slug', true) !== $data['post_name']) {
                return Registry::unconfirmed();
            }
        }
        foreach (['thumbnail_id' => '_thumbnail_id', 'template' => '_wp_page_template'] as $field => $meta) {
            if (!array_key_exists($field, $input['changes'])) {
                continue;
            }
            $value = $input['changes'][$field];
            if ($field === 'thumbnail_id') {
                $value ? set_post_thumbnail($post->ID, $value) : delete_post_thumbnail($post->ID);
            } else {
                update_post_meta($post->ID, $meta, wp_slash($value));
            }
            if ((string) get_post_meta($post->ID, $meta, true) !== (string) ($field === 'thumbnail_id' && !$value ? '' : $value)) {
                return Registry::unconfirmed();
            }
        }
        $terms = self::writeTerms($post->ID, $input['changes']['terms'] ?? []);
        if (is_wp_error($terms)) {
            return $terms;
        }
        if (isset($data['post_date_gmt']) && $post->post_mime_type === 'future-revision') {
            rvy_reschedule_revision_publication($post->ID, $data['post_date_gmt']);
        }
        foreach ($data as $key => $value) {
            if ((string) get_post_field($key, $post->ID, 'raw') !== (string) $value) {
                return Registry::unconfirmed();
            }
        }
        return self::read($input);
    }
    private static function checkTransition(array $row, string $operation)
    {
        $post = get_post($row['id']);
        if (!self::permitted($post)) {
            return Registry::denied();
        }
        $fresh = Registry::stale($row, self::snapshot($post));
        if (is_wp_error($fresh)) {
            return $fresh;
        }
        $source = self::source($post);
        $archive = $post->post_type === 'revision';
        if (in_array($operation, ['approve', 'publish', 'restore'], true)) {
            if ($operation === 'restore' && !current_user_can('edit_post', $source->ID)) {
                return Registry::denied();
            }
            if (!isset($row['parent_hash'])) {
                return Registry::error(__('Read the source and supply its parent_hash before applying content.', 'revisionary'));
            }
            if (!hash_equals(Registry::hash(self::snapshot($source)), $row['parent_hash'])) {
                return Registry::error(__('The source changed. Read the revision and source again before applying content.', 'revisionary'), 409);
            }
        }
        if (in_array($operation, ['approve', 'publish'], true) && Registry::pro()) {
            $status = get_post_status_object($source->post_status);
            $willPublish = $status && !$status->public && !$status->private && ($post->post_mime_type === 'future-revision' || rvy_get_option('publish_by_revision'));
            $type = get_post_type_object($source->post_type);
            if ($willPublish && (!$type || !current_user_can($type->cap->publish_posts))) {
                return Registry::denied();
            }
        }
        if ($archive) {
            if (!in_array($operation, ['restore', 'delete'], true)) {
                return Registry::error();
            }
            if ($operation === 'delete' && (!rvy_get_option('revision_archive_deletion') || !current_user_can('delete_post', $source->ID))) {
                return Registry::denied();
            }
            if ($operation === 'restore' && rvy_get_option('revision_restore_require_cap') && !current_user_can('restore_revisions')) {
                return Registry::denied();
            }
        } else {
            if (($operation === 'submit' && $post->post_mime_type !== 'draft-revision') || ($operation === 'decline' && $post->post_mime_type !== 'pending-revision') || ($operation === 'approve' && !in_array($post->post_mime_type, ['draft-revision', 'pending-revision'], true))) {
                return Registry::error();
            }
            if ($post->post_status === 'trash' && !in_array($operation, ['untrash', 'delete'], true)) {
                return Registry::error();
            }
            if ($operation === 'untrash' && ($post->post_status !== 'trash' || !current_user_can('delete_post', $post->ID) || ($post->post_mime_type === 'future-revision' && (!current_user_can('approve_revision', $post->ID) || !isset($row['parent_hash']) || !hash_equals(Registry::hash(self::snapshot($source)), $row['parent_hash']))))) {
                return Registry::denied();
            }
            if ($operation === 'delete' && ($post->post_status !== 'trash' || !current_user_can('delete_post', $post->ID))) {
                return Registry::denied();
            }
            if (!current_user_can('edit_post', $post->ID)) {
                return Registry::denied();
            }
            if (in_array($operation, ['approve', 'publish', 'decline', 'unschedule'], true) && !current_user_can('approve_revision', $post->ID)) {
                return Registry::denied();
            }
            if ($operation === 'submit' && (!rvy_get_option('pending_revisions') || !current_user_can('set_revision_pending-revision', $post->ID))) {
                return Registry::denied();
            }
            if (in_array($operation, ['publish', 'unschedule'], true) && $post->post_mime_type !== 'future-revision') {
                return Registry::error();
            }
            if ($operation === 'trash' && (!current_user_can('delete_post', $post->ID) || (defined('EMPTY_TRASH_DAYS') && !EMPTY_TRASH_DAYS))) {
                return Registry::denied();
            }
            if ($operation === 'restore') {
                return Registry::error();
            }
            if ($operation === 'approve' && strtotime($post->post_date_gmt) > time() && !rvy_get_option('scheduled_revisions')) {
                return Registry::denied();
            }
        }
        return $post;
    }
    public static function transition(array $input)
    {
        $seen = [];
        foreach ($input['items'] as $row) {
            if (isset($seen[$row['id']])) {
                return Registry::error();
            } $post = self::checkTransition($row, $input['operation']);
            if (is_wp_error($post)) {
                return $post;
            } $seen[$row['id']] = true;
        }
        if (!empty($input['dry_run'])) {
            return ['dry_run' => true, 'operation' => $input['operation'], 'applied_ids' => []];
        }
        require_once RVY_ABSPATH . '/admin/revision-action_rvy.php';
        $done = [];
        foreach ($input['items'] as $row) {
            $post = self::checkTransition($row, $input['operation']);
            if (is_wp_error($post)) {
                return ['complete' => false, 'applied_ids' => $done, 'failed_id' => $row['id']];
            }
            try {
                switch ($input['operation']) {
                    case 'submit':
                        $result = rvy_revision_submit($post->ID);
                        break;
                    case 'decline':
                        $result = rvy_revision_decline($post->ID);
                        break;
                    case 'approve':
                        $result = rvy_revision_approve($post->ID);
                        break;
                    case 'publish':
                        $result = rvy_revision_publish($post->ID);
                        break;
                    case 'unschedule':
                        $result = rvy_revision_unschedule($post->ID);
                        break;
                    case 'trash':
                        $result = wp_trash_post($post->ID);
                        break;
                    case 'delete':
                        $result = $post->post_type === 'revision' ? wp_delete_post_revision($post->ID) : wp_delete_post($post->ID, true);
                        break;
                    case 'untrash':
                        $result = wp_untrash_post($post->ID);
                        break;
                    case 'restore':
                        $result = rvy_restore_archived_revision($post->ID);
                        break;
                }
            } catch (\Throwable $error) {
                $result = false;
            }
            $actual = get_post($row['id']);
            $confirmed = match ($input['operation']) {
                'submit' => $actual && $actual->post_mime_type === 'pending-revision',
                'decline', 'unschedule' => $actual && $actual->post_mime_type === 'draft-revision',
                'trash' => $actual && $actual->post_status === 'trash',
                'untrash' => $actual && $actual->post_status !== 'trash',
                'delete' => !$actual,
                'approve', 'publish' => !$actual || $actual->post_type === 'revision' || $actual->post_mime_type === 'future-revision',
                default => (bool) $result,
            };
            if (!$result || is_wp_error($result) || !$confirmed) {
                return ['complete' => false, 'applied_ids' => $done, 'failed_id' => $row['id']];
            }
            $done[] = $row['id'];
        }
        return ['complete' => true, 'applied_ids' => $done, 'dry_run' => false];
    }
    public static function compare(array $input)
    {
        $read = self::read($input);
        if (is_wp_error($read)) {
            return $read;
        }
        $source = self::source(get_post($input['id']));
        $base = ['title' => $source->post_title, 'content' => $source->post_content, 'excerpt' => $source->post_excerpt,
            'slug' => $source->post_name, 'parent' => (int) $source->post_parent, 'author' => (int) $source->post_author,
            'date' => $source->post_date, 'terms' => (object) self::snapshot($source)['terms'],
            'thumbnail_id' => (int) get_post_thumbnail_id($source->ID), 'template' => (string) get_post_meta($source->ID, '_wp_page_template', true)];
        if (isset($input['compare_to'])) {
            $base = self::read(['id' => $input['compare_to']]);
            if (is_wp_error($base)) {
                return $base;
            }
            if ($base['post_id'] !== $read['post_id']) {
                return Registry::error(__('Both revisions must belong to the same source.', 'revisionary'));
            }
        }
        $changes = [];
        foreach (['title', 'content', 'excerpt', 'slug', 'parent', 'author', 'date', 'terms', 'thumbnail_id', 'template'] as $key) {
            if (array_key_exists($key, $read) && array_key_exists($key, $base) && ($key === 'terms' ? (array) $base[$key] != (array) $read[$key] : $base[$key] !== $read[$key])) {
                $changes[$key] = ['source' => $base[$key], 'revision' => $read[$key]];
            }
        }
        $result = ['id' => $read['id'], 'post_id' => $source->ID, 'changes' => (object) $changes,
            'expected_hash' => $read['expected_hash'], 'parent_hash' => $read['parent_hash']];
        if (isset($input['compare_to'])) {
            $result['comparison_id'] = $input['compare_to'];
            $result['comparison_hash'] = $base['expected_hash'];
        }
        return $result;
    }
}
