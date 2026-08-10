<?php
/**
 * Plugin Name: Request Monitor Bootstrap
 * Description: Mandatory MU bootstrap for Request Monitor.
 * Version: 0.4.0
 */

if (!defined('ABSPATH') || defined('RRT_MU_BOOTSTRAP_LOADED')) {
    return;
}

define('RRT_MU_BOOTSTRAP_LOADED', true);
define('RRT_MU_VERSION', '0.4.0');

$rrt_profiler_file = __DIR__ . '/request-monitor-hook-profiler.php';
if (is_file($rrt_profiler_file)) {
    require_once $rrt_profiler_file;
}

function rrt_mu_server($key, $limit = 1000) {
    if (!isset($_SERVER[$key]) || !is_scalar($_SERVER[$key])) return null;
    $value = (string) $_SERVER[$key];
    return $value === '' ? null : substr($value, 0, $limit);
}

function rrt_mu_timestamp($time) {
    $ms = (int) (($time - floor($time)) * 1000);
    return gmdate('Y-m-d\\TH:i:s', (int) $time) . '.' . sprintf('%03d', $ms) . 'Z';
}

function rrt_mu_snapshot() {
    $out = array(
        'cpu_user_ms'=>null,'cpu_sys_ms'=>null,'cpu_total_ms'=>null,'minor_faults'=>null,'major_faults'=>null,
        'voluntary_ctx'=>null,'involuntary_ctx'=>null,'block_in'=>null,'block_out'=>null,
        'rchar'=>null,'wchar'=>null,'read_bytes'=>null,'write_bytes'=>null,'syscr'=>null,'syscw'=>null,
    );
    if (function_exists('getrusage')) {
        $r = getrusage();
        $user = (($r['ru_utime.tv_sec'] ?? 0) * 1000) + (($r['ru_utime.tv_usec'] ?? 0) / 1000);
        $sys = (($r['ru_stime.tv_sec'] ?? 0) * 1000) + (($r['ru_stime.tv_usec'] ?? 0) / 1000);
        $out['cpu_user_ms']=$user; $out['cpu_sys_ms']=$sys; $out['cpu_total_ms']=$user+$sys;
        $out['minor_faults']=isset($r['ru_minflt'])?(int)$r['ru_minflt']:null;
        $out['major_faults']=isset($r['ru_majflt'])?(int)$r['ru_majflt']:null;
        $out['voluntary_ctx']=isset($r['ru_nvcsw'])?(int)$r['ru_nvcsw']:null;
        $out['involuntary_ctx']=isset($r['ru_nivcsw'])?(int)$r['ru_nivcsw']:null;
        $out['block_in']=isset($r['ru_inblock'])?(int)$r['ru_inblock']:null;
        $out['block_out']=isset($r['ru_oublock'])?(int)$r['ru_oublock']:null;
    }
    if (is_readable('/proc/self/io')) {
        $lines = @file('/proc/self/io', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (is_array($lines)) foreach ($lines as $line) {
            if (strpos($line, ':') === false) continue;
            list($key,$value)=array_map('trim',explode(':',$line,2));
            if (array_key_exists($key,$out) && is_numeric($value)) $out[$key]=(int)$value;
        }
    }
    return $out;
}

function rrt_mu_delta($start, $end) {
    $out = array();
    foreach ($end as $key=>$value) {
        $before = array_key_exists($key,$start) ? $start[$key] : null;
        if ($value===null || $before===null) { $out[$key]=null; continue; }
        $delta = $value-$before;
        $out[$key] = strpos($key,'_ms')!==false ? round($delta,3) : $delta;
    }
    return $out;
}

function rrt_mu_log_file() {
    $dir = WP_CONTENT_DIR . '/rocket-request-tracer';
    if (!is_dir($dir)) @wp_mkdir_p($dir);
    if (!file_exists($dir.'/index.php')) @file_put_contents($dir.'/index.php',"<?php\nexit;\n");
    if (!file_exists($dir.'/.htaccess')) @file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n");
    $seed = defined('AUTH_SALT') ? AUTH_SALT : ABSPATH;
    $file = $dir . '/trace-' . substr(hash('sha256',$seed),0,16) . '.php';
    if (!file_exists($file)) @file_put_contents($file,"<?php exit; __halt_compiler(); ?>\n",LOCK_EX);
    return $file;
}

function rrt_mu_rotate($file) {
    $max_mb = max(1, (int) get_option('rrt_max_log_mb', 25));
    if (!is_file($file) || @filesize($file) <= ($max_mb * 1048576)) return;
    $rotated = preg_replace('/\\.php$/', '-' . gmdate('Ymd-His') . '.php', $file);
    if (@rename($file, $rotated)) {
        @file_put_contents($file, "<?php exit; __halt_compiler(); ?>\n", LOCK_EX);
    }
    $files = glob(dirname($file) . '/trace-*.php');
    if (is_array($files) && count($files) > 4) {
        usort($files, function ($a,$b) { return @filemtime($a) <=> @filemtime($b); });
        while (count($files) > 4) {
            $oldest = array_shift($files);
            if ($oldest !== $file) @unlink($oldest);
        }
    }
}

function rrt_mu_write($event) {
    $file = rrt_mu_log_file();
    rrt_mu_rotate($file);
    $json = function_exists('wp_json_encode')
        ? wp_json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        : json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json !== false) @file_put_contents($file, $json . "\n", FILE_APPEND | LOCK_EX);
}

function rrt_mu_safe_query() {
    $out=array();
    foreach ($_GET as $key=>$value) {
        $key=(string)$key;
        $allowed = strpos($key,'filter_')===0 || strpos($key,'query_type_')===0 || in_array($key,array('orderby','paged','page','product_cat','product_tag','min_price','max_price','s','rest_route'),true);
        if (!$allowed) continue;
        if (is_scalar($value)) $out[$key]=substr((string)$value,0,500);
        elseif (is_array($value)) {
            $vals=array(); foreach(array_slice($value,0,20) as $v) $vals[]=is_scalar($v)?substr((string)$v,0,500):'[complex]';
            $out[$key]=$vals;
        }
    }
    return $out;
}

function rrt_mu_should_trace() {
    if (PHP_SAPI==='cli' || (defined('WP_CLI')&&WP_CLI)) return false;
    if (isset($_REQUEST['page']) && $_REQUEST['page']==='rocket-request-tracer') return false;
    if (isset($_REQUEST['action']) && in_array($_REQUEST['action'],array('rrt_save','rrt_clear','rrt_download','rrt_repair_mu'),true)) return false;
    return (bool)get_option('rrt_enabled',false);
}

function rrt_mu_mark($name) {
    if (empty($GLOBALS['rrt_bootstrap_context']) || !is_array($GLOBALS['rrt_bootstrap_context'])) return;
    $GLOBALS['rrt_bootstrap_context']['phases'][$name]=microtime(true);
}

function rrt_mu_classify($wall_ms,$ratio) {
    if ($wall_ms<750) return 'FAST';
    if ($ratio===null) return 'UNKNOWN';
    if ($ratio>=0.70) return 'CPU_BOUND';
    if ($ratio<=0.25) return 'WAIT_BOUND';
    return 'MIXED';
}

function rrt_mu_phase_durations($phases,$end) {
    $out=array(); $previous_name='mu_loaded'; $previous=$phases['mu_loaded']??null;
    foreach ($phases as $name=>$time) {
        if ($name==='mu_loaded' || $previous===null) continue;
        $out[$previous_name.'_to_'.$name.'_ms']=round(($time-$previous)*1000,3);
        $previous_name=$name; $previous=$time;
    }
    if ($previous!==null) $out[$previous_name.'_to_shutdown_ms']=round(($end-$previous)*1000,3);
    return $out;
}

function rrt_mu_shutdown() {
    if (empty($GLOBALS['rrt_bootstrap_context']) || !is_array($GLOBALS['rrt_bootstrap_context'])) return;
    $ctx=$GLOBALS['rrt_bootstrap_context']; $end=microtime(true);
    $resources=rrt_mu_delta($ctx['resource_start'],rrt_mu_snapshot());
    $wall_ms=($end-$ctx['start_time'])*1000; $cpu_ms=$resources['cpu_total_ms']??null;
    $ratio=($cpu_ms!==null&&$wall_ms>0)?min(10,$cpu_ms/$wall_ms):null;
    $is_slow = $wall_ms >= $ctx['slow_threshold_ms'];
    $last=error_get_last(); $fatal=null;
    if ($last&&in_array($last['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR),true)) $fatal=array('type'=>$last['type'],'message'=>$last['message'],'file'=>$last['file'],'line'=>$last['line']);
    $extra=array();
    if (!empty($ctx['finalizer'])&&is_callable($ctx['finalizer'])) {
        try { $candidate=call_user_func($ctx['finalizer']); if(is_array($candidate))$extra=$candidate; }
        catch(Throwable $e){$extra['finalizer_error']=$e->getMessage();}
    }

    $hook_profile = array('available'=>false,'reason'=>'hook profiler helper not loaded');
    if (class_exists('Request_Monitor_Hook_Profiler', false)) {
        $hook_profile = Request_Monitor_Hook_Profiler::report($is_slow || !empty($ctx['deep']));
        $hook_profile['available'] = true;
    }

    $capture_level = !empty($ctx['deep']) ? ($is_slow ? 'deep_slow' : 'deep') : ($is_slow ? 'auto_slow' : 'basic');

    $event=array_merge(array(
        'event'=>'END','version'=>'0.4.0','mu_version'=>RRT_MU_VERSION,'capture_stage'=>'mu-bootstrap','timestamp'=>rrt_mu_timestamp($end),
        'request_id'=>$ctx['request_id'],'pid'=>$ctx['pid'],'method'=>$ctx['method'],'host'=>$ctx['host'],'path'=>$ctx['path'],'status'=>function_exists('http_response_code')?http_response_code():null,
        'wall_ms'=>round($wall_ms,3),'cpu_user_ms'=>$resources['cpu_user_ms']??null,'cpu_sys_ms'=>$resources['cpu_sys_ms']??null,'cpu_total_ms'=>$cpu_ms,'cpu_ratio'=>$ratio!==null?round($ratio,4):null,
        'classification'=>rrt_mu_classify($wall_ms,$ratio),'slow_request'=>$is_slow,'slow_threshold_ms'=>$ctx['slow_threshold_ms'],'slow_over_ms'=>$is_slow?round($wall_ms-$ctx['slow_threshold_ms'],3):0,
        'capture_level'=>$capture_level,'auto_escalated'=>!empty($ctx['auto_escalated']),'auto_escalated_at_ms'=>$ctx['auto_escalated_at_ms']??null,'auto_escalation_reason'=>$ctx['auto_escalation_reason']??null,
        'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,2),'memory_end_mb'=>round(memory_get_usage(true)/1048576,2),
        'resources'=>$resources,'phases'=>$ctx['phases'],'phase_durations'=>rrt_mu_phase_durations($ctx['phases'],$end),'included_files'=>count(get_included_files()),
        'hook_profile'=>$hook_profile,'connection_aborted'=>function_exists('connection_aborted')?connection_aborted():null,'fatal'=>$fatal,
    ),$extra);
    rrt_mu_write($event);
}

if (!rrt_mu_should_trace()) return;
$start=microtime(true); $pid=function_exists('getmypid')?(int)getmypid():0;
try{$rand=bin2hex(random_bytes(3));}catch(Throwable $e){$rand=substr(md5(uniqid('',true)),0,6);}
$request_id=sprintf('%d-%s-%s',$pid,str_replace('.','',sprintf('%.6f',$start)),$rand);
$request_uri=isset($_SERVER['REQUEST_URI'])?(string)$_SERVER['REQUEST_URI']:''; $path=$request_uri!==''?parse_url($request_uri,PHP_URL_PATH):null;
$query_string=isset($_SERVER['QUERY_STRING'])?(string)$_SERVER['QUERY_STRING']:'';
$wp_action=isset($_REQUEST['action'])&&is_scalar($_REQUEST['action'])?substr((string)$_REQUEST['action'],0,200):null;
$wc_ajax=isset($_GET['wc-ajax'])&&is_scalar($_GET['wc-ajax'])?substr((string)$_GET['wc-ajax'],0,200):null;
$deep=(bool)get_option('rrt_deep_attribution',false);
$slow_threshold_ms=max(250,min(60000,(int)get_option('rrt_slow_threshold_ms',1500)));
$callback_floor_ms=max(0.1,min(1000,(float)get_option('rrt_callback_floor_ms',5)));

$GLOBALS['rrt_bootstrap_context']=array(
    'start_time'=>$start,'request_id'=>$request_id,'pid'=>$pid,'method'=>$_SERVER['REQUEST_METHOD']??null,'host'=>$_SERVER['HTTP_HOST']??null,'path'=>$path,
    'deep'=>$deep,'slow_threshold_ms'=>$slow_threshold_ms,'callback_floor_ms'=>$callback_floor_ms,'auto_escalated'=>false,'auto_escalated_at_ms'=>null,'auto_escalation_reason'=>null,
    'resource_start'=>rrt_mu_snapshot(),'phases'=>array('mu_loaded'=>$start),'finalizer'=>null,
);

if ($deep) {
    global $wpdb;
    if(isset($wpdb)&&is_object($wpdb)&&property_exists($wpdb,'save_queries'))$wpdb->save_queries=true;
}

if (class_exists('Request_Monitor_Hook_Profiler', false)) {
    Request_Monitor_Hook_Profiler::start($start,$slow_threshold_ms,$callback_floor_ms,$deep);
}

rrt_mu_write(array(
    'event'=>'START','version'=>'0.4.0','mu_version'=>RRT_MU_VERSION,'capture_stage'=>'mu-bootstrap','timestamp'=>rrt_mu_timestamp($start),'request_id'=>$request_id,'pid'=>$pid,
    'ppid'=>function_exists('posix_getppid')?@posix_getppid():null,'uid'=>function_exists('posix_geteuid')?@posix_geteuid():null,'method'=>$_SERVER['REQUEST_METHOD']??null,'host'=>$_SERVER['HTTP_HOST']??null,'path'=>$path,
    'query_keys'=>array_keys($_GET),'query_hash'=>$query_string!==''?substr(hash('sha256',$query_string),0,16):null,'safe_query'=>rrt_mu_safe_query(),'wp_action'=>$wp_action,'wc_ajax'=>$wc_ajax,
    'script'=>$_SERVER['SCRIPT_FILENAME']??null,'cf_ray'=>rrt_mu_server('HTTP_CF_RAY',200),'client_ip'=>rrt_mu_server('HTTP_CF_CONNECTING_IP',100)?:rrt_mu_server('REMOTE_ADDR',100),
    'remote_ip'=>rrt_mu_server('REMOTE_ADDR',100),'user_agent'=>rrt_mu_server('HTTP_USER_AGENT',1000),'referer'=>rrt_mu_server('HTTP_REFERER',1000),'content_type'=>rrt_mu_server('CONTENT_TYPE',200),
    'content_length'=>isset($_SERVER['CONTENT_LENGTH'])?(int)$_SERVER['CONTENT_LENGTH']:null,'deep'=>$deep,'slow_threshold_ms'=>$slow_threshold_ms,'callback_floor_ms'=>$callback_floor_ms,
    'hook_profiler_available'=>class_exists('Request_Monitor_Hook_Profiler',false),'is_cron'=>defined('DOING_CRON')&&DOING_CRON,'is_ajax'=>defined('DOING_AJAX')&&DOING_AJAX,
));
register_shutdown_function('rrt_mu_shutdown');
