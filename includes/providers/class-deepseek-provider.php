<?php

class AAG_Deepseek_Provider extends AAG_AI_Provider {
    private $model;

    public function __construct($api_key) {
        parent::__construct($api_key);
        $this->api_endpoint = 'https://api.deepseek.com/v1/chat/completions';
        $this->model = get_option('aag_deepseek_model', 'deepseek-chat');
    }

    public function generate_content($prompt) {
        $response = $this->make_api_request([
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7,
            'max_tokens' => 2000
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        // Check if response has the expected structure
        if (!isset($response['choices']) || 
            !is_array($response['choices']) || 
            empty($response['choices']) ||
            !isset($response['choices'][0]['message']) ||
            !isset($response['choices'][0]['message']['content'])) {
            
            // Log the unexpected response structure for debugging
            error_log('Deepseek API returned unexpected response structure: ' . print_r($response, true));
            
            return new WP_Error(
                'invalid_response', 
                'The AI provider returned an unexpected response format. Please try again or use a different provider.'
            );
        }

        return $response['choices'][0]['message']['content'];
    }
}
