<?php
/**
 * Plugin Name: Request Monitor
 * Description: Bounded WordPress request snapshots with PID/resource tracing, fingerprints, optional hook profiling, and WP-CLI control.
 * Version: 0.6.0
 * Author: Internal Diagnostics
 */

if (!defined('ABSPATH')) exit;

define('REQUEST_MONITOR_VERSION', '0.6.0');
define('REQUEST_MONITOR_FILE', __FILE__);
define('REQUEST_MONITOR_DIR', plugin_dir_path(__FILE__));

$rrt_is_cli = defined('WP_CLI') && WP_CLI;
$rrt_is_admin = is_admin();
$rrt_has_context = !empty($GLOBALS['rrt_bootstrap_context']) && is_array($GLOBALS['rrt_bootstrap_context']);
$rrt_mu_current = defined('RRT_MU_VERSION') && RRT_MU_VERSION === REQUEST_MONITOR_VERSION;

// Idle frontend fast-path: current MU foundation + no capture context = load nothing else.
// A version mismatch deliberately falls through so the one-time safety migration can run.
if (!$rrt_is_cli && !$rrt_is_admin && !$rrt_has_context && $rrt_mu_current) return;

require_once REQUEST_MONITOR_DIR . 'includes/class-request-monitor-store.php';
require_once REQUEST_MONITOR_DIR . 'includes/class-request-monitor-core.php';

register_activation_hook(__FILE__, array('Request_Monitor_Core', 'activate'));
register_deactivation_hook(__FILE__, array('Request_Monitor_Core', 'deactivate'));
Request_Monitor_Core::instance();

if ($rrt_is_admin) {
    require_once REQUEST_MONITOR_DIR . 'includes/class-request-monitor-admin.php';
    Request_Monitor_Admin::instance();
}

if ($rrt_is_cli) {
    require_once REQUEST_MONITOR_DIR . 'includes/class-request-monitor-cli.php';
    Request_Monitor_CLI::register();
}
