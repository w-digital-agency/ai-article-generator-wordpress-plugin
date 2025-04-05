<?php
class AAG_Admin_Menu {
    private $encryption_handler;
    private $security_logger;

    public function __construct() {
        // Initialize encryption handler and security logger if they exist
        if (class_exists('AAG_Encryption_Handler')) {
            $this->encryption_handler = new AAG_Encryption_Handler();
        }
        
        if (class_exists('AAG_Security_Logger')) {
            $this->security_logger = new AAG_Security_Logger();
        }
        
        add_action('admin_menu', array($this, 'add_plugin_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // Only add AJAX handlers if the security classes exist
        if (isset($this->encryption_handler) && isset($this->security_logger)) {
            add_action('wp_ajax_aag_validate_api_key', array($this, 'ajax_validate_api_key'));
            add_action('wp_ajax_aag_rotate_encryption_key', array($this, 'ajax_rotate_encryption_key'));
            add_action('wp_ajax_aag_clear_api_keys', array($this, 'ajax_clear_api_keys'));
            add_action('wp_ajax_aag_export_security_logs', array($this, 'ajax_export_security_logs'));
        }
    }

    public function add_plugin_page() {
        add_menu_page(
            'Article Generator', 
            'Article Generator', 
            'manage_options', 
            'article-generator', 
            array($this, 'create_admin_page'),
            'dashicons-text'
        );
    }

    public function register_settings() {
        // Non-sensitive settings
        register_setting('article_generator_options', 'aag_default_provider');
        register_setting('article_generator_options', 'aag_openrouter_model');
        register_setting('article_generator_options', 'aag_perplexity_model');
        register_setting('article_generator_options', 'aag_deepseek_model');
        register_setting('article_generator_options', 'aag_request_timeout');
        register_setting('article_generator_options', 'aag_image_quality');
        register_setting('article_generator_options', 'aag_max_image_width');
        register_setting('article_generator_options', 'aag_image_sizes');

        // Sensitive settings with encryption
        register_setting('article_generator_options', 'aag_deepseek_api_key', 
            array($this, 'encrypt_api_key'));
        register_setting('article_generator_options', 'aag_perplexity_api_key', 
            array($this, 'encrypt_api_key'));
        register_setting('article_generator_options', 'aag_grok_api_key', 
            array($this, 'encrypt_api_key'));
        register_setting('article_generator_options', 'aag_openrouter_api_key', 
            array($this, 'encrypt_api_key'));
        register_setting('article_generator_options', 'aag_vision_api_key', 
            array($this, 'encrypt_api_key'));
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <h2>Article Generator Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('article_generator_options');
                do_settings_sections('article_generator_options');
                ?>
                
                <div class="aag-section">
                    <h3>AI Provider Settings</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Default AI Provider</th>
                            <td>
                                <select name="aag_default_provider" class="regular-text">
                                    <option value="deepseek" <?php selected(get_option('aag_default_provider'), 'deepseek'); ?>>Deepseek</option>
                                    <option value="perplexity" <?php selected(get_option('aag_default_provider'), 'perplexity'); ?>>Perplexity</option>
                                    <option value="grok" <?php selected(get_option('aag_default_provider'), 'grok'); ?>>Grok</option>
                                    <option value="openrouter" <?php selected(get_option('aag_default_provider'), 'openrouter'); ?>>OpenRouter</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API Request Timeout</th>
                            <td>
                                <input type="number" name="aag_request_timeout" 
                                    value="<?php echo esc_attr(get_option('aag_request_timeout', 180)); ?>" 
                                    class="small-text" min="30" max="300" step="30" />
                                <p class="description">Timeout in seconds for API requests (30-300 seconds). Increase this if you're experiencing timeout errors.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Deepseek Model</th>
                            <td>
                                <select name="aag_deepseek_model" class="regular-text">
                                    <option value="deepseek-chat" <?php selected(get_option('aag_deepseek_model', 'deepseek-chat'), 'deepseek-chat'); ?>>Deepseek Chat</option>
                                    <option value="deepseek-reasoner" <?php selected(get_option('aag_deepseek_model', 'deepseek-chat'), 'deepseek-reasoner'); ?>>Deepseek Reasoner</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Deepseek API Key</th>
                            <td>
                                <input type="password" name="aag_deepseek_api_key" 
                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_deepseek_api_key')); ?>" 
                                    class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Perplexity API Key</th>
                            <td>
                                <input type="password" name="aag_perplexity_api_key" 
                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_perplexity_api_key')); ?>" 
                                    class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Perplexity Model</th>
                            <td>
                                <select name="aag_perplexity_model" class="regular-text">
                                    <optgroup label="English">
                                        <option value="sonar-deep-research" <?php selected(get_option('aag_perplexity_model'), 'sonar-deep-research'); ?>>sonar-deep-research</option>
                                        <option value="sonar-reasoning-pro" <?php selected(get_option('aag_perplexity_model'), 'sonar-reasoning-pro'); ?>>sonar-reasoning-pro</option>
                                        <option value="sonar-reasoning" <?php selected(get_option('aag_perplexity_model'), 'sonar-reasoning'); ?>>sonar-reasoning</option>
                                        <option value="sonar-pro" <?php selected(get_option('aag_perplexity_model'), 'sonar-pro'); ?>>sonar-pro</option>
                                        <option value="sonar" <?php selected(get_option('aag_perplexity_model'), 'sonar'); ?>>sonar</option>
                                        <option value="r1-1776" <?php selected(get_option('aag_perplexity_model'), 'r1-1776'); ?>>r1-1776</option>
                                    </optgroup>
                                </select>
                                <p class="description">Select a Perplexity model. <a href="https://docs.perplexity.ai/guides/model-cards#sonar-deep-research" target="_blank">Learn more about each model</a></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Grok API Key</th>
                            <td>
                                <input type="password" name="aag_grok_api_key" 
                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_grok_api_key')); ?>" 
                                    class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">OpenRouter API Key</th>
                            <td>
                                <input type="password" name="aag_openrouter_api_key" 
                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_openrouter_api_key')); ?>" 
                                    class="regular-text" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">OpenRouter Model</th>
                            <td>
                                <input type="text" name="aag_openrouter_model" 
                                    value="<?php echo esc_attr(get_option('aag_openrouter_model', 'openai/gpt-4')); ?>" 
                                    class="regular-text" />
                                <p class="description">e.g., openai/gpt-4, anthropic/claude-2, meta-llama/llama-2-70b-chat</p>
                            </td>
                        </tr>
                    </table>
                </div>

                <div class="aag-section">
                    <h3>Image Settings</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Image Quality</th>
                            <td>
                                <input type="number" name="aag_image_quality" 
                                    value="<?php echo esc_attr(get_option('aag_image_quality', 82)); ?>" 
                                    min="1" max="100" class="small-text" />
                                <p class="description">JPEG/WebP compression quality (1-100)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Max Image Width</th>
                            <td>
                                <input type="number" name="aag_max_image_width" 
                                    value="<?php echo esc_attr(get_option('aag_max_image_width', 1600)); ?>" 
                                    min="100" class="small-text" />
                                <p class="description">Maximum width for uploaded images (in pixels)</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Vision API Key</th>
                            <td>
                                <input type="password" name="aag_vision_api_key" 
                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_vision_api_key')); ?>" 
                                    class="regular-text" />
                                <p class="description">Google Cloud Vision API key for automatic alt text generation</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Custom Image Sizes</th>
                            <td>
                                <?php
                                $image_sizes = get_option('aag_image_sizes', [
                                    'small' => ['width' => 300, 'height' => 300],
                                    'medium' => ['width' => 600, 'height' => 600],
                                    'large' => ['width' => 1200, 'height' => 1200]
                                ]);
                                foreach ($image_sizes as $size_name => $dimensions) :
                                ?>
                                <div class="image-size-row">
                                    <input type="text" 
                                        name="aag_image_sizes[<?php echo esc_attr($size_name); ?>][name]" 
                                        value="<?php echo esc_attr($size_name); ?>" 
                                        placeholder="Size name" />
                                    <input type="number" 
                                        name="aag_image_sizes[<?php echo esc_attr($size_name); ?>][width]" 
                                        value="<?php echo esc_attr($dimensions['width']); ?>" 
                                        placeholder="Width" 
                                        class="small-text" />
                                    <input type="number" 
                                        name="aag_image_sizes[<?php echo esc_attr($size_name); ?>][height]" 
                                        value="<?php echo esc_attr($dimensions['height']); ?>" 
                                        placeholder="Height" 
                                        class="small-text" />
                                </div>
                                <?php endforeach; ?>
                                <button type="button" class="button add-image-size">Add Size</button>
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button('Save Settings'); ?>
            </form>

            <div class="aag-content-generator">
                <h3>Generate Article</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('aag_generate_article', 'aag_nonce'); ?>
                    <input type="hidden" name="aag_generate_article" value="1">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Target Keyword</th>
                            <td>
                                <input type="text" name="keyword" class="regular-text" required 
                                    placeholder="Enter your target keyword for SEO optimization" />
                                <p class="description">This keyword will be used to optimize your title and content for SEO</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Topic</th>
                            <td>
                                <textarea name="topic" rows="4" class="large-text" required 
                                    placeholder="Enter your article topic here"></textarea>
                                <p class="description">Describe what you want the article to be about</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Language</th>
                            <td>
                                <select name="language" class="regular-text">
                                    <optgroup label="English">
                                        <option value="en-US">English (US)</option>
                                        <option value="en-GB">English (UK)</option>
                                    </optgroup>
                                    <optgroup label="Chinese">
                                        <option value="zh-TW">Chinese (Traditional)</option>
                                        <option value="zh-CN">Chinese (Simplified)</option>
                                    </optgroup>
                                </select>
                                <p class="description">Select the language for your article</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Writing Style</th>
                            <td>
                                <select name="style" class="regular-text">
                                    <option value="informative">Informative</option>
                                    <option value="conversational">Conversational</option>
                                    <option value="professional">Professional</option>
                                    <option value="casual">Casual</option>
                                    <option value="academic">Academic</option>
                                    <option value="persuasive">Persuasive</option>
                                </select>
                                <p class="description">Select the writing style for your article</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">AI Provider</th>
                            <td>
                                <select name="provider" class="regular-text">
                                    <option value="default">Default (<?php echo esc_html(get_option('aag_default_provider', 'deepseek')); ?>)</option>
                                    <option value="deepseek">Deepseek</option>
                                    <option value="perplexity">Perplexity</option>
                                    <option value="grok">Grok</option>
                                    <option value="openrouter">OpenRouter</option>
                                </select>
                                <p class="description">Select which AI provider to use for generation</p>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="submit" id="submit" class="button button-primary" value="Generate Article">
                    </p>
                </form>
            </div>

            <div class="aag-section">
                <h3>Upload Markdown Files</h3>
                <form method="post" action="" enctype="multipart/form-data">
                    <?php wp_nonce_field('upload_markdown_nonce', 'markdown_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Select Files</th>
                            <td>
                                <input type="file" name="markdown_files[]" accept=".md" multiple required />
                                <p class="description">Select one or more markdown files to upload</p>
                            </td>
                        </tr>
                    </table>
                    <?php submit_button('Upload Files', 'primary', 'upload_markdown'); ?>
                </form>
            </div>

            <div class="aag-footer">
                <p class="aag-footer-text">
                    Developed by <a href="https://www.wdigitalagency.com" target="_blank">WD Automation</a> | Version 1.0
                </p>
                <div class="aag-footer-links">
                    <a href="" target="_blank">Documentation</a> |
                    <a href="" target="_blank">Support</a> |
                    <a href="" target="_blank">Privacy Policy</a>
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_article-generator' !== $hook) {
            return;
        }

        wp_enqueue_style('aag-admin-styles', AAG_PLUGIN_URL . 'assets/css/admin.css');
        
        // Enqueue Chart.js for security charts
        wp_enqueue_script('aag-chartjs', 
            'https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js', 
            array(), 
            '3.9.1', 
            true);
            
        wp_enqueue_script('aag-admin-script', 
            AAG_PLUGIN_URL . 'assets/js/admin.js', 
            array('jquery', 'aag-chartjs'), 
            '1.0', 
            true);
            
        // Add security chart data if security logger exists
        $chart_data = array(
            'labels' => array('Last 24h', 'High Severity', 'Failed Attempts'),
            'datasets' => array(
                array(
                    'label' => 'Security Events',
                    'data' => array(0, 0, 0),
                    'backgroundColor' => array(
                        'rgba(54, 162, 235, 0.2)',
                        'rgba(255, 99, 132, 0.2)',
                        'rgba(255, 206, 86, 0.2)'
                    ),
                    'borderColor' => array(
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 99, 132, 1)',
                        'rgba(255, 206, 86, 1)'
                    ),
                    'borderWidth' => 1
                )
            )
        );
        
        if (isset($this->security_logger)) {
            $security_stats = $this->security_logger->get_security_stats();
            $chart_data['datasets'][0]['data'] = array(
                $security_stats['last_24h'],
                $security_stats['high_severity'],
                $security_stats['failed_attempts']
            );
        }
        
        wp_localize_script('aag-admin-script', 'aagAdmin', array(
            'nonce' => wp_create_nonce('aag_admin_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php'),
            'securityChartData' => $chart_data
        ));
    }

    /**
     * AJAX handler for validating API keys
     */
    public function ajax_validate_api_key() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $key = isset($_POST['key']) ? sanitize_text_field($_POST['key']) : '';
        $provider = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : '';
        
        if (empty($key) || empty($provider)) {
            wp_send_json_error('Missing parameters');
        }
        
        // Basic format validation only - we don't actually test the key against the API
        $is_valid = strlen($key) >= 20;
        
        if ($is_valid) {
            $this->security_logger->log_event('api_key_validated', "API key validated for $provider", 'low');
            wp_send_json_success('Valid key format');
        } else {
            $this->security_logger->log_event('api_key_invalid', "Invalid API key format for $provider", 'medium');
            wp_send_json_error('Invalid key format');
        }
    }
    
    /**
     * AJAX handler for rotating encryption key
     */
    public function ajax_rotate_encryption_key() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        // Delete the old key and create a new one
        delete_option('aag_encryption_key');
        $this->encryption_handler = new AAG_Encryption_Handler();
        
        // Clear all encrypted data
        delete_option('aag_deepseek_api_key');
        delete_option('aag_perplexity_api_key');
        delete_option('aag_grok_api_key');
        delete_option('aag_openrouter_api_key');
        delete_option('aag_vision_api_key');
        
        $this->security_logger->log_event('encryption_key_rotated', 'Encryption key rotated', 'high');
        
        wp_send_json_success('Encryption key rotated successfully');
    }
    
    /**
     * AJAX handler for clearing API keys
     */
    public function ajax_clear_api_keys() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        delete_option('aag_deepseek_api_key');
        delete_option('aag_perplexity_api_key');
        delete_option('aag_grok_api_key');
        delete_option('aag_openrouter_api_key');
        delete_option('aag_vision_api_key');
        
        $this->security_logger->log_event('api_keys_cleared', 'All API keys cleared', 'high');
        
        wp_send_json_success('All API keys cleared successfully');
    }
    
    /**
     * AJAX handler for exporting security logs
     */
    public function ajax_export_security_logs() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $logs = $this->security_logger->get_recent_logs(1000);
        $csv = "ID,Event Type,Description,User ID,IP Address,User Agent,Created At,Severity\n";
        
        foreach ($logs as $log) {
            $csv .= '"' . $log->id . '","' . 
                    esc_csv($log->event_type) . '","' . 
                    esc_csv($log->event_description) . '","' . 
                    esc_csv($log->user_id) . '","' . 
                    esc_csv($log->ip_address) . '","' . 
                    esc_csv($log->user_agent) . '","' . 
                    esc_csv($log->created_at) . '","' . 
                    esc_csv($log->severity) . "\"\n";
        }
        
        $this->security_logger->log_event('security_logs_exported', 'Security logs exported', 'medium');
        
        wp_send_json_success($csv);
    }
    
    /**
     * Helper function to encrypt API keys
     */
    public function encrypt_api_key($key) {
        if (empty($key)) {
            return '';
        }
        
        // If encryption handler is not initialized, just return the key
        if (!isset($this->encryption_handler)) {
            return $key;
        }
        
        return $this->encryption_handler->encrypt($key);
    }
    
    /**
     * Helper function to decrypt API keys
     */
    public function get_decrypted_key($option_name) {
        $encrypted_key = get_option($option_name);
        if (empty($encrypted_key)) {
            return '';
        }
        
        // If encryption handler is not initialized, just return the key
        if (!isset($this->encryption_handler)) {
            return $encrypted_key;
        }
        
        return $this->encryption_handler->decrypt($encrypted_key);
    }
}