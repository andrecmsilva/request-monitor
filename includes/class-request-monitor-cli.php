<?php
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) return;

final class Request_Monitor_CLI { public static function register(){WP_CLI::add_command('request-monitor','Request_Monitor_CLI_Command');} }

final class Request_Monitor_CLI_Command {
    private function display_status($data,$format){if($format==='json'){WP_CLI::line(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return;}$rows=array();foreach($data as $k=>$v){$value=is_array($v)?wp_json_encode($v,JSON_UNESCAPED_SLASHES):(is_bool($v)?($v?'yes':'no'):(string)$v);$rows[]=array('setting'=>$k,'value'=>$value);}WP_CLI\Utils\format_items('table',$rows,array('setting','value'));}

    /** Show capture state, MU health, thresholds, log path, and scope. */
    public function status($args,$assoc){$capture=Request_Monitor_Core::capture_state();$data=array('version'=>Request_Monitor_Core::VERSION,'mu_version'=>Request_Monitor_Core::MU_VERSION,'mu_healthy'=>Request_Monitor_Core::mu_healthy(),'state'=>$capture['state'],'capture_session'=>$capture['session'],'capture_profile'=>$capture['profile'],'seconds_remaining'=>$capture['seconds_remaining'],'capture_until_utc'=>$capture['until_utc'],'slow_threshold_ms'=>(int)get_option(Request_Monitor_Core::OPT_SLOW_MS,1500),'callback_floor_ms'=>(float)get_option(Request_Monitor_Core::OPT_CALLBACK_FLOOR,5),'slow_sql_stack_ms'=>(float)get_option(Request_Monitor_Core::OPT_SQL_STACK_MS,10),'max_log_mb'=>(int)get_option(Request_Monitor_Core::OPT_MAX_MB,25),'log_file'=>Request_Monitor_Store::log_file(),'scope'=>Request_Monitor_Core::get_scope());$this->display_status($data,$assoc['format']??'table');}

    /** Record a bounded snapshot and automatically analyze the result. */
    public function capture($args,$assoc){
        if(empty($args[0]))WP_CLI::error('Use: wp request-monitor capture 30s [--profile=light|hooks|deep]');
        $seconds=Request_Monitor_Core::parse_duration($args[0]);if(!$seconds)WP_CLI::error('Invalid duration. Examples: 30s, 60s, 1m, 2m.');
        $profile=$assoc['profile']??(isset($assoc['deep'])?'deep':'light');if(Request_Monitor_Core::validate_profile($profile)===null)WP_CLI::error('Invalid profile "'.$profile.'". Use exactly: light, hooks, or deep.');
        if(!isset($assoc['no-clear']))Request_Monitor_Store::clear_logs();
        $session=Request_Monitor_Core::start_capture($seconds,$profile);if(is_wp_error($session))WP_CLI::error($session->get_error_message());
        WP_CLI::success(sprintf('Snapshot %s started for %ds using %s profile. It will stop accepting new traces automatically at %s.',$session['session'],$seconds,$session['profile'],gmdate('Y-m-d H:i:s',$session['until']).' UTC'));
        WP_CLI::line('Scope: '.wp_json_encode(Request_Monitor_Core::get_scope(),JSON_UNESCAPED_SLASHES));
        if(isset($assoc['no-wait'])){WP_CLI::line('Capture is self-expiring. Analyze later with: wp request-monitor analyze --session='.$session['session']);return;}
        sleep($seconds+1);Request_Monitor_Core::stop_capture();sleep(2);
        $active=Request_Monitor_Store::active_count($session['session']);if($active)WP_CLI::warning($active.' traced request(s) are still finishing. Analysis below uses completed END records only; rerun analyze shortly for the final picture.');
        $analysis=Request_Monitor_Analyzer::analyze($session['session'],null,(int)($assoc['limit']??10));$this->render_analysis($analysis,$assoc['format']??'table');
        WP_CLI::success('Snapshot complete. Request Monitor is idle.');
    }

    /** Alias for capture. */ public function snapshot($args,$assoc){$this->capture($args,$assoc);}
    /** Stop the current capture immediately. */ public function stop(){$before=Request_Monitor_Core::capture_state();Request_Monitor_Core::stop_capture();if($before['active'])WP_CLI::success('Capture stopped. New requests will not be traced.');else WP_CLI::line('Request Monitor was already idle.');}
    /** Safety alias. */ public function disable(){$this->stop();}
    /** Continuous monitoring does not exist. */ public function enable(){WP_CLI::error('Continuous monitoring does not exist. Use: wp request-monitor capture 30s');}
    /** Deep is selected per capture. */ public function deep(){WP_CLI::error('Deep mode is bounded to a capture window. Use: wp request-monitor capture 30s --profile=deep');}

    /** Analyze a completed capture. [--session=last] [--fingerprint=...] [--limit=10] [--format=table|json] */
    public function analyze($args,$assoc){$session=$assoc['session']??'last';$fingerprint=$assoc['fingerprint']??null;$analysis=Request_Monitor_Analyzer::analyze($session,$fingerprint,(int)($assoc['limit']??10));$this->render_analysis($analysis,$assoc['format']??'table');}

    /** Inspect one fingerprint with detailed SQL/HTTP stacks. */
    public function inspect($args,$assoc){if(empty($args[0]))WP_CLI::error('Use: wp request-monitor inspect <fingerprint> [--session=last]');$analysis=Request_Monitor_Analyzer::analyze($assoc['session']??'last',(string)$args[0],(int)($assoc['limit']??15));$this->render_analysis($analysis,$assoc['format']??'table',true);}

    /** Get/set/reset trace scope. */
    public function scope($args,$assoc){$op=$args[0]??'get';if($op==='get'){WP_CLI::line(wp_json_encode(Request_Monitor_Core::get_scope(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return;}if($op==='reset'){Request_Monitor_Core::save_scope(Request_Monitor_Core::default_scope());WP_CLI::success('Trace scope reset.');return;}if($op!=='set')WP_CLI::error('Use: wp request-monitor scope get|set|reset');$scope=Request_Monitor_Core::get_scope();$map=array('types'=>'types','methods'=>'methods','include-paths'=>'include_paths','exclude-paths'=>'exclude_paths','include-actions'=>'include_actions','exclude-actions'=>'exclude_actions');foreach($map as $arg=>$key)if(array_key_exists($arg,$assoc))$scope[$key]=$assoc[$arg]===''?array():preg_split('/[\r\n,]+/',(string)$assoc[$arg]);Request_Monitor_Core::save_scope($scope);WP_CLI::success('Trace scope updated.');WP_CLI::line(wp_json_encode(Request_Monitor_Core::get_scope(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
    /** Clear trace logs. */ public function clear(){Request_Monitor_Store::clear_logs();WP_CLI::success('Request Monitor logs cleared.');}
    /** Repair/update mandatory MU components. */ public function repair(){$r=Request_Monitor_Core::install_mu();if(is_wp_error($r))WP_CLI::error($r->get_error_message());WP_CLI::success('MU foundation repaired.');}
    /** Export current JSONL. */ public function export($args,$assoc){$dest=$assoc['file']??(getcwd().'/request-monitor-'.gmdate('Ymd-His').'.jsonl');$r=Request_Monitor_Store::export($dest);if(is_wp_error($r))WP_CLI::error($r->get_error_message());WP_CLI::success($dest);}
    /** Show active traced requests. */ public function active($args,$assoc){$session=$assoc['session']??null;$rows=array();foreach(Request_Monitor_Store::build_rows(null,$session) as $r)if($r['state']==='ACTIVE')$rows[]=array('pid'=>$r['pid'],'session'=>$r['session'],'profile'=>$r['profile'],'type'=>$r['type'],'method'=>$r['method'],'path'=>$r['path'],'action'=>$r['action'],'pattern_fp'=>$r['pattern_fingerprint'],'utc'=>$r['timestamp']);if(!$rows){WP_CLI::line('No active traced requests.');return;}WP_CLI\Utils\format_items($assoc['format']??'table',$rows,array('pid','session','profile','type','method','path','action','pattern_fp','utc'));}
    /** Group repeated traces. */ public function fingerprints($args,$assoc){$this->print_fingerprints($assoc);}

    private function print_fingerprints($assoc){$mode=$assoc['mode']??'pattern';$min=max(1,(int)($assoc['min-count']??2));$limit=max(1,min(200,(int)($assoc['limit']??20)));$slow=isset($assoc['slow-only']);$session=$assoc['session']??null;if($session==='last')$session=Request_Monitor_Store::last_session();$rows=Request_Monitor_Store::fingerprint_groups($mode,$min,$slow,$session);$sort=$assoc['sort']??'cpu';usort($rows,function($a,$b)use($sort){if($sort==='count')return $b['count']<=>$a['count'];if($sort==='wall')return $b['total_wall_ms']<=>$a['total_wall_ms'];if($sort==='max')return $b['max_wall_ms']<=>$a['max_wall_ms'];return $b['total_cpu_ms']<=>$a['total_cpu_ms'];});$rows=array_slice($rows,0,$limit);if(!$rows){WP_CLI::line('No matching completed fingerprints.');return;}$display=array();foreach($rows as $r)$display[]=array('fingerprint'=>$r['fingerprint'],'count'=>$r['count'],'slow'=>$r['slow'],'cpu_bound'=>$r['cpu_bound'],'avg_wall_ms'=>$r['avg_wall_ms'],'avg_cpu_ms'=>$r['avg_cpu_ms'],'cpu_share'=>$r['cpu_share_pct'].'%','sql_ms'=>$r['total_sql_ms'],'http_ms'=>$r['total_http_ms'],'total_cpu_ms'=>$r['total_cpu_ms'],'pattern'=>$r['pattern_path'],'action'=>$r['action']);WP_CLI\Utils\format_items($assoc['format']??'table',$display,array('fingerprint','count','slow','cpu_bound','avg_wall_ms','avg_cpu_ms','cpu_share','sql_ms','http_ms','total_cpu_ms','pattern','action'));}

    private function render_analysis($a,$format='table',$detailed=false){
        if($format==='json'){WP_CLI::line(wp_json_encode($a,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return;}
        WP_CLI::line('');WP_CLI::line('REQUEST MONITOR ANALYSIS');WP_CLI::line(str_repeat('=',24));
        WP_CLI::line('Session: '.($a['session']?:'unknown').' | Profile: '.($a['profile']??'unknown').(!empty($a['fingerprint'])?' | Fingerprint: '.$a['fingerprint']:''));
        $s=$a['summary'];$summary=array(
            array('metric'=>'Completed requests','value'=>$s['completed']??0),array('metric'=>'Still active','value'=>$s['active']??0),array('metric'=>'Slow requests','value'=>$s['slow']??0),
            array('metric'=>'Total PHP CPU','value'=>$this->seconds($s['total_cpu_ms']??0)),array('metric'=>'Aggregate wall','value'=>$this->seconds($s['total_wall_ms']??0)),array('metric'=>'CPU share','value'=>isset($s['avg_cpu_share_pct'])?$s['avg_cpu_share_pct'].'%':'—'),
            array('metric'=>'Estimated CPU demand','value'=>isset($s['estimated_cpu_core_demand'])?$s['estimated_cpu_core_demand'].' cores':'—'),array('metric'=>'Measured SQL','value'=>$this->seconds($s['total_sql_ms']??0)),array('metric'=>'Measured outbound HTTP','value'=>$this->seconds($s['total_http_ms']??0)),array('metric'=>'Residual/unattributed wait*','value'=>$this->seconds($s['estimated_other_wait_ms']??0)),array('metric'=>'Peak request memory','value'=>round((float)($s['peak_memory_mb']??0),1).' MB')
        );WP_CLI\Utils\format_items('table',$summary,array('metric','value'));WP_CLI::line('* Residual is an estimate; CPU/SQL/HTTP timers are not guaranteed to be perfectly non-overlapping.');
        $this->section_conclusions($a['conclusions']??array());
        $this->section_workloads($a['workloads']??array());
        $this->section_actions($a['actions']??array());
        $this->section_slowest($a['slowest_requests']??array());
        $this->section_callbacks($a['php']['callbacks']??array());
        $this->section_hooks($a['php']['hooks']??array());
        $this->section_owners($a['php']['owners']??array());
        $this->section_sql($a['sql']??array(),$detailed);
        $this->section_http($a['http']??array(),$detailed);
        $this->section_lifecycle($a['lifecycle']??array());
        if(!empty($a['warnings'])){WP_CLI::line('');WP_CLI::warning(implode(' | ',$a['warnings']));}
        if(!empty($a['active_requests'])){WP_CLI::line('');WP_CLI::line('ACTIVE REQUESTS');WP_CLI\Utils\format_items('table',$a['active_requests'],array('pid','method','path','action','started_utc'));}
    }
    private function section_conclusions($rows){if(!$rows)return;WP_CLI::line('');WP_CLI::line('CONCLUSIONS');foreach($rows as $i=>$text)WP_CLI::line(($i+1).'. '.$text);}
    private function section_workloads($rows){if(!$rows)return;WP_CLI::line('');WP_CLI::line('TOP REQUEST FAMILIES');$out=array();foreach($rows as $r)$out[]=array('fingerprint'=>$r['fingerprint'],'requests'=>$r['count'],'slow'=>$r['slow'],'avg_wall_ms'=>$r['avg_wall_ms'],'avg_cpu_ms'=>$r['avg_cpu_ms'],'cpu_share'=>$r['cpu_share_pct'].'%','sql_ms'=>$r['total_sql_ms'],'http_ms'=>$r['total_http_ms'],'total_cpu_ms'=>$r['total_cpu_ms'],'pattern'=>$r['pattern']);WP_CLI\Utils\format_items('table',$out,array('fingerprint','requests','slow','avg_wall_ms','avg_cpu_ms','cpu_share','sql_ms','http_ms','total_cpu_ms','pattern'));}
    private function section_actions($rows){if(!$rows)return;WP_CLI::line('');WP_CLI::line('SLOWEST / MOST EXPENSIVE ACTIONS');$out=array();foreach($rows as $r)$out[]=array('action'=>$r['action'],'requests'=>$r['count'],'slow'=>$r['slow'],'avg_wall_ms'=>$r['avg_wall_ms'],'avg_cpu_ms'=>$r['avg_cpu_ms'],'sql_ms'=>$r['total_sql_ms'],'http_ms'=>$r['total_http_ms'],'total_cpu_ms'=>$r['total_cpu_ms']);WP_CLI\Utils\format_items('table',$out,array('action','requests','slow','avg_wall_ms','avg_cpu_ms','sql_ms','http_ms','total_cpu_ms'));}
    private function section_slowest($rows){if(!$rows)return;WP_CLI::line('');WP_CLI::line('SLOWEST INDIVIDUAL REQUESTS');WP_CLI\Utils\format_items('table',$rows,array('pid','method','path','action','wall_ms','cpu_ms','sql_ms','http_ms','class','cf_ray'));}
    private function section_callbacks($rows){if(!$rows)return;WP_CLI::line('');WP_CLI::line('TOP PHP CALLBACKS');$out=array();foreach($rows as $r)$out[]=array('total_ms'=>$r['total_ms'],'calls'=>$r['count'],'max_ms'=>$r['max_ms'],'owner'=>$r['owner'],'hook'=>$r['hook'],'callable'=>$r['callable'],'source'=>$this->source($r['file']??'',$r['line']??null));WP_CLI\Utils\format_items('table',$out,array('total_ms','calls','max_ms','owner','hook','callable','source'));}
    private function section_hooks($rows){if(!$rows)return;WP_CLI::line('');WP_CLI::line('TOP WORDPRESS HOOKS');$out=array();foreach($rows as $r)$out[]=array('total_ms'=>$r['total_ms'],'runs'=>$r['count'],'requests'=>$r['request_count']??0,'avg_ms'=>$r['avg_ms'],'max_ms'=>$r['max_ms'],'hook'=>$r['hook']);WP_CLI\Utils\format_items('table',$out,array('total_ms','runs','requests','avg_ms','max_ms','hook'));}
    private function section_owners($rows){if(!$rows)return;WP_CLI::line('');WP_CLI::line('TOP PLUGIN/THEME OWNERS');$out=array();foreach($rows as $r)$out[]=array('total_ms'=>$r['total_ms'],'calls'=>$r['count'],'max_ms'=>$r['max_ms'],'owner'=>$r['owner']);WP_CLI\Utils\format_items('table',$out,array('total_ms','calls','max_ms','owner'));}
    private function section_sql($rows,$detailed){if(!$rows)return;WP_CLI::line('');WP_CLI::line('TOP MYSQL / WPDB QUERY FINGERPRINTS');$out=array();foreach($rows as $r)$out[]=array('query_hash'=>$r['query_hash'],'execs'=>$r['count'],'requests'=>$r['request_count'],'total_ms'=>$r['total_ms'],'avg_ms'=>$r['avg_ms'],'max_ms'=>$r['max_ms'],'owner'=>$r['dominant_owner'],'caller'=>$this->shorten($r['dominant_caller'],80),'query'=>$this->shorten($r['query'],100));WP_CLI\Utils\format_items('table',$out,array('query_hash','execs','requests','total_ms','avg_ms','max_ms','owner','caller','query'));$n=$detailed?min(5,count($rows)):min(2,count($rows));for($i=0;$i<$n;$i++){if(empty($rows[$i]['sample_stack']))continue;WP_CLI::line('SQL stack '.$rows[$i]['query_hash'].':');$this->print_stack($rows[$i]['sample_stack']);}}
    private function section_http($rows,$detailed){if(!$rows)return;WP_CLI::line('');WP_CLI::line('TOP OUTBOUND HTTP');$out=array();foreach($rows as $r)$out[]=array('calls'=>$r['count'],'total_ms'=>$r['total_ms'],'avg_ms'=>$r['avg_ms'],'max_ms'=>$r['max_ms'],'owner'=>$r['owner'],'url'=>$r['url'],'caller'=>$this->shorten($r['caller'],90));WP_CLI\Utils\format_items('table',$out,array('calls','total_ms','avg_ms','max_ms','owner','url','caller'));if($detailed&&!empty($rows[0]['sample_stack'])){$this->print_stack($rows[0]['sample_stack']);}}
    private function section_lifecycle($rows){if(!$rows)return;WP_CLI::line('');WP_CLI::line('WORDPRESS LIFECYCLE HOTSPOTS');$out=array();foreach($rows as $r)$out[]=array('phase'=>$r['phase'],'requests'=>$r['count'],'total_ms'=>$r['total_ms'],'avg_ms'=>$r['avg_ms'],'max_ms'=>$r['max_ms']);WP_CLI\Utils\format_items('table',$out,array('phase','requests','total_ms','avg_ms','max_ms'));}
    private function print_stack($stack){foreach((array)$stack as $i=>$f)WP_CLI::line(sprintf('  #%d %s — %s%s%s',$i,$f['callable']??'unknown',$f['file']??'',isset($f['line'])&&$f['line']?':'.$f['line']:'',!empty($f['owner'])?' ['.$f['owner'].']':''));}
    private function source($file,$line){return $file.($line?':'.$line:'');}
    private function seconds($ms){return round(((float)$ms)/1000,3).' s';}
    private function shorten($s,$n){$s=(string)$s;return strlen($s)>$n?substr($s,0,$n-1).'…':$s;}
}
