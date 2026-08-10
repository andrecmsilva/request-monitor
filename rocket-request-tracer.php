<?php
/**
 * Plugin Name: Request Monitor
 * Description: Request-to-PID diagnostics with MU tracing, slow escalation, hook timing, fingerprints, trace scopes, and WP-CLI control.
 * Version: 0.5.0
 * Author: Internal Diagnostics
 */

if (!defined('ABSPATH')) exit;

define('REQUEST_MONITOR_VERSION', '0.5.0');
define('REQUEST_MONITOR_FILE', __FILE__);
define('REQUEST_MONITOR_DIR', plugin_dir_path(__FILE__));

require_once REQUEST_MONITOR_DIR . 'includes/class-request-monitor-store.php';
require_once REQUEST_MONITOR_DIR . 'includes/class-request-monitor-core.php';
require_once REQUEST_MONITOR_DIR . 'includes/class-request-monitor-admin.php';

if (defined('WP_CLI') && WP_CLI) {
    require_once REQUEST_MONITOR_DIR . 'includes/class-request-monitor-cli.php';
}

register_activation_hook(__FILE__, array('Request_Monitor_Core', 'activate'));
register_deactivation_hook(__FILE__, array('Request_Monitor_Core', 'deactivate'));

Request_Monitor_Core::instance();
if (is_admin()) Request_Monitor_Admin::instance();
if (defined('WP_CLI') && WP_CLI) Request_Monitor_CLI::register();
