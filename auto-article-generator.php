<?php
/*
Plugin Name: Auto Article Generator
Description: Automatically generates SEO-optimized articles using various AI providers
Version: 1.0
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

// Helper functions
function esc_csv($value) {
    // Replace double quotes with two double quotes
    return str_replace('"', '""', $value);
}

// Include required files - ORDER IS IMPORTANT
// Base classes first
require_once AAG_PLUGIN_PATH . 'includes/class-ai-provider.php';
require_once AAG_PLUGIN_PATH . 'includes/class-encryption-handler.php';
require_once AAG_PLUGIN_PATH . 'includes/class-security-logger.php';
require_once AAG_PLUGIN_PATH . 'includes/class-image-handler.php';

// Provider classes next
require_once AAG_PLUGIN_PATH . 'includes/providers/class-deepseek-provider.php';
require_once AAG_PLUGIN_PATH . 'includes/providers/class-perplexity-provider.php';
require_once AAG_PLUGIN_PATH . 'includes/providers/class-grok-provider.php';
require_once AAG_PLUGIN_PATH . 'includes/providers/class-openrouter-provider.php';

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
        $security_logger->log_event('plugin_activated', 'Auto Article Generator plugin activated', 'info');
    }
    
    // Set default options if they don't exist
    if (!get_option('aag_default_provider')) {
        update_option('aag_default_provider', 'deepseek');
    }
    
    if (!get_option('aag_image_quality')) {
        update_option('aag_image_quality', 85);
    }
    
    if (!get_option('aag_max_image_width')) {
        update_option('aag_max_image_width', 1200);
    }
    
    // Flush rewrite rules
    flush_rewrite_rules();
}