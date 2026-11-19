<?php
// Run with: php tests/security/editor-preview.php
define('ABSPATH', __DIR__);
$root = dirname(__DIR__, 2);
require $root . '/revisionary_main.php';
function check($value, $message) { if (!$value) throw new RuntimeException($message); }
function load_function($path, $name) {
    $tokens = token_get_all(file_get_contents($path));
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (!is_array($tokens[$j]) || $tokens[$j][1] !== $name) continue;
        $code = ''; $depth = 0; $started = false;
        for (; $i < count($tokens); $i++) {
            $token = $tokens[$i]; $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{') { $depth++; $started = true; }
            if ($token === '}') $depth--;
            if ($started && !$depth) { eval($code); return; }
        }
    }
    throw new RuntimeException('Missing function ' . $name);
}
load_function($root . '/rvy_init-functions.php', '_rvy_rest_prepare');
$logged_in = true; $admin = false; $queried_ids = [];
$revision = (object) ['ID' => 271311, 'post_type' => 'page', 'post_status' => 'draft', 'post_mime_type' => 'draft-revision'];
$live = (object) ['ID' => 1, 'post_type' => 'page', 'post_status' => 'publish', 'post_mime_type' => ''];
function is_admin() { return $GLOBALS['admin']; }
function is_user_logged_in() { return $GLOBALS['logged_in']; }
function sanitize_text_field($value) { return trim($value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($value)); }
function wp_unslash($value) { return stripslashes($value); }
function get_post($id) { $GLOBALS['queried_ids'][] = $id; return $id === 271311 ? $GLOBALS['revision'] : ($id === 1 ? $GLOBALS['live'] : null); }
function rvy_in_revision_workflow($post) { return $post->post_mime_type === 'draft-revision'; }
function current_user_can(...$args) { throw new RuntimeException('Request classification must not query capabilities'); }
function get_post_type_object($type) { return (object) ['name' => $type, 'rest_base' => 'pages', 'rest_namespace' => 'wp/v2']; }
function rest_url($path) { return 'https://example.test/wp-json/' . $path; }
function trailingslashit($path) { return rtrim($path, '/') . '/'; }
function rvy_preview_url($post) { return 'https://example.test/?page_id=' . $post->ID . '&rv_preview=1'; }
class Response {
    public $data; public $links = [];
    function __construct($data) { $this->data = $data; }
    function add_link($rel, $href) { $this->links[$rel] = $href; }
}
class Request {
    private $context;
    function __construct($context) { $this->context = $context; }
    function get_param($key) { return $key === 'context' ? $this->context : null; }
}
$response = new Response(['link' => 'https://example.test/?page_id=271311', 'status' => 'draft']);
check(_rvy_rest_prepare($response, $revision, new Request('edit')) === $response, 'Preserve response object');
check($response->data['link'] === rvy_preview_url($revision), 'Post-save link uses revision preview URL');
check($response->data['status'] === 'draft' && isset($response->links['https://api.w.org/action-publish']), 'Preserve status and existing action link');
foreach (['view', null] as $context) {
    $response = new Response(['link' => 'original']);
    _rvy_rest_prepare($response, $revision, new Request($context));
    check($response->data['link'] === 'original', 'Do not change public/view-context links');
}
$logged_in = false; $response = new Response(['link' => 'original']);
_rvy_rest_prepare($response, $revision, new Request('edit'));
check($response->data['link'] === 'original', 'Guest links unchanged');
$logged_in = true; $response = new Response(['link' => 'published']);
_rvy_rest_prepare($response, $live, new Request('edit'));
check($response->data['link'] === 'published' && !$response->links, 'Published post response unchanged');
$response = new Response(['id' => 271311]);
_rvy_rest_prepare($response, $revision, new Request('edit'));
check(!isset($response->data['link']), 'Do not add fields excluded from response');
$handler = new Revisionary(); $_SERVER['REQUEST_METHOD'] = 'GET';
foreach (['page_id', 'p', 'preview_id'] as $key) {
    $_GET = [$key => '271311']; $_REQUEST = $_GET; $queried_ids = [];
    check($handler->fltIsPreview(false), 'Recognize revision ID from ' . $key);
    check($queried_ids === [271311], 'Look up exact revision ID rather than boolean true');
}
foreach ([[], ['page_id' => '0'], ['page_id' => '-1'], ['page_id' => '271311junk'], ['page_id' => ['271311']], ['page_id' => '1']] as $params) {
    $_GET = $params; $_REQUEST = $params;
    check(!$handler->fltIsPreview(false), 'Reject missing, malformed and ordinary published targets');
}
$_GET = ['page_id' => '271311']; $_REQUEST = $_GET;
$logged_in = false; check(!$handler->fltIsPreview(false), 'No guest fallback');
$logged_in = true; $_SERVER['REQUEST_METHOD'] = 'POST'; check(!$handler->fltIsPreview(false), 'No write-request fallback');
$_SERVER['REQUEST_METHOD'] = 'GET'; $admin = true; check(!$handler->fltIsPreview(false), 'No admin fallback');
echo "PASS\n";
