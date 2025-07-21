<?php
/**
 * Notion Sync Function Tester
 * A standalone testing script to verify Notion sync functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    // For testing outside WordPress, define basic constants
    define('ABSPATH', dirname(__FILE__) . '/');
    define('WP_DEBUG', true);
    
    // Mock WordPress functions for testing
    if (!function_exists('wp_remote_get')) {
        function wp_remote_get($url, $args = []) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $args['headers'] ?? []);
            curl_setopt($ch, CURLOPT_TIMEOUT, $args['timeout'] ?? 30);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            return [
                'response' => ['code' => $http_code],
                'body' => $response
            ];
        }
    }
    
    if (!function_exists('wp_remote_retrieve_response_code')) {
        function wp_remote_retrieve_response_code($response) {
            return $response['response']['code'];
        }
    }
    
    if (!function_exists('wp_remote_retrieve_body')) {
        function wp_remote_retrieve_body($response) {
            return $response['body'];
        }
    }
    
    if (!function_exists('is_wp_error')) {
        function is_wp_error($thing) {
            return $thing instanceof WP_Error;
        }
    }
    
    class WP_Error {
        private $errors = [];
        
        public function __construct($code, $message) {
            $this->errors[$code] = [$message];
        }
        
        public function get_error_message() {
            foreach ($this->errors as $messages) {
                return $messages[0];
            }
            return '';
        }
    }
}

class NotionSyncTester {
    private $notion_token;
    private $database_id;
    
    public function __construct($notion_token = '', $database_id = '') {
        $this->notion_token = $notion_token;
        $this->database_id = $database_id;
    }
    
    /**
     * Test Notion API connection
     */
    public function test_connection() {
        echo "<h2>🔗 Testing Notion Connection</h2>\n";
        
        if (empty($this->notion_token)) {
            echo "<p style='color: red;'>❌ No Notion token provided</p>\n";
            return false;
        }
        
        $response = wp_remote_get('https://api.notion.com/v1/users/me', [
            'headers' => [
                'Authorization: Bearer ' . $this->notion_token,
                'Notion-Version: 2022-06-28',
                'Content-Type: application/json'
            ],
            'timeout' => 30
        ]);
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        echo "<p><strong>Response Code:</strong> {$response_code}</p>\n";
        echo "<p><strong>Response Body:</strong></p>\n";
        echo "<pre>" . htmlspecialchars($body) . "</pre>\n";
        
        if ($response_code === 200) {
            echo "<p style='color: green;'>✅ Connection successful!</p>\n";
            return true;
        } else {
            echo "<p style='color: red;'>❌ Connection failed</p>\n";
            return false;
        }
    }
    
    /**
     * Test database access
     */
    public function test_database_access() {
        echo "<h2>🗄️ Testing Database Access</h2>\n";
        
        if (empty($this->database_id)) {
            echo "<p style='color: red;'>❌ No database ID provided</p>\n";
            return false;
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.notion.com/v1/databases/{$this->database_id}/query");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->notion_token,
            'Notion-Version: 2022-06-28',
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'page_size' => 5
        ]));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "<p><strong>Response Code:</strong> {$http_code}</p>\n";
        echo "<p><strong>Response Body:</strong></p>\n";
        echo "<pre>" . htmlspecialchars($response) . "</pre>\n";
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            $page_count = count($data['results'] ?? []);
            echo "<p style='color: green;'>✅ Database access successful! Found {$page_count} pages</p>\n";
            return $data;
        } else {
            echo "<p style='color: red;'>❌ Database access failed</p>\n";
            return false;
        }
    }
    
    /**
     * Test page content retrieval
     */
    public function test_page_content($page_id) {
        echo "<h2>📄 Testing Page Content Retrieval</h2>\n";
        echo "<p><strong>Page ID:</strong> {$page_id}</p>\n";
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, "https://api.notion.com/v1/blocks/{$page_id}/children");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->notion_token,
            'Notion-Version: 2022-06-28',
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        echo "<p><strong>Response Code:</strong> {$http_code}</p>\n";
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            $block_count = count($data['results'] ?? []);
            echo "<p style='color: green;'>✅ Page content retrieved! Found {$block_count} blocks</p>\n";
            
            // Show block types
            if (!empty($data['results'])) {
                echo "<h3>Block Types Found:</h3>\n";
                echo "<ul>\n";
                foreach ($data['results'] as $block) {
                    echo "<li>{$block['type']}</li>\n";
                }
                echo "</ul>\n";
            }
            
            echo "<details><summary>Full Response</summary>\n";
            echo "<pre>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</pre>\n";
            echo "</details>\n";
            
            return $data;
        } else {
            echo "<p style='color: red;'>❌ Failed to retrieve page content</p>\n";
            echo "<pre>" . htmlspecialchars($response) . "</pre>\n";
            return false;
        }
    }
    
    /**
     * Test block conversion
     */
    public function test_block_conversion($blocks) {
        echo "<h2>🔄 Testing Block Conversion</h2>\n";
        
        if (empty($blocks)) {
            echo "<p style='color: orange;'>⚠️ No blocks to convert</p>\n";
            return;
        }
        
        foreach ($blocks as $index => $block) {
            echo "<h3>Block #{$index} - Type: {$block['type']}</h3>\n";
            
            $converted = $this->convert_notion_block_to_gutenberg($block);
            
            echo "<p><strong>Original Block:</strong></p>\n";
            echo "<pre>" . htmlspecialchars(json_encode($block, JSON_PRETTY_PRINT)) . "</pre>\n";
            
            echo "<p><strong>Converted HTML:</strong></p>\n";
            echo "<pre>" . htmlspecialchars($converted) . "</pre>\n";
            
            echo "<p><strong>Rendered Preview:</strong></p>\n";
            echo "<div style='border: 1px solid #ccc; padding: 10px; background: #f9f9f9;'>\n";
            echo $converted;
            echo "</div>\n";
            
            echo "<hr>\n";
        }
    }
    
    /**
     * Convert Notion block to Gutenberg (simplified version)
     */
    private function convert_notion_block_to_gutenberg($block) {
        $type = $block['type'];
        
        switch ($type) {
            case 'paragraph':
                $text = $this->extract_rich_text($block['paragraph']['rich_text'] ?? []);
                if (empty(trim($text))) return '';
                return "<!-- wp:paragraph -->\n<p>{$text}</p>\n<!-- /wp:paragraph -->\n\n";

            case 'heading_1':
                $text = $this->extract_rich_text($block['heading_1']['rich_text'] ?? []);
                return "<!-- wp:heading {\"level\":1} -->\n<h1>{$text}</h1>\n<!-- /wp:heading -->\n\n";

            case 'heading_2':
                $text = $this->extract_rich_text($block['heading_2']['rich_text'] ?? []);
                return "<!-- wp:heading -->\n<h2>{$text}</h2>\n<!-- /wp:heading -->\n\n";

            case 'heading_3':
                $text = $this->extract_rich_text($block['heading_3']['rich_text'] ?? []);
                return "<!-- wp:heading {\"level\":3} -->\n<h3>{$text}</h3>\n<!-- /wp:heading -->\n\n";

            case 'bulleted_list_item':
                $text = $this->extract_rich_text($block['bulleted_list_item']['rich_text'] ?? []);
                return "<!-- wp:list-item -->\n<li>{$text}</li>\n<!-- /wp:list-item -->\n";

            case 'numbered_list_item':
                $text = $this->extract_rich_text($block['numbered_list_item']['rich_text'] ?? []);
                return "<!-- wp:list-item -->\n<li>{$text}</li>\n<!-- /wp:list-item -->\n";

            case 'quote':
                $text = $this->extract_rich_text($block['quote']['rich_text'] ?? []);
                return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>{$text}</p></blockquote>\n<!-- /wp:quote -->\n\n";

            case 'code':
                $text = $this->extract_rich_text($block['code']['rich_text'] ?? []);
                return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>{$text}</code></pre>\n<!-- /wp:code -->\n\n";

            case 'image':
                return $this->convert_notion_image($block['image'] ?? []);

            case 'divider':
                return "<!-- wp:separator -->\n<hr class=\"wp-block-separator\"/>\n<!-- /wp:separator -->\n\n";

            default:
                return "<!-- Unsupported block type: {$type} -->\n";
        }
    }
    
    /**
     * Extract rich text from Notion format
     */
    private function extract_rich_text($rich_text_array) {
        $text = '';
        
        foreach ($rich_text_array as $text_obj) {
            $content = $text_obj['text']['content'] ?? '';
            
            // Apply formatting
            if (isset($text_obj['annotations'])) {
                $annotations = $text_obj['annotations'];
                
                if ($annotations['bold'] ?? false) {
                    $content = "<strong>{$content}</strong>";
                }
                if ($annotations['italic'] ?? false) {
                    $content = "<em>{$content}</em>";
                }
                if ($annotations['strikethrough'] ?? false) {
                    $content = "<del>{$content}</del>";
                }
                if ($annotations['underline'] ?? false) {
                    $content = "<u>{$content}</u>";
                }
                if ($annotations['code'] ?? false) {
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
    
    /**
     * Convert Notion image
     */
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
            return '<!-- No image URL found -->';
        }

        $block = "<!-- wp:image -->\n";
        $block .= "<figure class=\"wp-block-image\"><img src=\"{$image_url}\" alt=\"\"/>";
        if (!empty($caption)) {
            $block .= "<figcaption>{$caption}</figcaption>";
        }
        $block .= "</figure>\n<!-- /wp:image -->\n\n";

        return $block;
    }
    
    /**
     * Run all tests
     */
    public function run_all_tests() {
        echo "<h1>🧪 Notion Sync Function Tester</h1>\n";
        echo "<p>Testing Notion integration functionality...</p>\n";
        
        // Test 1: Connection
        if (!$this->test_connection()) {
            echo "<p style='color: red;'><strong>❌ Cannot proceed without valid connection</strong></p>\n";
            return;
        }
        
        // Test 2: Database access
        $database_data = $this->test_database_access();
        if (!$database_data) {
            echo "<p style='color: red;'><strong>❌ Cannot proceed without database access</strong></p>\n";
            return;
        }
        
        // Test 3: Page content (use first page if available)
        if (!empty($database_data['results'])) {
            $first_page = $database_data['results'][0];
            $page_id = $first_page['id'];
            
            $page_content = $this->test_page_content($page_id);
            
            // Test 4: Block conversion
            if ($page_content && !empty($page_content['results'])) {
                $this->test_block_conversion($page_content['results']);
            }
        }
        
        echo "<h2>✅ Testing Complete!</h2>\n";
    }
}

// Usage example
if (isset($_GET['test']) && $_GET['test'] === 'notion') {
    // Get credentials from query parameters or form
    $notion_token = $_GET['token'] ?? '';
    $database_id = $_GET['database'] ?? '';
    
    $tester = new NotionSyncTester($notion_token, $database_id);
    
    echo "<!DOCTYPE html>\n";
    echo "<html><head><title>Notion Sync Tester</title></head><body>\n";
    
    if (empty($notion_token) || empty($database_id)) {
        echo "<h1>Notion Sync Tester</h1>\n";
        echo "<form method='get'>\n";
        echo "<input type='hidden' name='test' value='notion'>\n";
        echo "<p><label>Notion Token: <input type='text' name='token' placeholder='secret_...' style='width: 400px;'></label></p>\n";
        echo "<p><label>Database ID: <input type='text' name='database' placeholder='32-character database ID' style='width: 400px;'></label></p>\n";
        echo "<p><button type='submit'>Run Tests</button></p>\n";
        echo "</form>\n";
    } else {
        $tester->run_all_tests();
    }
    
    echo "</body></html>\n";
}
?>