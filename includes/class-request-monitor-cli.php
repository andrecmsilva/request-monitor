<?php
if (!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) return;

final class Request_Monitor_CLI {
    public static function register(){WP_CLI::add_command('request-monitor','Request_Monitor_CLI_Command');}
}

final class Request_Monitor_CLI_Command {
    private function bool_value($v){return in_array(strtolower((string)$v),array('1','true','yes','on','enabled'),true);}
    /** Show tracing state, MU health, thresholds, log path, and scope. */
    public function status($args,$assoc){
        $data=array('version'=>Request_Monitor_Core::VERSION,'mu_version'=>Request_Monitor_Core::MU_VERSION,'mu_healthy'=>Request_Monitor_Core::mu_healthy(),'enabled'=>(bool)get_option(Request_Monitor_Core::OPT_ENABLED,false),'deep'=>(bool)get_option(Request_Monitor_Core::OPT_DEEP,false),'slow_threshold_ms'=>(int)get_option(Request_Monitor_Core::OPT_SLOW_MS,1500),'callback_floor_ms'=>(float)get_option(Request_Monitor_Core::OPT_CALLBACK_FLOOR,5),'max_log_mb'=>(int)get_option(Request_Monitor_Core::OPT_MAX_MB,25),'log_file'=>Request_Monitor_Store::log_file(),'scope'=>Request_Monitor_Core::get_scope());
        if(($assoc['format']??'table')==='json'){WP_CLI::line(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return;}
        $rows=array();foreach($data as $k=>$v){$value=is_array($v)?wp_json_encode($v,JSON_UNESCAPED_SLASHES):(is_bool($v)?($v?'yes':'no'):(string)$v);$rows[]=array('setting'=>$k,'value'=>$value);}WP_CLI\Utils\format_items('table',$rows,array('setting','value'));
    }
    /** Enable tracing. [--deep] [--slow-ms=1500] [--callback-floor=5] */
    public function enable($args,$assoc){$r=Request_Monitor_Core::install_mu();if(is_wp_error($r))WP_CLI::error($r->get_error_message());update_option(Request_Monitor_Core::OPT_ENABLED,1,false);if(isset($assoc['deep']))update_option(Request_Monitor_Core::OPT_DEEP,1,false);if(isset($assoc['slow-ms']))update_option(Request_Monitor_Core::OPT_SLOW_MS,max(250,min(60000,(int)$assoc['slow-ms'])),false);if(isset($assoc['callback-floor']))update_option(Request_Monitor_Core::OPT_CALLBACK_FLOOR,max(0.1,min(1000,(float)$assoc['callback-floor'])),false);WP_CLI::success('Request Monitor tracing enabled.');}
    /** Disable tracing. */
    public function disable(){update_option(Request_Monitor_Core::OPT_ENABLED,0,false);WP_CLI::success('Request Monitor tracing disabled.');}
    /** Toggle deep mode: wp request-monitor deep on|off */
    public function deep($args){if(empty($args[0]))WP_CLI::error('Use: wp request-monitor deep on|off');$on=$this->bool_value($args[0]);update_option(Request_Monitor_Core::OPT_DEEP,$on?1:0,false);WP_CLI::success('Deep mode '.($on?'enabled':'disabled').'.');}
    /** Get/set/reset trace scope. */
    public function scope($args,$assoc){
        $op=$args[0]??'get';if($op==='get'){WP_CLI::line(wp_json_encode(Request_Monitor_Core::get_scope(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));return;}
        if($op==='reset'){Request_Monitor_Core::save_scope(Request_Monitor_Core::default_scope());WP_CLI::success('Trace scope reset.');return;}
        if($op!=='set')WP_CLI::error('Use: wp request-monitor scope get|set|reset');
        $scope=Request_Monitor_Core::get_scope();$map=array('types'=>'types','methods'=>'methods','include-paths'=>'include_paths','exclude-paths'=>'exclude_paths','include-actions'=>'include_actions','exclude-actions'=>'exclude_actions');
        foreach($map as $arg=>$key)if(array_key_exists($arg,$assoc))$scope[$key]=$assoc[$arg]===''?array():preg_split('/[\r\n,]+/',(string)$assoc[$arg]);Request_Monitor_Core::save_scope($scope);WP_CLI::success('Trace scope updated.');WP_CLI::line(wp_json_encode(Request_Monitor_Core::get_scope(),JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    /** Clear active and rotated trace logs. */
    public function clear(){Request_Monitor_Store::clear_logs();WP_CLI::success('Request Monitor logs cleared.');}
    /** Repair/update mandatory MU components. */
    public function repair(){$r=Request_Monitor_Core::install_mu();if(is_wp_error($r))WP_CLI::error($r->get_error_message());WP_CLI::success('MU foundation repaired.');}
    /** Export current JSONL. [--file=/tmp/request-monitor.jsonl] */
    public function export($args,$assoc){$dest=$assoc['file']??(getcwd().'/request-monitor-'.gmdate('Ymd-His').'.jsonl');$r=Request_Monitor_Store::export($dest);if(is_wp_error($r))WP_CLI::error($r->get_error_message());WP_CLI::success($dest);}
    /** Show START records without matching END records. */
    public function active($args,$assoc){$rows=array();foreach(Request_Monitor_Store::build_rows() as $r)if($r['state']==='ACTIVE')$rows[]=array('pid'=>$r['pid'],'type'=>$r['type'],'method'=>$r['method'],'path'=>$r['path'],'action'=>$r['action'],'pattern_fp'=>$r['pattern_fingerprint'],'utc'=>$r['timestamp']);if(!$rows){WP_CLI::line('No active traced requests.');return;}WP_CLI\Utils\format_items($assoc['format']??'table',$rows,array('pid','type','method','path','action','pattern_fp','utc'));}
    /** Group repeated traces. [--mode=pattern|request|query|query-shape] [--sort=cpu|wall|count|max] [--min-count=2] [--limit=20] [--slow-only] */
    public function fingerprints($args,$assoc){
        $mode=$assoc['mode']??'pattern';$min=max(1,(int)($assoc['min-count']??2));$limit=max(1,min(200,(int)($assoc['limit']??20)));$slow=isset($assoc['slow-only']);$rows=Request_Monitor_Store::fingerprint_groups($mode,$min,$slow);$sort=$assoc['sort']??'cpu';
        usort($rows,function($a,$b)use($sort){if($sort==='count')return $b['count']<=>$a['count'];if($sort==='wall')return $b['total_wall_ms']<=>$a['total_wall_ms'];if($sort==='max')return $b['max_wall_ms']<=>$a['max_wall_ms'];return $b['total_cpu_ms']<=>$a['total_cpu_ms'];});$rows=array_slice($rows,0,$limit);
        if(!$rows){WP_CLI::line('No matching repeated fingerprints.');return;}$display=array();foreach($rows as $r)$display[]=array('fingerprint'=>$r['fingerprint'],'count'=>$r['count'],'slow'=>$r['slow'],'cpu_bound'=>$r['cpu_bound'],'avg_wall_ms'=>$r['avg_wall_ms'],'avg_cpu_ms'=>$r['avg_cpu_ms'],'total_cpu_ms'=>$r['total_cpu_ms'],'max_wall_ms'=>$r['max_wall_ms'],'pattern'=>$r['pattern_path'],'action'=>$r['action']);WP_CLI\Utils\format_items($assoc['format']??'table',$display,array('fingerprint','count','slow','cpu_bound','avg_wall_ms','avg_cpu_ms','total_cpu_ms','max_wall_ms','pattern','action'));
    }
}
