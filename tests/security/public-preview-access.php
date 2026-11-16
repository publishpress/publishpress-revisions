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
check(!$f->fltPublicPostPreviewAccess(false,2,1),'No verified token, no grant');
$f->actPublicPostPreview(2);
foreach(['publish','private','draft','pending','future','custom-public'] as $status){$posts[1]->post_status=$status;check($f->fltPublicPostPreviewAccess(false,2,1),'Allow verified revision for '.$status);}
foreach(['trash','auto-draft','inherit','custom-hidden','unknown'] as $status){$posts[1]->post_status=$status;check(!$f->fltPublicPostPreviewAccess(false,2,1),'Reject parent status '.$status);}
$posts[1]->post_status='private';
check(!$f->fltPublicPostPreviewAccess(false,3,1),'Token cannot authorize another revision');
check(!$f->fltPublicPostPreviewAccess(false,2,2),'Reject self parent');
check(!$f->fltPublicPostPreviewAccess(false,2,99),'Reject missing parent');
$posts[1]->post_type='internal';check(!$f->fltPublicPostPreviewAccess(false,2,1),'Reject non-viewable type');
echo "PASS Public Post Preview parent and token scope\n";
