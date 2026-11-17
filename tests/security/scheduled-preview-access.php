<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__, 2) . '/front_rvy.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
$posts=[1=>(object)['ID'=>1,'post_type'=>'page','post_status'=>'publish','post_mime_type'=>''],2=>(object)['ID'=>2,'post_type'=>'page','post_status'=>'pending','post_mime_type'=>'future-revision']];
function get_post($id){return is_object($id)?$id:($GLOBALS['posts'][$id]??null);}
function rvy_in_revision_workflow($id){$p=get_post($id);return $p && $p->post_mime_type==='future-revision';}
function rvy_post_id($id){return 1;}
function get_post_status_object($status){return $status==='unknown'?null:(object)['public'=>in_array($status,['publish','custom-public'],true)];}
function is_post_type_viewable($type){return $type==='page';}
$f=(new ReflectionClass('RevisionaryFront'))->newInstanceWithoutConstructor();
$posts[1]->post_type='page';$allowed=true;$admin=false;$logged=true;
function is_admin(){return $GLOBALS['admin'];}
function is_user_logged_in(){return $GLOBALS['logged'];}
function rvy_can_read_revision_and_parent($id){return $GLOBALS['allowed'];}
class Query {public $vars=['page_id'=>2];public $main=true;public $singular=true;public $is_preview=false;function is_main_query(){return $this->main;}function is_singular(){return $this->singular;}function get($k){return $this->vars[$k]??0;}function set($k,$v){$this->vars[$k]=$v;}}
$_SERVER['REQUEST_METHOD']='GET';$_POST=[];
$q=new Query;$f->configureWorkflowRevisionPreview($q);check(($q->vars['post_status']??null)==='pending' && $q->is_preview,'Authorized preview requests exact stored status');
foreach(['denied','post','admin','guest','secondary','nonsingular','live'] as $case){
 $q=new Query;$allowed=$case!=='denied';$admin=$case==='admin';$logged=$case!=='guest';$_SERVER['REQUEST_METHOD']=$case==='post'?'POST':'GET';$q->main=$case!=='secondary';$q->singular=$case!=='nonsingular';if($case==='live')$q->vars=['page_id'=>1];
 $f->configureWorkflowRevisionPreview($q);check(!isset($q->vars['post_status']),'Do not admit '.$case);
}
echo "PASS Scheduled preview admits reads without granting editing\n";
