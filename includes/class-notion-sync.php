<?php

class AAG_Notion_Sync {
    private $notion_token;
    private $database_id;
    private $sync_table;
    private $encryption_handler;
    private $security_logger;
    private $image_handler;

    public function __construct() {
        global $wpdb;
        $this->sync_table = $wpdb->prefix . 'aag_notion_sync';
        
        if (class_exists('AAG_Encryption_Handler')) {
            $this->encryption_handler = new AAG_Encryption_Handler();
            $this->notion_token = $this->get_decrypted_key('aag_notion_token');
            $this->database_id = get_option('aag_notion_database_id');
        }
        
        if (class_exists('AAG_Security_Logger')) {
            $this->security_logger = new AAG_Security_Logger();
        }
        
        if (class_exists('AAG_Image_Handler')) {
            $this->image_handler = new AAG_Image_Handler();
        }
        
        add_action('wp_ajax_aag_test_notion_connection', array($this, 'ajax_test_notion_connection'));
        add_action('wp_ajax_aag_sync_notion_now', array($this, 'ajax_sync_notion_now'));
        add_action('wp_ajax_aag_get_notion_databases', array($this, 'ajax_get_notion_databases'));
    }

    public function create_sync_table() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $this->sync_table (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            notion_page_id varchar(255) NOT NULL,
            wordpress_post_id bigint(20) NOT NULL,
            last_synced datetime NOT NULL,
            notion_last_edited datetime NOT NULL,
            sync_status varchar(50) NOT NULL DEFAULT 'synced',
            sync_direction varchar(20) NOT NULL DEFAULT 'notion_to_wp',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY notion_page_id (notion_page_id),
            KEY wordpress_post_id (wordpress_post_id)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    private function get_decrypted_key($option_name) {
        if (!isset($this->encryption_handler)) {
            return get_option($option_name);
        }
        
        $encrypted_key = get_option($option_name);
        if (empty($encrypted_key)) {
            return '';
        }
        
        return $this->encryption_handler->decrypt($encrypted_key);
    }

    public function test_notion_connection() {
        if (empty($this->notion_token)) {
            return new WP_Error('missing_token', 'Notion integration token is required');
        }

        // Log the token format for debugging (without exposing the actual token)
        $token_length = strlen($this->notion_token);
        $token_prefix = substr($this->notion_token, 0, 7);
        error_log("AAG Notion Debug: Token length: {$token_length}, starts with: {$token_prefix}");

        $response = wp_remote_get('https://api.notion.com/v1/users/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->notion_token,
                'Notion-Version' => '2022-06-28',
                'Content-Type' => 'application/json'
            ],
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            error_log('AAG Notion Debug: WP Error - ' . $response->get_error_message());
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        error_log("AAG Notion Debug: Response code: {$response_code}");
        error_log("AAG Notion Debug: Response body: " . substr($response_body, 0, 500));
        
        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            
            // Provide more specific error messages
            $error_message = 'Failed to connect to Notion';
            if (isset($error_data['message'])) {
                $error_message = $error_data['message'];
            } elseif ($response_code === 401) {
                $error_message = 'Invalid Notion token. Please check your integration token and make sure it starts with "secret_"';
            } elseif ($response_code === 403) {
                $error_message = 'Access denied. Make sure your integration has the correct permissions.';
            } elseif ($response_code === 429) {
                $error_message = 'Rate limit exceeded. Please wait a moment and try again.';
            }
            
            return new WP_Error('notion_api_error', $error_message
            );
        }

        return true;
    }

    public function get_notion_databases() {
        if (empty($this->notion_token)) {
            return new WP_Error('missing_token', 'Notion integration token is required');
        }

        $response = wp_remote_post('https://api.notion.com/v1/search', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->notion_token,
                'Notion-Version' => '2022-06-28',
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'filter' => [
                    'property' => 'object',
                    'value' => 'database'
                ]
            ]),
            'timeout' => 30
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            $body = wp_remote_retrieve_body($response);
            $error_data = json_decode($body, true);
            return new WP_Error('notion_api_error', 
                isset($error_data['message']) ? $error_data['message'] : 'Failed to fetch databases'
            );
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return isset($data['results']) ? $data['results'] : [];
    }

    public function sync_from_notion() {
        if (empty($this->notion_token) || empty($this->database_id)) {
            return new WP_Error('missing_config', 'Notion token and database ID are required');
        }

        // Get pages from Notion database
        $pages = $this->get_notion_pages();
        if (is_wp_error($pages)) {
            return $pages;
        }

        $synced_count = 0;
        $errors = [];

        foreach ($pages as $page) {
            $result = $this->sync_notion_page($page);
            if (is_wp_error($result)) {
                $errors[] = $result->get_error_message();
            } else {
                $synced_count++;
            }
        }

        if (isset($this->security_logger)) {
            $this->security_logger->log_event(
                'notion_sync_completed',
                sprintf('Synced %d pages from Notion. Errors: %d', $synced_count, count($errors)),
                'info'
            );
        }

        return [
            'synced' => $synced_count,
            'errors' => $errors
        ];
    }

    private function get_notion_pages() {
        $response = wp_remote_post("https://api.notion.com/v1/databases/{$this->database_id}/query", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->notion_token,
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
                'sorts' => [
                    [
                        'property' => 'Last edited time',
                        'direction' => 'descending'
                    ]
                ]
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

    private function sync_notion_page($page) {
        global $wpdb;

        $notion_page_id = $page['id'];
        $last_edited = $page['last_edited_time'];

        // Check if page already exists in sync table
        $sync_record = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $this->sync_table WHERE notion_page_id = %s",
            $notion_page_id
        ));

        // Skip if already synced and not modified
        if ($sync_record && $sync_record->notion_last_edited >= $last_edited) {
            return true;
        }

        // Get page content from Notion
        $page_content = $this->get_notion_page_content($notion_page_id);
        if (is_wp_error($page_content)) {
            return $page_content;
        }

        // Extract title and content
        $title = $this->extract_page_title($page);
        $content = $this->convert_notion_blocks_to_html($page_content);

        // Create or update WordPress post
        $post_data = [
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => get_current_user_id()
        ];

        if ($sync_record && $sync_record->wordpress_post_id) {
            // Update existing post
            $post_data['ID'] = $sync_record->wordpress_post_id;
            $post_id = wp_update_post($post_data);
        } else {
            // Create new post
            $post_id = wp_insert_post($post_data);
        }

        if (is_wp_error($post_id) || !$post_id) {
            return new WP_Error('post_creation_failed', 'Failed to create/update WordPress post');
        }

        // Update sync table
        if ($sync_record) {
            $wpdb->update(
                $this->sync_table,
                [
                    'last_synced' => current_time('mysql'),
                    'notion_last_edited' => $last_edited,
                    'sync_status' => 'synced'
                ],
                ['notion_page_id' => $notion_page_id]
            );
        } else {
            $wpdb->insert(
                $this->sync_table,
                [
                    'notion_page_id' => $notion_page_id,
                    'wordpress_post_id' => $post_id,
                    'last_synced' => current_time('mysql'),
                    'notion_last_edited' => $last_edited,
                    'sync_status' => 'synced',
                    'sync_direction' => 'notion_to_wp',
                    'created_at' => current_time('mysql')
                ]
            );
        }

        return $post_id;
    }

    private function get_notion_page_content($page_id) {
        $response = wp_remote_get("https://api.notion.com/v1/blocks/{$page_id}/children", [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->notion_token,
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

    private function extract_page_title($page) {
        if (isset($page['properties']['Name']['title'][0]['text']['content'])) {
            return $page['properties']['Name']['title'][0]['text']['content'];
        }
        
        if (isset($page['properties']['Title']['title'][0]['text']['content'])) {
            return $page['properties']['Title']['title'][0]['text']['content'];
        }

        return 'Untitled Post';
    }

    private function convert_notion_blocks_to_html($blocks) {
        $html = '';

        foreach ($blocks as $block) {
            $html .= $this->convert_notion_block_to_gutenberg($block);
        }

        return $html;
    }

    private function convert_notion_block_to_gutenberg($block) {
        $type = $block['type'];
        
        switch ($type) {
            case 'paragraph':
                $text = $this->extract_rich_text($block['paragraph']['rich_text']);
                if (empty(trim($text))) return '';
                return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n\n";

            case 'heading_1':
                $text = $this->extract_rich_text($block['heading_1']['rich_text']);
                return "<!-- wp:heading {\"level\":1} -->\n<h1>{$text}</h1>\n<!-- /wp:heading -->\n\n";

            case 'heading_2':
                $text = $this->extract_rich_text($block['heading_2']['rich_text']);
                return "<!-- wp:heading -->\n<h2>{$text}</h2>\n<!-- /wp:heading -->\n\n";

            case 'heading_3':
                $text = $this->extract_rich_text($block['heading_3']['rich_text']);
                return "<!-- wp:heading {\"level\":3} -->\n<h3>{$text}</h3>\n<!-- /wp:heading -->\n\n";

            case 'bulleted_list_item':
                $text = $this->extract_rich_text($block['bulleted_list_item']['rich_text']);
                return "<!-- wp:list-item -->\n<li>{$text}</li>\n<!-- /wp:list-item -->\n";

            case 'numbered_list_item':
                $text = $this->extract_rich_text($block['numbered_list_item']['rich_text']);
                return "<!-- wp:list-item -->\n<li>{$text}</li>\n<!-- /wp:list-item -->\n";

            case 'quote':
                $text = $this->extract_rich_text($block['quote']['rich_text']);
                return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>{$text}</p></blockquote>\n<!-- /wp:quote -->\n\n";

            case 'code':
                $text = $this->extract_rich_text($block['code']['rich_text']);
                $language = isset($block['code']['language']) ? $block['code']['language'] : '';
                return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>{$text}</code></pre>\n<!-- /wp:code -->\n\n";

            case 'image':
                return $this->convert_notion_image($block['image']);

            case 'video':
                return $this->convert_notion_video($block['video']);

            case 'embed':
                return $this->convert_notion_embed($block['embed']);

            case 'bookmark':
                return $this->convert_notion_bookmark($block['bookmark']);

            case 'divider':
                return "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n";

            default:
                return '';
        }
    }

    private function extract_rich_text($rich_text_array) {
        $text = '';
        
        foreach ($rich_text_array as $text_obj) {
            $content = $text_obj['text']['content'];
            
            // Apply formatting
            if (isset($text_obj['annotations'])) {
                $annotations = $text_obj['annotations'];
                
                if ($annotations['bold']) {
                    $content = "<strong>{$content}</strong>";
                }
                if ($annotations['italic']) {
                    $content = "<em>{$content}</em>";
                }
                if ($annotations['strikethrough']) {
                    $content = "<del>{$content}</del>";
                }
                if ($annotations['underline']) {
                    $content = "<u>{$content}</u>";
                }
                if ($annotations['code']) {
                    $content = "<code>{$content}</code>";
                }
            }
            
            // Handle links
            if (isset($text_obj['text']['link']['url'])) {
                $url = $text_obj['text']['link']['url'];
                $content = "<a href=\"{$url}\">{$content}</a>";
            }
            
            $text .= $content;
        }
        
        return $text;
    }

    private function convert_notion_image($image_block) {
        $image_url = '';
        $caption = '';

        if (isset($image_block['file']['url'])) {
            $image_url = $image_block['file']['url'];
        } elseif (isset($image_block['external']['url'])) {
            $image_url = $image_block['external']['url'];
        }

        if (isset($image_block['caption'])) {
            $caption = $this->extract_rich_text($image_block['caption']);
        }

        if (empty($image_url)) {
            return '';
        }

        // Process image through image handler
        if (isset($this->image_handler)) {
            $attachment_id = $this->image_handler->process_image($image_url);
            if ($attachment_id) {
                $image_html = wp_get_attachment_image($attachment_id, 'large');
                $block = "<!-- wp:image {\"id\":{$attachment_id},\"sizeSlug\":\"large\"} -->\n";
                $block .= "<figure class=\"wp-block-image size-large\">{$image_html}";
                if (!empty($caption)) {
                    $block .= "<figcaption>{$caption}</figcaption>";
                }
                $block .= "</figure>\n<!-- /wp:image -->\n\n";
                return $block;
            }
        }

        // Fallback to external image
        $block = "<!-- wp:image -->\n";
        $block .= "<figure class=\"wp-block-image\"><img src=\"{$image_url}\" alt=\"\"/>";
        if (!empty($caption)) {
            $block .= "<figcaption>{$caption}</figcaption>";
        }
        $block .= "</figure>\n<!-- /wp:image -->\n\n";

        return $block;
    }

    private function convert_notion_video($video_block) {
        $video_url = '';

        if (isset($video_block['file']['url'])) {
            $video_url = $video_block['file']['url'];
        } elseif (isset($video_block['external']['url'])) {
            $video_url = $video_block['external']['url'];
        }

        if (empty($video_url)) {
            return '';
        }

        // Check if it's a YouTube video
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([a-zA-Z0-9_-]+)/', $video_url, $matches)) {
            $video_id = $matches[1];
            return "<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v={$video_id}\",\"type\":\"video\",\"providerNameSlug\":\"youtube\"} -->\n" .
                   "<figure class=\"wp-block-embed is-type-video is-provider-youtube wp-block-embed-youtube wp-embed-aspect-16-9 wp-has-aspect-ratio\">" .
                   "<div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v={$video_id}\n</div>" .
                   "</figure>\n<!-- /wp:embed -->\n\n";
        }

        // Check if it's a Vimeo video
        if (preg_match('/vimeo\.com\/(\d+)/', $video_url, $matches)) {
            $video_id = $matches[1];
            return "<!-- wp:embed {\"url\":\"https://vimeo.com/{$video_id}\",\"type\":\"video\",\"providerNameSlug\":\"vimeo\"} -->\n" .
                   "<figure class=\"wp-block-embed is-type-video is-provider-vimeo wp-block-embed-vimeo\">" .
                   "<div class=\"wp-block-embed__wrapper\">\nhttps://vimeo.com/{$video_id}\n</div>" .
                   "</figure>\n<!-- /wp:embed -->\n\n";
        }

        // Generic video embed
        return "<!-- wp:embed {\"url\":\"$video_url\",\"type\":\"video\"} -->\n" .
               "<figure class=\"wp-block-embed is-type-video\">" .
               "<div class=\"wp-block-embed__wrapper\">\n{$video_url}\n</div>" .
               "</figure>\n<!-- /wp:embed -->\n\n";
    }

    private function convert_notion_embed($embed_block) {
        $embed_url = $embed_block['url'];
        
        return "<!-- wp:embed {\"url\":\"$embed_url\"} -->\n" .
               "<figure class=\"wp-block-embed\">" .
               "<div class=\"wp-block-embed__wrapper\">\n{$embed_url}\n</div>" .
               "</figure>\n<!-- /wp:embed -->\n\n";
    }

    private function convert_notion_bookmark($bookmark_block) {
        $url = $bookmark_block['url'];
        $caption = isset($bookmark_block['caption']) ? $this->extract_rich_text($bookmark_block['caption']) : '';
        
        $block = "<!-- wp:paragraph -->\n<p><a href=\"{$url}\">{$url}</a>";
        if (!empty($caption)) {
            $block .= " - {$caption}";
        }
        $block .= "</p>\n<!-- /wp:paragraph -->\n\n";
        
        return $block;
    }

    // AJAX handlers
    public function ajax_test_notion_connection() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $result = $this->test_notion_connection();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success('Connection successful');
    }

    public function ajax_sync_notion_now() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $result = $this->sync_from_notion();
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        wp_send_json_success($result);
    }

    public function ajax_get_notion_databases() {
        check_ajax_referer('aag_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        
        $databases = $this->get_notion_databases();
        
        if (is_wp_error($databases)) {
            wp_send_json_error($databases->get_error_message());
        }
        
        wp_send_json_success($databases);
    }

    public function get_sync_stats() {
        global $wpdb;
        
        $stats = [
            'total_synced' => 0,
            'last_sync' => null,
            'pending_sync' => 0,
            'sync_errors' => 0
        ];
        
        if ($wpdb->get_var("SHOW TABLES LIKE '$this->sync_table'") != $this->sync_table) {
            return $stats;
        }
        
        $stats['total_synced'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->sync_table WHERE sync_status = 'synced'");
        $stats['last_sync'] = $wpdb->get_var("SELECT MAX(last_synced) FROM $this->sync_table");
        $stats['pending_sync'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->sync_table WHERE sync_status = 'pending'");
        $stats['sync_errors'] = $wpdb->get_var("SELECT COUNT(*) FROM $this->sync_table WHERE sync_status = 'error'");
        
        return $stats;
    }
}