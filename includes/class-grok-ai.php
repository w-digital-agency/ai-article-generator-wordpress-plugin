<?php

class AAG_Grok_AI {
    private $api_key;

    public function __construct() {
        $this->api_key = get_option('aag_grok_api_key');
    }

    public function generate_content($prompt) {
        // TODO: Replace with actual Grok AI API endpoint and implementation
        $api_endpoint = 'https://api.grok.ai/v1/generate';
        
        $response = wp_remote_post($api_endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'prompt' => $prompt,
                'format' => 'markdown'
            ])
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('grok_api_error', $response->get_error_message());
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        return $data['content'];
    }

    public function save_to_markdown($content, $filename) {
        $upload_dir = wp_upload_dir();
        $markdown_dir = $upload_dir['basedir'] . '/aag-markdown';
        
        if (!file_exists($markdown_dir)) {
            wp_mkdir_p($markdown_dir);
        }

        $file_path = $markdown_dir . '/' . sanitize_file_name($filename) . '.md';
        $result = file_put_contents($file_path, $content);

        if ($result === false) {
            return new WP_Error('file_save_error', 'Failed to save markdown file');
        }

        return $file_path;
    }
}
