<?php
namespace PublishPress_Statuses { class Revisions {} }
namespace {
define('ABSPATH', __DIR__);
$fixture = sys_get_temp_dir() . '/rvy-statuses-' . uniqid();
mkdir($fixture . '/admin', 0777, true);
file_put_contents($fixture . '/admin/revision-action_rvy.php', '<?php');
define('REVISIONARY_FILE', $fixture . '/revisionary.php');
register_shutdown_function(static function () use ($fixture) {
    unlink($fixture . '/admin/revision-action_rvy.php');
    rmdir($fixture . '/admin');
    rmdir($fixture);
});

class WP_Error {
    public $code;
    public function __construct($code, $message = '', $data = []) { $this->code = $code; }
}
class WP_REST_Response {
    public $data;
    private $status;
    public function __construct($data = [], $status = 200) { $this->data = $data; $this->status = $status; }
    public function get_status() { return $this->status; }
}
class TestRequest {
    private $params;
    public $id;
    public $method = 'PUT';
    public function __construct($params = [], $id = 1057) {
        $this->id = $id;
        $this->params = array_merge(['id' => $id, 'status' => 'publish', 'pp_statuses_selecting_workflow' => true, 'pp_workflow_action' => 'max'], $params);
    }
    public function get_method() { return $this->method; }
    public function get_url_params() { return ['id' => $this->id]; }
    public function get_param($name) { return $this->params[$name] ?? null; }
    public function set_param($name, $value) { $this->params[$name] = $value; }
}
class WP_REST_Posts_Controller {
    public function prepare_item_for_response($post, $request) {
        return new WP_REST_Response(['id' => $post->ID, 'status' => $post->post_status, 'pp_post_mime_type' => $post->post_mime_type, 'content' => $post->post_content]);
    }
}
class TestType {
    public $name = 'page';
    public function get_rest_controller() { return $GLOBALS['controller']; }
}
class PublishPress_Statuses {
    public $workflow_disabled = false;
    public $workflow_by_sequence = false;
    public static function instance() { return $GLOBALS['statuses']; }
    public static function canBypassStatusSequence($type) { return false; }
    public static function defaultStatusProgression($id, $args) { $GLOBALS['progression_args'] = $args; return $GLOBALS['target']; }
    public static function haveStatusPermission($op, $type, $status) { return true; }
}
function __($s, $domain = '') { return $s; }
function absint($v) { return abs((int) $v); }
function is_wp_error($v) { return $v instanceof WP_Error; }
function rest_sanitize_boolean($v) { return !in_array($v, [false, null, '', 0, '0', 'false'], true); }
function get_post($id) { return $GLOBALS['posts'][$id] ?? null; }
function get_post_type_object($type) { return $type === 'page' ? new TestType() : null; }
function rvy_in_revision_workflow($post) {
    $post = is_object($post) ? $post : get_post($post);
    return $post && $post->post_type === 'page' && in_array($post->post_mime_type, ['draft-revision', 'pending-revision'], true) && $post->post_status !== 'publish';
}
function current_user_can($cap, $id = 0) {
    $GLOBALS['cap_checks'][] = [$cap, $id];
    return $id === 1057 && ($GLOBALS['caps'][$cap] ?? false);
}
function is_content_administrator_rvy() { return $GLOBALS['administrator']; }
function apply_filters($hook, $value, ...$args) { return $value; }
function add_filter(...$args) { $GLOBALS['registrations'][] = $args; }
function add_action(...$args) { $GLOBALS['registrations'][] = $args; }
function clean_post_cache($id) {}
function rvy_get_option($name) { return $GLOBALS['compat']; }
function rvy_revision_submit($id) {
    $GLOBALS['submissions'][] = $id;
    if ($GLOBALS['submit_failure']) { return false; }
    $GLOBALS['posts'][$id]->post_mime_type = 'pending-revision';
    $GLOBALS['posts'][$id]->post_status = $GLOBALS['compat'] ? 'pending-revision' : 'pending';
    return true;
}
require dirname(__DIR__) . '/admin/StatusesRestSubmission.php';
use PublishPress\Revisions\StatusesRestSubmission as Bridge;

function check($condition, $message) {
    if (!$condition) { throw new \RuntimeException($message); }
    $GLOBALS['assertions']++;
}
function reset_case() {
    $GLOBALS['controller'] = new WP_REST_Posts_Controller();
    $GLOBALS['statuses'] = new PublishPress_Statuses();
    $GLOBALS['caps'] = ['edit_post' => true, 'set_revision_pending-revision' => true];
    $GLOBALS['administrator'] = $GLOBALS['compat'] = $GLOBALS['submit_failure'] = false;
    $GLOBALS['target'] = 'pending-revision';
    $GLOBALS['submissions'] = $GLOBALS['cap_checks'] = [];
    $GLOBALS['posts'] = [
        1057 => (object) ['ID' => 1057, 'post_type' => 'page', 'post_status' => 'draft', 'post_mime_type' => 'draft-revision', 'post_content' => 'Old', 'post_parent' => 0],
        100 => (object) ['ID' => 100, 'post_type' => 'page', 'post_status' => 'publish', 'post_mime_type' => '', 'post_content' => 'Live'],
    ];
}
function handler() { return ['callback' => [$GLOBALS['controller'], 'update_item']]; }
function finish($request, $error = false, $verified = true) {
    if (!$error) {
        $post = get_post(1057);
        $post->post_content = 'Saved content and metadata';
        if ($verified) { Bridge::saved($post, $request, false); }
    }
    return Bridge::complete($error ?: new WP_REST_Response(), handler(), $request);
}
$assertions = 0;
Bridge::register();
check($registrations[0][2] === 5, 'Normalize before Statuses binds REST parameters');
foreach ([false, true] as $compat_mode) {
    reset_case(); $compat = $compat_mode;
    if ($compat) { $posts[1057]->post_status = 'draft-revision'; }
    $request = new TestRequest();
    check(null === Bridge::prepare(null, handler(), $request), 'Authorized request reaches normal controller');
    check($request->get_param('status') === $posts[1057]->post_status, 'No publish status reaches Core');
    check($request->get_param('pp_post_mime_type') === 'draft-revision' && false === $request->get_param('pp_statuses_selecting_workflow'), 'Statuses retains the draft during content save');
    $response = finish($request);
    check($response instanceof WP_REST_Response && $response->data['pp_post_mime_type'] === 'pending-revision', 'Response reports verified submission');
    check($response->data['content'] === 'Saved content and metadata' && $submissions === [1057], 'Submit only after saving content');
    check($posts[100]->post_content === 'Live' && $posts[100]->post_status === 'publish', 'Published page untouched');
    check(!in_array('publish_pages', array_column($cap_checks, 0), true), 'No publishing capability override');
    finish($request);
    check(count($submissions) === 1, 'Completed request cannot submit twice');
}
foreach (['edit_post', 'set_revision_pending-revision'] as $denied) {
    reset_case(); $caps[$denied] = false; $request = new TestRequest();
    check(Bridge::prepare(null, handler(), $request) instanceof WP_Error, 'Page-specific edit and submission capabilities required');
    check(!$submissions, 'Denied request has no transition');
}
reset_case(); $request = new TestRequest(); Bridge::prepare(null, handler(), $request);
$error = new WP_Error('rest_invalid_meta');
check(finish($request, $error) === $error && !$submissions, 'Failed metadata save does not submit');
check(finish($request) instanceof WP_REST_Response && !$submissions, 'Failed request context cleared');
reset_case(); $request = new TestRequest(); Bridge::prepare(null, handler(), $request);
check(finish($request, false, false) instanceof WP_Error && !$submissions, 'Intercepted success response without a native save cannot submit');
reset_case(); $request = new TestRequest(); Bridge::prepare(null, handler(), $request); $caps['set_revision_pending-revision'] = false;
check(finish($request) instanceof WP_Error && !$submissions, 'Authorization rechecked after save');
reset_case(); $request = new TestRequest(); Bridge::prepare(null, handler(), $request); $submit_failure = true;
check(finish($request) instanceof WP_Error, 'Persisted transition required for success');
foreach ([['pp_workflow_action' => 'current'], ['pp_workflow_action' => 'specified'], ['pp_status_selection' => 'revision-needs-work'], ['pp_post_mime_type' => 'future-revision'], ['pp_statuses_selecting_workflow' => false], ['id' => 100]] as $params) {
    reset_case(); $request = new TestRequest($params);
    Bridge::prepare(null, handler(), $request);
    check($request->get_param('status') === 'publish', 'Non-submission and ambiguous request left to existing guards');
    finish($request); check(!$submissions, 'No transition for unrelated requests');
}
reset_case(); $target = 'revision-needs-work'; $request = new TestRequest(); Bridge::prepare(null, handler(), $request);
check($request->get_param('status') === 'publish', 'Custom workflow destination respected');
reset_case(); $request = new TestRequest([], 100); Bridge::prepare(null, handler(), $request);
check($request->get_param('status') === 'publish', 'Live page publication never normalized');
reset_case(); $request = new TestRequest(); $other = ['callback' => [new WP_REST_Posts_Controller(), 'update_item']]; Bridge::prepare(null, $other, $request);
check($request->get_param('status') === 'publish', 'Unregistered controller cannot select target');
reset_case(); $request = new TestRequest(); $other = ['callback' => [$controller, 'create_item']]; Bridge::prepare(null, $other, $request);
check($request->get_param('status') === 'publish', 'Creation and autosave handlers excluded');
reset_case(); $statuses->workflow_by_sequence = true; $request = new TestRequest(); Bridge::prepare(null, handler(), $request);
check($progression_args['default_by_sequence'] === true, 'Max respects enforced workflow sequence'); finish($request);
reset_case(); $administrator = true; $caps['set_revision_pending-revision'] = false; $request = new TestRequest(); Bridge::prepare(null, handler(), $request);
check(finish($request) instanceof WP_REST_Response, 'Administrator submits without guard exception');
echo "PASS: {$assertions} assertions\n";
}
