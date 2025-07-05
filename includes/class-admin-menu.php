<?php
class AAG_Admin_Menu {
    private $encryption_handler;
    private $security_logger;
    private $notion_sync;

    public function __construct() {
        // Initialize encryption handler and security logger if they exist
        if (class_exists('AAG_Encryption_Handler')) {
            $this->encryption_handler = new AAG_Encryption_Handler();
        }
        
        if (class_exists('AAG_Security_Logger')) {
            $this->security_logger = new AAG_Security_Logger();
        }
        
        if (class_exists('AAG_Notion_Sync')) {
            $this->notion_sync = new AAG_Notion_Sync();
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
            'Article Generator Pro', 
            'Article Generator Pro', 
            'manage_options', 
            'article-generator', 
            array($this, 'create_admin_page'),
            AAG_PLUGIN_URL . 'assets/images/icon.svg'
        );
    }

    public function register_settings() {
        // Non-sensitive settings
        register_setting('article_generator_options', 'aag_default_provider');
        register_setting('article_generator_options', 'aag_openrouter_model');
        register_setting('article_generator_options', 'aag_openai_model');
        register_setting('article_generator_options', 'aag_request_timeout');
        register_setting('article_generator_options', 'aag_image_quality');
        register_setting('article_generator_options', 'aag_max_image_width');
        register_setting('article_generator_options', 'aag_image_sizes');
        register_setting('article_generator_options', 'aag_notion_database_id');
        register_setting('article_generator_options', 'aag_notion_sync_enabled');

        // Sensitive settings with encryption
        register_setting('article_generator_options', 'aag_openrouter_api_key', 
            array($this, 'encrypt_api_key'));
        register_setting('article_generator_options', 'aag_openai_api_key', 
            array($this, 'encrypt_api_key'));
        register_setting('article_generator_options', 'aag_vision_api_key', 
            array($this, 'encrypt_api_key'));
        register_setting('article_generator_options', 'aag_notion_token', 
            array($this, 'encrypt_api_key'));
    }

    public function create_admin_page() {
        ?>
        <div class="wrap">
            <div style="display: flex; align-items: center; margin-bottom: 20px;">
                <img src="<?php echo AAG_PLUGIN_URL . 'assets/images/icon.svg'; ?>" 
                     alt="Auto Article Generator Pro" 
                     style="width: 48px; height: 48px; margin-right: 15px;">
                <h1 style="margin: 0;">Article Generator Pro Settings</h1>
            </div>
            
            <div class="aag-tabs">
                <nav class="nav-tab-wrapper">
                    <a href="#ai-settings" class="nav-tab nav-tab-active">AI Settings</a>
                    <a href="#notion-sync" class="nav-tab">Notion Sync</a>
                    <a href="#image-settings" class="nav-tab">Image Settings</a>
                    <a href="#generate-content" class="nav-tab">Generate Content</a>
                    <a href="#markdown-upload" class="nav-tab">Markdown Upload</a>
                </nav>
                
                <div class="aag-tab-content">
                    <!-- AI Settings Tab -->
                    <div id="ai-settings" class="aag-tab-pane active">
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
                                                <option value="openrouter" <?php selected(get_option('aag_default_provider'), 'openrouter'); ?>>OpenRouter (Recommended)</option>
                                                <option value="openai" <?php selected(get_option('aag_default_provider'), 'openai'); ?>>OpenAI</option>
                                            </select>
                                            <p class="description">OpenRouter provides access to multiple AI models with competitive pricing. Perfect for beginners!</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">API Request Timeout</th>
                                        <td>
                                            <input type="number" name="aag_request_timeout" 
                                                value="<?php echo esc_attr(get_option('aag_request_timeout', 180)); ?>" 
                                                class="small-text" min="30" max="300" step="30" />
                                            <p class="description">Timeout in seconds for API requests (30-300 seconds).</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div class="aag-section">
                                <h3>OpenRouter Settings</h3>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">OpenRouter API Key</th>
                                        <td>
                                            <div class="aag-api-key-input">
                                                <input type="password" name="aag_openrouter_api_key" 
                                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_openrouter_api_key')); ?>" 
                                                    class="regular-text" />
                                                <button type="button" class="button toggle-password">Show</button>
                                            </div>
                                            <p class="description">
                                                Get your free API key at <a href="https://openrouter.ai/" target="_blank">OpenRouter.ai</a>. 
                                                New users get free credits to get started!
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">OpenRouter Model</th>
                                        <td>
                                            <select name="aag_openrouter_model" class="regular-text">
                                                <optgroup label="Recommended (Free Credits Available)">
                                                    <option value="meta-llama/llama-3.1-8b-instruct:free" <?php selected(get_option('aag_openrouter_model'), 'meta-llama/llama-3.1-8b-instruct:free'); ?>>Llama 3.1 8B (Free)</option>
                                                    <option value="microsoft/phi-3-mini-128k-instruct:free" <?php selected(get_option('aag_openrouter_model'), 'microsoft/phi-3-mini-128k-instruct:free'); ?>>Phi-3 Mini (Free)</option>
                                                </optgroup>
                                                <optgroup label="Premium Models">
                                                    <option value="openai/gpt-4o-mini" <?php selected(get_option('aag_openrouter_model'), 'openai/gpt-4o-mini'); ?>>GPT-4o Mini</option>
                                                    <option value="openai/gpt-4o" <?php selected(get_option('aag_openrouter_model'), 'openai/gpt-4o'); ?>>GPT-4o</option>
                                                    <option value="anthropic/claude-3.5-sonnet" <?php selected(get_option('aag_openrouter_model'), 'anthropic/claude-3.5-sonnet'); ?>>Claude 3.5 Sonnet</option>
                                                    <option value="meta-llama/llama-3.1-70b-instruct" <?php selected(get_option('aag_openrouter_model'), 'meta-llama/llama-3.1-70b-instruct'); ?>>Llama 3.1 70B</option>
                                                </optgroup>
                                            </select>
                                            <p class="description">
                                                Start with free models, then upgrade to premium models as needed. 
                                                <a href="https://openrouter.ai/models" target="_blank">View all available models</a>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <div class="aag-section">
                                <h3>OpenAI Settings</h3>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">OpenAI API Key</th>
                                        <td>
                                            <div class="aag-api-key-input">
                                                <input type="password" name="aag_openai_api_key" 
                                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_openai_api_key')); ?>" 
                                                    class="regular-text" />
                                                <button type="button" class="button toggle-password">Show</button>
                                            </div>
                                            <p class="description">
                                                Get your API key at <a href="https://platform.openai.com/api-keys" target="_blank">OpenAI Platform</a>
                                            </p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">OpenAI Model</th>
                                        <td>
                                            <select name="aag_openai_model" class="regular-text">
                                                <option value="gpt-4o-mini" <?php selected(get_option('aag_openai_model'), 'gpt-4o-mini'); ?>>GPT-4o Mini (Recommended)</option>
                                                <option value="gpt-4o" <?php selected(get_option('aag_openai_model'), 'gpt-4o'); ?>>GPT-4o</option>
                                                <option value="gpt-4-turbo" <?php selected(get_option('aag_openai_model'), 'gpt-4-turbo'); ?>>GPT-4 Turbo</option>
                                                <option value="gpt-3.5-turbo" <?php selected(get_option('aag_openai_model'), 'gpt-3.5-turbo'); ?>>GPT-3.5 Turbo</option>
                                            </select>
                                            <p class="description">GPT-4o Mini offers the best balance of quality and cost.</p>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <?php submit_button('Save AI Settings'); ?>
                        </form>
                    </div>

                    <!-- Notion Sync Tab -->
                    <div id="notion-sync" class="aag-tab-pane">
                        <div class="aag-section">
                            <h3>Notion Integration Setup</h3>
                            <p>Sync your Notion database pages directly to WordPress as blog posts. This feature supports rich content including images, videos, embeds, and more.</p>
                            
                            <div class="aag-notion-setup-guide">
                                <h4>Setup Instructions:</h4>
                                <ol>
                                    <li><strong>Create a Notion Integration:</strong>
                                        <ul>
                                            <li>Go to <a href="https://www.notion.so/my-integrations" target="_blank">Notion Integrations</a></li>
                                            <li>Click "New integration"</li>
                                            <li>Give it a name (e.g., "WordPress Sync")</li>
                                            <li>Copy the "Internal Integration Token"</li>
                                        </ul>
                                    </li>
                                    <li><strong>Share your database with the integration:</strong>
                                        <ul>
                                            <li>Open your Notion database</li>
                                            <li>Click "Share" → "Invite"</li>
                                            <li>Search for your integration name and invite it</li>
                                        </ul>
                                    </li>
                                    <li><strong>Required database properties:</strong>
                                        <ul>
                                            <li><code>Name</code> or <code>Title</code> (Title property)</li>
                                            <li><code>Status</code> (Select property with "Published" option)</li>
                                        </ul>
                                    </li>
                                </ol>
                            </div>

                            <form method="post" action="options.php">
                                <?php settings_fields('article_generator_options'); ?>
                                
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Enable Notion Sync</th>
                                        <td>
                                            <label>
                                                <input type="checkbox" name="aag_notion_sync_enabled" value="1" 
                                                    <?php checked(get_option('aag_notion_sync_enabled'), 1); ?> />
                                                Enable automatic synchronization from Notion
                                            </label>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Notion Integration Token</th>
                                        <td>
                                            <div class="aag-api-key-input">
                                                <input type="password" name="aag_notion_token" 
                                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_notion_token')); ?>" 
                                                    class="regular-text" placeholder="secret_..." />
                                                <button type="button" class="button toggle-password">Show</button>
                                                <button type="button" class="button" id="test-notion-connection">Test Connection</button>
                                            </div>
                                            <div class="aag-key-status"></div>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Notion Database ID</th>
                                        <td>
                                            <input type="text" name="aag_notion_database_id" 
                                                value="<?php echo esc_attr(get_option('aag_notion_database_id')); ?>" 
                                                class="regular-text" placeholder="32-character database ID" />
                                            <button type="button" class="button" id="get-notion-databases">Browse Databases</button>
                                            <p class="description">
                                                Copy the database ID from your Notion database URL. 
                                                It's the 32-character string after the last slash.
                                            </p>
                                            <div id="notion-databases-list" style="display:none; margin-top: 10px;"></div>
                                        </td>
                                    </tr>
                                </table>

                                <?php submit_button('Save Notion Settings'); ?>
                            </form>

                            <?php if (isset($this->notion_sync)): ?>
                            <div class="aag-notion-actions">
                                <h4>Sync Actions</h4>
                                <button type="button" class="button button-primary" id="sync-notion-now">Sync Now</button>
                                <p class="description">Manually trigger a sync from Notion. Automatic sync runs every hour.</p>
                                
                                <?php 
                                $sync_stats = $this->notion_sync->get_sync_stats();
                                if ($sync_stats['total_synced'] > 0): 
                                ?>
                                <div class="aag-sync-stats">
                                    <h4>Sync Statistics</h4>
                                    <ul>
                                        <li>Total synced posts: <?php echo $sync_stats['total_synced']; ?></li>
                                        <li>Last sync: <?php echo $sync_stats['last_sync'] ? date('Y-m-d H:i:s', strtotime($sync_stats['last_sync'])) : 'Never'; ?></li>
                                        <li>Pending sync: <?php echo $sync_stats['pending_sync']; ?></li>
                                        <li>Sync errors: <?php echo $sync_stats['sync_errors']; ?></li>
                                    </ul>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Image Settings Tab -->
                    <div id="image-settings" class="aag-tab-pane">
                        <form method="post" action="options.php">
                            <?php settings_fields('article_generator_options'); ?>
                            
                            <div class="aag-section">
                                <h3>Image Processing Settings</h3>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Image Quality</th>
                                        <td>
                                            <input type="number" name="aag_image_quality" 
                                                value="<?php echo esc_attr(get_option('aag_image_quality', 85)); ?>" 
                                                min="1" max="100" class="small-text" />
                                            <p class="description">JPEG/WebP compression quality (1-100). Higher values = better quality but larger files.</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Max Image Width</th>
                                        <td>
                                            <input type="number" name="aag_max_image_width" 
                                                value="<?php echo esc_attr(get_option('aag_max_image_width', 1200)); ?>" 
                                                min="100" class="small-text" />
                                            <p class="description">Maximum width for uploaded images (in pixels)</p>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th scope="row">Vision API Key (Optional)</th>
                                        <td>
                                            <div class="aag-api-key-input">
                                                <input type="password" name="aag_vision_api_key" 
                                                    value="<?php echo esc_attr($this->get_decrypted_key('aag_vision_api_key')); ?>" 
                                                    class="regular-text" />
                                                <button type="button" class="button toggle-password">Show</button>
                                            </div>
                                            <p class="description">
                                                Google Cloud Vision API key for automatic alt text generation. 
                                                <a href="https://cloud.google.com/vision/docs/setup" target="_blank">Get API key</a>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </div>

                            <?php submit_button('Save Image Settings'); ?>
                        </form>
                    </div>

                    <!-- Generate Content Tab -->
                    <div id="generate-content" class="aag-tab-pane">
                        <div class="aag-content-generator">
                            <h3>Generate Article with AI</h3>
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
                                                <option value="default">Default (<?php echo esc_html(get_option('aag_default_provider', 'openrouter')); ?>)</option>
                                                <option value="openrouter">OpenRouter</option>
                                                <option value="openai">OpenAI</option>
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
                    </div>

                    <!-- Markdown Upload Tab -->
                    <div id="markdown-upload" class="aag-tab-pane">
                        <div class="aag-section">
                            <h3>Upload Markdown Files (Backup Method)</h3>
                            <p>Upload markdown files directly to create WordPress posts. This is a backup method - we recommend using Notion sync for better content management.</p>
                            
                            <form method="post" action="" enctype="multipart/form-data">
                                <?php wp_nonce_field('upload_markdown_nonce', 'markdown_nonce'); ?>
                                <table class="form-table">
                                    <tr>
                                        <th scope="row">Select Files</th>
                                        <td>
                                            <input type="file" name="markdown_files[]" accept=".md" multiple required />
                                            <p class="description">Select one or more markdown files to upload (max 5MB each)</p>
                                        </td>
                                    </tr>
                                </table>
                                <?php submit_button('Upload Files', 'primary', 'upload_markdown'); ?>
                            </form>
                        </div>
                    </div>
                </div>
            </div>

            <div class="aag-footer">
                <p class="aag-footer-text">
                    Developed by <a href="https://www.wdigitalagency.com" target="_blank">WD Automation</a> | Version 2.0
                </p>
                <div class="aag-footer-links">
                    <a href="#" target="_blank">Documentation</a> |
                    <a href="#" target="_blank">Support</a> |
                    <a href="#" target="_blank">Privacy Policy</a>
                </div>
            </div>
        </div>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_article-generator' !== $hook) {
            return;
        }

        wp_enqueue_style('aag-admin-styles', AAG_PLUGIN_URL . 'assets/css/admin.css', [], AAG_VERSION);
        
        wp_enqueue_script('aag-admin-script', 
            AAG_PLUGIN_URL . 'assets/js/admin.js', 
            array('jquery'), 
            AAG_VERSION, 
            true);
            
        wp_localize_script('aag-admin-script', 'aagAdmin', array(
            'nonce' => wp_create_nonce('aag_admin_nonce'),
            'ajaxurl' => admin_url('admin-ajax.php')
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
        
        // Basic format validation only
        $is_valid = strlen($key) >= 20;
        
        if ($is_valid) {
            if (isset($this->security_logger)) {
                $this->security_logger->log_event('api_key_validated', "API key validated for $provider", 'low');
            }
            wp_send_json_success('Valid key format');
        } else {
            if (isset($this->security_logger)) {
                $this->security_logger->log_event('api_key_invalid', "Invalid API key format for $provider", 'medium');
            }
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
        delete_option('aag_openrouter_api_key');
        delete_option('aag_openai_api_key');
        delete_option('aag_vision_api_key');
        delete_option('aag_notion_token');
        
        if (isset($this->security_logger)) {
            $this->security_logger->log_event('encryption_key_rotated', 'Encryption key rotated', 'high');
        }
        
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
        
        delete_option('aag_openrouter_api_key');
        delete_option('aag_openai_api_key');
        delete_option('aag_vision_api_key');
        delete_option('aag_notion_token');
        
        if (isset($this->security_logger)) {
            $this->security_logger->log_event('api_keys_cleared', 'All API keys cleared', 'high');
        }
        
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
        
        if (!isset($this->security_logger)) {
            wp_send_json_error('Security logger not available');
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