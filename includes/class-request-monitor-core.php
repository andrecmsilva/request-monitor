<?php
if (!defined('ABSPATH')) exit;

final class Request_Monitor_Core {
    const VERSION='0.7.0';
    const MU_VERSION='0.7.0';

    const OPT_ENABLED='rrt_enabled';
    const OPT_DEEP='rrt_deep_attribution';
    const OPT_MAX_MB='rrt_max_log_mb';
    const OPT_SLOW_MS='rrt_slow_threshold_ms';
    const OPT_CALLBACK_FLOOR='rrt_callback_floor_ms';
    const OPT_SQL_STACK_MS='rrt_sql_stack_threshold_ms';
    const OPT_SCOPE='rrt_trace_scope';
    const OPT_CAPTURE_UNTIL='rrt_capture_until';
    const OPT_CAPTURE_STARTED='rrt_capture_started';
    const OPT_CAPTURE_DURATION='rrt_capture_duration';
    const OPT_CAPTURE_SESSION='rrt_capture_session';
    const OPT_CAPTURE_PROFILE='rrt_capture_profile';
    const OPT_VERSION='rrt_monitor_version';

    const MIN_CAPTURE_SECONDS=5;
    const MAX_CAPTURE_SECONDS=300;

    private static $instance=null;
    private $http_calls=array();
    private $http_pending=array();

    public static function instance(){if(self::$instance===null)self::$instance=new self();return self::$instance;}
    private function __construct(){self::maybe_upgrade();if($this->has_trace_context())$this->attach_to_mu_context();}

    private static function maybe_upgrade(){
        $installed=(string)get_option(self::OPT_VERSION,'');
        if($installed===self::VERSION)return;
        update_option(self::OPT_ENABLED,0,false);
        update_option(self::OPT_DEEP,0,false);
        add_option(self::OPT_CAPTURE_UNTIL,0,'',true);
        add_option(self::OPT_CAPTURE_STARTED,0,'',false);
        add_option(self::OPT_CAPTURE_DURATION,0,'',false);
        add_option(self::OPT_CAPTURE_SESSION,'','',false);
        add_option(self::OPT_CAPTURE_PROFILE,'light','',false);
        add_option(self::OPT_SQL_STACK_MS,10,'',false);
        update_option(self::OPT_CAPTURE_UNTIL,0,true);
        self::install_mu();
        update_option(self::OPT_VERSION,self::VERSION,true);
    }

    public static function default_scope(){return array('types'=>array('front','admin','ajax','rest','cron'),'methods'=>array(),'include_paths'=>array(),'exclude_paths'=>array(),'include_actions'=>array(),'exclude_actions'=>array());}
    public static function get_scope(){$s=get_option(self::OPT_SCOPE,array());return is_array($s)?array_merge(self::default_scope(),$s):self::default_scope();}
    public static function sanitize_scope($scope){
        $out=self::default_scope();
        foreach(array_keys($out) as $key){if(!isset($scope[$key]))continue;$v=is_array($scope[$key])?$scope[$key]:preg_split('/[\r\n,]+/',(string)$scope[$key]);$out[$key]=array_values(array_filter(array_map('trim',$v),function($x){return $x!=='';}));}
        $out['types']=array_values(array_intersect(array('front','admin','ajax','rest','cron','cli'),$out['types']));
        $out['methods']=array_values(array_unique(array_map('strtoupper',$out['methods'])));
        return $out;
    }
    public static function save_scope($scope){update_option(self::OPT_SCOPE,self::sanitize_scope($scope),false);}

    private static function mu_files(){return array('request-monitor-bootstrap.php','request-monitor-runtime.php','request-monitor-hook-profiler.php');}
    private static function mu_source($file){return REQUEST_MONITOR_DIR.'mu/'.$file;}
    private static function mu_target($file){$dir=defined('WPMU_PLUGIN_DIR')?WPMU_PLUGIN_DIR:WP_CONTENT_DIR.'/mu-plugins';return trailingslashit($dir).$file;}
    public static function install_mu(){
        $dir=defined('WPMU_PLUGIN_DIR')?WPMU_PLUGIN_DIR:WP_CONTENT_DIR.'/mu-plugins';
        if(!is_dir($dir)&&!wp_mkdir_p($dir))return new WP_Error('rrt_mu_dir','Could not create MU plugin directory: '.$dir);
        foreach(self::mu_files() as $file){
            $src=self::mu_source($file);$dst=self::mu_target($file);
            if(!is_file($src))return new WP_Error('rrt_mu_source','Missing bundled MU component: '.$file);
            if(!is_writable($dir)&&!(is_file($dst)&&is_writable($dst)))return new WP_Error('rrt_mu_permissions','MU plugin directory is not writable: '.$dir);
            $src_hash=@hash_file('sha256',$src);$dst_hash=is_file($dst)?@hash_file('sha256',$dst):null;if($src_hash&&$src_hash===$dst_hash)continue;
            $tmp=$dst.'.tmp-'.getmypid();if(!@copy($src,$tmp))return new WP_Error('rrt_mu_copy','Could not copy '.$file);@chmod($tmp,0644);
            if(!@rename($tmp,$dst)){@unlink($tmp);return new WP_Error('rrt_mu_rename','Could not install '.$file);}
        }
        return true;
    }
    public static function mu_healthy(){foreach(self::mu_files() as $file){$src=self::mu_source($file);$dst=self::mu_target($file);if(!is_file($src)||!is_file($dst)||@hash_file('sha256',$src)!==@hash_file('sha256',$dst))return false;}return true;}

    public static function activate(){
        $r=self::install_mu();if(is_wp_error($r)){deactivate_plugins(plugin_basename(REQUEST_MONITOR_FILE));wp_die('<h1>Request Monitor activation failed</h1><p>'.esc_html($r->get_error_message()).'</p>','Request Monitor',array('back_link'=>true));}
        update_option(self::OPT_ENABLED,0,false);update_option(self::OPT_DEEP,0,false);
        add_option(self::OPT_SLOW_MS,1500,'',false);add_option(self::OPT_CALLBACK_FLOOR,5,'',false);add_option(self::OPT_SQL_STACK_MS,10,'',false);add_option(self::OPT_MAX_MB,25,'',false);add_option(self::OPT_SCOPE,self::default_scope(),'',false);
        add_option(self::OPT_CAPTURE_UNTIL,0,'',true);add_option(self::OPT_CAPTURE_STARTED,0,'',false);add_option(self::OPT_CAPTURE_DURATION,0,'',false);add_option(self::OPT_CAPTURE_SESSION,'','',false);add_option(self::OPT_CAPTURE_PROFILE,'light','',false);add_option(self::OPT_VERSION,self::VERSION,'',true);
        update_option(self::OPT_VERSION,self::VERSION,true);self::stop_capture();
    }
    public static function deactivate(){self::stop_capture();update_option(self::OPT_ENABLED,0,false);update_option(self::OPT_DEEP,0,false);foreach(self::mu_files() as $file){$dst=self::mu_target($file);if(!is_file($dst))continue;$head=@file_get_contents($dst,false,null,0,512);if(is_string($head)&&strpos($head,'Request Monitor')!==false)@unlink($dst);}}

    public static function parse_duration($value){$value=strtolower(trim((string)$value));if($value==='')return 0;if(ctype_digit($value))return (int)$value;if(!preg_match('/^(\d+)\s*([sm])$/',$value,$m))return 0;$n=(int)$m[1];return $m[2]==='m'?$n*60:$n;}
    public static function valid_profiles(){return array('light','hooks','deep');}
    public static function validate_profile($profile){$profile=strtolower(trim((string)$profile));return in_array($profile,self::valid_profiles(),true)?$profile:null;}
    public static function normalize_profile($profile){$valid=self::validate_profile($profile);return $valid!==null?$valid:'light';}
    public static function start_capture($seconds,$profile='light'){
        $seconds=(int)$seconds;
        if($seconds<self::MIN_CAPTURE_SECONDS||$seconds>self::MAX_CAPTURE_SECONDS)return new WP_Error('rrt_capture_duration',sprintf('Capture duration must be between %d and %d seconds.',self::MIN_CAPTURE_SECONDS,self::MAX_CAPTURE_SECONDS));
        $profile=self::validate_profile($profile);if($profile===null)return new WP_Error('rrt_capture_profile','Invalid profile. Use one of: light, hooks, deep.');
        $r=self::install_mu();if(is_wp_error($r))return $r;
        update_option(self::OPT_ENABLED,0,false);update_option(self::OPT_DEEP,0,false);
        $started=time();$until=$started+$seconds;
        try{$session=gmdate('Ymd-His',$started).'-'.bin2hex(random_bytes(3));}catch(Throwable $e){$session=gmdate('Ymd-His',$started).'-'.substr(md5(uniqid('',true)),0,6);}
        update_option(self::OPT_CAPTURE_STARTED,$started,false);update_option(self::OPT_CAPTURE_DURATION,$seconds,false);update_option(self::OPT_CAPTURE_SESSION,$session,false);update_option(self::OPT_CAPTURE_PROFILE,$profile,false);update_option(self::OPT_CAPTURE_UNTIL,$until,true);
        return array('session'=>$session,'profile'=>$profile,'started'=>$started,'until'=>$until,'seconds'=>$seconds);
    }
    public static function stop_capture(){update_option(self::OPT_CAPTURE_UNTIL,0,true);return true;}
    public static function capture_state(){$until=(int)get_option(self::OPT_CAPTURE_UNTIL,0);$started=(int)get_option(self::OPT_CAPTURE_STARTED,0);$duration=(int)get_option(self::OPT_CAPTURE_DURATION,0);$now=time();$active=$until>$now;return array('state'=>$active?'capturing':'idle','active'=>$active,'seconds_remaining'=>$active?max(0,$until-$now):0,'started'=>$started,'started_utc'=>$started?gmdate('Y-m-d\TH:i:s\Z',$started):null,'until'=>$until,'until_utc'=>$until?gmdate('Y-m-d\TH:i:s\Z',$until):null,'duration_seconds'=>$duration,'session'=>(string)get_option(self::OPT_CAPTURE_SESSION,''),'profile'=>self::normalize_profile(get_option(self::OPT_CAPTURE_PROFILE,'light')));}

    private function has_trace_context(){return !empty($GLOBALS['rrt_bootstrap_context'])&&is_array($GLOBALS['rrt_bootstrap_context']);}
    private function mark($name){if($this->has_trace_context())$GLOBALS['rrt_bootstrap_context']['phases'][$name]=microtime(true);}
    private function attach_to_mu_context(){
        $this->mark('regular_plugin_loaded');$GLOBALS['rrt_bootstrap_context']['finalizer']=array($this,'build_enrichment');
        foreach(array('plugins_loaded','after_setup_theme','init','wp_loaded','parse_request','wp','template_redirect','admin_init','rest_api_init') as $hook)add_action($hook,function()use($hook){$this->mark($hook);},PHP_INT_MAX);
        $profile=$GLOBALS['rrt_bootstrap_context']['profile']??'light';
        if($profile!=='light'){
            add_filter('http_request_args',array($this,'http_start'),-9999,2);add_action('http_api_debug',array($this,'http_end'),9999,5);
            add_filter('log_query_custom_data',array($this,'query_custom_data'),9999,5);
        }
    }

    public function query_custom_data($query_data,$query,$query_time,$query_callstack,$query_start){
        if(!is_array($query_data))$query_data=array();
        $threshold=max(1,min(1000,(float)get_option(self::OPT_SQL_STACK_MS,10)));
        $ms=(float)$query_time*1000;
        if($ms<$threshold)return $query_data;
        $query_data['rrt_slow_query']=true;
        $query_data['rrt_query_hash']=substr(hash('sha256',self::normalize_sql($query)),0,20);
        $query_data['rrt_stack']=$this->compact_backtrace(18);
        $query_data['rrt_stack_threshold_ms']=$threshold;
        return $query_data;
    }

    public function http_start($args,$url){
        $id=count($this->http_pending);
        $this->http_pending[$id]=array('raw'=>(string)$url,'url'=>$this->sanitize_url($url),'start'=>microtime(true),'caller'=>function_exists('wp_debug_backtrace_summary')?wp_debug_backtrace_summary(null,0,false):null,'stack'=>$this->compact_backtrace(14),'done'=>false);
        return $args;
    }
    public function http_end($response,$context,$class,$parsed_args,$url){
        $match=null;foreach($this->http_pending as $id=>$p)if(!$p['done']&&$p['raw']===(string)$url){$match=$id;break;}if($match===null)return;
        $p=$this->http_pending[$match];$this->http_pending[$match]['done']=true;$code=null;$error=null;if(is_wp_error($response))$error=$response->get_error_code();elseif(is_array($response))$code=wp_remote_retrieve_response_code($response);
        $owner=$this->first_application_owner($p['stack']);
        $this->http_calls[]=array('url'=>$p['url'],'duration_ms'=>round((microtime(true)-$p['start'])*1000,3),'response'=>$code,'error'=>$error,'caller'=>$p['caller'],'stack'=>$p['stack'],'owner'=>$owner,'transport'=>is_object($class)?get_class($class):(is_string($class)?$class:null));
    }

    public function build_enrichment(){
        $ctx=$this->has_trace_context()?$GLOBALS['rrt_bootstrap_context']:array();$profile=$ctx['profile']??'light';$deep=$profile==='deep';$escalated=!empty($ctx['auto_escalated']);$rich=$profile!=='light';
        $sql_enabled=defined('SAVEQUERIES')&&SAVEQUERIES;
        return array(
            'plugin_version'=>self::VERSION,
            'wordpress'=>$this->wp_context(),
            'included_groups'=>$rich?$this->included_groups():array(),
            'sql'=>($sql_enabled&&($deep||$escalated||$profile==='hooks'))?$this->sql_summary():array('enabled'=>false,'count'=>null,'total_ms'=>null,'top_groups'=>array(),'top_queries'=>array(),'coverage'=>'not_enabled','reason'=>$this->savequeries_reason($profile,$ctx)),
            'http'=>$rich?$this->http_summary():array('count'=>null,'total_ms'=>null,'groups'=>array(),'coverage'=>'not_enabled'),
            'deep_coverage'=>array('profile'=>$profile,'savequeries_enabled'=>$sql_enabled,'sql_started_from_mu_bootstrap'=>$deep&&$sql_enabled,'sql_started_post_threshold'=>$profile==='hooks'&&$escalated&&$sql_enabled,'http_started_from_regular_plugin_load'=>$rich,'hook_profiler_loaded'=>$rich,'slow_sql_stack_threshold_ms'=>(float)get_option(self::OPT_SQL_STACK_MS,10))
        );
    }

    private function savequeries_reason($profile,$ctx){
        if($profile==='light')return 'light profile';
        if(defined('SAVEQUERIES')&&!SAVEQUERIES)return 'SAVEQUERIES was already defined false and cannot be changed at runtime';
        if($profile==='hooks'&&empty($ctx['auto_escalated']))return 'hooks profile did not cross slow threshold';
        return 'SAVEQUERIES unavailable';
    }

    private function wp_context(){global $wp_query;$c=array('is_admin'=>is_admin(),'is_ajax'=>wp_doing_ajax(),'is_cron'=>wp_doing_cron(),'is_rest'=>defined('REST_REQUEST')&&REST_REQUEST,'memory_limit'=>ini_get('memory_limit'),'php_version'=>PHP_VERSION,'wp_version'=>get_bloginfo('version'));if(isset($wp_query)&&is_object($wp_query))$c['query']=array('is_home'=>$wp_query->is_home(),'is_search'=>$wp_query->is_search(),'is_archive'=>$wp_query->is_archive(),'is_tax'=>$wp_query->is_tax(),'is_singular'=>$wp_query->is_singular(),'is_404'=>$wp_query->is_404(),'post_count'=>isset($wp_query->post_count)?(int)$wp_query->post_count:null,'found_posts'=>isset($wp_query->found_posts)?(int)$wp_query->found_posts:null);return $c;}

    private function included_groups(){$groups=array();foreach(get_included_files() as $file){$g=self::owner_from_file($file);$groups[$g]=($groups[$g]??0)+1;}arsort($groups);return array_slice($groups,0,30,true);}

    public static function normalize_sql($sql){
        $sql=(string)$sql;
        $sql=preg_replace("/'(?:''|\\\\'|[^'])*'/s","'?'",$sql);
        $sql=preg_replace('/"(?:\\\\"|[^"])*"/s','"?"',$sql);
        $sql=preg_replace('/\b0x[0-9a-f]+\b/i','?',$sql);
        $sql=preg_replace('/\b\d+(?:\.\d+)?\b/','?',$sql);
        $sql=preg_replace('/\s+/',' ',trim($sql));
        return substr($sql,0,1600);
    }

    private function sql_summary(){
        global $wpdb;
        $ctx=$this->has_trace_context()?$GLOBALS['rrt_bootstrap_context']:array();$profile=$ctx['profile']??'light';
        $result=array('enabled'=>true,'count'=>0,'total_ms'=>0.0,'coverage'=>$profile==='deep'?'from_mu_bootstrap':'post_threshold','top_groups'=>array(),'top_queries'=>array());
        if(!isset($wpdb->queries)||!is_array($wpdb->queries)){$result['enabled']=false;$result['reason']='wpdb queries buffer unavailable';return $result;}
        $groups=array();$individual=array();
        foreach($wpdb->queries as $entry){
            if(!is_array($entry)||!isset($entry[0],$entry[1]))continue;
            $sql=(string)$entry[0];$ms=(float)$entry[1]*1000;$caller=isset($entry[2])?(string)$entry[2]:'';$data=isset($entry[4])&&is_array($entry[4])?$entry[4]:array();
            $normalized=self::normalize_sql($sql);$hash=substr(hash('sha256',$normalized),0,20);$stack=isset($data['rrt_stack'])&&is_array($data['rrt_stack'])?$data['rrt_stack']:array();$owner=$this->first_application_owner($stack);
            if(!isset($groups[$hash]))$groups[$hash]=array('query_hash'=>$hash,'query'=>$normalized,'count'=>0,'total_ms'=>0.0,'max_ms'=>0.0,'callers'=>array(),'owners'=>array(),'sample_stack'=>$stack);
            $g=&$groups[$hash];$g['count']++;$g['total_ms']+=$ms;$g['max_ms']=max($g['max_ms'],$ms);if($caller!=='')$g['callers'][$caller]=($g['callers'][$caller]??0)+1;if($owner!=='')$g['owners'][$owner]=($g['owners'][$owner]??0)+1;if(empty($g['sample_stack'])&&!empty($stack))$g['sample_stack']=$stack;unset($g);
            $individual[]=array('duration_ms'=>round($ms,3),'query_hash'=>$hash,'query'=>$normalized,'caller'=>$caller,'owner'=>$owner,'stack'=>$stack);
            $result['total_ms']+=$ms;
        }
        foreach($groups as &$g){$g['avg_ms']=$g['count']?round($g['total_ms']/$g['count'],3):0;$g['total_ms']=round($g['total_ms'],3);$g['max_ms']=round($g['max_ms'],3);arsort($g['callers']);arsort($g['owners']);$g['dominant_caller']=$g['callers']?array_key_first($g['callers']):'';$g['dominant_owner']=$g['owners']?array_key_first($g['owners']):'';unset($g['callers'],$g['owners']);}unset($g);
        $group_rows=array_values($groups);usort($group_rows,function($a,$b){return $b['total_ms']<=>$a['total_ms'];});usort($individual,function($a,$b){return $b['duration_ms']<=>$a['duration_ms'];});
        $result['count']=count($individual);$result['total_ms']=round($result['total_ms'],3);$result['top_groups']=array_slice($group_rows,0,25);$result['top_queries']=array_slice($individual,0,15);
        return $result;
    }

    private function http_total(){$t=0;foreach($this->http_calls as $c)$t+=(float)$c['duration_ms'];return round($t,3);}
    private function http_summary(){
        $groups=array();
        foreach($this->http_calls as $c){$key=substr(hash('sha256',$c['url'].'|'.$c['owner'].'|'.$c['caller']),0,20);if(!isset($groups[$key]))$groups[$key]=array('http_hash'=>$key,'url'=>$c['url'],'owner'=>$c['owner'],'caller'=>$c['caller'],'count'=>0,'total_ms'=>0.0,'max_ms'=>0.0,'responses'=>array(),'sample_stack'=>$c['stack'],'transport'=>$c['transport']);$g=&$groups[$key];$g['count']++;$g['total_ms']+=(float)$c['duration_ms'];$g['max_ms']=max($g['max_ms'],(float)$c['duration_ms']);$rk=$c['error']?'error:'.$c['error']:'http:'.(string)$c['response'];$g['responses'][$rk]=($g['responses'][$rk]??0)+1;unset($g);}
        foreach($groups as &$g){$g['avg_ms']=$g['count']?round($g['total_ms']/$g['count'],3):0;$g['total_ms']=round($g['total_ms'],3);$g['max_ms']=round($g['max_ms'],3);}unset($g);
        $rows=array_values($groups);usort($rows,function($a,$b){return $b['total_ms']<=>$a['total_ms'];});
        return array('count'=>count($this->http_calls),'total_ms'=>$this->http_total(),'groups'=>array_slice($rows,0,20),'coverage'=>'from_regular_plugin_load');
    }

    private function sanitize_url($url){$p=wp_parse_url((string)$url);if(!is_array($p))return substr((string)$url,0,1000);return substr((isset($p['scheme'])?$p['scheme'].'://':'').($p['host']??'').(isset($p['port'])?':'.$p['port']:'').($p['path']??''),0,1000);}

    private function compact_backtrace($limit=16){
        $trace=debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS,max(12,$limit+8));$rows=array();
        foreach($trace as $frame){
            $class=isset($frame['class'])?(string)$frame['class']:'';$fn=isset($frame['function'])?(string)$frame['function']:'';
            if(strpos($class,'Request_Monitor_')===0||strpos($fn,'rrt_')===0)continue;
            if(in_array($fn,array('apply_filters','do_action','apply_filters_ref_array','call_user_func_array'),true)&&($class===''||$class==='WP_Hook'))continue;
            $file=isset($frame['file'])?(string)$frame['file']:'';$line=isset($frame['line'])?(int)$frame['line']:null;
            $rows[]=array('callable'=>($class!==''?$class.(isset($frame['type'])?$frame['type']:'::'):'').$fn,'file'=>$this->relative_file($file),'line'=>$line,'owner'=>self::owner_from_file($file));
            if(count($rows)>=$limit)break;
        }
        return $rows;
    }
    private function first_application_owner($stack){foreach((array)$stack as $frame){$owner=$frame['owner']??'';if(preg_match('/^(plugin|mu-plugin|theme):/',$owner))return $owner;}return '';}
    private function relative_file($file){if(!$file)return '';$f=str_replace('\\','/',$file);$root=defined('ABSPATH')?str_replace('\\','/',ABSPATH):'';return $root&&strpos($f,$root)===0?ltrim(substr($f,strlen($root)),'/'):$f;}
    public static function owner_from_file($file){if(!$file)return 'internal-or-unknown';$f=str_replace('\\','/',$file);$content=defined('WP_CONTENT_DIR')?str_replace('\\','/',WP_CONTENT_DIR):'';$plugins=defined('WP_PLUGIN_DIR')?str_replace('\\','/',WP_PLUGIN_DIR):($content?$content.'/plugins':'');$mu=defined('WPMU_PLUGIN_DIR')?str_replace('\\','/',WPMU_PLUGIN_DIR):($content?$content.'/mu-plugins':'');$themes=$content?$content.'/themes':'';foreach(array('mu-plugin'=>$mu,'plugin'=>$plugins,'theme'=>$themes) as $type=>$root){if($root&&strpos($f,rtrim($root,'/').'/')===0){$rel=substr($f,strlen(rtrim($root,'/'))+1);$parts=explode('/',$rel);return $type.':'.($parts[0]?:basename($f));}}if(defined('ABSPATH')&&strpos($f,str_replace('\\','/',ABSPATH))===0)return 'wordpress-core';return 'other';}
}
