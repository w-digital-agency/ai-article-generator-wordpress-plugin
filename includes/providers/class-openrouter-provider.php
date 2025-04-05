<?php

class AAG_OpenRouter_Provider extends AAG_AI_Provider {
    private $model;

    public function __construct($api_key, $model = 'openai/gpt-4') {
        parent::__construct($api_key);
        $this->api_endpoint = 'https://openrouter.ai/api/v1/chat/completions';
        $this->model = $model;
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
        ], [
            'HTTP-Referer' => get_site_url(),
            'X-Title' => 'Auto Article Generator'
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
            error_log('OpenRouter API returned unexpected response structure: ' . print_r($response, true));
            
            return new WP_Error(
                'invalid_response', 
                'The AI provider returned an unexpected response format. Please try again or use a different provider.'
            );
        }

        return $response['choices'][0]['message']['content'];
    }

    public function set_model($model) {
        $this->model = $model;
    }
}
