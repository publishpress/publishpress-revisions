<?php

/** Execute only against isolated revisions_mcp_* databases and released Adapter. */

if (!defined('REST_REQUEST')) {
    define('REST_REQUEST', true);
}
require $argv[1];
if (strpos(DB_NAME, 'revisions_mcp_') !== 0) {
    throw new RuntimeException('Isolated Revisions database required.');
}
require $argv[2] . '/vendor/autoload.php';
wp_set_current_user(1);
$assertions = 0;
function check($value, $message)
{
    global $assertions;
    ++$assertions;
    if (!$value) {
        throw new RuntimeException($message);
    }
}
function callRevisions($name, $input = [])
{

    $ability = wp_get_ability('publishpress-revisions/' . $name);
    check($ability !== null, 'Missing ability ' . $name);
    $tool = \WP\MCP\Domain\Tools\McpTool::fromAbility($ability);
    check(!is_wp_error($tool), 'Adapter rejected ' . $name);
    return $tool->execute($input);
}
function success($value, $message)
{
    check(!is_wp_error($value), $message . (is_wp_error($value) ? ': ' . $value->get_error_message() : ''));
    return $value;
}
$choices = success(callRevisions('get-content-choices'), 'Content choices');
check(isset($choices['choices']->post_types->post), 'Post type choices missing');
$settings = success(callRevisions('get-plugin-settings'), 'Settings discovery');
foreach ($settings['schema']['properties'] as $key => $schema) {
    $validated = \PublishPress\Revisions\Mcp\Registry::validate($settings['settings']->$key, $schema);
    check(!is_wp_error($validated), 'Current setting conflicts with schema ' . $key);
}
$sourceId = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Revisions source fixture', 'post_content' => 'Original content', 'post_author' => 1]);
$hash = \PublishPress\Revisions\Mcp\Registry::hash(\PublishPress\Revisions\Mcp\Revisions::snapshot(get_post($sourceId)));
$preview = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $hash, 'changes' => (object) ['title' => 'Revision title'], 'dry_run' => true]), 'Create preview');
check(get_post_field('post_title', $sourceId) === 'Revisions source fixture', 'Preview changed source');
$revision = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $hash, 'changes' => (object) ['title' => 'Revision title']]), 'Create revision');
check($revision['title'] === 'Revision title' && $revision['status'] === 'draft-revision', 'Native draft mismatch');
$selectedAuthorId = wp_insert_user(['user_login' => 'revision_selected_author', 'user_pass' => 'synthetic-only', 'role' => 'editor']);
$revision = success(callRevisions('update-revision', ['id' => $revision['id'], 'expected_hash' => $revision['expected_hash'], 'changes' => (object) ['content' => 'Revised content \\path', 'slug' => 'mcp-revision-slug', 'author' => $selectedAuthorId]]), 'Update revision');
check($revision['content'] === 'Revised content \\path' && get_post_field('post_content', $sourceId) === 'Original content', 'Revision edit changed source or escaped content');
$compare = success(callRevisions('compare-revision', ['id' => $revision['id']]), 'Compare native fields');
check(isset($compare['changes']->content), 'Content difference omitted');
$submitted = success(callRevisions('transition-revisions', ['operation' => 'submit', 'items' => [(object) ['id' => $revision['id'], 'expected_hash' => $revision['expected_hash']]]]), 'Submit revision');
check($submitted['complete'], 'Native submission unconfirmed');
$revision = success(callRevisions('get-revision', ['id' => $revision['id']]), 'Read submitted revision');
check($revision['status'] === 'pending-revision', 'Native submitted status differs');
success(callRevisions('transition-revisions', ['operation' => 'approve', 'items' => [(object) ['id' => $revision['id'], 'expected_hash' => $revision['expected_hash'], 'parent_hash' => $revision['parent_hash']]]]), 'Approve revision');
check(get_post_field('post_content', $sourceId) === 'Revised content \\path', 'Native approval did not apply revision');
check(get_post_field('post_name', $sourceId) === 'mcp-revision-slug', 'Requested revision slug did not publish');
check((int) get_post_field('post_author', $sourceId) === $selectedAuthorId, 'Selected post author did not publish');

// Native setting round trips, strict JSON types and mixed invalid patches.
$state = success(callRevisions('get-plugin-settings'), 'Typed settings');
foreach ($state['schema']['properties'] as $key => $field) {
    $value = $state['settings']->$key;
    $before = get_option('rvy_' . $key, null);
    success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) [$key => $value], 'dry_run' => true]), 'Preview ' . $key);
    check(get_option('rvy_' . $key, null) === $before, 'Preview wrote ' . $key);
    $state = success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) [$key => $value]]), 'Save ' . $key);
    $read = success(callRevisions('get-plugin-settings'), 'Read back ' . $key);
    check($read['settings']->$key == $state['settings']->$key, 'Native setting changed representation ' . $key);
    check(is_wp_error(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) [$key => $field['type'] === 'boolean' ? 'false' : ($field['type'] === 'integer' ? '12' : true)]])), 'Malformed value accepted ' . $key);
}
$oldHints = rvy_get_option('display_hints');
check(is_wp_error(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['display_hints' => false, 'unknown' => true]])), 'Unknown mixed settings accepted');
check(rvy_get_option('display_hints') == $oldHints, 'Mixed invalid settings wrote');
$denyManage = static function ($caps, $cap) {
    return $cap === 'manage_options' ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $denyManage, PHP_INT_MAX, 2);
check(is_wp_error(callRevisions('get-plugin-settings')), 'Administrator bypassed denied setting capability');
remove_filter('map_meta_cap', $denyManage, PHP_INT_MAX);
$sourceState = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Source discovery token');
$scheduled = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash'], 'changes' => (object) ['title' => 'Scheduled revision', 'date' => '2030-01-01T12:00:00Z']]), 'Create future-dated copy');
$scheduledResult = success(callRevisions('transition-revisions', ['operation' => 'approve', 'items' => [(object) ['id' => $scheduled['id'], 'expected_hash' => $scheduled['expected_hash'], 'parent_hash' => $scheduled['parent_hash']]]]), 'Schedule native revision');
check($scheduledResult['complete'] && get_post_field('post_mime_type', $scheduled['id']) === 'future-revision', 'Native schedule failed');
$scheduled = callRevisions('get-revision', ['id' => $scheduled['id']]);
$denyApprove = static function ($caps, $cap) {
    return $cap === 'approve_revision' ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $denyApprove, PHP_INT_MAX, 2);
check(is_wp_error(callRevisions('transition-revisions', ['operation' => 'publish', 'items' => [(object) ['id' => $scheduled['id'], 'expected_hash' => $scheduled['expected_hash'], 'parent_hash' => $scheduled['parent_hash']]]])), 'Administrator bypassed approval denial');
remove_filter('map_meta_cap', $denyApprove, PHP_INT_MAX);
$unscheduled = success(callRevisions('transition-revisions', ['operation' => 'unschedule', 'items' => [(object) ['id' => $scheduled['id'], 'expected_hash' => $scheduled['expected_hash']]]]), 'Native unschedule');
check($unscheduled['complete'] && get_post_field('post_mime_type', $scheduled['id']) === 'draft-revision', 'Unschedule did not return draft');
$editCopy = success(callRevisions('get-revision', ['id' => $scheduled['id']]), 'Copy before native field edit');
$editCopy = success(callRevisions('update-revision', ['id' => $editCopy['id'], 'expected_hash' => $editCopy['expected_hash'], 'changes' => (object) ['template' => 'default', 'thumbnail_id' => 0]]), 'Native page fields');
check(get_post_meta($editCopy['id'], '_wp_page_template', true) === 'default', 'Native template did not persist');
// Real REST queue filtering and native capability policies.
$queue = success(callRevisions('list-revisions', ['post_id' => $sourceId]), 'Filtered queue');
check(in_array($scheduled['id'], array_column($queue['items'], 'id'), true), 'Filtered queue lost native working copy');
$denyQueue = static fn($caps, $cap) => $cap === 'manage_revision_queue' ? ['do_not_allow'] : $caps;
rvy_update_option('revision_queue_capability', 1);
rvy_refresh_options();
add_filter('map_meta_cap', $denyQueue, PHP_INT_MAX, 2);
check(is_wp_error(callRevisions('list-revisions')), 'Denied native queue capability bypassed');
success(callRevisions('list-revisions', ['status' => 'archive']), 'Archive access independent of working queue capability');
remove_filter('map_meta_cap', $denyQueue, PHP_INT_MAX);
rvy_update_option('revision_queue_capability', '');
rvy_refresh_options();
$sourceState = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Duplicate source token');
$duplicate = success(callRevisions('duplicate-post', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash'], 'dry_run' => true]), 'Duplicate preview');
check(!isset($duplicate['id']), 'Duplicate preview created a post');
$duplicate = success(callRevisions('duplicate-post', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash']]), 'Native draft duplication');
check(get_post_status($duplicate['id']) === 'draft' && !rvy_in_revision_workflow($duplicate['id']), 'Duplicate is not native draft');
$copy = success(callRevisions('get-revision', ['id' => $scheduled['id']]), 'Read editable copy');
check(is_wp_error(callRevisions('update-revision', ['id' => $copy['id'], 'expected_hash' => str_repeat('0', 64), 'changes' => (object) ['title' => 'Stale write']])), 'Stale write accepted');
check(is_wp_error(callRevisions('update-revision', ['id' => (string) $copy['id'], 'expected_hash' => $copy['expected_hash'], 'changes' => (object) ['title' => 'String ID']])), 'String ID accepted');
$beforeTitle = get_post_field('post_title', $copy['id']);
check(is_wp_error(callRevisions('update-revision', ['id' => $copy['id'], 'expected_hash' => $copy['expected_hash'], 'changes' => (object) ['title' => 'Invalid patch', 'unexpected' => false]])), 'Mixed invalid content accepted');
check(get_post_field('post_title', $copy['id']) === $beforeTitle, 'Mixed invalid patch wrote');
$trash = success(callRevisions('transition-revisions', ['operation' => 'trash', 'items' => [(object) ['id' => $copy['id'], 'expected_hash' => $copy['expected_hash']]]]), 'Trash copy');
check($trash['complete'] && get_post_status($copy['id']) === 'trash', 'Trash not persisted');
$trashed = success(callRevisions('get-revision', ['id' => $copy['id']]), 'Read trashed copy');
$untrash = success(callRevisions('transition-revisions', ['operation' => 'untrash', 'items' => [(object) ['id' => $copy['id'], 'expected_hash' => $trashed['expected_hash']]]]), 'Untrash draft');
check($untrash['complete'] && get_post_status($copy['id']) !== 'trash', 'Untrash not persisted');
$archiveId = wp_save_post_revision($sourceId);
if ($archiveId) {
    $archived = success(callRevisions('get-revision', ['id' => $archiveId]), 'Read archive');
    $restore = success(callRevisions('transition-revisions', ['operation' => 'restore', 'items' => [(object) ['id' => $archiveId, 'expected_hash' => $archived['expected_hash'], 'parent_hash' => $archived['parent_hash']],], 'dry_run' => true]), 'Restore preview');
    check($restore['dry_run'], 'Restore preview missing');
}
// Native editor locks and actor-specific object permissions.
$copy = success(callRevisions('get-revision', ['id' => $scheduled['id']]), 'Lock fixture copy');
$subscriberId = wp_insert_user(['user_login' => 'revisions_reader', 'user_pass' => 'synthetic-fixture-only', 'role' => 'subscriber']);
update_post_meta($copy['id'], '_edit_lock', time() . ':' . $subscriberId);
check(is_wp_error(callRevisions('update-revision', ['id' => $copy['id'], 'expected_hash' => $copy['expected_hash'], 'changes' => (object) ['title' => 'Locked']])), 'Another editor lock bypassed');
delete_post_meta($copy['id'], '_edit_lock');
wp_set_current_user($subscriberId);
check(is_wp_error(callRevisions('get-plugin-settings')), 'Subscriber read settings');
check(is_wp_error(callRevisions('get-source-post', ['id' => $sourceId])), 'Subscriber read revision source tool');
check(is_wp_error(callRevisions('get-revision', ['id' => $copy['id']])), 'Subscriber read another user working copy');
wp_set_current_user(1);
$beforeContent = get_post_field('post_content', $sourceId);
$beforeCategories = wp_get_object_terms($sourceId, 'category', ['fields' => 'ids']);
require_once ABSPATH . 'wp-admin/includes/revision.php';
$archiveId = _wp_put_post_revision($sourceId);
check(is_int($archiveId) && $archiveId > 0, 'Archive fixture failed');
wp_update_post(['ID' => $sourceId, 'post_content' => 'Temporary source content']);
$archive = success(callRevisions('get-revision', ['id' => $archiveId]), 'Archive token');
$restored = success(callRevisions('transition-revisions', ['operation' => 'restore', 'items' => [(object) ['id' => $archiveId, 'expected_hash' => $archive['expected_hash'], 'parent_hash' => $archive['parent_hash']]]]), 'Native archive restore');
check($restored['complete'] && get_post_field('post_content', $sourceId) === $beforeContent, 'Archive restore did not apply content');
check(wp_get_object_terms($sourceId, 'category', ['fields' => 'ids']) === $beforeCategories, 'Core archive restore cleared unarchived categories');
check(!isset($archive['terms']) && !isset($archive['parent']), 'Core archive invented unavailable historical fields');
// A published working copy does retain native taxonomy relationships in its archive.
$settings = success(callRevisions('get-plugin-settings'), 'Archive retention fixture');
success(callRevisions('update-plugin-settings', ['expected_hash' => $settings['expected_hash'], 'settings' => (object) ['num_revisions' => 50]]), 'Retain workflow archive');
$term = wp_insert_term('Archived category fixture', 'category');
$sourceState = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Taxonomy revision source');
$termCopy = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash'], 'changes' => (object) ['terms' => (object) ['category' => [(int) $term['term_id']]]]]), 'Create taxonomy revision');
$applied = success(callRevisions('transition-revisions', ['operation' => 'approve', 'items' => [(object) ['id' => $termCopy['id'], 'expected_hash' => $termCopy['expected_hash'], 'parent_hash' => $termCopy['parent_hash']]]]), 'Publish taxonomy revision');
check($applied['complete'], 'Taxonomy revision publication failed');
$termArchive = success(callRevisions('get-revision', ['id' => $termCopy['id']]), 'Workflow archive taxonomy');
check($termArchive['terms']->category === [(int) $term['term_id']], 'Workflow archive omitted stored taxonomy');
wp_set_object_terms($sourceId, [1], 'category');
$termArchive = success(callRevisions('get-revision', ['id' => $termCopy['id']]), 'Restore taxonomy token');
$restored = success(callRevisions('transition-revisions', ['operation' => 'restore', 'items' => [(object) ['id' => $termCopy['id'], 'expected_hash' => $termArchive['expected_hash'], 'parent_hash' => $termArchive['parent_hash']]]]), 'Restore workflow taxonomy');
check($restored['complete'] && wp_get_object_terms($sourceId, 'category', ['fields' => 'ids']) === [(int) $term['term_id']], 'Native workflow taxonomy restore failed');
// Reject an indirect privileged grant and validate entire bulk requests before writes.
register_post_type('mcp_privileged', ['public' => true, 'show_ui' => true, 'capabilities' => ['edit_posts' => 'manage_options']]);
$settings = success(callRevisions('get-plugin-settings'), 'Privileged type discovery');
check(is_wp_error(callRevisions('update-plugin-settings', ['expected_hash' => $settings['expected_hash'], 'settings' => (object) ['enabled_post_types' => (object) ['mcp_privileged' => true]]])), 'Indirect administrator-level grant accepted');
unregister_post_type('mcp_privileged');
$copy = success(callRevisions('get-revision', ['id' => $scheduled['id']]), 'Bulk copy token');
$sourceState = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Second copy source');
$secondCopy = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash']]), 'Second bulk copy');
check(is_wp_error(callRevisions('transition-revisions', ['operation' => 'trash', 'items' => [(object) ['id' => $copy['id'], 'expected_hash' => $copy['expected_hash']], (object) ['id' => $secondCopy['id'], 'expected_hash' => str_repeat('0', 64)]]])), 'Mixed stale batch accepted');
check(get_post_status($copy['id']) !== 'trash', 'Invalid batch partially wrote during validation');
$failTrash = static fn($override, $target) => $target->ID === $secondCopy['id'] ? false : $override;
add_filter('pre_trash_post', $failTrash, 10, 2);
$partial = success(callRevisions('transition-revisions', ['operation' => 'trash', 'items' => [(object) ['id' => $copy['id'], 'expected_hash' => $copy['expected_hash']], (object) ['id' => $secondCopy['id'], 'expected_hash' => $secondCopy['expected_hash']]]]), 'Native partial failure');
remove_filter('pre_trash_post', $failTrash, 10);
check(!$partial['complete'] && $partial['applied_ids'] === [$copy['id']] && $partial['failed_id'] === $secondCopy['id'], 'Partial failure hid confirmed outcome');
// Retain a readable working copy for Pro comparison tests.
$scheduled = $secondCopy;
if (getenv('REVISIONS_MCP_MODE') === 'pro') {
    $copy = success(callRevisions('get-revision', ['id' => $scheduled['id']]), 'Comparison copy');
    update_post_meta($sourceId, '_api_secret', 'SENSITIVE-MCP-FIXTURE');
    update_post_meta($copy['id'], '_api_secret', 'OTHER-SENSITIVE-MCP-FIXTURE');
    $allowSensitive = static fn() => true;
    add_filter('revisionary_visual_compare_can_view_meta_key', $allowSensitive, 999);
    $fields = success(callRevisions('get-comparison-fields', ['id' => $copy['id']]), 'Native Pro field comparison');
    check(strpos(wp_json_encode($fields), 'SENSITIVE-MCP-FIXTURE') === false, 'Native comparison exposed sensitive metadata');
    remove_filter('revisionary_visual_compare_can_view_meta_key', $allowSensitive, 999);
    foreach (['ignored', 'suppressed'] as $scope) {
        $prefs = success(callRevisions('get-comparison-preferences', ['scope' => $scope]), 'Native comparison preference');
        success(callRevisions('update-comparison-preferences', ['scope' => $scope, 'fields' => ['synthetic_field'], 'expected_hash' => $prefs['expected_hash']]), 'Native preference save');
        $prefs = callRevisions('get-comparison-preferences', ['scope' => $scope]);
        check($prefs['fields'] === ['synthetic_field'], 'Native ignored/suppressed list did not persist');
    }
    $settings = callRevisions('get-plugin-settings');
    success(callRevisions('update-plugin-settings', ['expected_hash' => $settings['expected_hash'], 'settings' => (object) ['deletion_queue' => true]]), 'Enable native deletion queue');
    $deletionPost = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Deletion request fixture', 'post_author' => 1]);
    $deleteHash = success(callRevisions('get-deletion-request', ['id' => $deletionPost]), 'New deletion request discovery')['expected_hash'];
    success(callRevisions('manage-deletion-requests', ['operation' => 'request', 'items' => [(object) ['id' => $deletionPost, 'expected_hash' => $deleteHash]]]), 'Native deletion request');
    $requests = success(callRevisions('get-deletion-requests'), 'Native deletion queue');
    check(in_array($deletionPost, array_column($requests['items'], 'id'), true), 'Deletion request not listed');
    $row = array_values(array_filter($requests['items'], static fn($row) => $row['id'] === $deletionPost))[0];
    success(callRevisions('manage-deletion-requests', ['operation' => 'clear', 'items' => [(object) ['id' => $deletionPost, 'expected_hash' => $row['expected_hash']]]]), 'Clear native request');
    check(get_post($deletionPost) !== null && !in_array($deletionPost, (array) get_option('rvy_deletion_requests'), true), 'Clear deleted content or retained request');
    $request = success(callRevisions('get-deletion-request', ['id' => $deletionPost]), 'Deletion approval source');
    success(callRevisions('manage-deletion-requests', ['operation' => 'request', 'items' => [(object) ['id' => $deletionPost, 'expected_hash' => $request['expected_hash']]]]), 'Request before approval');
    $request = success(callRevisions('get-deletion-request', ['id' => $deletionPost]), 'Approval token');
    success(callRevisions('manage-deletion-requests', ['operation' => 'approve', 'items' => [(object) ['id' => $deletionPost, 'expected_hash' => $request['expected_hash']]], 'dry_run' => true]), 'Deletion approval preview');
    check(get_post($deletionPost) !== null, 'Deletion preview removed post');
    $deleted = success(callRevisions('manage-deletion-requests', ['operation' => 'approve', 'items' => [(object) ['id' => $deletionPost, 'expected_hash' => $request['expected_hash']]]]), 'Native deletion approval');
    check($deleted['complete'] && get_post($deletionPost) === null, 'Native deletion approval unconfirmed');
}
// Native duplication controls use their own thumbnail/template identifiers and exclusion list.
$attachment = wp_insert_attachment(['post_mime_type' => 'image/png', 'post_title' => 'Synthetic image fixture', 'post_status' => 'inherit']);
update_post_meta($attachment, '_wp_attached_file', 'mcp-fixture.png');
wp_update_attachment_metadata($attachment, ['width' => 1, 'height' => 1, 'file' => 'mcp-fixture.png', 'sizes' => []]);
check((bool) set_post_thumbnail($sourceId, $attachment), 'Image fixture setup failed');
update_post_meta($sourceId, '_wp_page_template', 'default');
update_post_meta($sourceId, 'mcp_unrelated_value', 'preserve native copying');
$state = success(callRevisions('get-plugin-settings'), 'Duplicate field schema');
check(isset($state['schema']['properties']['enabled_fields_copy']['properties']['post_thumbnail']) && isset($state['schema']['properties']['enabled_fields_copy']['properties']['post_template']), 'Native duplicate field names differ');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_fields_copy' => (object) ['post_thumbnail' => false, 'post_template' => false]]]), 'Exclude duplicate image/template');
$copySource = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Duplication source state');
$excludedCopy = success(callRevisions('duplicate-post', ['post_id' => $sourceId, 'expected_hash' => $copySource['expected_hash']]), 'Native duplicate exclusions');
check(!get_post_thumbnail_id($excludedCopy['id']) && get_post_meta($excludedCopy['id'], '_wp_page_template', true) === '', 'Disabled duplicate metadata copied');
check(get_post_meta($excludedCopy['id'], 'mcp_unrelated_value', true) === 'preserve native copying', 'Unrelated duplicate metadata lost');
$state = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_fields_copy' => (object) ['post_thumbnail' => true, 'post_template' => true]]]), 'Restore native duplicate controls');
$copySource = callRevisions('get-source-post', ['id' => $sourceId]);
$metadataCopy = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $copySource['expected_hash'], 'changes' => (object) ['thumbnail_id' => 0, 'template' => '']]), 'Clear working-copy metadata');
$state = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_fields' => (object) ['_thumbnail_id' => false, '_wp_page_template' => false]]]), 'Protect published metadata');
$metadataResult = success(callRevisions('transition-revisions', ['operation' => 'approve', 'items' => [(object) ['id' => $metadataCopy['id'], 'expected_hash' => $metadataCopy['expected_hash'], 'parent_hash' => $metadataCopy['parent_hash']]]]), 'Apply revision with excluded metadata');
check($metadataResult['complete'] && (int) get_post_thumbnail_id($sourceId) === (int) $attachment && get_post_meta($sourceId, '_wp_page_template', true) === 'default', 'Publication overwrote disabled native metadata');
$state = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_fields' => (object) ['_thumbnail_id' => true, '_wp_page_template' => true]]]), 'Restore revision metadata controls');
// Empty maps preserve native inherited-all storage rather than materializing a different policy.
rvy_update_option('enabled_fields_copy', true, false);
rvy_refresh_options();
$GLOBALS['revisionary']->setFieldsCopy();
$state = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_fields_copy' => (object) []]]), 'Empty native field patch');
check(get_option('rvy_enabled_fields_copy') === true, 'Empty map changed native inherited-all storage');
// Compare two authorized copies, and apply an explicitly cleared image through native publication.
$copySource = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Comparison source');
$comparisonCopy = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $copySource['expected_hash'], 'changes' => (object) ['content' => 'Pair comparison content', 'thumbnail_id' => 0]]), 'Comparison target copy');
$pair = success(callRevisions('compare-revision', ['id' => $comparisonCopy['id'], 'compare_to' => $metadataCopy['id']]), 'Compare working copy to native archive');
check($pair['comparison_id'] === $metadataCopy['id'] && isset($pair['changes']->content), 'Pair comparison lost its base or changed content');
$denyBase = static fn($caps, $cap, $userId, $args) => $cap === 'read_post' && (int) ($args[0] ?? 0) === $metadataCopy['id'] ? ['do_not_allow'] : $caps;
add_filter('map_meta_cap', $denyBase, PHP_INT_MAX, 4);
check(is_wp_error(callRevisions('compare-revision', ['id' => $comparisonCopy['id'], 'compare_to' => $metadataCopy['id']])), 'Pair comparison bypassed base permissions');
if (getenv('REVISIONS_MCP_MODE') === 'pro') {
    check(is_wp_error(callRevisions('get-comparison-fields', ['id' => $comparisonCopy['id'], 'compare_to' => $metadataCopy['id']])), 'Pro pair comparison bypassed base permissions');
}
remove_filter('map_meta_cap', $denyBase, PHP_INT_MAX);
if (getenv('REVISIONS_MCP_MODE') === 'pro') {
    success(callRevisions('get-comparison-fields', ['id' => $comparisonCopy['id'], 'compare_to' => $metadataCopy['id']]), 'Native Pro pair comparison');
}
$clearResult = success(callRevisions('transition-revisions', ['operation' => 'approve', 'items' => [(object) ['id' => $comparisonCopy['id'], 'expected_hash' => $comparisonCopy['expected_hash'], 'parent_hash' => $comparisonCopy['parent_hash']]]]), 'Publish explicitly cleared image');
check($clearResult['complete'] && !get_post_thumbnail_id($sourceId), 'Native publication retained explicitly cleared image');
// A duplication-only source must still provide a usable token.
$state = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_post_types' => (object) ['post' => false]]]), 'Disable revision creation for source type');
$duplicateOnly = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Duplication-only source token');
success(callRevisions('duplicate-post', ['post_id' => $sourceId, 'expected_hash' => $duplicateOnly['expected_hash'], 'dry_run' => true]), 'Duplication-only source preview');
check(is_wp_error(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $duplicateOnly['expected_hash'], 'dry_run' => true])), 'Disabled revision type permitted creation');
$state = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_post_types' => (object) ['post' => true]]]), 'Restore source revision type');
// Non-default string enums, null, zero and negative retention limits retain native types.
foreach (['pending_rev_notify_admin' => ['always', '0', '1'], 'pending_rev_notify_author' => ['always', '0'], 'revision_limit_per_post' => ['submitted', '1', '0'], 'num_revisions' => [null, 0, -1, 12], 'revision_editor_bg_color' => ['#123abc', '']] as $key => $values) {
    $state = callRevisions('get-plugin-settings');
    $original = $state['settings']->$key;
    foreach ($values as $value) {
        $state = success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) [$key => $value]]), 'Non-default native value ' . $key);
        check($state['settings']->$key === $value, 'Native value/type changed ' . $key);
    }
    success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) [$key => $original]]), 'Restore audited setting ' . $key);
}
// Derived listing capabilities and type-derived delete names must not grant administrator access.
foreach (['mcp_derived' => ['edit_published_posts' => 'manage_network_users'], 'mcp_private' => ['edit_private_posts' => 'manage_capabilities_custom'], 'mcp_resume' => ['read_private_posts' => 'resume_plugins'], 'user' => []] as $typeName => $overrides) {
    register_post_type($typeName, ['public' => true, 'show_ui' => true, 'capability_type' => 'mcp_fixture', 'capabilities' => $overrides]);
    $state = callRevisions('get-plugin-settings');
    $oldTypes = get_option('rvy_enabled_post_types');
    check(is_wp_error(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_post_types' => (object) [$typeName => true]]])), 'Derived privileged grant accepted: ' . $typeName);
    check(get_option('rvy_enabled_post_types') === $oldTypes, 'Privileged type patch wrote before validation');
    unregister_post_type($typeName);
}
register_post_type('mcp_safe_type', ['public' => true, 'show_ui' => true, 'capability_type' => 'mcp_safe_item']);
$state = callRevisions('get-plugin-settings');
$allowedType = success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_post_types' => (object) ['mcp_safe_type' => true]]]), 'Enable safe native custom type');
check($allowedType['complete'] && !empty($GLOBALS['revisionary']->enabled_post_types['mcp_safe_type']), 'Safe custom type rejected');
$state = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['enabled_post_types' => (object) ['mcp_safe_type' => false]]]), 'Disable safe fixture type');
unregister_post_type('mcp_safe_type');
$unpublishedSource = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Unpublished edition gate', 'post_author' => 1]);
$unpublishedToken = success(callRevisions('get-source-post', ['id' => $unpublishedSource]), 'Unpublished duplication token');
$unpublishedAllowed = getenv('REVISIONS_MCP_MODE') === 'pro' && rvy_get_option('pending_revision_unpublished');
if (!$unpublishedAllowed) {
    check(is_wp_error(callRevisions('create-revision', ['post_id' => $unpublishedSource, 'expected_hash' => $unpublishedToken['expected_hash']])), 'Unpublished revision edition/feature gate bypassed');
    success(callRevisions('duplicate-post', ['post_id' => $unpublishedSource, 'expected_hash' => $unpublishedToken['expected_hash'], 'dry_run' => true]), 'Unpublished duplication remains available');
}
register_post_type('mcp_private_type', ['public' => false, 'show_ui' => false, 'capability_type' => 'mcp_private_item']);
$privateChoices = callRevisions('get-plugin-settings');
check(isset($privateChoices['schema']['properties']['enabled_post_types']['properties']['mcp_private_type']), 'Native private type choice omitted');
unregister_post_type('mcp_private_type');
wp_set_current_user($subscriberId);
check(is_wp_error(callRevisions('get-revision', ['id' => $metadataCopy['id']])), 'Subscriber read unpublished historical content through an archive ID');
check(is_wp_error(callRevisions('compare-revision', ['id' => $metadataCopy['id']])), 'Subscriber compared private revision history');
if (getenv('REVISIONS_MCP_MODE') === 'pro') {
    check(is_wp_error(callRevisions('get-comparison-fields', ['id' => $metadataCopy['id']])), 'Subscriber read Pro archive metadata');
}
wp_set_current_user(1);
// Audit regressions: per-action denied capabilities remain denied on an Administrator.
$sourceState = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Audit source');
$auditCopy = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash']]), 'Audit working copy');
$unmodifiedComparison = success(callRevisions('compare-revision', ['id' => $auditCopy['id']]), 'Unchanged native permalink comparison');
check(!isset($unmodifiedComparison['changes']->slug), 'Working-copy internal slug reported as a permalink change');
$updatesBefore = did_action('post_updated');
success(callRevisions('update-revision', ['id' => $auditCopy['id'], 'expected_hash' => $auditCopy['expected_hash'], 'changes' => (object) []]), 'Empty revision patch');
check(did_action('post_updated') === $updatesBefore, 'Empty revision patch triggered native writes');
$denials = [
    ['read_post', 'get-revision', ['id' => $auditCopy['id']]],
    ['copy_post', 'create-revision', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash'], 'dry_run' => true]],
    ['duplicate_post', 'duplicate-post', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash'], 'dry_run' => true]],
    ['edit_post', 'update-revision', ['id' => $auditCopy['id'], 'expected_hash' => $auditCopy['expected_hash'], 'changes' => (object) ['title' => 'Forbidden'], 'dry_run' => true]],
    ['set_revision_pending-revision', 'transition-revisions', ['operation' => 'submit', 'items' => [(object) ['id' => $auditCopy['id'], 'expected_hash' => $auditCopy['expected_hash']]], 'dry_run' => true]],
    ['delete_post', 'transition-revisions', ['operation' => 'trash', 'items' => [(object) ['id' => $auditCopy['id'], 'expected_hash' => $auditCopy['expected_hash']]], 'dry_run' => true]],
];
foreach ($denials as [$deniedCap, $action, $input]) {
    $denyCap = static fn($caps, $cap) => $cap === $deniedCap ? ['do_not_allow'] : $caps;
    add_filter('map_meta_cap', $denyCap, PHP_INT_MAX, 2);
    $denied = callRevisions($action, $input);
    remove_filter('map_meta_cap', $denyCap, PHP_INT_MAX);
    check(is_wp_error($denied) && ($denied->get_error_data()['status'] ?? 0) === 403, 'Denied native capability bypassed: ' . $deniedCap);
}
// Explicit category switches take precedence over the generic custom-taxonomies switch.
$settings = success(callRevisions('get-plugin-settings'), 'Taxonomy field controls');
$oldCategory = $settings['settings']->enabled_fields->category;
success(callRevisions('update-plugin-settings', ['expected_hash' => $settings['expected_hash'], 'settings' => (object) ['enabled_fields' => (object) ['category' => false, 'taxonomies' => true]]]), 'Disable native category field');
check(is_wp_error(callRevisions('update-revision', ['id' => $auditCopy['id'], 'expected_hash' => $auditCopy['expected_hash'], 'changes' => (object) ['title' => 'Must not save', 'terms' => (object) ['category' => [1]]]])), 'Custom taxonomy flag bypassed category exclusion');
check(get_post_field('post_title', $auditCopy['id']) !== 'Must not save', 'Invalid taxonomy patch partly saved');
$settings = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $settings['expected_hash'], 'settings' => (object) ['enabled_fields' => (object) ['category' => $oldCategory]]]), 'Restore category field');
// Scheduled content is already approved to execute; any modification requires approval authority.
$auditCopy = success(callRevisions('get-revision', ['id' => $auditCopy['id']]), 'Schedule audit token');
$auditCopy = success(callRevisions('update-revision', ['id' => $auditCopy['id'], 'expected_hash' => $auditCopy['expected_hash'], 'changes' => (object) ['date' => '2035-01-01T12:00:00Z']]), 'Schedule audit date');
success(callRevisions('transition-revisions', ['operation' => 'approve', 'items' => [(object) ['id' => $auditCopy['id'], 'expected_hash' => $auditCopy['expected_hash'], 'parent_hash' => $auditCopy['parent_hash']]]]), 'Schedule audit copy');
$auditCopy = success(callRevisions('get-revision', ['id' => $auditCopy['id']]), 'Scheduled audit copy');
$denyApprove = static fn($caps, $cap) => $cap === 'approve_revision' ? ['do_not_allow'] : $caps;
add_filter('map_meta_cap', $denyApprove, PHP_INT_MAX, 2);
check(is_wp_error(callRevisions('update-revision', ['id' => $auditCopy['id'], 'expected_hash' => $auditCopy['expected_hash'], 'changes' => (object) ['title' => 'Unapproved scheduled edit']])), 'Scheduled content edit bypassed denied approval');
remove_filter('map_meta_cap', $denyApprove, PHP_INT_MAX);
check(get_post_field('post_title', $auditCopy['id']) !== 'Unapproved scheduled edit', 'Denied scheduled edit wrote');
$choices = success(callRevisions('get-content-choices', ['post_id' => $sourceId]), 'Scoped content choices');
check(isset($choices['choices']->source['templates']) && isset($choices['choices']->duplicate_post_types->post), 'Native scoped/duplicate choices missing');
if (getenv('REVISIONS_MCP_MODE') === 'pro') {
    $state = success(callRevisions('get-plugin-settings'), 'Pro builder metadata');
    if (\RevisionaryBuilderCompare::settingsAvailable()) {
        check(isset($state['schema']['properties']['builder_compare_external_requests']), 'Native builder control missing');
        $old = $state['settings']->builder_compare_external_requests;
        success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['builder_compare_external_requests' => !$old]]), 'Builder option native save');
        check((bool) rvy_get_option('builder_compare_external_requests') === !$old, 'Builder option native read-back differs');
    }
    $draftSource = wp_insert_post(['post_type' => 'post', 'post_status' => 'draft', 'post_title' => 'Unpublished permission fixture', 'post_author' => 1]);
    $state = callRevisions('get-plugin-settings');
    success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['pending_revision_unpublished' => true, 'publish_by_revision' => true]]), 'Enable native unpublished workflow');
    $draftState = success(callRevisions('get-source-post', ['id' => $draftSource]), 'Draft source');
    $draftCopy = success(callRevisions('create-revision', ['post_id' => $draftSource, 'expected_hash' => $draftState['expected_hash']]), 'Draft revision');
    $denyPublish = static fn($caps, $cap) => $cap === 'publish_posts' ? ['do_not_allow'] : $caps;
    add_filter('map_meta_cap', $denyPublish, PHP_INT_MAX, 2);
    check(is_wp_error(callRevisions('transition-revisions', ['operation' => 'approve', 'items' => [(object) ['id' => $draftCopy['id'], 'expected_hash' => $draftCopy['expected_hash'], 'parent_hash' => $draftCopy['parent_hash']]]])), 'Denied publication capability bypassed by Pro approval');
    remove_filter('map_meta_cap', $denyPublish, PHP_INT_MAX);
    check(get_post_status($draftSource) === 'draft', 'Denied Pro publication changed source status');
}
$state = callRevisions('get-plugin-settings');
$oldPending = $state['settings']->pending_revisions;
$oldScheduled = $state['settings']->scheduled_revisions;
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['pending_revisions' => false, 'scheduled_revisions' => false]]), 'Disable native workflows');
$sourceState = callRevisions('get-source-post', ['id' => $sourceId]);
check(is_wp_error(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash']])), 'Disabled workflows still created revision');
check(is_wp_error(callRevisions('list-revisions')), 'Disabled native working queue remained available');
success(callRevisions('list-revisions', ['status' => 'archive']), 'Archive remains available with workflows off');
$state = callRevisions('get-plugin-settings');
success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['pending_revisions' => $oldPending, 'scheduled_revisions' => $oldScheduled]]), 'Restore native workflows');
if (getenv('REVISIONS_MCP_MODE') === 'pro') {
    $state = callRevisions('get-plugin-settings');
    success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['revision_restore_require_cap' => true]]), 'Require native restore capability');
    $archiveToken = success(callRevisions('get-revision', ['id' => $metadataCopy['id']]), 'Restricted restore archive');
    $denyRestore = static fn($caps, $cap) => $cap === 'restore_revisions' ? ['do_not_allow'] : $caps;
    add_filter('map_meta_cap', $denyRestore, PHP_INT_MAX, 2);
    check(is_wp_error(callRevisions('transition-revisions', ['operation' => 'restore', 'items' => [(object) ['id' => $metadataCopy['id'], 'expected_hash' => $archiveToken['expected_hash'], 'parent_hash' => $archiveToken['parent_hash']]], 'dry_run' => true])), 'Denied configured restore capability bypassed');
    remove_filter('map_meta_cap', $denyRestore, PHP_INT_MAX);
    $state = callRevisions('get-plugin-settings');
    success(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['revision_restore_require_cap' => false]]), 'Restore native restore policy');
    $prefs = success(callRevisions('get-comparison-preferences', ['scope' => 'suppressed']), 'Suppressed preference revision');
    add_filter('map_meta_cap', $denyManage, PHP_INT_MAX, 2);
    check(is_wp_error(callRevisions('update-comparison-preferences', ['scope' => 'suppressed', 'expected_hash' => $prefs['expected_hash'], 'fields' => []])), 'Administrator bypassed global comparison preference capability');
    remove_filter('map_meta_cap', $denyManage, PHP_INT_MAX);
}
foreach (wp_get_abilities() as $name => $ability) {
    if (strpos($name, 'publishpress-revisions/') !== 0) {
        continue;
    }
    $wireSchema = json_decode(wp_json_encode($ability->get_input_schema()));
    check(!isset($wireSchema->properties) || is_object($wireSchema->properties), 'Object schema properties serialized as a JSON array');
    $meta = $ability->get_meta();
    check($meta['public'] === false && $meta['show_in_rest'] === false && $meta['mcp']['public'] === false, 'Public default-server exposure');
}
if (is_multisite()) {
    check(defined('RVY_NETWORK') && RVY_NETWORK, 'Network activation not exercised');
    $state = success(callRevisions('get-plugin-settings'), 'Network settings');
    check($state['schema']['properties']['display_hints']['network_controlled'], 'Network-controlled field not declared');
    $beforeNetwork = get_site_option('rvy_display_hints');
    $denyNetwork = static fn($caps, $cap) => $cap === 'manage_network_options' ? ['do_not_allow'] : $caps;
    add_filter('map_meta_cap', $denyNetwork, PHP_INT_MAX, 2);
    check(is_wp_error(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['display_hints' => !$state['settings']->display_hints]])), 'Network denial bypassed');
    remove_filter('map_meta_cap', $denyNetwork, PHP_INT_MAX);
    check(get_site_option('rvy_display_hints') === $beforeNetwork, 'Denied network setting wrote');
}
// Optional real application-password restriction service; source is supplied read-only by the runner.
$scopeFile = getenv('REVISIONS_MCP_CAPABILITIES_FILE');
if ($scopeFile && is_readable($scopeFile)) {
    require_once $scopeFile;
    update_option('cme_capabilities_application_password_capabilities', true);
    PP_Capabilities_Application_Password_Capabilities::instance();
    $scopeUserId = is_multisite() ? wp_insert_user(['user_login' => 'mcp_scope_admin', 'user_pass' => 'synthetic-only', 'role' => 'administrator']) : 1;
    wp_set_current_user($scopeUserId);
    $password = WP_Application_Passwords::create_new_application_password($scopeUserId, ['name' => 'Revisions isolated scope fixture']);
    check(!is_wp_error($password), 'Native application-password fixture creation failed');
    $uuid = $password[1]['uuid'];
    $key = pp_capabilities_application_password_storage_key($scopeUserId, $uuid);
    update_option(pp_capabilities_application_password_option_name(), [$key => ['user_id' => $scopeUserId, 'uuid' => $uuid, 'denied' => ['manage_options' => false]]]);
    do_action('application_password_did_authenticate', get_userdata($scopeUserId), $password[1]);
    check(is_wp_error(callRevisions('get-plugin-settings')), 'Actual application-password restriction bypassed');
    update_option(pp_capabilities_application_password_option_name(), []);
    update_option('cme_capabilities_application_password_capabilities', false);
    WP_Application_Passwords::delete_application_password($scopeUserId, $uuid);
    unset($password);
    wp_set_current_user(1);
}
// Stale source tokens are conflicts, not authentication failures, and never apply content.
$sourceState = success(callRevisions('get-source-post', ['id' => $sourceId]), 'Conflict source');
$conflictCopy = success(callRevisions('create-revision', ['post_id' => $sourceId, 'expected_hash' => $sourceState['expected_hash']]), 'Conflict copy');
wp_update_post(['ID' => $sourceId, 'post_title' => 'Source changed after preview']);
$conflict = callRevisions('transition-revisions', ['operation' => 'approve', 'items' => [(object) ['id' => $conflictCopy['id'], 'expected_hash' => $conflictCopy['expected_hash'], 'parent_hash' => $conflictCopy['parent_hash']]]]);
check(is_wp_error($conflict) && ($conflict->get_error_data()['status'] ?? 0) === 409, 'Stale source reported as permission/authentication failure');
check(get_post_field('post_mime_type', $conflictCopy['id']) === 'draft-revision', 'Stale source conflict applied revision');
if (getenv('REVISIONS_MCP_MODE') === 'pro') {
    $savedRequests = get_option('rvy_deletion_requests');
    $hiddenRequests = [];
    foreach (['Hidden request one', 'Hidden request two'] as $title) {
        $hiddenRequests[] = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1]);
    }
    $visibleRequest = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'Visible request fixture', 'post_author' => 1]);
    update_option('rvy_deletion_requests', array_merge($hiddenRequests, [$visibleRequest]));
    $hiddenIds = array_fill_keys($hiddenRequests, true);
    $hideRequests = static fn($caps, $cap, $userId, $args) => $cap === 'read_post' && isset($hiddenIds[(int) ($args[0] ?? 0)]) ? ['do_not_allow'] : $caps;
    add_filter('map_meta_cap', $hideRequests, PHP_INT_MAX, 4);
    $page = success(callRevisions('get-deletion-requests', ['per_page' => 1]), 'Permission-filtered request page');
    check(array_column($page['items'], 'id') === [$visibleRequest] && $page['has_more'] === false, 'Unreadable requests leaked into pagination or hid a readable request');
    check(strpos(wp_json_encode($page), 'Hidden request') === false, 'Unreadable request title exposed');
    remove_filter('map_meta_cap', $hideRequests, PHP_INT_MAX);
    $oldRequest = success(callRevisions('get-deletion-request', ['id' => $visibleRequest]), 'Deletion conflict token');
    wp_update_post(['ID' => $visibleRequest, 'post_title' => 'Request changed after read']);
    $conflict = callRevisions('manage-deletion-requests', ['operation' => 'clear', 'items' => [(object) ['id' => $visibleRequest, 'expected_hash' => $oldRequest['expected_hash']]]]);
    check(is_wp_error($conflict) && ($conflict->get_error_data()['status'] ?? 0) === 409, 'Stale deletion request reported as authentication failure');
    check(in_array($visibleRequest, (array) get_option('rvy_deletion_requests'), true), 'Stale request conflict wrote');
    update_option('rvy_deletion_requests', range(1000000, 1001000));
    $oversize = callRevisions('get-deletion-requests');
    check(is_wp_error($oversize) && ($oversize->get_error_data()['status'] ?? 0) === 413, 'Oversized request queue was not bounded');
    update_option('rvy_deletion_requests', $savedRequests);
}
// Permission filtering precedes pagination, including when inaccessible copies lead the query.
$queueSource = wp_insert_post(['post_title' => 'Pagination source', 'post_status' => 'publish', 'post_type' => 'post']);
$queueSourceData = success(callRevisions('get-source-post', ['id' => $queueSource]), 'Pagination source');
$queueCopies = [];
foreach (range(1, 3) as $number) {
    $queueSourceData = success(callRevisions('get-source-post', ['id' => $queueSource]), 'Fresh pagination source');
    $queueCopies[] = success(callRevisions('create-revision', ['post_id' => $queueSource, 'expected_hash' => $queueSourceData['expected_hash']]), 'Pagination copy')['id'];
}
$hideCopies = static function ($caps, $cap, $userId, $args) use ($queueCopies) {
    return $cap === 'read_post' && in_array((int) ($args[0] ?? 0), array_slice($queueCopies, 0, 2), true) ? ['do_not_allow'] : $caps;
};
add_filter('map_meta_cap', $hideCopies, PHP_INT_MAX, 4);
$queuePage = success(callRevisions('list-revisions', ['post_id' => $queueSource, 'per_page' => 1]), 'Readable revision page');
check(array_column($queuePage['items'], 'id') === [$queueCopies[2]] && !$queuePage['has_more'], 'Unreadable copies distorted revision pagination');
$queuePage = success(callRevisions('list-revisions', ['post_id' => $queueSource, 'per_page' => 1, 'page' => 2]), 'Readable revision second page');
check(!$queuePage['items'] && !$queuePage['has_more'], 'Revision pagination duplicated readable copies');
remove_filter('map_meta_cap', $hideCopies, PHP_INT_MAX);

// Native compatibility locks expose their effective value and reject mixed patches before writes.
if (!function_exists('relevanssi_query')) {
    function relevanssi_query()
    {
    }
}
$state = success(callRevisions('get-plugin-settings'), 'Native async compatibility lock');
$hintsBefore = rvy_get_option('display_hints');
check($state['settings']->async_scheduled_publish === false && $state['schema']['properties']['async_scheduled_publish']['enum'] === [false], 'Native async lock not discovered');
check(is_wp_error(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['display_hints' => !$hintsBefore, 'async_scheduled_publish' => true]])), 'Native async compatibility lock bypassed');
check(rvy_get_option('display_hints') == $hintsBefore, 'Compatibility-invalid patch partially wrote');
if (!defined('REVISIONARY_DISABLE_VISUAL_COMPARE')) {
    define('REVISIONARY_DISABLE_VISUAL_COMPARE', true);
}
$state = success(callRevisions('get-plugin-settings'), 'Native visual compare lock');
check($state['settings']->visual_compare === false && $state['schema']['properties']['visual_compare']['enum'] === [false], 'Native visual comparison lock not discovered');
check(is_wp_error(callRevisions('update-plugin-settings', ['expected_hash' => $state['expected_hash'], 'settings' => (object) ['visual_compare' => true]])), 'Native visual comparison constant bypassed');
printf("Revisions %s: %d assertions passed through released Adapter.\n", getenv('REVISIONS_MCP_MODE'), $assertions);
