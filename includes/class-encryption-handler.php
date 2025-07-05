<?php

class AAG_Encryption_Handler {
    private $encryption_key;
    private $cipher_method = 'aes-256-cbc';

    public function __construct() {
        $this->encryption_key = $this->get_encryption_key();
    }

    private function get_encryption_key() {
        $key = get_option('aag_encryption_key');
        if (!$key) {
            // Generate a cryptographically secure key
            $key = wp_generate_password(64, true, true);
            update_option('aag_encryption_key', $key);
        }
        return $key;
    }

    public function encrypt($data) {
        if (empty($data)) {
            return false;
        }

        // Generate a random IV for each encryption
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->cipher_method));
        $encrypted = openssl_encrypt($data, $this->cipher_method, $this->encryption_key, 0, $iv);

        if ($encrypted === false) {
            error_log('AAG Encryption failed');
            return false;
        }

        // Combine IV and encrypted data, then base64 encode
        return base64_encode($iv . $encrypted);
    }

    public function decrypt($data) {
        if (empty($data)) {
            return false;
        }

        $data = base64_decode($data);
        if ($data === false) {
            error_log('AAG Decryption: Invalid base64 data');
            return false;
        }

        $iv_length = openssl_cipher_iv_length($this->cipher_method);
        
        if (strlen($data) < $iv_length) {
            error_log('AAG Decryption: Data too short');
            return false;
        }

        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        $decrypted = openssl_decrypt($encrypted, $this->cipher_method, $this->encryption_key, 0, $iv);
        
        if ($decrypted === false) {
            error_log('AAG Decryption failed');
            return false;
        }

        return $decrypted;
    }

    public function secure_delete($option_name) {
        // Securely delete the option
        $result = delete_option($option_name);
        
        // Log the deletion for security audit
        if (class_exists('AAG_Security_Logger')) {
            $logger = new AAG_Security_Logger();
            $logger->log_event('api_key_deleted', "API key option {$option_name} deleted", 'medium');
        }
        
        return $result;
    }

    /**
     * Rotate the encryption key (will invalidate all existing encrypted data)
     */
    public function rotate_encryption_key() {
        // This will invalidate all existing encrypted data
        delete_option('aag_encryption_key');
        $this->encryption_key = $this->get_encryption_key();
        
        if (class_exists('AAG_Security_Logger')) {
            $logger = new AAG_Security_Logger();
            $logger->log_event('encryption_key_rotated', 'Encryption key rotated - all API keys need to be re-entered', 'high');
        }
        
        return true;
    }

    /**
     * Validate that encryption is working properly
     */
    public function test_encryption() {
        $test_data = 'test_encryption_' . wp_generate_password(16, false);
        $encrypted = $this->encrypt($test_data);
        
        if (!$encrypted) {
            return false;
        }
        
        $decrypted = $this->decrypt($encrypted);
        return $decrypted === $test_data;
    }
}