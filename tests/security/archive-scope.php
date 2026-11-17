<?php
// Standalone archive pagination/authorization regression harness.
$tmp = sys_get_temp_dir() . '/rvy-archive-pagination-' . getmypid();
@mkdir($tmp . '/wp-admin/includes', 0700, true);
file_put_contents($tmp . '/wp-admin/includes/class-wp-list-table.php', '<?php class WP_List_Table {public $items=[]; public $pagination=[]; function get_items_per_page($a){return 20;} function set_pagination_args($a){$this->pagination=$a;}}');
define('ABSPATH', $tmp . '/');
register_shutdown_function(static function () use ($tmp) {
    unlink($tmp . '/wp-admin/includes/class-wp-list-table.php');
    rmdir($tmp . '/wp-admin/includes');rmdir($tmp . '/wp-admin');rmdir($tmp);
});
require dirname(__DIR__, 2) . '/admin/class-list-table-archive.php';
function check($v, $message) { if (!$v) { throw new RuntimeException($message); } }
function sanitize_key($s) { return preg_replace('/[^a-z0-9_-]/', '', strtolower($s)); }
function sanitize_text_field($s) { return is_scalar($s) ? strip_tags($s) : ''; }
function wp_unslash($s) { return stripslashes($s); }
function wp_list_pluck($a, $key) { return array_column($a, $key); }
function rvy_revision_statuses() { return ['draft-revision','pending-revision','future-revision']; }
function get_option($key) { return 0; }
function __($s, $domain='') { return $s; }
function esc_html__($s, $domain='') { return htmlspecialchars($s, ENT_QUOTES); }
function esc_html_e($s, $domain='') { echo esc_html__($s, $domain); }
function esc_html($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
function esc_attr($s) { return esc_html($s); }
function esc_url($s) { return esc_html($s); }
function esc_url_raw($s) { return $s; }
function admin_url($s) { return 'https://example.test/wp-admin/' . $s; }
function add_query_arg($key, $value=null, $url='') {
    if (is_array($key)) { $url=$value; $args=$key; } else { $args=[$key=>$value]; }
    $parts=parse_url($url);parse_str($parts['query']??'', $query);
    $query=array_merge($query,$args);
    return ($parts['scheme']??'https').'://'.($parts['host']??'example.test').($parts['path']??'/').'?'.http_build_query($query);
}
function submit_button(...$args) { echo '<button>Filter</button>'; }
function get_userdata($id) { return (object)['ID'=>$id]; }
function get_post_type_object($type) { return null; }
function get_post($id) {
    $GLOBALS['post_cache'][$id] = (object)['ID'=>$id, 'post_content'=>str_repeat('x',5120).$id];
    $GLOBALS['max_cached'] = max($GLOBALS['max_cached'], count($GLOBALS['post_cache']));
    return $GLOBALS['post_cache'][$id];
}
function rvy_can_read_revision_and_parent($id) {
    $GLOBALS['checked'][] = $id;
    get_post($id);$GLOBALS['meta_cache'][$id]=str_repeat('m',5120).$id;
    return !in_array($id, $GLOBALS['denied'], true);
}
function wp_cache_delete($id,$group) {
    if ($group==='posts') { unset($GLOBALS['post_cache'][$id]); }
    if ($group==='post_meta') { unset($GLOBALS['meta_cache'][$id]); }
}
class DB {
    public $posts='wp_posts';public $postmeta='wp_postmeta';public $users='wp_users';public $queries=[];
    public function prepare($query,...$args) {
        if (count($args)===1 && is_array($args[0])) { $args=$args[0]; }
        $i=0;
        return preg_replace_callback('/%[ds]/',static function($m)use(&$i,$args){
            check(array_key_exists($i,$args),'All SQL placeholders have parameters');
            $value=$args[$i++];return $m[0]==='%d'?(string)(int)$value:"'".str_replace("'","''",$value)."'";
        },$query);
    }
    public function esc_like($s) { return addcslashes($s,'_%\\'); }
    private function ids($q) { preg_match('/\bID IN \(([\d,]+)\)/',$q,$m);return isset($m[1])?array_map('intval',explode(',',$m[1])):[]; }
    public function get_col($q) {
        $this->queries[]=$q;
        if(str_contains($q,'archive_parent_candidates')) {
            return str_contains($q,'WHERE 1 = 0')?[]:range(20,119);
        }
        $ids=array_filter($this->ids($q));
        if(!$ids) { return []; }
        if(str_contains($q,'SELECT DISTINCT meta_value')) { return [9]; }
        if(str_contains($q,'SELECT DISTINCT post_author')) {
            check(!in_array(21,$ids,true)&&!in_array(1001,$ids,true),'Denied objects excluded from author facets');
            return min($ids)<1000?[7]:[5];
        }
        return [];
    }
    public function get_results($q) {
        $this->queries[]=$q;
        if(str_contains($q,'FROM wp_users')) {
            preg_match_all('/\d+/',$q,$m);return array_map(static fn($id)=>(object)['ID'=>(int)$id,'display_name'=>'User '.(int)$id],$m[0]);
        }
        if(str_contains($q,'WHERE 1 = 0')||str_contains($q,'AND 1 = 0')) { return []; }
        preg_match('/post_parent IN \(([\d,]+)\)/',$q,$m);check(isset($m[1]),'Row query has authorized parent scope');
        $parents=array_map('intval',explode(',',$m[1]));$eligible=[];
        for($id=1000;$id<31000;$id++) {
            if(in_array(20+intdiv($id-1000,300),$parents,true)) { $eligible[]=$id; }
        }
        if(!str_contains($q,'ORDER BY post_modified ASC')) { $eligible=array_reverse($eligible); }
        preg_match('/LIMIT (\d+),(\d+)$/',$q,$limit);check(isset($limit[2]),'SQL page has a LIMIT');
        return array_map(static fn($id)=>(object)['ID'=>$id,'post_parent'=>20+intdiv($id-1000,300)],array_slice($eligible,(int)$limit[1],(int)$limit[2]));
    }
    public function get_var($q) {
        $this->queries[]=$q;
        check(!str_contains($q,'COUNT('),'No global count queries');return 1;
    }
}
$class=new ReflectionClass('Revisionary_Archive_List_Table');
function fresh($request=[], $types=['late_type'], $denials=[21,1001]) {
    global $class,$wpdb,$current_user,$checked,$denied,$post_cache,$meta_cache,$max_cached;
    $_REQUEST=$request;$wpdb=new DB;$current_user=(object)['ID'=>5];$checked=[];$denied=$denials;
    $post_cache=$meta_cache=[];$max_cached=0;
    $obj=$class->newInstanceWithoutConstructor();$class->getProperty('post_types')->setValue($obj,$types);return $obj;
}
$obj=fresh(['order'=>'asc']);$obj->prepare_items();
check(count($obj->items)===19,'Only current-page denial removes a row');
check(!in_array(1001,array_column($obj->items,'ID'),true),'Denied revision never rendered');
check(count(array_filter($checked,static fn($id)=>$id>=1000))===20,'Only 20 revisions authorized, not 30000');
check(count($checked)<=140,'Parent plus page authorization bound');
check(!in_array(1020,$checked,true),'Lookahead is not rendered or authorized');
check($max_cached<=21,'Full post object cache is independent of archive history size');
check($class->getProperty('archive_has_more')->getValue($obj),'Next SQL page detected');
$authors=$class->getMethod('readableAuthors')->invoke($obj);
check($authors===['approved_by'=>[9],'origin_post_author'=>[7],'post_author'=>[5]],'Facets scoped to authorized page rows');
ob_start();$class->getMethod('pagination')->invoke($obj,'top');$nav=ob_get_clean();
check(str_contains($nav,'next-page')&&!str_contains($nav,'prev-page'),'First-page navigation');
$first_ids=array_column($obj->items,'ID');
$obj=fresh(['order'=>'asc','paged'=>2]);$obj->prepare_items();
check(array_column($obj->items,'ID')===range(1020,1039),'Next SQL page is contiguous and deterministic');
check(!array_intersect($first_ids,array_column($obj->items,'ID')),'No repeated rows across page boundaries');
$obj=fresh(['order'=>'asc','paged'=>16]);$obj->prepare_items();
check(array_column($obj->items,'ID')===range(1600,1619),'Parent denial removed before SQL pagination');
$obj=fresh(['order'=>'asc','paged'=>1485]);$obj->prepare_items();
check(count($obj->items)===20&&!$class->getProperty('archive_has_more')->getValue($obj),'Final full page has no false next link');
$obj=fresh(['order'=>'asc'],[],[]);$obj->prepare_items();check(!$checked&&!$obj->items,'No types means no authorization scan');
$obj=fresh(['post_author'=>5,'origin_post_author'=>7,'approved_by'=>9,'origin_post_date'=>1735689600]);$obj->prepare_items();
$queries=implode("\n",$wpdb->queries);
check(str_contains($queries,'post_author = 5')&&str_contains($queries,'origin_post_author = 7'),'Author filters retained before SQL paging');
check(substr_count($queries,'AS prev_revision_status')===2,'One metadata alias per candidate/row query when both filters are active');
check(str_contains($queries,'AND (approved_by = 9 OR ('),'Approval fallback cannot bypass other filters');
$obj=fresh(['order'=>'asc'],['late_type'],range(20,119));$obj->prepare_items();
check(!$obj->items&&count($checked)===100,'No readable parents fails closed without revision checks');
ob_start();$class->getMethod('extra_tablenav')->invoke($obj,'top');$html=ob_get_clean();
check(!str_contains($html,'User 9')&&!str_contains($html,'User 7'),'Empty scope exposes no author/approver identities');
$obj=fresh(['order'=>'asc'],['late_type'],range(1000,1019));$obj->prepare_items();
check(!$obj->items&&$class->getProperty('archive_has_more')->getValue($obj),'An entirely denied page does not hide later candidates');
ob_start();$obj->no_items();$message=ob_get_clean();check(str_contains($message,'next page'),'Empty candidate page explains continuation');
if(in_array('--sql', $argv, true)) {
    $obj=fresh(['order'=>'asc'],['late_type']);
    $method=$class->getMethod('do_query');$sql=[];
    foreach([
        'first'=>['orderby'=>'post_modified','order'=>'ASC','readable_parents'=>[20,22]],
        'authors'=>['orderby'=>'post_modified','order'=>'ASC','readable_parents'=>[20,22],'post_author'=>5,'origin_post_author'=>7],
        'types'=>['orderby'=>'post_modified','order'=>'ASC','origin_post_type'=>'late_type'],
        'date'=>['orderby'=>'post_modified','order'=>'ASC','origin_post_date'=>1735689600,'approved_by'=>9],
        'search'=>['orderby'=>'post_modified','order'=>'ASC','s'=>'needle'],
    ] as $key=>$args){$sql[$key]=$method->invoke($obj,$args).' LIMIT 0,21';}
    echo json_encode($sql,JSON_THROW_ON_ERROR),"\n";
} else {
    echo "PASS: 30,000-candidate archive; at most 20 revision checks per page; parent/row denials, facets, filters, and navigation preserved.\n";
}
