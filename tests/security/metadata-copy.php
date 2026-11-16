<?php
define('ABSPATH', __DIR__);
require dirname(__DIR__, 2).'/utils.php';
function load_fn($file,$name,$replace=false) {
 $tokens=token_get_all(file_get_contents($file));
 for($i=0;$i<count($tokens);$i++) {
  if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
  $j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
  if(!is_array($tokens[$j])||$tokens[$j][1]!==$name)continue;
  $code='';$d=0;$started=false;
  for(;$i<count($tokens);$i++) { $t=$tokens[$i];$code.=is_array($t)?$t[1]:$t;
   if($t==='{'){$d++;$started=true;}if($t==='}')$d--;
   if($started&&$d===0){if($replace)$code=str_replace("'allowed_classes' => false","'allowed_classes' => ['stdClass']",$code);eval($code);return;}
  }
 }
 throw new Exception('Missing '.$name);
}
function check($v,$m){if(!$v)throw new Exception($m);echo 'PASS '.$m."\n";}
function is_serialized($v){return is_string($v)&&preg_match('/^(?:[aObisdC]:|N;)/',$v);}
function __($s,...$a){return $s;}
function is_wp_error($v){return $v instanceof WP_Error;}
class WP_Error{}
function do_action(...$a){}
function apply_filters($h,$v,...$a){return $v;}
function revisionary_unrevisioned_postmeta(){return [];}
function get_post($p){return $GLOBALS['post_objects'][$p] ?? (object)['ID'=>$p];}
$meta=[];
function get_post_custom_keys($id){return array_keys($GLOBALS['meta'][$id]??[]);}
function get_post_custom_values($key,$id){return $GLOBALS['meta'][$id][$key]??[];}
// Model WordPress metadata APIs' object traversal and one serialization pass.
function unslash_deep($v){
 if(is_array($v))foreach($v as $k=>$x)$v[$k]=unslash_deep($x);
 elseif(is_object($v))foreach(get_object_vars($v) as $k=>$x)$v->$k=unslash_deep($x);
 elseif(is_string($v))$v=stripslashes($v);
 return $v;
}
function update_metadata($type,$id,$key,$v){$v=unslash_deep($v);$GLOBALS['meta'][$id][$key]=[(is_array($v)||is_object($v)||is_serialized($v))?serialize($v):$v];}
function add_metadata($type,$id,$key,$v){$v=unslash_deep($v);$GLOBALS['meta'][$id][$key][]=(is_array($v)||is_object($v)||is_serialized($v))?serialize($v):$v;}
function delete_metadata($type,$id,$key){unset($GLOBALS['meta'][$id][$key]);}
class DB { public $postmeta='postmeta'; function prepare($q,$id){return $id;}function get_col($id){return array_merge(...array_values($GLOBALS['meta'][$id]??[[]]));} }
$wpdb=new DB();
load_fn(dirname(__DIR__, 2) . '/functions.php','rvy_revision_metadata_values_are_safe');
load_fn(dirname(__DIR__, 2) . '/functions.php','rvy_revision_metadata_is_safe');
load_fn(dirname(__DIR__, 2) . '/functions.php','rvy_unserialize_meta_value');
load_fn(dirname(__DIR__, 2) . '/functions.php','revisionary_copy_postmeta');
$node=(object)['type'=>'module','settings'=>(object)['text'=>"O'Reilly \\ path <b>text</b>",'flag'=>false,'nil'=>null]];
$layout=['node'=>$node];
$meta[1]=['_fl_builder_enabled'=>['1'],'_fl_builder_data'=>[serialize($layout)],'_fl_builder_draft'=>[serialize($layout)],'array'=>[serialize(['x'=>false])],'multiple'=>[serialize($layout),serialize(['a'=>2])]];
check(rvy_revision_metadata_is_safe(1),'2675 accepts Beaver stdClass layout');
class Dangerous {function __wakeup(){$GLOBALS['woke']=true;}}
$meta[3]=['x'=>[serialize((object)['nested'=>new Dangerous()])]];
check(!rvy_revision_metadata_is_safe(3),'reject custom object nested inside stdClass');
check(empty($GLOBALS['woke']),'do not invoke custom wakeup');
$cycle=new stdClass();$cycle->self=$cycle;$meta[4]=['x'=>[serialize($cycle)]];
check(!rvy_revision_metadata_is_safe(4),'cycles fail closed at depth limit');
try {revisionary_copy_postmeta(1,2);} catch(Throwable $e) {
 throw $e;
 echo 'CONFIRMED helper with allowed_classes=false fails: '.$e->getMessage()."\n";exit;
}
check($meta[1]===$meta[2],'single and multiple values round trip with stdClass and escaping');
revisionary_copy_postmeta(2,1);
check($meta[1]===$meta[2],'copy revision metadata back to parent');
check(unserialize($meta[2]['_fl_builder_data'][0])['node'] instanceof stdClass,'layout nodes remain stdClass');

$meta[81]=['_fl_builder_enabled'=>['1'],'_fl_builder_data'=>[serialize(['node'=>(object)['type'=>'module']])],'_elementor_css'=>[serialize(new Dangerous())]];
$meta[82]=[];
check(true===revisionary_copy_postmeta(81,82),'Excluded unsafe cache must not block safe fields');
check(isset($meta[82]['_fl_builder_data']) && !isset($meta[82]['_elementor_css']),'Copy layout without excluded cache');
$meta[83]=['safe'=>['yes'],'unsafe'=>[serialize(new Dangerous())]];
check(revisionary_copy_postmeta(83,84) instanceof WP_Error,'Reject selected unsafe metadata');
check(empty($meta[84]),'Reject all selected metadata before any destination writes');
check(true===revisionary_copy_postmeta(83,0,['skip_meta_keys'=>['unsafe'],'validate_only'=>true]),'Preflight honors explicit exclusions');
$meta[85]=['unsafe'=>['existing']];
check(true===revisionary_copy_postmeta(83,85,['empty_target_only'=>true]),'Parent fallback ignores fields supplied by autosave');
check($meta[85]['unsafe']===['existing'] && $meta[85]['safe']===['yes'],'Fallback preserves autosave and copies missing fields');
