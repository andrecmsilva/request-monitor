<?php
if (!defined('ABSPATH')) exit;

final class Request_Monitor_Store {
    public static function log_dir(){return WP_CONTENT_DIR.'/rocket-request-tracer';}
    public static function log_file(){$seed=defined('AUTH_SALT')?AUTH_SALT:ABSPATH;return self::log_dir().'/trace-'.substr(hash('sha256',$seed),0,16).'.php';}
    public static function ensure_log(){
        $dir=self::log_dir();if(!is_dir($dir))wp_mkdir_p($dir);
        if(!file_exists($dir.'/index.php'))@file_put_contents($dir.'/index.php',"<?php\nexit;\n");
        if(!file_exists($dir.'/.htaccess'))@file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n");
        if(!file_exists(self::log_file()))@file_put_contents(self::log_file(),"<?php exit; __halt_compiler(); ?>\n",LOCK_EX);
    }
    public static function read_events($limit=30000){
        self::ensure_log();$lines=@file(self::log_file(),FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);if(!is_array($lines))return array();
        if(isset($lines[0])&&strpos($lines[0],'__halt_compiler')!==false)array_shift($lines);if(count($lines)>$limit)$lines=array_slice($lines,-$limit);
        $events=array();foreach($lines as $line){$decoded=json_decode($line,true);if(is_array($decoded)&&!empty($decoded['request_id']))$events[]=$decoded;}return $events;
    }
    public static function last_session(){return (string)get_option(Request_Monitor_Core::OPT_CAPTURE_SESSION,'');}
    public static function resolve_session($session){if($session===null||$session==='')return null;if($session==='last')return self::last_session();return (string)$session;}

    public static function build_rows($events=null,$session=null){
        if($events===null)$events=self::read_events();$session=self::resolve_session($session);$pairs=array();
        foreach($events as $event){if($session!==null&&($event['capture_session']??'')!==$session)continue;$id=$event['request_id'];if(!isset($pairs[$id]))$pairs[$id]=array();if(($event['event']??'')==='START')$pairs[$id]['start']=$event;if(($event['event']??'')==='END')$pairs[$id]['end']=$event;}
        $rows=array();foreach($pairs as $id=>$pair){if(empty($pair['start']))continue;$s=$pair['start'];$e=$pair['end']??null;$basis=$s['fingerprint_basis']??array();$rows[]=array(
            'id'=>$id,'session'=>$s['capture_session']??'','profile'=>$s['capture_profile']??'legacy','state'=>$e?'DONE':'ACTIVE','timestamp'=>$s['timestamp']??'','pid'=>$s['pid']??0,
            'capture_started'=>$s['capture_started']??null,'capture_until'=>$s['capture_until']??null,
            'method'=>$s['method']??'','type'=>$s['request_type']??'','path'=>$s['path']??'','pattern_path'=>$basis['pattern_path']??($s['path']??''),'action'=>$s['wp_action']??($s['wc_ajax']??''),
            'query'=>$s['safe_query']??array(),'query_keys'=>$s['query_keys']??array(),'ip'=>$s['client_ip']??'','ray'=>$s['cf_ray']??'',
            'request_fingerprint'=>$s['request_fingerprint']??($e['request_fingerprint']??''),'pattern_fingerprint'=>$s['pattern_fingerprint']??($e['pattern_fingerprint']??''),
            'query_fingerprint'=>$s['query_fingerprint']??($e['query_fingerprint']??''),'query_shape_fingerprint'=>$s['query_shape_fingerprint']??($e['query_shape_fingerprint']??''),
            'class'=>$e['classification']??'ACTIVE','slow'=>$e['slow_request']??false,'capture'=>$e['capture_level']??($e?'legacy':'active'),'wall'=>$e['wall_ms']??null,'cpu'=>$e['cpu_total_ms']??null,
            'ratio'=>$e['cpu_ratio']??null,'memory'=>$e['peak_memory_mb']??null,'files'=>$e['included_files']??null,'phases'=>$e['phase_durations']??array(),'hooks'=>$e['hook_profile']??array(),
            'sql'=>$e['sql']??array(),'http'=>$e['http']??array(),'groups'=>$e['included_groups']??array(),'resources'=>$e['resources']??array(),'wp'=>$e['wordpress']??array(),'coverage'=>$e['deep_coverage']??array(),'fatal'=>$e['fatal']??null
        );}
        usort($rows,function($a,$b){if($a['state']!==$b['state'])return $a['state']==='ACTIVE'?-1:1;if($a['state']==='ACTIVE')return strcmp($b['timestamp'],$a['timestamp']);return ((float)($b['wall']??0))<=>((float)($a['wall']??0));});return $rows;
    }

    public static function fingerprint_groups($mode='pattern',$min_count=2,$slow_only=false,$session=null){
        $field_map=array('pattern'=>'pattern_fingerprint','request'=>'request_fingerprint','query'=>'query_fingerprint','query-shape'=>'query_shape_fingerprint');$field=$field_map[$mode]??$field_map['pattern'];$groups=array();
        foreach(self::build_rows(null,$session) as $row){if($row['state']!=='DONE'||empty($row[$field]))continue;if($slow_only&&!$row['slow'])continue;$key=$row[$field];if(!isset($groups[$key]))$groups[$key]=array(
            'fingerprint'=>$key,'mode'=>$mode,'count'=>0,'slow'=>0,'cpu_bound'=>0,'mixed'=>0,'wait_bound'=>0,'total_wall_ms'=>0.0,'total_cpu_ms'=>0.0,'total_sql_ms'=>0.0,'total_http_ms'=>0.0,'max_wall_ms'=>0.0,'max_cpu_ms'=>0.0,'peak_memory_mb'=>0.0,
            'sample_path'=>$row['path'],'pattern_path'=>$row['pattern_path'],'action'=>$row['action'],'query_keys'=>$row['query_keys'],'session'=>$row['session'],'profile'=>$row['profile']
        );$g=&$groups[$key];$g['count']++;if($row['slow'])$g['slow']++;if($row['class']==='CPU_BOUND')$g['cpu_bound']++;elseif($row['class']==='MIXED')$g['mixed']++;elseif($row['class']==='WAIT_BOUND')$g['wait_bound']++;$g['total_wall_ms']+=(float)$row['wall'];$g['total_cpu_ms']+=(float)$row['cpu'];$g['total_sql_ms']+=(float)($row['sql']['total_ms']??0);$g['total_http_ms']+=(float)($row['http']['total_ms']??0);$g['max_wall_ms']=max($g['max_wall_ms'],(float)$row['wall']);$g['max_cpu_ms']=max($g['max_cpu_ms'],(float)$row['cpu']);$g['peak_memory_mb']=max($g['peak_memory_mb'],(float)$row['memory']);unset($g);}
        foreach($groups as $key=>&$g){if($g['count']<$min_count){unset($groups[$key]);continue;}$g['avg_wall_ms']=round($g['total_wall_ms']/$g['count'],2);$g['avg_cpu_ms']=round($g['total_cpu_ms']/$g['count'],2);$g['cpu_share_pct']=$g['total_wall_ms']>0?round(($g['total_cpu_ms']/$g['total_wall_ms'])*100,1):0;foreach(array('total_wall_ms','total_cpu_ms','total_sql_ms','total_http_ms','max_wall_ms','max_cpu_ms','peak_memory_mb') as $metric)$g[$metric]=round($g[$metric],2);}unset($g);return array_values($groups);
    }

    public static function rows_for_fingerprint($fingerprint,$session=null){$out=array();foreach(self::build_rows(null,$session) as $r){if($r['pattern_fingerprint']===$fingerprint||$r['request_fingerprint']===$fingerprint||$r['query_fingerprint']===$fingerprint||$r['query_shape_fingerprint']===$fingerprint)$out[]=$r;}return $out;}
    public static function active_count($session=null){$n=0;foreach(self::build_rows(null,$session) as $r)if($r['state']==='ACTIVE')$n++;return $n;}
    public static function clear_logs(){self::ensure_log();foreach((array)glob(self::log_dir().'/trace-*.php') as $file){if($file===self::log_file())@file_put_contents($file,"<?php exit; __halt_compiler(); ?>\n",LOCK_EX);else @unlink($file);}}
    public static function export($destination){self::ensure_log();$in=@fopen(self::log_file(),'rb');if(!$in)return new WP_Error('rrt_read','Cannot read trace log.');$out=@fopen($destination,'wb');if(!$out){fclose($in);return new WP_Error('rrt_write','Cannot write: '.$destination);}fgets($in);while(!feof($in))fwrite($out,fread($in,1048576));fclose($in);fclose($out);return true;}
}
