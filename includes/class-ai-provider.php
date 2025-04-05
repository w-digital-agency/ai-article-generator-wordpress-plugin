<?php

abstract class AAG_AI_Provider {
    protected $api_key;
    protected $api_endpoint;
    protected $timeout = 180; // 3 minutes default timeout

    public function __construct($api_key) {
        if (empty($api_key)) {
            throw new Exception('API key is required');
        }
        $this->api_key = $api_key;
    }

    abstract public function generate_content($prompt);
    
    /**
     * Alias for generate_content for backward compatibility
     */
    public function generate($prompt) {
        return $this->generate_content($prompt);
    }

    /**
     * Set request timeout in seconds
     */
    public function set_timeout($seconds) {
        $this->timeout = max(30, min(300, (int)$seconds)); // Between 30s and 5m
    }

    protected function make_api_request($data, $headers = []) {
        if (empty($this->api_key)) {
            return new WP_Error(
                'missing_api_key',
                'API key is not set. Please configure the API key in the plugin settings.'
            );
        }

        if (empty($this->api_endpoint)) {
            return new WP_Error(
                'missing_api_endpoint',
                'API endpoint is not configured. Please contact the plugin administrator.'
            );
        }

        $default_headers = [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type' => 'application/json'
        ];

        $headers = array_merge($default_headers, $headers);

        try {
            $response = wp_remote_post($this->api_endpoint, [
                'headers' => $headers,
                'body' => json_encode($data),
                'timeout' => $this->timeout,
                'httpversion' => '1.1',
                'sslverify' => true
            ]);

            if (is_wp_error($response)) {
                $error_message = $response->get_error_message();
                error_log('API Request Error: ' . $error_message);
                
                if (strpos($error_message, 'timed out') !== false) {
                    return new WP_Error(
                        'api_timeout',
                        sprintf('The request timed out after %d seconds. This might happen when generating longer content. Please try again or adjust the timeout in settings.', $this->timeout)
                    );
                }
                
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                $error_message = wp_remote_retrieve_response_message($response);
                $body = wp_remote_retrieve_body($response);
                $error_data = json_decode($body, true);
                
                $detailed_message = isset($error_data['error']['message']) 
                    ? $error_data['error']['message'] 
                    : (isset($error_data['message']) ? $error_data['message'] : $error_message);
                
                error_log('API Error: ' . $response_code . ' - ' . $detailed_message);
                
                return new WP_Error(
                    'api_error_' . $response_code,
                    'API Error: ' . $detailed_message,
                    $error_data
                );
            }

            $body = wp_remote_retrieve_body($response);
            $decoded_body = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('JSON decode error: ' . json_last_error_msg() . ' - Body: ' . substr($body, 0, 1000));
                return new WP_Error(
                    'json_decode_error',
                    'Failed to parse API response: ' . json_last_error_msg()
                );
            }
            
            return $decoded_body;
        } catch (Exception $e) {
            error_log('Exception in make_api_request: ' . $e->getMessage());
            return new WP_Error(
                'api_request_exception',
                'An error occurred while making the API request: ' . $e->getMessage()
            );
        }
    }
}
