<?php
function load_function($path, $name) {
    $tokens = token_get_all(file_get_contents($path));
    for ($i=0;$i<count($tokens);$i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j=$i+1;while (is_array($tokens[$j]) && $tokens[$j][0]===T_WHITESPACE) $j++;
        if (!is_array($tokens[$j]) || $tokens[$j][1] !== $name) continue;
        $code='';$depth=0;$started=false;
        for (;$i<count($tokens);$i++) {
            $t=$tokens[$i];$code.=is_array($t)?$t[1]:$t;
            if ($t==='{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {$depth++;$started=true;}
            if ($t==='}') $depth--;
            if ($started && $depth===0) {eval($code);return;}
        }
    }
    throw new RuntimeException('Missing function '.$name);
}
function check($value,$message) {if (!$value) throw new RuntimeException($message);}
$root = dirname(__DIR__, 2);

// Exercise the actual REST pre-insert callback with WordPress shims.
class WP_Error {function __construct(...$a){}}
function is_wp_error($v){return $v instanceof WP_Error;}
function __($s,$d){return $s;}
function absint($n){return abs((int)$n);}
function add_action($hook,$fn){$fn();}
function add_filter($hook,$fn,...$args){$GLOBALS['guard']=$fn;}
function get_post_types(...$a){return [(object)['name'=>'post','cap'=>(object)['publish_posts'=>'publish_posts']]];}
function rvy_in_revision_workflow($p){$p=get_post($p);return $p && in_array($p->post_mime_type,['draft-revision','pending-revision','future-revision'],true);}
$GLOBALS['posts'] = [
 10 => (object)['ID'=>10,'post_type'=>'post','post_parent'=>0,'post_status'=>'draft','post_mime_type'=>'draft-revision'],
 20 => (object)['ID'=>20,'post_type'=>'post','post_parent'=>0,'post_status'=>'publish','post_mime_type'=>''],
 30 => (object)['ID'=>30,'post_type'=>'revision','post_parent'=>10,'post_status'=>'inherit','post_mime_type'=>''],
];
function get_post($id){return is_object($id)?$id:($GLOBALS['posts'][$id]??null);}
function rvy_post_id($id){return $GLOBALS['parent_id']??20;}
function user_can($user_id,$cap,$id){
 $GLOBALS['parent_checks'][]=[$user_id,$cap,$id];
 return $user_id===7 && $cap==='edit_post' && $id===20;
}
function get_post_status_object($s){return (object)['public'=>false,'private'=>false];}
function current_user_can($c){return false;}
class Request {function __construct(public $status){} function get_param($k){return $k==='id'?10:($k==='status'?$this->status:0);}}
$s=file_get_contents($root.'/functions.php');$a=strpos($s,"add_action('rest_api_init'");$b=strpos($s,'/**',$a);eval(substr($s,$a,$b-$a));
foreach(['pending-revision','future-revision','draft-revision','publish','future'] as $status) check(is_wp_error(($GLOBALS['guard'])((object)['post_status'=>$status],new Request($status))),'REST transition must fail: '.$status);
check(!is_wp_error(($GLOBALS['guard'])((object)['post_title'=>'new'],new Request(null))),'Ordinary content save');

// Exercise the scheduled-edit guard independently of current-user role or request context.
load_function($root.'/functions.php','rvy_limit_scheduled_revision_edit_caps');
$original=['edit_posts'];
$scheduled=$GLOBALS['posts'][10];
foreach (['draft-revision','pending-revision'] as $status) {
 $scheduled->post_mime_type=$status;
 check($original===rvy_limit_scheduled_revision_edit_caps($original,'edit_post',8,[10]),'Unscheduled revisions retain editing policy');
}
$scheduled->post_mime_type='future-revision';
foreach (['edit_post','edit_page'] as $cap) {
 check(['do_not_allow']===rvy_limit_scheduled_revision_edit_caps($original,$cap,8,[10]),'Deny scheduled edits without live-post access');
 check($original===rvy_limit_scheduled_revision_edit_caps($original,$cap,7,[10]),'Allow parent editor without relaxing revision caps');
}
check(['do_not_allow']===rvy_limit_scheduled_revision_edit_caps($original,'edit_post',8,[30]),'Autosaves inherit scheduled lock');
check(['do_not_allow']===rvy_limit_scheduled_revision_edit_caps($original,'edit_post',8,[$scheduled]),'Post object arguments remain protected');
foreach (['read_post','delete_post','approve_revision'] as $cap) {
 check($original===rvy_limit_scheduled_revision_edit_caps($original,$cap,8,[10]),'Leave reading and workflow actions unchanged');
}
check(['do_not_allow']===rvy_limit_scheduled_revision_edit_caps(['do_not_allow'],'edit_post',7,[10]),'Do not override existing denials');
foreach ([0,10,30,999] as $parent_id) {
 $GLOBALS['parent_id']=$parent_id;
 check(['do_not_allow']===rvy_limit_scheduled_revision_edit_caps($original,'edit_post',7,[10]),'Invalid parents fail closed');
}
unset($GLOBALS['parent_id']);
$GLOBALS['posts'][20]->post_type='page';
check(['do_not_allow']===rvy_limit_scheduled_revision_edit_caps($original,'edit_post',7,[10]),'Mismatched parent type fails closed');
$GLOBALS['posts'][20]->post_type='post';
$GLOBALS['posts'][20]->post_mime_type='pending-revision';
check(['do_not_allow']===rvy_limit_scheduled_revision_edit_caps($original,'edit_post',7,[10]),'Workflow parent fails closed');
$GLOBALS['posts'][20]->post_mime_type='';
check(in_array([8,'edit_post',20],$GLOBALS['parent_checks'],true),'Check the supplied user against the exact live parent');
$scheduled->post_mime_type='';
check($original===rvy_limit_scheduled_revision_edit_caps($original,'edit_post',8,[10]),'Live posts retain ordinary permissions');

echo "PASS\n";
