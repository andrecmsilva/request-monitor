<?php
/**
 * Plugin Name: Request Monitor
 * Description: Request-to-PID diagnostics for WordPress with a mandatory MU bootstrap, CPU/wait classification, SQL/HTTP attribution, lifecycle timing, and live process escalation.
 * Version: 0.3.0
 * Author: Internal Diagnostics
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Rocket_Request_Tracer {
    const VERSION = '0.3.0';
    const MU_VERSION = '0.3.0';
    const OPT_ENABLED = 'rrt_enabled';
    const OPT_DEEP = 'rrt_deep_attribution';
    const OPT_MAX_MB = 'rrt_max_log_mb';
    const NONCE = 'rrt_admin_action';
    const MU_FILENAME = 'request-monitor-bootstrap.php';

    private static $instance = null;
    private $http_calls = array();
    private $http_pending = array();

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        if (is_admin()) {
            add_action('admin_init', array($this, 'ensure_mu_bridge'));
            add_action('admin_menu', array($this, 'admin_menu'));
            add_action('admin_post_rrt_save', array($this, 'handle_save'));
            add_action('admin_post_rrt_clear', array($this, 'handle_clear'));
            add_action('admin_post_rrt_download', array($this, 'handle_download'));
            add_action('admin_post_rrt_repair_mu', array($this, 'handle_repair_mu'));
        }
        if ($this->has_trace_context()) $this->attach_to_mu_context();
    }

    public static function activate() {
        $result = self::install_mu_bridge();
        if (is_wp_error($result)) {
            deactivate_plugins(plugin_basename(__FILE__));
            wp_die(
                '<h1>Request Monitor activation failed</h1><p>' . esc_html($result->get_error_message()) . '</p><p>The MU bootstrap is mandatory and must be writable under <code>wp-content/mu-plugins</code>.</p>',
                'Request Monitor', array('back_link' => true)
            );
        }
    }

    public static function deactivate() {
        update_option(self::OPT_ENABLED, 0, false);
        $target = self::mu_target_path();
        if (is_file($target)) {
            $head = @file_get_contents($target, false, null, 0, 512);
            if (is_string($head) && strpos($head, 'Request Monitor Bootstrap') !== false) @unlink($target);
        }
    }

    private static function mu_source_path() {
        return plugin_dir_path(__FILE__) . 'mu/' . self::MU_FILENAME;
    }

    private static function mu_target_path() {
        $dir = defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
        return trailingslashit($dir) . self::MU_FILENAME;
    }

    private static function install_mu_bridge() {
        $source = self::mu_source_path();
        $target = self::mu_target_path();
        $dir = dirname($target);
        if (!is_file($source)) return new WP_Error('rrt_mu_source_missing', 'Bundled MU bootstrap is missing from the plugin package.');
        if (!is_dir($dir) && !wp_mkdir_p($dir)) return new WP_Error('rrt_mu_dir', 'Could not create the MU plugin directory: ' . $dir);
        if (!is_writable($dir) && !is_writable($target)) return new WP_Error('rrt_mu_permissions', 'The MU plugin directory is not writable: ' . $dir);
        $source_hash = @hash_file('sha256', $source);
        $target_hash = is_file($target) ? @hash_file('sha256', $target) : null;
        if ($source_hash && $target_hash === $source_hash) return true;
        $tmp = $target . '.tmp-' . getmypid();
        if (!@copy($source, $tmp)) return new WP_Error('rrt_mu_copy', 'Could not copy the MU bootstrap into wp-content/mu-plugins.');
        @chmod($tmp, 0644);
        if (!@rename($tmp, $target)) {
            @unlink($tmp);
            return new WP_Error('rrt_mu_rename', 'Could not atomically install the MU bootstrap.');
        }
        return true;
    }

    public function ensure_mu_bridge() {
        if (!current_user_can('manage_options')) return;
        if (!$this->mu_healthy()) self::install_mu_bridge();
    }

    private function mu_healthy() {
        $source = self::mu_source_path();
        $target = self::mu_target_path();
        return is_file($source) && is_file($target) && @hash_file('sha256', $source) === @hash_file('sha256', $target);
    }

    private function has_trace_context() {
        return !empty($GLOBALS['rrt_bootstrap_context']) && is_array($GLOBALS['rrt_bootstrap_context']);
    }

    private function attach_to_mu_context() {
        $this->mark_phase('regular_plugin_loaded');
        $GLOBALS['rrt_bootstrap_context']['finalizer'] = array($this, 'build_end_enrichment');
        add_action('plugins_loaded', function () { $this->mark_phase('plugins_loaded'); }, PHP_INT_MAX);
        add_action('after_setup_theme', function () { $this->mark_phase('after_setup_theme'); }, PHP_INT_MAX);
        add_action('init', function () { $this->mark_phase('init'); }, PHP_INT_MAX);
        add_action('wp_loaded', function () { $this->mark_phase('wp_loaded'); }, PHP_INT_MAX);
        add_action('parse_request', function () { $this->mark_phase('parse_request'); }, PHP_INT_MAX);
        add_action('wp', function () { $this->mark_phase('wp'); }, PHP_INT_MAX);
        add_action('template_redirect', function () { $this->mark_phase('template_redirect'); }, PHP_INT_MAX);
        add_action('admin_init', function () { $this->mark_phase('admin_init'); }, PHP_INT_MAX);
        add_action('rest_api_init', function () { $this->mark_phase('rest_api_init'); }, PHP_INT_MAX);
        if (!empty($GLOBALS['rrt_bootstrap_context']['deep'])) {
            add_filter('http_request_args', array($this, 'http_request_start'), -9999, 2);
            add_action('http_api_debug', array($this, 'http_request_end'), 9999, 5);
        }
    }

    private function mark_phase($name) {
        if ($this->has_trace_context()) $GLOBALS['rrt_bootstrap_context']['phases'][$name] = microtime(true);
    }

    public function http_request_start($args, $url) {
        $id = count($this->http_pending);
        $this->http_pending[$id] = array(
            'url_raw'=>(string)$url,
            'url'=>$this->sanitize_url($url),
            'start'=>microtime(true),
            'caller'=>function_exists('wp_debug_backtrace_summary') ? wp_debug_backtrace_summary(null,0,false) : null,
            'done'=>false,
        );
        return $args;
    }

    public function http_request_end($response, $context, $class, $parsed_args, $url) {
        $match = null;
        foreach ($this->http_pending as $id => $pending) {
            if (!$pending['done'] && $pending['url_raw'] === (string)$url) { $match = $id; break; }
        }
        if ($match === null) return;
        $pending = $this->http_pending[$match];
        $this->http_pending[$match]['done'] = true;
        $code = null; $error = null;
        if (is_wp_error($response)) $error = $response->get_error_code();
        elseif (is_array($response)) $code = wp_remote_retrieve_response_code($response);
        $this->http_calls[] = array(
            'url'=>$pending['url'],
            'duration_ms'=>round((microtime(true)-$pending['start'])*1000,3),
            'response'=>$code,'error'=>$error,'caller'=>$pending['caller'],
            'transport'=>is_object($class)?get_class($class):(is_string($class)?$class:null),
        );
    }

    public function build_end_enrichment() {
        $deep = $this->has_trace_context() && !empty($GLOBALS['rrt_bootstrap_context']['deep']);
        return array(
            'plugin_version'=>self::VERSION,
            'wordpress'=>$this->wordpress_context(),
            'included_groups'=>$this->included_file_groups(),
            'sql'=>$deep?$this->sql_summary():array('count'=>null,'total_ms'=>null,'top'=>array()),
            'http'=>$deep?$this->http_summary():array('count'=>null,'total_ms'=>null,'top'=>array()),
        );
    }

    private function wordpress_context() {
        global $wp_query;
        $context = array(
            'is_admin'=>is_admin(),
            'is_ajax'=>wp_doing_ajax(),
            'is_cron'=>wp_doing_cron(),
            'is_rest'=>defined('REST_REQUEST')&&REST_REQUEST,
            'memory_limit'=>ini_get('memory_limit'),
            'php_version'=>PHP_VERSION,
            'wp_version'=>get_bloginfo('version'),
        );
        if (isset($wp_query) && is_object($wp_query)) {
            $context['query'] = array(
                'is_home'=>$wp_query->is_home(),'is_search'=>$wp_query->is_search(),
                'is_archive'=>$wp_query->is_archive(),'is_tax'=>$wp_query->is_tax(),
                'is_singular'=>$wp_query->is_singular(),'is_404'=>$wp_query->is_404(),
                'post_count'=>isset($wp_query->post_count)?(int)$wp_query->post_count:null,
                'found_posts'=>isset($wp_query->found_posts)?(int)$wp_query->found_posts:null,
            );
        }
        return $context;
    }

    private function included_file_groups() {
        $groups = array();
        foreach (get_included_files() as $file) {
            if (preg_match('#/wp-content/mu-plugins/([^/]+)/#',$file,$m)) $group='mu-plugin:'.$m[1];
            elseif (preg_match('#/wp-content/plugins/([^/]+)/#',$file,$m)) $group='plugin:'.$m[1];
            elseif (preg_match('#/wp-content/themes/([^/]+)/#',$file,$m)) $group='theme:'.$m[1];
            elseif (strpos($file,'/wp-includes/')!==false||strpos($file,'/wp-admin/')!==false) $group='wordpress-core';
            else $group='other';
            $groups[$group]=($groups[$group]??0)+1;
        }
        arsort($groups);
        return array_slice($groups,0,30,true);
    }

    private function sql_summary() {
        global $wpdb;
        $result=array('count'=>0,'total_ms'=>0,'top'=>array());
        if (!isset($wpdb->queries)||!is_array($wpdb->queries)) return $result;
        $rows=array();
        foreach ($wpdb->queries as $entry) {
            if (!is_array($entry)||!isset($entry[0],$entry[1])) continue;
            $sql=(string)$entry[0]; $seconds=(float)$entry[1];
            $rows[]=array(
                'duration_ms'=>round($seconds*1000,3),
                'query'=>$this->sanitize_sql($sql),
                'query_hash'=>substr(hash('sha256',$sql),0,16),
                'caller'=>isset($entry[2])?(string)$entry[2]:null,
            );
            $result['total_ms'] += $seconds*1000;
        }
        usort($rows,function($a,$b){return $b['duration_ms']<=>$a['duration_ms'];});
        $result['count']=count($rows); $result['total_ms']=round($result['total_ms'],3); $result['top']=array_slice($rows,0,10);
        return $result;
    }

    private function sanitize_sql($sql) {
        $sql=preg_replace("/'(?:''|\\\\'|[^'])*'/s","'?'",$sql);
        $sql=preg_replace('/"(?:\\\\"|[^"])*"/s','"?"',$sql);
        $sql=preg_replace('/\\b\\d+(?:\\.\\d+)?\\b/','?',$sql);
        $sql=preg_replace('/\\s+/',' ',trim($sql));
        return substr($sql,0,1200);
    }

    private function http_summary() {
        $calls=$this->http_calls;
        usort($calls,function($a,$b){return $b['duration_ms']<=>$a['duration_ms'];});
        $total=0; foreach($calls as $call) $total += $call['duration_ms'];
        return array('count'=>count($calls),'total_ms'=>round($total,3),'top'=>array_slice($calls,0,10));
    }

    private function sanitize_url($url) {
        $p=wp_parse_url((string)$url);
        if(!is_array($p)) return substr((string)$url,0,1000);
        return substr((isset($p['scheme'])?$p['scheme'].'://':'').($p['host']??'').(isset($p['port'])?':'.$p['port']:'').($p['path']??''),0,1000);
    }

    private function log_dir() { return WP_CONTENT_DIR . '/rocket-request-tracer'; }
    private function log_file() {
        $seed=defined('AUTH_SALT')?AUTH_SALT:ABSPATH;
        return $this->log_dir().'/trace-'.substr(hash('sha256',$seed),0,16).'.php';
    }
    private function ensure_log() {
        $dir=$this->log_dir();
        if(!is_dir($dir)) wp_mkdir_p($dir);
        if(!file_exists($dir.'/index.php')) @file_put_contents($dir.'/index.php',"<?php\nexit;\n");
        if(!file_exists($dir.'/.htaccess')) @file_put_contents($dir.'/.htaccess',"Require all denied\nDeny from all\n");
        if(!file_exists($this->log_file())) @file_put_contents($this->log_file(),"<?php exit; __halt_compiler(); ?>\n",LOCK_EX);
    }

    private function read_events($limit=12000) {
        $this->ensure_log();
        $lines=@file($this->log_file(),FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
        if(!is_array($lines)) return array();
        if(isset($lines[0])&&strpos($lines[0],'__halt_compiler')!==false) array_shift($lines);
        if(count($lines)>$limit) $lines=array_slice($lines,-$limit);
        $events=array();
        foreach($lines as $line){$decoded=json_decode($line,true);if(is_array($decoded)&&!empty($decoded['request_id']))$events[]=$decoded;}
        return $events;
    }

    private function build_rows($events) {
        $pairs=array();
        foreach($events as $event){$id=$event['request_id'];if(!isset($pairs[$id]))$pairs[$id]=array();if($event['event']==='START')$pairs[$id]['start']=$event;if($event['event']==='END')$pairs[$id]['end']=$event;}
        $rows=array();
        foreach($pairs as $id=>$pair){
            if(empty($pair['start']))continue;$s=$pair['start'];$e=$pair['end']??null;
            $rows[]=array(
                'id'=>$id,'state'=>$e?'DONE':'ACTIVE','timestamp'=>$s['timestamp']??'','pid'=>$s['pid']??0,'method'=>$s['method']??'','path'=>$s['path']??'',
                'action'=>$s['wp_action']??($s['wc_ajax']??''),'query'=>$s['safe_query']??array(),'ip'=>$s['client_ip']??'','ray'=>$s['cf_ray']??'',
                'class'=>$e['classification']??'ACTIVE','wall'=>$e['wall_ms']??null,'cpu'=>$e['cpu_total_ms']??null,'ratio'=>$e['cpu_ratio']??null,
                'memory'=>$e['peak_memory_mb']??null,'files'=>$e['included_files']??null,'phases'=>$e['phase_durations']??array(),
                'sql'=>$e['sql']??array(),'http'=>$e['http']??array(),'groups'=>$e['included_groups']??array(),'resources'=>$e['resources']??array(),'wp'=>$e['wordpress']??array(),
            );
        }
        usort($rows,function($a,$b){if($a['state']!==$b['state'])return $a['state']==='ACTIVE'?-1:1;if($a['state']==='ACTIVE')return strcmp($b['timestamp'],$a['timestamp']);return ((float)($b['cpu']??0))<=>((float)($a['cpu']??0));});
        return $rows;
    }

    public function admin_menu() {
        add_management_page('Request Monitor','Request Monitor','manage_options','rocket-request-tracer',array($this,'render_admin'));
    }

    public function handle_save() {
        $this->require_admin_action();
        $healthy=$this->mu_healthy();
        if(!$healthy){$repair=self::install_mu_bridge();$healthy=!is_wp_error($repair)&&$this->mu_healthy();}
        update_option(self::OPT_ENABLED,($healthy&&!empty($_POST['enabled']))?1:0,false);
        update_option(self::OPT_DEEP,!empty($_POST['deep'])?1:0,false);
        update_option(self::OPT_MAX_MB,max(1,min(250,(int)($_POST['max_mb']??25))),false);
        $this->redirect_admin();
    }
    public function handle_repair_mu(){ $this->require_admin_action(); self::install_mu_bridge(); $this->redirect_admin(); }
    public function handle_clear(){ $this->require_admin_action(); $this->ensure_log(); @file_put_contents($this->log_file(),"<?php exit; __halt_compiler(); ?>\n",LOCK_EX); $this->redirect_admin(); }
    public function handle_download(){
        $this->require_admin_action();$this->ensure_log();
        header('Content-Type: application/x-ndjson');header('Content-Disposition: attachment; filename="request-monitor-'.gmdate('Ymd-His').'.jsonl"');
        $fh=@fopen($this->log_file(),'rb');if($fh){fgets($fh);while(!feof($fh))echo fread($fh,1048576);fclose($fh);}exit;
    }
    private function require_admin_action(){if(!current_user_can('manage_options'))wp_die('Insufficient permissions.');check_admin_referer(self::NONCE);}
    private function redirect_admin(){wp_safe_redirect(admin_url('tools.php?page=rocket-request-tracer'));exit;}

    private function badge($class){
        $colors=array('CPU_BOUND'=>'#b32d2e','WAIT_BOUND'=>'#996800','MIXED'=>'#7a5b00','FAST'=>'#2271b1','ACTIVE'=>'#8a2424','UNKNOWN'=>'#646970');
        $color=$colors[$class]??'#646970';
        return '<span style="display:inline-block;padding:2px 7px;border-radius:12px;background:'.esc_attr($color).';color:#fff;font-size:11px;font-weight:700">'.esc_html($class).'</span>';
    }

    private function render_detail($row){
        echo '<details><summary style="cursor:pointer">Inspect</summary><div style="min-width:700px">';
        if($row['state']==='ACTIVE'){
            $pid=(int)$row['pid'];echo '<p><strong>Live PID</strong></p>';
            foreach(array("ps -p $pid -o pid,ppid,user,stat,etime,time,%cpu,%mem,rss,wchan:32,cmd","timeout 5 strace -f -ttT -s 256 -p $pid","sudo phpspy --pid=$pid --limit=200","lsof -nP -p $pid") as $cmd)echo '<p><code>'.esc_html($cmd).'</code></p>';
        } else {
            foreach(array('Lifecycle phases'=>$row['phases'],'WordPress context'=>$row['wp'],'Resource deltas'=>$row['resources'],'Included code'=>$row['groups'],'SQL'=>$row['sql'],'Outbound HTTP'=>$row['http']) as $title=>$data){echo '<p><strong>'.esc_html($title).'</strong></p><pre style="white-space:pre-wrap">'.esc_html(wp_json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</pre>';}
        }
        echo '</div></details>';
    }

    public function render_admin(){
        if(!current_user_can('manage_options'))return;
        $healthy=$this->mu_healthy();$rows=$this->build_rows($this->read_events());$enabled=(bool)get_option(self::OPT_ENABLED,false);$deep=(bool)get_option(self::OPT_DEEP,false);$max_mb=(int)get_option(self::OPT_MAX_MB,25);
        ?>
        <div class="wrap">
            <h1>Request Monitor <small style="font-size:14px;color:#646970">v<?php echo esc_html(self::VERSION); ?></small></h1>
            <p>Mandatory MU bootstrap tracing: request → PID → WordPress lifecycle → CPU/I/O → SQL/HTTP attribution.</p>
            <div style="background:#fff;border:1px solid #ccd0d4;padding:14px 16px;margin:16px 0"><strong>MU bootstrap:</strong>
                <?php if($healthy):?><span style="color:#008a20;font-weight:700">HEALTHY</span><?php else:?><span style="color:#b32d2e;font-weight:700">MISSING / OUTDATED</span><?php endif;?>
                &nbsp;<code><?php echo esc_html(self::mu_target_path()); ?></code>
                <?php if(!$healthy):?><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rrt_repair_mu'),self::NONCE)); ?>">Repair MU bridge</a><?php endif;?>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="background:#fff;border:1px solid #ccd0d4;padding:14px 16px;margin:16px 0">
                <?php wp_nonce_field(self::NONCE); ?><input type="hidden" name="action" value="rrt_save">
                <label style="margin-right:20px"><input type="checkbox" name="enabled" value="1" <?php checked($enabled); ?> <?php disabled(!$healthy); ?>> <strong>Enable tracing</strong></label>
                <label style="margin-right:20px"><input type="checkbox" name="deep" value="1" <?php checked($deep); ?>> <strong>Deep attribution</strong></label>
                <label>Max log MB <input type="number" name="max_mb" min="1" max="250" value="<?php echo esc_attr($max_mb); ?>" style="width:75px"></label>
                <?php submit_button('Save settings','primary','submit',false); ?>
                <p style="margin-bottom:0;color:#646970">Deep mode enables SQL query retention from MU-plugin load onward plus WordPress HTTP caller timing. Use it for short production windows.</p>
            </form>
            <p><a class="button" href="<?php echo esc_url(admin_url('tools.php?page=rocket-request-tracer')); ?>">Refresh</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rrt_download'),self::NONCE)); ?>">Download JSONL</a>
                <a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=rrt_clear'),self::NONCE)); ?>" onclick="return confirm('Clear trace log?')">Clear Log</a></p>
            <div style="overflow:auto;background:#fff;border:1px solid #ccd0d4"><table class="widefat striped" style="min-width:1800px">
                <thead><tr><th>Class</th><th>UTC</th><th>PID</th><th>Method</th><th>Path</th><th>Action</th><th>Safe query</th><th>Wall</th><th>CPU</th><th>CPU %</th><th>Peak MB</th><th>Files</th><th>IP</th><th>CF-Ray</th><th>Details</th></tr></thead><tbody>
                <?php foreach(array_slice($rows,0,300) as $row):?><tr>
                    <td><?php echo $this->badge($row['class']); ?></td><td><small><?php echo esc_html($row['timestamp']); ?></small></td><td><code><?php echo esc_html($row['pid']); ?></code></td>
                    <td><?php echo esc_html($row['method']); ?></td><td><code><?php echo esc_html($row['path']); ?></code></td><td><code><?php echo esc_html($row['action']); ?></code></td>
                    <td><code><?php echo esc_html(wp_json_encode($row['query'],JSON_UNESCAPED_SLASHES)); ?></code></td>
                    <td><?php echo $row['wall']!==null?esc_html(number_format_i18n($row['wall'],1).' ms'):'—'; ?></td><td><?php echo $row['cpu']!==null?esc_html(number_format_i18n($row['cpu'],1).' ms'):'—'; ?></td>
                    <td><?php echo $row['ratio']!==null?esc_html(number_format_i18n($row['ratio']*100,1).'%'):'—'; ?></td><td><?php echo $row['memory']!==null?esc_html(number_format_i18n($row['memory'],1)):'—'; ?></td>
                    <td><?php echo $row['files']!==null?esc_html($row['files']):'—'; ?></td><td><?php echo esc_html($row['ip']); ?></td><td><code><?php echo esc_html($row['ray']); ?></code></td><td><?php $this->render_detail($row); ?></td>
                </tr><?php endforeach;?></tbody></table></div>
        </div><?php
    }
}

register_activation_hook(__FILE__, array('Rocket_Request_Tracer','activate'));
register_deactivation_hook(__FILE__, array('Rocket_Request_Tracer','deactivate'));
Rocket_Request_Tracer::instance();
