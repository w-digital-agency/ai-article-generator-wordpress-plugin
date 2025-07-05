<?php

class AAG_OpenAI_Provider extends AAG_AI_Provider {
    private $model;

    public function __construct($api_key, $model = 'gpt-4o-mini') {
        parent::__construct($api_key);
        $this->api_endpoint = 'https://api.openai.com/v1/chat/completions';
        $this->model = $model;
    }

    public function generate_content($prompt) {
        $response = $this->make_api_request([
            'model' => $this->model,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a professional content writer specializing in SEO-optimized articles. Write engaging, informative content that provides real value to readers.'
                ],
                [
                    'role' => 'user',
                    'content' => $prompt
                ]
            ],
            'temperature' => 0.7,
            'max_tokens' => 4000
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
            error_log('OpenAI API returned unexpected response structure: ' . print_r($response, true));
            
            return new WP_Error(
                'invalid_response', 
                'The AI provider returned an unexpected response format. Please try again or check your API key.'
            );
        }

        return $response['choices'][0]['message']['content'];
    }

    public function set_model($model) {
        $this->model = $model;
    }

    public function get_available_models() {
        return [
            'gpt-4o' => 'GPT-4o (Most Capable)',
            'gpt-4o-mini' => 'GPT-4o Mini (Fast & Efficient)',
            'gpt-4-turbo' => 'GPT-4 Turbo (Previous Generation)',
            'gpt-3.5-turbo' => 'GPT-3.5 Turbo (Budget Option)'
        ];
    }
}