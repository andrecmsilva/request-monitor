<?php
if (!defined('ABSPATH')) exit;

final class Request_Monitor_Core {
    const VERSION='0.5.0'; const MU_VERSION='0.5.0';
    const OPT_ENABLED='rrt_enabled'; const OPT_DEEP='rrt_deep_attribution'; const OPT_MAX_MB='rrt_max_log_mb';
    const OPT_SLOW_MS='rrt_slow_threshold_ms'; const OPT_CALLBACK_FLOOR='rrt_callback_floor_ms'; const OPT_SCOPE='rrt_trace_scope';
    private static $instance=null; private $http_calls=array(); private $http_pending=array();

    public static function instance(){if(self::$instance===null)self::$instance=new self();return self::$instance;}
    private function __construct(){if($this->has_trace_context())$this->attach_to_mu_context();}

    public static function default_scope(){return array('types'=>array('front','admin','ajax','rest','cron'),'methods'=>array(),'include_paths'=>array(),'exclude_paths'=>array(),'include_actions'=>array(),'exclude_actions'=>array());}
    public static function get_scope(){$s=get_option(self::OPT_SCOPE,array());return is_array($s)?array_merge(self::default_scope(),$s):self::default_scope();}
    public static function sanitize_scope($scope){
        $out=self::default_scope(); foreach(array_keys($out) as $key) if(isset($scope[$key])){
            $v=is_array($scope[$key])?$scope[$key]:preg_split('/[\r\n,]+/',(string)$scope[$key]);
            $out[$key]=array_values(array_filter(array_map('trim',$v),function($x){return $x!=='';}));
        }
        $out['types']=array_values(array_intersect(array('front','admin','ajax','rest','cron','cli'),$out['types']));
        $out['methods']=array_values(array_unique(array_map('strtoupper',$out['methods']))); return $out;
    }
    public static function save_scope($scope){update_option(self::OPT_SCOPE,self::sanitize_scope($scope),false);}

    private static function mu_files(){return array('request-monitor-bootstrap.php','request-monitor-runtime.php','request-monitor-hook-profiler.php');}
    private static function mu_source($file){return REQUEST_MONITOR_DIR.'mu/'.$file;}
    private static function mu_target($file){$dir=defined('WPMU_PLUGIN_DIR')?WPMU_PLUGIN_DIR:WP_CONTENT_DIR.'/mu-plugins';return trailingslashit($dir).$file;}
    public static function install_mu(){
        $dir=defined('WPMU_PLUGIN_DIR')?WPMU_PLUGIN_DIR:WP_CONTENT_DIR.'/mu-plugins';
        if(!is_dir($dir)&&!wp_mkdir_p($dir))return new WP_Error('rrt_mu_dir','Could not create MU plugin directory: '.$dir);
        foreach(self::mu_files() as $file){$src=self::mu_source($file);$dst=self::mu_target($file);
            if(!is_file($src))return new WP_Error('rrt_mu_source','Missing bundled MU component: '.$file);
            if(!is_writable($dir)&&!(is_file($dst)&&is_writable($dst)))return new WP_Error('rrt_mu_permissions','MU plugin directory is not writable: '.$dir);
            $src_hash=@hash_file('sha256',$src);$dst_hash=is_file($dst)?@hash_file('sha256',$dst):null;if($src_hash&&$src_hash===$dst_hash)continue;
            $tmp=$dst.'.tmp-'.getmypid();if(!@copy($src,$tmp))return new WP_Error('rrt_mu_copy','Could not copy '.$file);@chmod($tmp,0644);
            if(!@rename($tmp,$dst)){@unlink($tmp);return new WP_Error('rrt_mu_rename','Could not install '.$file);}
        } return true;
    }
    public static function mu_healthy(){foreach(self::mu_files() as $file){$src=self::mu_source($file);$dst=self::mu_target($file);if(!is_file($src)||!is_file($dst)||@hash_file('sha256',$src)!==@hash_file('sha256',$dst))return false;}return true;}
    public static function activate(){
        $r=self::install_mu(); if(is_wp_error($r)){deactivate_plugins(plugin_basename(REQUEST_MONITOR_FILE));wp_die('<h1>Request Monitor activation failed</h1><p>'.esc_html($r->get_error_message()).'</p>','Request Monitor',array('back_link'=>true));}
        add_option(self::OPT_SLOW_MS,1500,'',false);add_option(self::OPT_CALLBACK_FLOOR,5,'',false);add_option(self::OPT_SCOPE,self::default_scope(),'',false);
    }
    public static function deactivate(){update_option(self::OPT_ENABLED,0,false);foreach(self::mu_files() as $file){$dst=self::mu_target($file);if(!is_file($dst))continue;$head=@file_get_contents($dst,false,null,0,512);if(is_string($head)&&strpos($head,'Request Monitor')!==false)@unlink($dst);}}

    private function has_trace_context(){return !empty($GLOBALS['rrt_bootstrap_context'])&&is_array($GLOBALS['rrt_bootstrap_context']);}
    private function mark($name){if($this->has_trace_context())$GLOBALS['rrt_bootstrap_context']['phases'][$name]=microtime(true);}
    private function attach_to_mu_context(){
        $this->mark('regular_plugin_loaded');$GLOBALS['rrt_bootstrap_context']['finalizer']=array($this,'build_enrichment');
        foreach(array('plugins_loaded','after_setup_theme','init','wp_loaded','parse_request','wp','template_redirect','admin_init','rest_api_init') as $hook)add_action($hook,function()use($hook){$this->mark($hook);},PHP_INT_MAX);
        add_filter('http_request_args',array($this,'http_start'),-9999,2);add_action('http_api_debug',array($this,'http_end'),9999,5);
    }
    public function http_start($args,$url){$id=count($this->http_pending);$this->http_pending[$id]=array('raw'=>(string)$url,'url'=>$this->sanitize_url($url),'start'=>microtime(true),'caller'=>function_exists('wp_debug_backtrace_summary')?wp_debug_backtrace_summary(null,0,false):null,'done'=>false);return $args;}
    public function http_end($response,$context,$class,$parsed_args,$url){$match=null;foreach($this->http_pending as $id=>$p)if(!$p['done']&&$p['raw']===(string)$url){$match=$id;break;}if($match===null)return;$p=$this->http_pending[$match];$this->http_pending[$match]['done']=true;$code=null;$error=null;if(is_wp_error($response))$error=$response->get_error_code();elseif(is_array($response))$code=wp_remote_retrieve_response_code($response);$this->http_calls[]=array('url'=>$p['url'],'duration_ms'=>round((microtime(true)-$p['start'])*1000,3),'response'=>$code,'error'=>$error,'caller'=>$p['caller'],'transport'=>is_object($class)?get_class($class):(is_string($class)?$class:null));}

    public function build_enrichment(){
        $ctx=$this->has_trace_context()?$GLOBALS['rrt_bootstrap_context']:array();$deep=!empty($ctx['deep']);$escalated=!empty($ctx['auto_escalated']);$detail=$deep||$escalated;
        return array('plugin_version'=>self::VERSION,'wordpress'=>$this->wp_context(),'included_groups'=>$this->included_groups(),
            'sql'=>$detail?$this->sql_summary():array('count'=>null,'total_ms'=>null,'top'=>array(),'coverage'=>'not_enabled'),
            'http'=>$detail?$this->http_summary():array('count'=>count($this->http_calls),'total_ms'=>$this->http_total(),'top'=>array(),'coverage'=>'summary_only'),
            'deep_coverage'=>array('mode'=>$deep?'from_start':($escalated?'post_threshold':'basic'),'sql_started_from_request_start'=>$deep,'http_started_from_request_start'=>true));
    }
    private function wp_context(){global $wp_query;$c=array('is_admin'=>is_admin(),'is_ajax'=>wp_doing_ajax(),'is_cron'=>wp_doing_cron(),'is_rest'=>defined('REST_REQUEST')&&REST_REQUEST,'memory_limit'=>ini_get('memory_limit'),'php_version'=>PHP_VERSION,'wp_version'=>get_bloginfo('version'));if(isset($wp_query)&&is_object($wp_query))$c['query']=array('is_home'=>$wp_query->is_home(),'is_search'=>$wp_query->is_search(),'is_archive'=>$wp_query->is_archive(),'is_tax'=>$wp_query->is_tax(),'is_singular'=>$wp_query->is_singular(),'is_404'=>$wp_query->is_404(),'post_count'=>isset($wp_query->post_count)?(int)$wp_query->post_count:null,'found_posts'=>isset($wp_query->found_posts)?(int)$wp_query->found_posts:null);return $c;}
    private function included_groups(){$groups=array();foreach(get_included_files() as $file){if(preg_match('#/wp-content/mu-plugins/([^/]+)/#',$file,$m))$g='mu-plugin:'.$m[1];elseif(preg_match('#/wp-content/plugins/([^/]+)/#',$file,$m))$g='plugin:'.$m[1];elseif(preg_match('#/wp-content/themes/([^/]+)/#',$file,$m))$g='theme:'.$m[1];elseif(strpos($file,'/wp-includes/')!==false||strpos($file,'/wp-admin/')!==false)$g='wordpress-core';else$g='other';$groups[$g]=($groups[$g]??0)+1;}arsort($groups);return array_slice($groups,0,30,true);}
    private function sql_summary(){global $wpdb;$result=array('count'=>0,'total_ms'=>0,'top'=>array(),'coverage'=>!empty($GLOBALS['rrt_bootstrap_context']['deep'])?'from_start':'post_threshold');if(!isset($wpdb->queries)||!is_array($wpdb->queries))return $result;$rows=array();foreach($wpdb->queries as $entry){if(!is_array($entry)||!isset($entry[0],$entry[1]))continue;$sql=(string)$entry[0];$sec=(float)$entry[1];$rows[]=array('duration_ms'=>round($sec*1000,3),'query'=>$this->sanitize_sql($sql),'query_hash'=>substr(hash('sha256',$sql),0,16),'caller'=>isset($entry[2])?(string)$entry[2]:null);$result['total_ms']+=$sec*1000;}usort($rows,function($a,$b){return $b['duration_ms']<=>$a['duration_ms'];});$result['count']=count($rows);$result['total_ms']=round($result['total_ms'],3);$result['top']=array_slice($rows,0,10);return $result;}
    private function sanitize_sql($sql){$sql=preg_replace("/'(?:''|\\\\'|[^'])*'/s","'?'",$sql);$sql=preg_replace('/"(?:\\\\"|[^"])*"/s','"?"',$sql);$sql=preg_replace('/\b\d+(?:\.\d+)?\b/','?',$sql);$sql=preg_replace('/\s+/',' ',trim($sql));return substr($sql,0,1200);}
    private function http_total(){$t=0;foreach($this->http_calls as $c)$t+=$c['duration_ms'];return round($t,3);}
    private function http_summary(){$calls=$this->http_calls;usort($calls,function($a,$b){return $b['duration_ms']<=>$a['duration_ms'];});return array('count'=>count($calls),'total_ms'=>$this->http_total(),'top'=>array_slice($calls,0,10),'coverage'=>'from_start');}
    private function sanitize_url($url){$p=wp_parse_url((string)$url);if(!is_array($p))return substr((string)$url,0,1000);return substr((isset($p['scheme'])?$p['scheme'].'://':'').($p['host']??'').(isset($p['port'])?':'.$p['port']:'').($p['path']??''),0,1000);}
}
