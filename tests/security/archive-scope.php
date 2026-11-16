<?php
$tmp=sys_get_temp_dir().'/rvy-archive-test-'.getmypid();
@mkdir($tmp.'/wp-admin/includes',0700,true);
file_put_contents($tmp.'/wp-admin/includes/class-wp-list-table.php','<?php class WP_List_Table {public $items;function get_items_per_page($a){return 20;}function set_pagination_args($a){}}');
define('ABSPATH',$tmp.'/');
require dirname(__DIR__, 2) . '/admin/class-list-table-archive.php';
function check($v,$m){if(!$v)throw new RuntimeException($m);}
function sanitize_key($s){return preg_replace('/[^a-z0-9_-]/','',strtolower($s));}
function sanitize_text_field($s){return $s;}function wp_unslash($s){return $s;}function wp_list_pluck($a,$k){return array_column($a,$k);}
function rvy_revision_statuses(){return ['draft-revision','pending-revision','future-revision'];}
function get_post($id){return (object)['ID'=>$id,'post_parent'=>20,'post_type'=>$id===20?'late_type':'revision'];}
function rvy_can_read_revision_and_parent($id){return $id!==11;}
class DB {
 public $posts='wp_posts';public $postmeta='wp_postmeta';public $queries=[];
 function prepare($s,...$a){if(count($a)===1&&is_array($a[0]))$a=$a[0];foreach($a as $v){$s=preg_replace('/%[ds]/',is_int($v)?(string)$v:"'".addslashes($v)."'",$s,1);}return $s;}
 function get_col($q){$this->queries[]=$q;if(str_contains($q,'SELECT r.ID'))return [10,11];if(str_contains($q,'SELECT DISTINCT meta_value'))return [9];if(str_contains($q,'SELECT DISTINCT post_author'))return str_contains($q,'IN (20)')?[7]:[5];return [];}
 function get_results($q){$this->queries[]=$q;return [];}function get_var($q){$this->queries[]=$q;return 0;}function esc_like($s){return $s;}
}
$wpdb=new DB;$current_user=(object)['ID'=>5];
$class=new ReflectionClass('Revisionary_Archive_List_Table');$method=$class->getMethod('readableParentIds');$obj=$class->newInstanceWithoutConstructor();
check($method->invoke($obj)===[] && !$wpdb->queries,'No enabled types means no revision scan');
$obj=$class->newInstanceWithoutConstructor();$class->getProperty('post_types')->setValue($obj,['late_type']);
check($method->invoke($obj)===[20=>20],'Readable parent retained');
$ids=$class->getProperty('readable_revision_ids')->getValue($obj);check($ids===[10],'Per-revision denial retained');
check(str_contains($wpdb->queries[0],"p.post_type IN ('late_type')") && str_contains($wpdb->queries[0],'autosave'),'Narrow candidate SQL before object authorization without requiring registration');
$_REQUEST=['post_author'=>5,'origin_post_author'=>7,'approved_by'=>9];
$obj->prepare_items();
$queries=implode("\n",$wpdb->queries);
check(str_contains($queries,'post_author = 5')&&str_contains($queries,'origin_post_author = 7')&&str_contains($queries,'approved_by = 9'),'Author and approver filters initialized in prepare_items');
check(!str_contains($queries,'IN (10,11)'),'Denied revision never enters row/count/facet scope');
echo "PASS Archive candidate scope, per-object denial and author filtering\n";
