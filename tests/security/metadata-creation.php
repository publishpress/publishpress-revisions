<?php
require __DIR__.'/metadata-copy.php';
require dirname(__DIR__, 2) . '/revision-creation_rvy.php';
function rvy_in_revision_workflow($p){return false;}function rvy_is_revision_status($s){return false;}
function wp_unique_post_slug(...$a){return 'slug';}function current_time(...$a){return '2026-11-16 12:00:00';}function agp_time_gmt(){return time();}
function wp_insert_post(...$a){$GLOBALS['inserted']++;return 99;}
$post_objects[83]=(object)['ID'=>83,'post_type'=>'post','post_status'=>'publish','post_parent'=>0,'post_name'=>'source'];
$revisionary=(object)['enabled_fields_copy'=>[]];$current_user=(object)['ID'=>7];$inserted=0;
$c=(new ReflectionClass('PublishPress\Revisions\RevisionCreation'))->newInstanceWithoutConstructor();
$data=['post_type'=>'post','post_name'=>'slug','post_status'=>'draft','post_mime_type'=>'','post_date'=>'','post_date_gmt'=>''];
$m=new ReflectionMethod($c,'insert_post');check($m->invoke($c,$data,83,'draft',[]) instanceof WP_Error,'Duplicate rejects unsupported selected fields');check($inserted===0,'Duplicate validation precedes insertion');
$m=new ReflectionMethod($c,'insert_revision');check($m->invoke($c,$data,83,'future-revision',[]) instanceof WP_Error,'Scheduled creation rejects unsupported selected fields');check($inserted===0,'Scheduled validation precedes insertion');
$meta[86]=['unsafe'=>[serialize(new Dangerous())]];$post_objects[86]=(object)['ID'=>86,'post_type'=>'revision','post_status'=>'inherit'];
check($m->invoke($c,$data,83,'draft-revision',['meta_post_id'=>86]) instanceof WP_Error,'Autosave selected metadata validated before insertion');check($inserted===0,'Unsafe autosave creates no partial destination');
echo "PASS Creation preflight prevents incomplete revisions and duplicates\n";
