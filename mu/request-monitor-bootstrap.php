<?php
/**
 * Plugin Name: Request Monitor Bootstrap
 * Description: Mandatory MU bootstrap for Request Monitor.
 * Version: 0.5.0
 */

if (!defined('ABSPATH') || defined('RRT_MU_BOOTSTRAP_LOADED')) {
    return;
}

define('RRT_MU_BOOTSTRAP_LOADED', true);
define('RRT_MU_VERSION', '0.5.0');

$rrt_runtime_file = __DIR__ . '/request-monitor-runtime.php';
$rrt_profiler_file = __DIR__ . '/request-monitor-hook-profiler.php';

if (!is_file($rrt_runtime_file)) {
    return;
}

require_once $rrt_runtime_file;
if (is_file($rrt_profiler_file)) {
    require_once $rrt_profiler_file;
}

function rrt_mu_shutdown() {
    if (empty($GLOBALS['rrt_bootstrap_context']) || !is_array($GLOBALS['rrt_bootstrap_context'])) return;
    $ctx=$GLOBALS['rrt_bootstrap_context']; $end=microtime(true);
    $resources=rrt_mu_delta($ctx['resource_start'],rrt_mu_snapshot());
    $wall_ms=($end-$ctx['start_time'])*1000; $cpu_ms=$resources['cpu_total_ms']??null;
    $ratio=($cpu_ms!==null&&$wall_ms>0)?min(10,$cpu_ms/$wall_ms):null;
    $is_slow=$wall_ms >= $ctx['slow_threshold_ms'];
    $last=error_get_last(); $fatal=null;
    if ($last&&in_array($last['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR,E_USER_ERROR,E_RECOVERABLE_ERROR),true)) $fatal=array('type'=>$last['type'],'message'=>$last['message'],'file'=>$last['file'],'line'=>$last['line']);
    $extra=array();
    if (!empty($ctx['finalizer'])&&is_callable($ctx['finalizer'])) {
        try { $candidate=call_user_func($ctx['finalizer']); if(is_array($candidate))$extra=$candidate; }
        catch(Throwable $e){$extra['finalizer_error']=$e->getMessage();}
    }
    $hook_profile=array('available'=>false,'reason'=>'hook profiler helper not loaded');
    if (class_exists('Request_Monitor_Hook_Profiler',false)) { $hook_profile=Request_Monitor_Hook_Profiler::report($is_slow||!empty($ctx['deep'])); $hook_profile['available']=true; }
    $capture_level=!empty($ctx['deep'])?($is_slow?'deep_slow':'deep'):($is_slow?'auto_slow':'basic');
    $event=array_merge(array(
        'event'=>'END','version'=>'0.5.0','mu_version'=>RRT_MU_VERSION,'capture_stage'=>'mu-bootstrap','timestamp'=>rrt_mu_timestamp($end),
        'request_id'=>$ctx['request_id'],'pid'=>$ctx['pid'],'method'=>$ctx['method'],'host'=>$ctx['host'],'path'=>$ctx['path'],'request_type'=>$ctx['request_type'],'status'=>function_exists('http_response_code')?http_response_code():null,
        'request_fingerprint'=>$ctx['request_fingerprint'],'pattern_fingerprint'=>$ctx['pattern_fingerprint'],'query_fingerprint'=>$ctx['query_fingerprint'],'query_shape_fingerprint'=>$ctx['query_shape_fingerprint'],'fingerprint_basis'=>$ctx['fingerprint_basis'],
        'wall_ms'=>round($wall_ms,3),'cpu_user_ms'=>$resources['cpu_user_ms']??null,'cpu_sys_ms'=>$resources['cpu_sys_ms']??null,'cpu_total_ms'=>$cpu_ms,'cpu_ratio'=>$ratio!==null?round($ratio,4):null,
        'classification'=>rrt_mu_classify($wall_ms,$ratio),'slow_request'=>$is_slow,'slow_threshold_ms'=>$ctx['slow_threshold_ms'],'slow_over_ms'=>$is_slow?round($wall_ms-$ctx['slow_threshold_ms'],3):0,
        'capture_level'=>$capture_level,'auto_escalated'=>!empty($ctx['auto_escalated']),'auto_escalated_at_ms'=>$ctx['auto_escalated_at_ms']??null,'auto_escalation_reason'=>$ctx['auto_escalation_reason']??null,
        'peak_memory_mb'=>round(memory_get_peak_usage(true)/1048576,2),'memory_end_mb'=>round(memory_get_usage(true)/1048576,2),'resources'=>$resources,
        'phases'=>$ctx['phases'],'phase_durations'=>rrt_mu_phase_durations($ctx['phases'],$end),'included_files'=>count(get_included_files()),'hook_profile'=>$hook_profile,
        'connection_aborted'=>function_exists('connection_aborted')?connection_aborted():null,'fatal'=>$fatal,
    ),$extra);
    rrt_mu_write($event);
}

$start=microtime(true);
$request_uri=isset($_SERVER['REQUEST_URI'])?(string)$_SERVER['REQUEST_URI']:'';
$raw_path=$request_uri!==''?parse_url($request_uri,PHP_URL_PATH):'/';
$path=rrt_mu_normalize_path($raw_path,false);
$method=$_SERVER['REQUEST_METHOD']??(((defined('WP_CLI')&&WP_CLI)||PHP_SAPI==='cli')?'CLI':'GET');
$action=rrt_mu_request_action();
$request_type=rrt_mu_request_type($path);
$descriptor=array('type'=>$request_type,'method'=>$method,'path'=>$path,'action'=>$action);
if (!rrt_mu_should_trace($descriptor)) return;

$pid=function_exists('getmypid')?(int)getmypid():0;
try{$rand=bin2hex(random_bytes(3));}catch(Throwable $e){$rand=substr(md5(uniqid('',true)),0,6);}
$request_id=sprintf('%d-%s-%s',$pid,str_replace('.','',sprintf('%.6f',$start)),$rand);
$query_string=isset($_SERVER['QUERY_STRING'])?(string)$_SERVER['QUERY_STRING']:'';
$query_fp=rrt_mu_query_fingerprints();
$pattern_path=rrt_mu_normalize_path($path,true);
$basis=array('method'=>strtoupper($method),'path'=>$path,'pattern_path'=>$pattern_path,'action'=>$action,'query_keys'=>$query_fp['query_keys']);
$request_fingerprint=substr(hash('sha256',strtoupper($method).'|'.$path.'|'.$action.'|'.$query_fp['query_fingerprint']),0,20);
$pattern_fingerprint=substr(hash('sha256',strtoupper($method).'|'.$pattern_path.'|'.$action.'|'.$query_fp['query_shape_fingerprint']),0,20);
$deep=(bool)get_option('rrt_deep_attribution',false);
$slow_threshold_ms=max(250,min(60000,(int)get_option('rrt_slow_threshold_ms',1500)));
$callback_floor_ms=max(0.1,min(1000,(float)get_option('rrt_callback_floor_ms',5)));

$GLOBALS['rrt_bootstrap_context']=array(
    'start_time'=>$start,'request_id'=>$request_id,'pid'=>$pid,'method'=>$method,'host'=>$_SERVER['HTTP_HOST']??null,'path'=>$path,'request_type'=>$request_type,
    'request_fingerprint'=>$request_fingerprint,'pattern_fingerprint'=>$pattern_fingerprint,'query_fingerprint'=>$query_fp['query_fingerprint'],'query_shape_fingerprint'=>$query_fp['query_shape_fingerprint'],'fingerprint_basis'=>$basis,
    'deep'=>$deep,'slow_threshold_ms'=>$slow_threshold_ms,'callback_floor_ms'=>$callback_floor_ms,'auto_escalated'=>false,'auto_escalated_at_ms'=>null,'auto_escalation_reason'=>null,
    'resource_start'=>rrt_mu_snapshot(),'phases'=>array('mu_loaded'=>$start),'finalizer'=>null,
);

if ($deep) { global $wpdb; if(isset($wpdb)&&is_object($wpdb)&&property_exists($wpdb,'save_queries'))$wpdb->save_queries=true; }
if (class_exists('Request_Monitor_Hook_Profiler',false)) Request_Monitor_Hook_Profiler::start($start,$slow_threshold_ms,$callback_floor_ms,$deep);

rrt_mu_write(array(
    'event'=>'START','version'=>'0.5.0','mu_version'=>RRT_MU_VERSION,'capture_stage'=>'mu-bootstrap','timestamp'=>rrt_mu_timestamp($start),'request_id'=>$request_id,'pid'=>$pid,
    'ppid'=>function_exists('posix_getppid')?@posix_getppid():null,'uid'=>function_exists('posix_geteuid')?@posix_geteuid():null,'method'=>$method,'host'=>$_SERVER['HTTP_HOST']??null,'path'=>$path,'request_type'=>$request_type,
    'request_fingerprint'=>$request_fingerprint,'pattern_fingerprint'=>$pattern_fingerprint,'query_fingerprint'=>$query_fp['query_fingerprint'],'query_shape_fingerprint'=>$query_fp['query_shape_fingerprint'],'fingerprint_basis'=>$basis,
    'query_keys'=>$query_fp['query_keys'],'query_hash'=>$query_string!==''?substr(hash('sha256',$query_string),0,16):null,'safe_query'=>rrt_mu_safe_query(),'wp_action'=>isset($_REQUEST['action'])&&is_scalar($_REQUEST['action'])?substr((string)$_REQUEST['action'],0,200):null,'wc_ajax'=>isset($_GET['wc-ajax'])&&is_scalar($_GET['wc-ajax'])?substr((string)$_GET['wc-ajax'],0,200):null,
    'script'=>$_SERVER['SCRIPT_FILENAME']??null,'cf_ray'=>rrt_mu_server('HTTP_CF_RAY',200),'client_ip'=>rrt_mu_server('HTTP_CF_CONNECTING_IP',100)?:rrt_mu_server('REMOTE_ADDR',100),
    'remote_ip'=>rrt_mu_server('REMOTE_ADDR',100),'user_agent'=>rrt_mu_server('HTTP_USER_AGENT',1000),'referer'=>rrt_mu_server('HTTP_REFERER',1000),'content_type'=>rrt_mu_server('CONTENT_TYPE',200),'content_length'=>isset($_SERVER['CONTENT_LENGTH'])?(int)$_SERVER['CONTENT_LENGTH']:null,
    'deep'=>$deep,'slow_threshold_ms'=>$slow_threshold_ms,'callback_floor_ms'=>$callback_floor_ms,'trace_scope'=>rrt_mu_scope(),'hook_profiler_available'=>class_exists('Request_Monitor_Hook_Profiler',false),'is_cron'=>$request_type==='cron','is_ajax'=>$request_type==='ajax','is_rest'=>$request_type==='rest','is_cli'=>$request_type==='cli',
));
register_shutdown_function('rrt_mu_shutdown');
