<?php
/**
 * Request Monitor hook profiler.
 * Bundled helper installed alongside the mandatory MU bootstrap.
 */

if (!defined('ABSPATH') || class_exists('Request_Monitor_Hook_Profiler', false)) {
    return;
}

final class Request_Monitor_Hook_Profiler {
    private static $started = false;
    private static $request_start = 0.0;
    private static $slow_threshold_ms = 1500.0;
    private static $callback_floor_ms = 5.0;
    private static $deep = false;
    private static $callback_armed = false;
    private static $callback_started_ms = null;
    private static $hook_starts = array();
    private static $hooks = array();
    private static $callbacks = array();
    private static $owners = array();
    private static $wrapped = array();
    private static $skipped_reference = array();
    private static $seen_hooks = array();
    private static $max_callback_rows = 250;

    public static function start($request_start, $slow_threshold_ms, $callback_floor_ms, $deep) {
        if (self::$started || !function_exists('add_action')) {
            return;
        }

        self::$started = true;
        self::$request_start = (float) $request_start;
        self::$slow_threshold_ms = max(250.0, (float) $slow_threshold_ms);
        self::$callback_floor_ms = max(0.1, (float) $callback_floor_ms);
        self::$deep = (bool) $deep;

        if (self::$deep) {
            self::arm_callback_timing(0.0, 'deep');
        }

        add_action('all', array(__CLASS__, 'observe_hook_entry'), PHP_INT_MIN, 1);
    }

    public static function observe_hook_entry() {
        if (!self::$started || !function_exists('current_filter')) {
            return;
        }

        $hook = current_filter();
        if (!$hook || $hook === 'all') {
            return;
        }

        $now = self::now_ms();
        self::$seen_hooks[$hook] = true;
        self::$hook_starts[$hook][] = self::clock_ns();

        self::ensure_end_sentinel($hook);

        if (!self::$callback_armed && $now >= self::$slow_threshold_ms) {
            self::arm_callback_timing($now, 'threshold');
        }

        if (self::$callback_armed) {
            self::wrap_hook_callbacks($hook);
        }
    }

    public static function finish_hook($value = null) {
        if (!self::$started || !function_exists('current_filter')) {
            return $value;
        }

        $hook = current_filter();
        if (!$hook || empty(self::$hook_starts[$hook])) {
            return $value;
        }

        $start_ns = array_pop(self::$hook_starts[$hook]);
        $duration_ms = (self::clock_ns() - $start_ns) / 1000000;

        if (!isset(self::$hooks[$hook])) {
            self::$hooks[$hook] = array(
                'hook' => $hook,
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
                'callbacks' => array(),
            );
        }

        self::$hooks[$hook]['count']++;
        self::$hooks[$hook]['total_ms'] += $duration_ms;
        self::$hooks[$hook]['max_ms'] = max(self::$hooks[$hook]['max_ms'], $duration_ms);

        if (empty(self::$hooks[$hook]['callbacks'])) {
            self::$hooks[$hook]['callbacks'] = self::hook_manifest($hook, 20);
        }

        $elapsed = self::now_ms();
        if (!self::$callback_armed && $elapsed >= self::$slow_threshold_ms) {
            self::arm_callback_timing($elapsed, 'threshold_after_hook');
        }

        return $value;
    }

    private static function ensure_end_sentinel($hook) {
        remove_filter($hook, array(__CLASS__, 'finish_hook'), PHP_INT_MAX);
        add_filter($hook, array(__CLASS__, 'finish_hook'), PHP_INT_MAX, 1);
    }

    private static function arm_callback_timing($elapsed_ms, $reason) {
        if (self::$callback_armed) {
            return;
        }

        self::$callback_armed = true;
        self::$callback_started_ms = round((float) $elapsed_ms, 3);

        if (!self::$deep && !empty($GLOBALS['rrt_bootstrap_context']) && is_array($GLOBALS['rrt_bootstrap_context'])) {
            $GLOBALS['rrt_bootstrap_context']['auto_escalated'] = true;
            $GLOBALS['rrt_bootstrap_context']['auto_escalated_at_ms'] = self::$callback_started_ms;
            $GLOBALS['rrt_bootstrap_context']['auto_escalation_reason'] = $reason;

            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && property_exists($wpdb, 'save_queries')) {
                $wpdb->save_queries = true;
            }
        }
    }

    private static function wrap_hook_callbacks($hook) {
        global $wp_filter;

        if (!isset($wp_filter[$hook]) || !is_object($wp_filter[$hook]) || empty($wp_filter[$hook]->callbacks)) {
            return;
        }

        foreach ($wp_filter[$hook]->callbacks as $priority => &$callbacks) {
            foreach ($callbacks as $id => &$entry) {
                if (empty($entry['function']) || self::is_our_callback($entry['function'])) {
                    continue;
                }

                $slot = $hook . '|' . $priority . '|' . $id;
                if (isset(self::$wrapped[$slot]) && $entry['function'] === self::$wrapped[$slot]['wrapper']) {
                    continue;
                }

                $meta = self::describe_callable($entry['function']);
                if (empty($meta['timable'])) {
                    if (!empty($meta['by_reference'])) {
                        self::$skipped_reference[$slot] = array(
                            'hook' => $hook,
                            'callable' => $meta['callable'],
                            'owner' => $meta['owner'],
                            'file' => $meta['file'],
                        );
                    }
                    continue;
                }

                if (!preg_match('/^(plugin|mu-plugin|theme):/', (string) $meta['owner'])) {
                    continue;
                }
                if ($meta['owner'] === 'plugin:request-monitor' || $meta['owner'] === 'mu-plugin:request-monitor-bootstrap.php' || $meta['owner'] === 'mu-plugin:request-monitor-hook-profiler.php') {
                    continue;
                }
                if (!empty($meta['file']) && basename($meta['file']) === 'rocket-request-tracer.php') {
                    continue;
                }

                $original = $entry['function'];
                $callback_key = substr(hash('sha256', $hook . '|' . $priority . '|' . $id . '|' . $meta['callable']), 0, 20);
                $wrapper = function () use ($original, $meta, $callback_key, $hook, $priority) {
                    $args = func_get_args();
                    $start = Request_Monitor_Hook_Profiler::clock_for_wrapper();
                    try {
                        return call_user_func_array($original, $args);
                    } finally {
                        $duration_ms = (Request_Monitor_Hook_Profiler::clock_for_wrapper() - $start) / 1000000;
                        Request_Monitor_Hook_Profiler::record_callback($callback_key, $hook, $priority, $meta, $duration_ms);
                    }
                };

                self::$wrapped[$slot] = array('wrapper' => $wrapper, 'original' => $original);
                $entry['function'] = $wrapper;
            }
            unset($entry);
        }
        unset($callbacks);
    }

    public static function clock_for_wrapper() {
        return self::clock_ns();
    }

    private static function clock_ns() {
        if (function_exists('hrtime')) {
            return hrtime(true);
        }
        return (int) round(microtime(true) * 1000000000);
    }

    public static function record_callback($key, $hook, $priority, $meta, $duration_ms) {
        if ($duration_ms < self::$callback_floor_ms) {
            return;
        }

        if (!isset(self::$callbacks[$key])) {
            if (count(self::$callbacks) >= self::$max_callback_rows) {
                $smallest_key = null;
                $smallest = INF;
                foreach (self::$callbacks as $existing_key => $row) {
                    if ($row['total_ms'] < $smallest) {
                        $smallest = $row['total_ms'];
                        $smallest_key = $existing_key;
                    }
                }
                if ($smallest_key === null || $duration_ms <= $smallest) {
                    return;
                }
                unset(self::$callbacks[$smallest_key]);
            }

            self::$callbacks[$key] = array(
                'hook' => $hook,
                'priority' => (int) $priority,
                'callable' => $meta['callable'],
                'owner' => $meta['owner'],
                'file' => $meta['file'],
                'count' => 0,
                'total_ms' => 0.0,
                'max_ms' => 0.0,
            );
        }

        self::$callbacks[$key]['count']++;
        self::$callbacks[$key]['total_ms'] += $duration_ms;
        self::$callbacks[$key]['max_ms'] = max(self::$callbacks[$key]['max_ms'], $duration_ms);

        $owner = (string) $meta['owner'];
        if (!isset(self::$owners[$owner])) {
            self::$owners[$owner] = array('owner' => $owner, 'count' => 0, 'total_ms' => 0.0, 'max_ms' => 0.0);
        }
        self::$owners[$owner]['count']++;
        self::$owners[$owner]['total_ms'] += $duration_ms;
        self::$owners[$owner]['max_ms'] = max(self::$owners[$owner]['max_ms'], $duration_ms);
    }

    private static function is_our_callback($callback) {
        if (is_array($callback) && count($callback) === 2) {
            $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
            return $class === __CLASS__ || $class === 'Rocket_Request_Tracer';
        }
        return false;
    }

    private static function describe_callable($callback) {
        $callable = 'unknown';
        $file = null;
        $reflection = null;

        try {
            if ($callback instanceof Closure) {
                $reflection = new ReflectionFunction($callback);
                $callable = 'Closure@' . basename((string) $reflection->getFileName()) . ':' . $reflection->getStartLine();
            } elseif (is_string($callback)) {
                if (strpos($callback, '::') !== false) {
                    list($class, $method) = explode('::', $callback, 2);
                    $reflection = new ReflectionMethod($class, $method);
                    $callable = $class . '::' . $method;
                } else {
                    $reflection = new ReflectionFunction($callback);
                    $callable = $callback;
                }
            } elseif (is_array($callback) && count($callback) === 2) {
                $class = is_object($callback[0]) ? get_class($callback[0]) : (string) $callback[0];
                $method = (string) $callback[1];
                $reflection = new ReflectionMethod($class, $method);
                $callable = $class . '::' . $method;
            } elseif (is_object($callback) && method_exists($callback, '__invoke')) {
                $reflection = new ReflectionMethod($callback, '__invoke');
                $callable = get_class($callback) . '::__invoke';
            }
        } catch (Throwable $e) {
            return array('callable' => $callable, 'file' => null, 'owner' => 'unknown', 'timable' => false, 'by_reference' => false);
        }

        if ($reflection) {
            $file = $reflection->getFileName();
        }

        $by_reference = false;
        if ($reflection) {
            foreach ($reflection->getParameters() as $parameter) {
                if ($parameter->isPassedByReference()) {
                    $by_reference = true;
                    break;
                }
            }
        }

        $owner = self::owner_from_file($file);

        return array(
            'callable' => $callable,
            'file' => $file ?: null,
            'owner' => $owner,
            'timable' => $reflection && !$by_reference && !$reflection->isInternal(),
            'by_reference' => $by_reference,
        );
    }

    private static function owner_from_file($file) {
        if (!$file) {
            return 'internal-or-unknown';
        }

        $normalized = str_replace('\\', '/', $file);
        $content = defined('WP_CONTENT_DIR') ? str_replace('\\', '/', WP_CONTENT_DIR) : '';
        $plugins = defined('WP_PLUGIN_DIR') ? str_replace('\\', '/', WP_PLUGIN_DIR) : ($content ? $content . '/plugins' : '');
        $mu = defined('WPMU_PLUGIN_DIR') ? str_replace('\\', '/', WPMU_PLUGIN_DIR) : ($content ? $content . '/mu-plugins' : '');
        $themes = $content ? $content . '/themes' : '';

        foreach (array('mu-plugin' => $mu, 'plugin' => $plugins, 'theme' => $themes) as $type => $root) {
            if ($root && strpos($normalized, rtrim($root, '/') . '/') === 0) {
                $relative = substr($normalized, strlen(rtrim($root, '/')) + 1);
                $parts = explode('/', $relative);
                return $type . ':' . ($parts[0] ?: basename($normalized));
            }
        }

        if (defined('ABSPATH') && strpos($normalized, str_replace('\\', '/', ABSPATH)) === 0) {
            return 'wordpress-core';
        }

        return 'other';
    }

    private static function hook_manifest($hook, $limit) {
        global $wp_filter;
        $rows = array();

        if (!isset($wp_filter[$hook]) || !is_object($wp_filter[$hook]) || empty($wp_filter[$hook]->callbacks)) {
            return $rows;
        }

        foreach ($wp_filter[$hook]->callbacks as $priority => $callbacks) {
            foreach ($callbacks as $entry) {
                if (empty($entry['function']) || self::is_our_callback($entry['function'])) {
                    continue;
                }
                $meta = self::describe_callable($entry['function']);
                $rows[] = array(
                    'priority' => (int) $priority,
                    'callable' => $meta['callable'],
                    'owner' => $meta['owner'],
                    'file' => $meta['file'],
                    'timable' => $meta['timable'],
                    'by_reference' => $meta['by_reference'],
                );
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }
        return $rows;
    }

    private static function now_ms() {
        return (microtime(true) - self::$request_start) * 1000;
    }

    public static function report($include_details) {
        $hooks = array_values(self::$hooks);
        usort($hooks, function ($a, $b) { return $b['total_ms'] <=> $a['total_ms']; });
        foreach ($hooks as &$hook) {
            $hook['total_ms'] = round($hook['total_ms'], 3);
            $hook['max_ms'] = round($hook['max_ms'], 3);
        }
        unset($hook);

        $callbacks = array_values(self::$callbacks);
        usort($callbacks, function ($a, $b) { return $b['total_ms'] <=> $a['total_ms']; });
        foreach ($callbacks as &$callback) {
            $callback['total_ms'] = round($callback['total_ms'], 3);
            $callback['max_ms'] = round($callback['max_ms'], 3);
        }
        unset($callback);

        $owners = array_values(self::$owners);
        usort($owners, function ($a, $b) { return $b['total_ms'] <=> $a['total_ms']; });
        foreach ($owners as &$owner) {
            $owner['total_ms'] = round($owner['total_ms'], 3);
            $owner['max_ms'] = round($owner['max_ms'], 3);
        }
        unset($owner);

        $report = array(
            'mode' => self::$deep ? 'deep_from_start' : 'auto_threshold',
            'slow_threshold_ms' => self::$slow_threshold_ms,
            'callback_floor_ms' => self::$callback_floor_ms,
            'callback_timing_armed' => self::$callback_armed,
            'callback_timing_started_ms' => self::$callback_started_ms,
            'hooks_seen' => count(self::$seen_hooks),
            'hooks_timed' => count(self::$hooks),
            'timed_callback_rows' => count(self::$callbacks),
            'skipped_by_reference' => count(self::$skipped_reference),
        );

        if ($include_details) {
            $report['top_hooks'] = array_slice($hooks, 0, 30);
            $report['top_callbacks'] = array_slice($callbacks, 0, 40);
            $report['top_owners'] = array_slice($owners, 0, 20);
            $report['skipped_reference_callbacks'] = array_slice(array_values(self::$skipped_reference), 0, 20);
        }

        return $report;
    }
}
