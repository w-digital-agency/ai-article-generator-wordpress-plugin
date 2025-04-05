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
            PRIMARY KEY  (id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public function log_event($event_type, $description, $severity = 'info') {
        global $wpdb;
        
        // Ensure table exists
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->log_table'") != $this->log_table) {
            $this->init_table();
        }
        
        // Clean old logs if we exceed max_logs
        $this->cleanup_old_logs();

        $data = array(
            'event_type' => sanitize_text_field($event_type),
            'event_description' => sanitize_textarea_field($description),
            'user_id' => get_current_user_id(),
            'ip_address' => $this->get_client_ip(),
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '',
            'created_at' => current_time('mysql'),
            'severity' => sanitize_text_field($severity)
        );

        $wpdb->insert($this->log_table, $data);

        // If it's a high severity event, notify admin
        if ($severity === 'high') {
            $this->notify_admin($event_type, $description);
        }
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
        $ip = '';
        if (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return sanitize_text_field($ip);
    }

    private function notify_admin($event_type, $description) {
        $admin_email = get_option('admin_email');
        $subject = sprintf('Security Alert: %s - Article Generator Plugin', $event_type);
        $message = sprintf(
            "A high-severity security event has occurred:\n\nType: %s\nDescription: %s\nIP: %s\nUser: %s\nTime: %s",
            $event_type,
            $description,
            $this->get_client_ip(),
            wp_get_current_user()->user_login,
            current_time('mysql')
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
                $limit
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
                WHERE event_type = %s",
                'failed_api_request'
            )
        );
        
        return $stats;
    }
}
