<?php

class AAG_Security_Logger {
    private $log_table;
    private $max_logs = 1000;

    public function __construct() {
        global $wpdb;
        $this->log_table = $wpdb->prefix . 'aag_security_logs';
        $this->init_table();
    }

    private function init_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $this->log_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            event_type varchar(50) NOT NULL,
            event_description text NOT NULL,
            user_id bigint(20) NOT NULL,
            ip_address varchar(45) NOT NULL,
            user_agent varchar(255) NOT NULL,
            created_at datetime NOT NULL,
            severity varchar(20) NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY severity (severity),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function log_event($event_type, $description, $severity = 'info') {
        global $wpdb;
        
        // Sanitize all inputs
        $event_type = sanitize_text_field($event_type);
        $description = sanitize_textarea_field($description);
        $severity = sanitize_text_field($severity);
        
        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->log_table'") != $this->log_table) {
            $this->init_table();
        }
        
        // Clean old logs if we exceed max_logs
        $this->cleanup_old_logs();

        // Prepare data - ensure no sensitive information is logged
        $data = array(
            'event_type' => $event_type,
            'event_description' => $this->sanitize_log_description($description),
            'user_id' => get_current_user_id(),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => $this->sanitize_user_agent(),
            'created_at' => current_time('mysql'),
            'severity' => in_array($severity, ['low', 'medium', 'high', 'info']) ? $severity : 'info'
        );

        $result = $wpdb->insert($this->log_table, $data);

        // If it's a high severity event, notify admin
        if ($severity === 'high' && $result !== false) {
            $this->notify_admin($event_type, $description);
        }

        return $result !== false;
    }

    private function sanitize_log_description($description) {
        // Remove any potential API keys or sensitive data from logs
        $patterns = [
            '/sk-[a-zA-Z0-9]{48}/',  // OpenAI API keys
            '/Bearer\s+[a-zA-Z0-9_-]+/', // Bearer tokens
            '/secret_[a-zA-Z0-9_-]+/', // Notion tokens
            '/password["\s]*[:=]["\s]*[^"\s]+/i', // Passwords
            '/token["\s]*[:=]["\s]*[^"\s]+/i', // Tokens
        ];
        
        $replacements = [
            'sk-***REDACTED***',
            'Bearer ***REDACTED***',
            'secret_***REDACTED***',
            'password: ***REDACTED***',
            'token: ***REDACTED***',
        ];
        
        return preg_replace($patterns, $replacements, $description);
    }

    private function sanitize_user_agent() {
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        // Limit length and sanitize
        return substr(sanitize_text_field($user_agent), 0, 255);
    }

    private function cleanup_old_logs() {
        global $wpdb;
        
        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->log_table'") != $this->log_table) {
            return;
        }
        
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $this->log_table");
        
        if ($count > $this->max_logs) {
            $to_delete = $count - $this->max_logs;
            $wpdb->query(
                $wpdb->prepare(
                    "DELETE FROM $this->log_table 
                    WHERE id IN (
                        SELECT id FROM (
                            SELECT id FROM $this->log_table 
                            ORDER BY created_at ASC 
                            LIMIT %d
                        ) tmp
                    )",
                    $to_delete
                )
            );
        }
    }

    private function get_client_ip() {
        $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        
        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key]) && !empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                // Handle comma-separated IPs (X-Forwarded-For)
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                // Validate IP address
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return sanitize_text_field($ip);
                }
            }
        }
        
        return 'unknown';
    }

    private function notify_admin($event_type, $description) {
        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        $subject = sprintf('[Security Alert] %s - Auto Article Generator Pro', $event_type);
        $message = sprintf(
            "A high-severity security event has occurred on your WordPress site:\n\n" .
            "Event Type: %s\n" .
            "Description: %s\n" .
            "IP Address: %s\n" .
            "User: %s\n" .
            "Time: %s\n" .
            "Site: %s\n\n" .
            "Please review your security logs in the WordPress admin area.",
            $event_type,
            $this->sanitize_log_description($description),
            $this->get_client_ip(),
            wp_get_current_user()->user_login ?: 'Unknown',
            current_time('mysql'),
            get_site_url()
        );
        
        wp_mail($admin_email, $subject, $message);
    }

    public function get_recent_logs($limit = 50) {
        global $wpdb;
        
        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->log_table'") != $this->log_table) {
            $this->init_table();
            return array();
        }
        
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $this->log_table 
                ORDER BY created_at DESC 
                LIMIT %d",
                absint($limit)
            )
        );
    }

    public function get_security_stats() {
        global $wpdb;
        
        $stats = array(
            'total_events' => 0,
            'high_severity' => 0,
            'last_24h' => 0,
            'failed_attempts' => 0
        );
        
        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->log_table'") != $this->log_table) {
            return $stats;
        }
        
        $stats['total_events'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->log_table");
        
        $stats['high_severity'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $this->log_table WHERE severity = %s",
                'high'
            )
        );
        
        $stats['last_24h'] = $wpdb->get_var(
            "SELECT COUNT(*) FROM $this->log_table 
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        $stats['failed_attempts'] = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $this->log_table 
                WHERE event_type LIKE %s",
                '%failed%'
            )
        );
        
        return $stats;
    }

    /**
     * Clear all security logs (admin function)
     */
    public function clear_logs() {
        global $wpdb;
        
        if (!current_user_can('manage_options')) {
            return false;
        }
        
        $result = $wpdb->query("TRUNCATE TABLE $this->log_table");
        
        if ($result !== false) {
            $this->log_event('security_logs_cleared', 'All security logs cleared by admin', 'medium');
        }
        
        return $result !== false;
    }
}