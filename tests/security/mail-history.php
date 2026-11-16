<?php
define('ABSPATH',__DIR__);define('DB_NAME','test');
require __DIR__.'/regression-support.php';
$root=dirname(__DIR__, 2) . '';
require $root.'/mail-buffer_rvy.php';
load_function($root.'/rvy_init-functions.php','rvy_mail');load_function($root.'/rvy_init-functions.php','rvy_deliver_mail');
class DB {
 public $prefix='wp_';public $options='wp_options';public $available=false;public $locked=false;public $race=false;
 function prepare($sql,...$args){return [$sql,$args];}
 function get_var($q){[$sql,$args]=$q;if(str_contains($sql,'GET_LOCK')){$this->locked=$this->available;return $this->available?1:0;}if(str_contains($sql,'RELEASE_LOCK')){$this->locked=false;return 1;}return isset($GLOBALS['options']['revisionary_sent_mail'])?serialize($GLOBALS['options']['revisionary_sent_mail']):null;}
 function query($q){if(is_string($q))return 1;[$sql,$a]=$q;if($this->race){$this->race=false;$GLOBALS['options']['revisionary_sent_mail'][]=['title'=>'concurrent','time_gmt'=>time()];return 0;}if(md5(serialize($GLOBALS['options']['revisionary_sent_mail']))!==$a[2])return 0;$GLOBALS['options']['revisionary_sent_mail']=unserialize($a[0]);return 1;}
}
$wpdb=new DB;$options=['revisionary_sent_mail'=>[['title'=>'existing','time_gmt'=>time()]]];$sent=0;
function apply_filters($h,$v,...$a){return $v;}function do_action(...$a){}function wp_cache_delete(...$a){}
function get_option($k,$d=false){return $GLOBALS['options'][$k]??$d;}function update_option($k,$v){$GLOBALS['options'][$k]=$v;return true;}function add_option($k,$v,...$a){if(isset($GLOBALS['options'][$k]))return false;$GLOBALS['options'][$k]=$v;return true;}
function current_time($f){return date('Y-m-d H:i:s');}function rvy_get_option($k){return true;}
function is_serialized($v){return is_string($v)&&preg_match('/^a:/',$v);}function maybe_serialize($v){return serialize($v);}
function rvy_check_duplicate_mail($n,$history,$buffer){foreach($history as $m)if(($m['title']??'')===$n['title'])return true;return false;}
function wp_mail(...$a){$GLOBALS['sent']++;if($GLOBALS['wpdb']->available)check($GLOBALS['wpdb']->locked,'Lock spans actual delivery');return true;}
$wpdb->race=true;rvy_mail('test@example.org','new','body',[]);
check(array_column($options['revisionary_sent_mail'],'title')===['existing','concurrent','new'],'Fallback preserves history and retries concurrent CAS changes');
rvy_mail('test@example.org','new','body',[]);check($sent===1,'Fallback retains duplicate suppression');
$wpdb->available=true;rvy_mail('test@example.org','locked','body',[]);check(!$wpdb->locked,'Release lock after delivery');check(count($options['revisionary_sent_mail'])===4,'Locked delivery appends history');
$options['revisionary_mail_buffer']=[['address'=>'test@example.org','title'=>'drain','message'=>'body','headers'=>['X-Test: yes'],'time_gmt'=>time()]];
_rvy_send_buffered_mail();check(!$options['revisionary_mail_buffer'],'Drain removes delivered entry');check(count($options['revisionary_sent_mail'])===5,'Drain appends history');
echo "PASS Mail fallback, concurrent append, duplicate suppression and lock coverage\n";
