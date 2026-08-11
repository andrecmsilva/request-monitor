<?php
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) return;

final class Request_Monitor_CLI { public static function register(){WP_CLI::add_command('request-monitor','Request_Monitor_CLI_Command');} }

final class Request_Monitor_CLI_Command {
    private function display_status($data,$format){if($format==='json'){WP_CLI::line(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return;}$rows=array();foreach($data as $k=>$v){$value=is_array($v)?wp_json_encode($v,JSON_UNESCAPED_SLASHES):(is_bool($v)?($v?'yes':'no'):(string)$v);$rows[]=array('setting'=>$k,'value'=>$value);}WP_CLI\Utils\format_items('table',$rows,array('setting','value'));}

    /** Show capture state, MU health, thresholds, log path, and scope. */
    public function status($args,$assoc){$capture=Request_Monitor_Core::capture_state();$data=array('version'=>Request_Monitor_Core::VERSION,'mu_version'=>Request_Monitor_Core::MU_VERSION,'mu_healthy'=>Request_Monitor_Core::mu_healthy(),'state'=>$capture['state'],'capture_session'=>$capture['session'],'capture_profile'=>$capture['profile'],'seconds_remaining'=>$capture['seconds_remaining'],'capture_until_utc'=>$capture['until_utc'],'slow_threshold_ms'=>(int)get_option(Request_Monitor_Core::OPT_SLOW_MS,1500),'callback_floor_ms'=>(float)get_option(Request_Monitor_Core::OPT_CALLBACK_FLOOR,5),'max_log_mb'=>(int)get_option(Request_Monitor_Core::OPT_MAX_MB,25),'log_file'=>Request_Monitor_Store::log_file(),'scope'=>Request_Monitor_Core::get_scope());$this->display_status($data,$assoc['format']??'table');}

    /** Record a bounded snapshot and print fingerprint results. */
    public function capture($args,$assoc){
        if(empty($args[0]))WP_CLI::error('Use: wp request-monitor capture 30s [--profile=light|hooks|deep]');
        $seconds=Request_Monitor_Core::parse_duration($args[0]);
        if(!$seconds)WP_CLI::error('Invalid duration. Examples: 30s, 60s, 1m, 2m.');
        if(!isset($assoc['no-clear']))Request_Monitor_Store::clear_logs();
        $profile=$assoc['profile']??'light';if(isset($assoc['deep']))$profile='deep';
        $session=Request_Monitor_Core::start_capture($seconds,$profile);if(is_wp_error($session))WP_CLI::error($session->get_error_message());
        WP_CLI::success(sprintf('Snapshot %s started for %ds using %s profile. It will stop accepting new traces automatically at %s.',$session['session'],$seconds,$session['profile'],gmdate('Y-m-d H:i:s',$session['until']).' UTC'));
        WP_CLI::line('Scope: '.wp_json_encode(Request_Monitor_Core::get_scope(),JSON_UNESCAPED_SLASHES));
        if(isset($assoc['no-wait'])){WP_CLI::line('Capture is self-expiring. Check later with: wp request-monitor fingerprints --session='.$session['session'].' --min-count=1');return;}
        sleep($seconds+1);Request_Monitor_Core::stop_capture();sleep(2);
        $active=Request_Monitor_Store::active_count($session['session']);if($active)WP_CLI::warning($active.' traced request(s) are still finishing; rerun fingerprints in a few seconds for their END records.');
        $this->print_fingerprints(array(),array('mode'=>$assoc['mode']??'pattern','sort'=>$assoc['sort']??'cpu','min-count'=>$assoc['min-count']??1,'limit'=>$assoc['limit']??20,'session'=>$session['session']));
        WP_CLI::success('Snapshot complete. Request Monitor is idle.');
    }

    /** Alias for capture. */
    public function snapshot($args,$assoc){$this->capture($args,$assoc);}
    /** Stop the current capture immediately. */
    public function stop(){$before=Request_Monitor_Core::capture_state();Request_Monitor_Core::stop_capture();if($before['active'])WP_CLI::success('Capture stopped. New requests will not be traced.');else WP_CLI::line('Request Monitor was already idle.');}
    /** Deprecated safety alias. Continuous monitoring no longer exists. */
    public function disable(){$this->stop();}
    /** Continuous monitoring was removed in v0.6. */
    public function enable(){WP_CLI::error('Continuous monitoring was removed in Request Monitor v0.6. Use: wp request-monitor capture 30s');}
    /** Deep mode is selected per capture in v0.6. */
    public function deep(){WP_CLI::error('Deep mode is bounded to a capture window. Use: wp request-monitor capture 30s --profile=deep');}

    /** Get/set/reset trace scope. */
    public function scope($args,$assoc){$op=$args[0]??'get';if($op==='get'){WP_CLI::line(wp_json_encode(Request_Monitor_Core::get_scope(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return;}if($op==='reset'){Request_Monitor_Core::save_scope(Request_Monitor_Core::default_scope());WP_CLI::success('Trace scope reset.');return;}if($op!=='set')WP_CLI::error('Use: wp request-monitor scope get|set|reset');$scope=Request_Monitor_Core::get_scope();$map=array('types'=>'types','methods'=>'methods','include-paths'=>'include_paths','exclude-paths'=>'exclude_paths','include-actions'=>'include_actions','exclude-actions'=>'exclude_actions');foreach($map as $arg=>$key)if(array_key_exists($arg,$assoc))$scope[$key]=$assoc[$arg]===''?array():preg_split('/[\r\n,]+/',(string)$assoc[$arg]);Request_Monitor_Core::save_scope($scope);WP_CLI::success('Trace scope updated.');WP_CLI::line(wp_json_encode(Request_Monitor_Core::get_scope(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
    /** Clear active and rotated trace logs. */
    public function clear(){Request_Monitor_Store::clear_logs();WP_CLI::success('Request Monitor logs cleared.');}
    /** Repair/update mandatory MU components. */
    public function repair(){$r=Request_Monitor_Core::install_mu();if(is_wp_error($r))WP_CLI::error($r->get_error_message());WP_CLI::success('MU foundation repaired.');}
    /** Export current JSONL. */
    public function export($args,$assoc){$dest=$assoc['file']??(getcwd().'/request-monitor-'.gmdate('Ymd-His').'.jsonl');$r=Request_Monitor_Store::export($dest);if(is_wp_error($r))WP_CLI::error($r->get_error_message());WP_CLI::success($dest);}
    /** Show START records without matching END records. */
    public function active($args,$assoc){$session=$assoc['session']??null;$rows=array();foreach(Request_Monitor_Store::build_rows(null,$session) as $r)if($r['state']==='ACTIVE')$rows[]=array('pid'=>$r['pid'],'session'=>$r['session'],'profile'=>$r['profile'],'type'=>$r['type'],'method'=>$r['method'],'path'=>$r['path'],'action'=>$r['action'],'pattern_fp'=>$r['pattern_fingerprint'],'utc'=>$r['timestamp']);if(!$rows){WP_CLI::line('No active traced requests.');return;}WP_CLI\Utils\format_items($assoc['format']??'table',$rows,array('pid','session','profile','type','method','path','action','pattern_fp','utc'));}
    /** Group repeated traces. */
    public function fingerprints($args,$assoc){$this->print_fingerprints($args,$assoc);}

    private function print_fingerprints($args,$assoc){$mode=$assoc['mode']??'pattern';$min=max(1,(int)($assoc['min-count']??2));$limit=max(1,min(200,(int)($assoc['limit']??20)));$slow=isset($assoc['slow-only']);$session=$assoc['session']??null;if($session==='last')$session=(string)get_option(Request_Monitor_Core::OPT_CAPTURE_SESSION,'');$rows=Request_Monitor_Store::fingerprint_groups($mode,$min,$slow,$session);$sort=$assoc['sort']??'cpu';usort($rows,function($a,$b)use($sort){if($sort==='count')return $b['count']<=>$a['count'];if($sort==='wall')return $b['total_wall_ms']<=>$a['total_wall_ms'];if($sort==='max')return $b['max_wall_ms']<=>$a['max_wall_ms'];return $b['total_cpu_ms']<=>$a['total_cpu_ms'];});$rows=array_slice($rows,0,$limit);if(!$rows){WP_CLI::line('No matching completed fingerprints.');return;}$display=array();foreach($rows as $r)$display[]=array('fingerprint'=>$r['fingerprint'],'count'=>$r['count'],'slow'=>$r['slow'],'cpu_bound'=>$r['cpu_bound'],'avg_wall_ms'=>$r['avg_wall_ms'],'avg_cpu_ms'=>$r['avg_cpu_ms'],'total_cpu_ms'=>$r['total_cpu_ms'],'max_wall_ms'=>$r['max_wall_ms'],'pattern'=>$r['pattern_path'],'action'=>$r['action']);WP_CLI\Utils\format_items($assoc['format']??'table',$display,array('fingerprint','count','slow','cpu_bound','avg_wall_ms','avg_cpu_ms','total_cpu_ms','max_wall_ms','pattern','action'));}
}
