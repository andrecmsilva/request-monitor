<?php
/** Request Monitor hook profiler. Version: 0.7.0 */
if (!defined('ABSPATH') || class_exists('Request_Monitor_Hook_Profiler', false)) return;

final class Request_Monitor_Hook_Profiler {
    private static $started=false,$request_start=0.0,$slow_threshold_ms=1500.0,$callback_floor_ms=5.0,$profile='hooks',$callback_armed=false,$callback_started_ms=null,$sql_escalated=false;
    private static $hook_starts=array(),$hooks=array(),$callbacks=array(),$owners=array(),$wrapped=array(),$skipped_reference=array(),$seen_hooks=array();
    private static $max_callback_rows=300;

    public static function start($request_start,$slow_threshold_ms,$callback_floor_ms,$profile){
        if(self::$started||!function_exists('add_action'))return;
        self::$started=true;self::$request_start=(float)$request_start;self::$slow_threshold_ms=max(250.0,(float)$slow_threshold_ms);self::$callback_floor_ms=max(0.1,(float)$callback_floor_ms);
        self::$profile=in_array($profile,array('hooks','deep'),true)?$profile:'hooks';
        // Bounded hooks/deep captures exist specifically to attribute callbacks, so time them from request start.
        self::arm_callback_timing(0.0,'profile_start');
        add_action('all',array(__CLASS__,'observe_hook_entry'),PHP_INT_MIN,1);
    }

    public static function observe_hook_entry(){
        if(!self::$started||!function_exists('current_filter'))return;$hook=current_filter();if(!$hook||$hook==='all')return;
        $now=self::now_ms();self::$seen_hooks[$hook]=true;self::$hook_starts[$hook][]=self::clock_ns();self::ensure_end_sentinel($hook);
        self::maybe_escalate_sql($now);
        if(self::$callback_armed)self::wrap_hook_callbacks($hook);
    }
    public static function finish_hook($value=null){
        if(!self::$started||!function_exists('current_filter'))return $value;$hook=current_filter();if(!$hook||empty(self::$hook_starts[$hook]))return $value;
        $start_ns=array_pop(self::$hook_starts[$hook]);$duration_ms=(self::clock_ns()-$start_ns)/1000000;
        if(!isset(self::$hooks[$hook]))self::$hooks[$hook]=array('hook'=>$hook,'count'=>0,'total_ms'=>0.0,'max_ms'=>0.0);
        self::$hooks[$hook]['count']++;self::$hooks[$hook]['total_ms']+=$duration_ms;self::$hooks[$hook]['max_ms']=max(self::$hooks[$hook]['max_ms'],$duration_ms);
        self::maybe_escalate_sql(self::now_ms());return $value;
    }
    private static function ensure_end_sentinel($hook){remove_filter($hook,array(__CLASS__,'finish_hook'),PHP_INT_MAX);add_filter($hook,array(__CLASS__,'finish_hook'),PHP_INT_MAX,1);}

    private static function arm_callback_timing($elapsed_ms,$reason){
        if(self::$callback_armed)return;self::$callback_armed=true;self::$callback_started_ms=round((float)$elapsed_ms,3);
        if(!empty($GLOBALS['rrt_bootstrap_context'])&&is_array($GLOBALS['rrt_bootstrap_context'])){
            $GLOBALS['rrt_bootstrap_context']['callback_timing_started_ms']=self::$callback_started_ms;
            $GLOBALS['rrt_bootstrap_context']['callback_timing_reason']=$reason;
        }
    }
    private static function maybe_escalate_sql($elapsed_ms){
        if(self::$profile!=='hooks'||self::$sql_escalated||$elapsed_ms<self::$slow_threshold_ms)return;
        self::$sql_escalated=true;
        if(!defined('SAVEQUERIES'))define('SAVEQUERIES',true);
        if(!empty($GLOBALS['rrt_bootstrap_context'])&&is_array($GLOBALS['rrt_bootstrap_context'])){
            $GLOBALS['rrt_bootstrap_context']['auto_escalated']=true;
            $GLOBALS['rrt_bootstrap_context']['auto_escalated_at_ms']=round((float)$elapsed_ms,3);
            $GLOBALS['rrt_bootstrap_context']['auto_escalation_reason']='slow_threshold_sql';
            $GLOBALS['rrt_bootstrap_context']['savequeries_enabled']=defined('SAVEQUERIES')&&SAVEQUERIES;
            if(defined('SAVEQUERIES')&&!SAVEQUERIES)$GLOBALS['rrt_bootstrap_context']['savequeries_reason']='SAVEQUERIES already defined false';
        }
    }

    private static function wrap_hook_callbacks($hook){
        global $wp_filter;if(!isset($wp_filter[$hook])||!is_object($wp_filter[$hook])||empty($wp_filter[$hook]->callbacks))return;
        foreach($wp_filter[$hook]->callbacks as $priority=>&$callbacks){foreach($callbacks as $id=>&$entry){
            if(empty($entry['function'])||self::is_our_callback($entry['function']))continue;$slot=$hook.'|'.$priority.'|'.$id;
            if(isset(self::$wrapped[$slot])&&$entry['function']===self::$wrapped[$slot]['wrapper'])continue;
            $meta=self::describe_callable($entry['function']);if(empty($meta['timable'])){if(!empty($meta['by_reference']))self::$skipped_reference[$slot]=array('hook'=>$hook,'callable'=>$meta['callable'],'owner'=>$meta['owner'],'file'=>$meta['file'],'line'=>$meta['line']);continue;}
            if(!preg_match('/^(plugin|mu-plugin|theme):/',(string)$meta['owner']))continue;if(strpos((string)$meta['owner'],'request-monitor')!==false)continue;
            $original=$entry['function'];$key=substr(hash('sha256',$hook.'|'.$priority.'|'.$id.'|'.$meta['callable']),0,20);
            $wrapper=function()use($original,$meta,$key,$hook,$priority){$args=func_get_args();$start=Request_Monitor_Hook_Profiler::clock_for_wrapper();try{return call_user_func_array($original,$args);}finally{$duration=(Request_Monitor_Hook_Profiler::clock_for_wrapper()-$start)/1000000;Request_Monitor_Hook_Profiler::record_callback($key,$hook,$priority,$meta,$duration);}};
            self::$wrapped[$slot]=array('wrapper'=>$wrapper,'original'=>$original);$entry['function']=$wrapper;
        }unset($entry);}unset($callbacks);
    }

    public static function clock_for_wrapper(){return self::clock_ns();}
    private static function clock_ns(){return function_exists('hrtime')?hrtime(true):(int)round(microtime(true)*1000000000);}
    public static function record_callback($key,$hook,$priority,$meta,$duration_ms){
        if($duration_ms<self::$callback_floor_ms)return;
        if(!isset(self::$callbacks[$key])){
            if(count(self::$callbacks)>=self::$max_callback_rows){$smallest_key=null;$smallest=INF;foreach(self::$callbacks as $k=>$row)if($row['total_ms']<$smallest){$smallest=$row['total_ms'];$smallest_key=$k;}if($smallest_key===null||$duration_ms<=$smallest)return;unset(self::$callbacks[$smallest_key]);}
            self::$callbacks[$key]=array('hook'=>$hook,'priority'=>(int)$priority,'callable'=>$meta['callable'],'owner'=>$meta['owner'],'file'=>$meta['file'],'line'=>$meta['line'],'count'=>0,'total_ms'=>0.0,'max_ms'=>0.0);
        }
        self::$callbacks[$key]['count']++;self::$callbacks[$key]['total_ms']+=$duration_ms;self::$callbacks[$key]['max_ms']=max(self::$callbacks[$key]['max_ms'],$duration_ms);
        $owner=(string)$meta['owner'];if(!isset(self::$owners[$owner]))self::$owners[$owner]=array('owner'=>$owner,'count'=>0,'total_ms'=>0.0,'max_ms'=>0.0);self::$owners[$owner]['count']++;self::$owners[$owner]['total_ms']+=$duration_ms;self::$owners[$owner]['max_ms']=max(self::$owners[$owner]['max_ms'],$duration_ms);
    }

    private static function is_our_callback($cb){if(is_array($cb)&&count($cb)===2){$class=is_object($cb[0])?get_class($cb[0]):(string)$cb[0];return $class===__CLASS__||strpos($class,'Request_Monitor_')===0;}return false;}
    private static function describe_callable($cb){
        $callable='unknown';$file=null;$line=null;$r=null;
        try{if($cb instanceof Closure){$r=new ReflectionFunction($cb);$callable='Closure@'.basename((string)$r->getFileName()).':'.$r->getStartLine();}elseif(is_string($cb)){if(strpos($cb,'::')!==false){list($c,$m)=explode('::',$cb,2);$r=new ReflectionMethod($c,$m);$callable=$c.'::'.$m;}else{$r=new ReflectionFunction($cb);$callable=$cb;}}elseif(is_array($cb)&&count($cb)===2){$c=is_object($cb[0])?get_class($cb[0]):(string)$cb[0];$m=(string)$cb[1];$r=new ReflectionMethod($c,$m);$callable=$c.'::'.$m;}elseif(is_object($cb)&&method_exists($cb,'__invoke')){$r=new ReflectionMethod($cb,'__invoke');$callable=get_class($cb).'::__invoke';}}catch(Throwable $e){return array('callable'=>$callable,'file'=>null,'line'=>null,'owner'=>'unknown','timable'=>false,'by_reference'=>false);}
        if($r){$file=$r->getFileName();$line=$r->getStartLine();}$byref=false;if($r)foreach($r->getParameters() as $p)if($p->isPassedByReference()){$byref=true;break;}$owner=self::owner_from_file($file);
        return array('callable'=>$callable,'file'=>$file?:null,'line'=>$line?:null,'owner'=>$owner,'timable'=>$r&&!$byref&&!$r->isInternal(),'by_reference'=>$byref);
    }
    private static function owner_from_file($file){if(!$file)return 'internal-or-unknown';$f=str_replace('\\','/',$file);$content=defined('WP_CONTENT_DIR')?str_replace('\\','/',WP_CONTENT_DIR):'';$plugins=defined('WP_PLUGIN_DIR')?str_replace('\\','/',WP_PLUGIN_DIR):($content?$content.'/plugins':'');$mu=defined('WPMU_PLUGIN_DIR')?str_replace('\\','/',WPMU_PLUGIN_DIR):($content?$content.'/mu-plugins':'');$themes=$content?$content.'/themes':'';foreach(array('mu-plugin'=>$mu,'plugin'=>$plugins,'theme'=>$themes) as $type=>$root){if($root&&strpos($f,rtrim($root,'/').'/')===0){$rel=substr($f,strlen(rtrim($root,'/'))+1);$parts=explode('/',$rel);return $type.':'.($parts[0]?:basename($f));}}if(defined('ABSPATH')&&strpos($f,str_replace('\\','/',ABSPATH))===0)return 'wordpress-core';return 'other';}
    private static function now_ms(){return (microtime(true)-self::$request_start)*1000;}

    public static function report($include_details){
        $hooks=array_values(self::$hooks);usort($hooks,function($a,$b){return $b['total_ms']<=>$a['total_ms'];});foreach($hooks as &$h){$h['total_ms']=round($h['total_ms'],3);$h['max_ms']=round($h['max_ms'],3);}unset($h);
        $callbacks=array_values(self::$callbacks);usort($callbacks,function($a,$b){return $b['total_ms']<=>$a['total_ms'];});foreach($callbacks as &$c){$c['total_ms']=round($c['total_ms'],3);$c['max_ms']=round($c['max_ms'],3);}unset($c);
        $owners=array_values(self::$owners);usort($owners,function($a,$b){return $b['total_ms']<=>$a['total_ms'];});foreach($owners as &$o){$o['total_ms']=round($o['total_ms'],3);$o['max_ms']=round($o['max_ms'],3);}unset($o);
        $report=array('mode'=>self::$profile==='deep'?'deep_callbacks_from_start':'hooks_callbacks_from_start','slow_threshold_ms'=>self::$slow_threshold_ms,'callback_floor_ms'=>self::$callback_floor_ms,'callback_timing_armed'=>self::$callback_armed,'callback_timing_started_ms'=>self::$callback_started_ms,'sql_escalated'=>self::$sql_escalated,'hooks_seen'=>count(self::$seen_hooks),'hooks_timed'=>count(self::$hooks),'timed_callback_rows'=>count(self::$callbacks),'skipped_by_reference'=>count(self::$skipped_reference));
        if($include_details){$report['top_hooks']=array_slice($hooks,0,40);$report['top_callbacks']=array_slice($callbacks,0,60);$report['top_owners']=array_slice($owners,0,30);$report['skipped_reference_callbacks']=array_slice(array_values(self::$skipped_reference),0,20);}return $report;
    }
}
