<?php
/*
Plugin Name: Auto Article Generator Pro
Description: Automatically generates SEO-optimized articles using AI providers with Notion synchronization
Version: 2.0
Author: W Digital Agency
Author URI: https://www.wdigitalagency.com/
License: GPL v2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: auto-article-generator
Domain Path: /languages
*/

if (!defined('ABSPATH')) exit;

// Define plugin constants
define('AAG_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('AAG_PLUGIN_URL', plugin_dir_url(__FILE__));
define('AAG_VERSION', '2.0');

// Helper functions
function esc_csv($value) {
    return str_replace('"', '""', $value);
}

// Include required files - ORDER IS IMPORTANT
// Base classes first
require_once AAG_PLUGIN_PATH . 'includes/class-ai-provider.php';
require_once AAG_PLUGIN_PATH . 'includes/class-encryption-handler.php';
require_once AAG_PLUGIN_PATH . 'includes/class-security-logger.php';
require_once AAG_PLUGIN_PATH . 'includes/class-image-handler.php';
require_once AAG_PLUGIN_PATH . 'includes/class-notion-sync.php';
require_once AAG_PLUGIN_PATH . 'includes/class-notion-sync-debugger.php';

// Provider classes next
require_once AAG_PLUGIN_PATH . 'includes/providers/class-openrouter-provider.php';
require_once AAG_PLUGIN_PATH . 'includes/providers/class-openai-provider.php';

// Main plugin classes last
require_once AAG_PLUGIN_PATH . 'includes/class-article-generator.php';
require_once AAG_PLUGIN_PATH . 'includes/class-admin-menu.php';

// Initialize the plugin
function aag_init() {
    // Ensure all classes are loaded before initializing
    if (!class_exists('AAG_Admin_Menu') || !class_exists('AAG_Article_Generator')) {
        return;
    }
    
    new AAG_Admin_Menu();
    new AAG_Article_Generator();
    
    // Initialize Notion sync if available
    if (class_exists('AAG_Notion_Sync')) {
        new AAG_Notion_Sync();
    }
}
add_action('plugins_loaded', 'aag_init');

// Activation hook
register_activation_hook(__FILE__, 'aag_activate');

function aag_activate() {
    // Create upload directory for markdown files
    $upload_dir = wp_upload_dir();
    $markdown_dir = $upload_dir['basedir'] . '/aag-markdown';
    
    if (!file_exists($markdown_dir)) {
        wp_mkdir_p($markdown_dir);
    }
    
    // Initialize security logger to create the table
    if (class_exists('AAG_Security_Logger')) {
        $security_logger = new AAG_Security_Logger();
        $security_logger->log_event('plugin_activated', 'Auto Article Generator Pro plugin activated', 'info');
    }
    
    // Set default options if they don't exist
    if (!get_option('aag_default_provider')) {
        update_option('aag_default_provider', 'openrouter');
    }
    
    if (!get_option('aag_image_quality')) {
        update_option('aag_image_quality', 85);
    }
    
    if (!get_option('aag_max_image_width')) {
        update_option('aag_max_image_width', 1200);
    }
    
    // Initialize Notion sync table
    if (class_exists('AAG_Notion_Sync')) {
        $notion_sync = new AAG_Notion_Sync();
        $notion_sync->create_sync_table();
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Add cron job for Notion sync
add_action('wp', 'aag_schedule_notion_sync');
function aag_schedule_notion_sync() {
    if (!wp_next_scheduled('aag_notion_sync_cron')) {
        wp_schedule_event(time(), 'hourly', 'aag_notion_sync_cron');
    }
}

add_action('aag_notion_sync_cron', 'aag_run_notion_sync');
function aag_run_notion_sync() {
    if (class_exists('AAG_Notion_Sync')) {
        $notion_sync = new AAG_Notion_Sync();
        $notion_sync->sync_from_notion();
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'aag_deactivate');
function aag_deactivate() {
    wp_clear_scheduled_hook('aag_notion_sync_cron');
}