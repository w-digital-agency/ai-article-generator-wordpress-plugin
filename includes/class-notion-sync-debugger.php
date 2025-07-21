<?php

class AAG_Notion_Sync_Debugger {
    private $notion_sync;
    private $debug_log = [];
    
    public function __construct() {
        if (class_exists('AAG_Notion_Sync')) {
            $this->notion_sync = new AAG_Notion_Sync();
        }
        
        add_action('wp_ajax_aag_debug_notion_sync', array($this, 'ajax_debug_notion_sync'));
        add_action('wp_ajax_aag_test_notion_block_conversion', array($this, 'ajax_test_block_conversion'));
    }
    
    /**
     * Debug the entire sync process
     */
    public function debug_sync_process() {
        $this->log('Starting Notion sync debug process...');
        
        // Check if Notion sync is enabled
        if (!get_option('aag_notion_sync_enabled')) {
            $this->log('ERROR: Notion sync is not enabled in settings', 'error');
            return $this->get_debug_results();
        }
        
        // Check credentials
        $notion_token = $this->get_notion_token();
        $database_id = get_option('aag_notion_database_id');
        
        if (empty($notion_token)) {
            $this->log('ERROR: Notion token is not configured', 'error');
            return $this->get_debug_results();
        }
        
        if (empty($database_id)) {
            $this->log('ERROR: Database ID is not configured', 'error');
            return $this->get_debug_results();
        }
        
        $this->log("Using database ID: {$database_id}");
        
        // Test connection
        $connection_test = $this->test_notion_connection();
        if (is_wp_error($connection_test)) {
            $this->log('ERROR: Connection test failed - ' . $connection_test->get_error_message(), 'error');
            return $this->get_debug_results();
        }
        
        $this->log('✅ Connection test passed');
        
        // Test database access
        $pages = $this->get_notion_pages();
        if (is_wp_error($pages)) {
            $this->log('ERROR: Failed to fetch pages - ' . $pages->get_error_message(), 'error');
            return $this->get_debug_results();
        }
        
        $page_count = count($pages);
        $this->log("✅ Successfully fetched {$page_count} pages from Notion");
        
        if ($page_count === 0) {
            $this->log('WARNING: No pages found with "Published" status', 'warning');
            return $this->get_debug_results();
        }
        
        // Test first page content
        $first_page = $pages[0];
        $page_id = $first_page['id'];
        $this->log("Testing page content for: {$page_id}");
        
        $page_content = $this->get_notion_page_content($page_id);
        if (is_wp_error($page_content)) {
            $this->log('ERROR: Failed to fetch page content - ' . $page_content->get_error_message(), 'error');
            return $this->get_debug_results();
        }
        
        $block_count = count($page_content);
        $this->log("✅ Successfully fetched {$block_count} blocks from page");
        
        // Test block conversion
        if (!empty($page_content)) {
            $this->test_block_conversion($page_content);
        }
        
        // Test sync table
        $this->test_sync_table();
        
        $this->log('🎉 Debug process completed successfully!');
        return $this->get_debug_results();
    }
    
    /**
     * Test block conversion functionality
     */
    private function test_block_conversion($blocks) {
        $this->log('Testing block conversion...');
        
        $supported_types = [];
        $unsupported_types = [];
        
        foreach ($blocks as $block) {
            $type = $block['type'];
            
            if (in_array($type, ['paragraph', 'heading_1', 'heading_2', 'heading_3', 'bulleted_list_item', 'numbered_list_item', 'quote', 'code', 'image', 'video', 'embed', 'bookmark', 'divider'])) {
                $supported_types[] = $type;
            } else {
                $unsupported_types[] = $type;
            }
        }
        
        $supported_count = count(array_unique($supported_types));
        $unsupported_count = count(array_unique($unsupported_types));
        
        $this->log("✅ Supported block types found: {$supported_count}");
        if (!empty($supported_types)) {
            $this->log('Supported types: ' . implode(', ', array_unique($supported_types)));
        }
        
        if ($unsupported_count > 0) {
            $this->log("⚠️ Unsupported block types found: {$unsupported_count}", 'warning');
            $this->log('Unsupported types: ' . implode(', ', array_unique($unsupported_types)));
        }
    }
    
    /**
     * Test sync table functionality
     */
    private function test_sync_table() {
        global $wpdb;
        
        $this->log('Testing sync table...');
        
        $table_name = $wpdb->prefix . 'aag_notion_sync';
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
        
        if (!$table_exists) {
            $this->log('⚠️ Sync table does not exist, creating...', 'warning');
            if (isset($this->notion_sync)) {
                $this->notion_sync->create_sync_table();
                $this->log('✅ Sync table created');
            } else {
                $this->log('ERROR: Cannot create sync table - Notion sync class not available', 'error');
            }
        } else {
            $this->log('✅ Sync table exists');
            
            // Get table stats
            $total_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $synced_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE sync_status = 'synced'");
            $error_records = $wpdb->get_var("SELECT COUNT(*) FROM $table_name WHERE sync_status = 'error'");
            
            $this->log("Table stats - Total: {$total_records}, Synced: {$synced_records}, Errors: {$error_records}");
        }
    }
    
    /**
     * Get Notion token (decrypted)
     */
    private function get_notion_token() {
        if (class_exists('AAG_Encryption_Handler')) {
            $encryption_handler = new AAG_Encryption_Handler();
            $encrypted_token = get_option('aag_notion_token');
            return $encryption_handler->decrypt($encrypted_token);
        }
        
        return get_option('aag_notion_token');
    }
    
    /**
     * Test Notion connection
     */
    private function test_notion_connection() {
        $notion_token = $this->get_notion_token();
        
        // Validate token format first
        if (empty($notion_token)) {
            $this->log('ERROR: No Notion token found in settings', 'error');
            return new WP_Error('missing_token', 'No Notion token configured');
        }
        
        if (!preg_match('/^secret_[a-zA-Z0-9]{43}$/', $notion_token)) {
            $this->log('ERROR: Invalid token format. Expected: secret_[43 characters]', 'error');
            $this->log('Token length: ' . strlen($notion_token), 'error');
            $this->log('Token starts with: ' . substr($notion_token, 0, 7), 'error');
            return new WP_Error('invalid_token_format', 'Invalid token format');
        }
        
        $this->log('Token format validation passed');
        
        $response = wp_remote_get('https://api.notion.com/v1/users/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $notion_token,
                'Notion-Version' => '2022-06-28',
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            $this->log('ERROR: WordPress HTTP error - ' . $response->get_error_message(), 'error');
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        $this->log("API Response Code: {$response_code}");
        $this->log("API Response Body: " . substr($response_body, 0, 200) . '...');
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            
            $error_message = 'Failed to connect to Notion';
            if (isset($error_data['message'])) {
                $error_message = $error_data['message'];
                $this->log('ERROR: Notion API error - ' . $error_message, 'error');
            } elseif ($response_code === 401) {
                $error_message = 'Unauthorized - Invalid token or insufficient permissions';
                $this->log('ERROR: 401 Unauthorized - Check your token', 'error');
            } elseif ($response_code === 403) {
                $error_message = 'Forbidden - Integration may not have required permissions';
                $this->log('ERROR: 403 Forbidden - Check integration permissions', 'error');
            }
            
            return new WP_Error('notion_api_error', $error_message
            );
        }

        $user_data = json_decode($response_body, true);
        if (isset($user_data['name'])) {
            $this->log('✅ Connected successfully as: ' . $user_data['name']);
        }
        
        return true;
    }
    
    /**
     * Get Notion pages
     */
    private function get_notion_pages() {
        $notion_token = $this->get_notion_token();
        $database_id = get_option('aag_notion_database_id');
        
        $response = wp_remote_post("https://api.notion.com/v1/databases/{$database_id}/query", [
            'headers' => [
                'Authorization' => 'Bearer ' . $notion_token,
                'Notion-Version' => '2022-06-28',
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'filter' => [
                    'property' => 'Status',
                    'select' => [
                        'equals' => 'Published'
                    ]
                ],
                'page_size' => 5 // Limit for testing
            ]),
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            return new WP_Error('notion_api_error', 
                isset($error_data['message']) ? $error_data['message'] : 'Failed to fetch pages'
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['results']) ? $data['results'] : [];
    }
    
    /**
     * Get Notion page content
     */
    private function get_notion_page_content($page_id) {
        $notion_token = $this->get_notion_token();
        
        $response = wp_remote_get("https://api.notion.com/v1/blocks/{$page_id}/children", [
            'headers' => [
                'Authorization' => 'Bearer ' . $notion_token,
                'Notion-Version' => '2022-06-28',
                'Content-Type' => 'application/json'
            ],
            'timeout' => 60
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            return new WP_Error('notion_api_error', 
                isset($error_data['message']) ? $error_data['message'] : 'Failed to fetch page content'
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['results']) ? $data['results'] : [];
    }
    
    /**
     * Log debug message
     */
    private function log($message, $level = 'info') {
        $this->debug_log[] = [
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message
        ];
        
        // Also log to WordPress debug log if enabled
        if (WP_DEBUG_LOG) {
            error_log("[AAG Notion Debug] {$message}");
        }
    }
    
    /**
     * Get debug results
     */
    private function get_debug_results() {
        return [
            'success' => !$this->has_errors(),
            'logs' => $this->debug_log,
            'summary' => $this->get_summary()
        ];
    }
    
    /**
     * Check if there are any errors in the log
     */
    private function has_errors() {
        foreach ($this->debug_log as $log) {
            if ($log['level'] === 'error') {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Get summary of debug results
     */
    private function get_summary() {
        $total = count($this->debug_log);
        $errors = 0;
        $warnings = 0;
        
        foreach ($this->debug_log as $log) {
            if ($log['level'] === 'error') $errors++;
            if ($log['level'] === 'warning') $warnings++;
        }
        
        return [
            'total_logs' => $total,
            'errors' => $errors,
            'warnings' => $warnings,
            'status' => $errors > 0 ? 'failed' : ($warnings > 0 ? 'warning' : 'success')
        ];
    }
    
    /**
     * AJAX handler for debug sync
     */
    public function ajax_debug_notion_sync() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $results = $this->debug_sync_process();
        
        if ($results['success']) {
            wp_send_json_success($results);
        } else {
            wp_send_json_error($results);
        }
    }
    
    /**
     * AJAX handler for testing block conversion
     */
    public function ajax_test_block_conversion() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $sample_blocks = [
            [
                'type' => 'paragraph',
                'paragraph' => [
                    'rich_text' => [
                        [
                            'text' => ['content' => 'This is a sample paragraph with '],
                            'annotations' => ['bold' => false, 'italic' => false]
                        ],
                        [
                            'text' => ['content' => 'bold text'],
                            'annotations' => ['bold' => true, 'italic' => false]
                        ],
                        [
                            'text' => ['content' => ' and '],
                            'annotations' => ['bold' => false, 'italic' => false]
                        ],
                        [
                            'text' => ['content' => 'italic text'],
                            'annotations' => ['bold' => false, 'italic' => true]
                        ]
                    ]
                ]
            ],
            [
                'type' => 'heading_2',
                'heading_2' => [
                    'rich_text' => [
                        [
                            'text' => ['content' => 'Sample Heading'],
                            'annotations' => ['bold' => false, 'italic' => false]
                        ]
                    ]
                ]
            ]
        ];
        
        $converted_blocks = [];
        foreach ($sample_blocks as $block) {
            $converted_blocks[] = [
                'original' => $block,
                'converted' => $this->convert_sample_block($block)
            ];
        }
        
        wp_send_json_success([
            'blocks' => $converted_blocks,
            'message' => 'Block conversion test completed'
        ]);
    }
    
    /**
     * Convert sample block for testing
     */
    private function convert_sample_block($block) {
        // This is a simplified version for testing
        $type = $block['type'];
        
        switch ($type) {
            case 'paragraph':
                $text = $this->extract_rich_text($block['paragraph']['rich_text']);
                return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->";
                
            case 'heading_2':
                $text = $this->extract_rich_text($block['heading_2']['rich_text']);
                return "<!-- wp:heading -->\n<h2>{$text}</h2>\n<!-- /wp:heading -->";
                
            default:
                return "<!-- Unsupported block type: {$type} -->";
        }
    }
    
    /**
     * Extract rich text (simplified for testing)
     */
    private function extract_rich_text($rich_text_array) {
        $text = '';
        
        foreach ($rich_text_array as $text_obj) {
            $content = $text_obj['text']['content'];
            
            if (isset($text_obj['annotations'])) {
                $annotations = $text_obj['annotations'];
                
                if ($annotations['bold']) {
                    $content = "<strong>{$content}</strong>";
                }
                if ($annotations['italic']) {
                    $content = "<em>{$content}</em>";
                }
            }
            
            $text .= $content;
        }
        
        return $text;
    }
}

// Initialize the debugger
new AAG_Notion_Sync_Debugger();