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
            $key = wp_generate_password(64, true, true);
            update_option('aag_encryption_key', $key);
        }
        return $key;
    }

    public function encrypt($data) {
        if (empty($data)) {
            return false;
        }

        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length($this->cipher_method));
        $encrypted = openssl_encrypt($data, $this->cipher_method, $this->encryption_key, 0, $iv);

        if ($encrypted === false) {
            return false;
        }

        // Combine IV and encrypted data
        return base64_encode($iv . $encrypted);
    }

    public function decrypt($data) {
        if (empty($data)) {
            return false;
        }

        $data = base64_decode($data);
        $iv_length = openssl_cipher_iv_length($this->cipher_method);
        
        if (strlen($data) < $iv_length) {
            return false;
        }

        $iv = substr($data, 0, $iv_length);
        $encrypted = substr($data, $iv_length);

        return openssl_decrypt($encrypted, $this->cipher_method, $this->encryption_key, 0, $iv);
    }

    public function secure_delete($option_name) {
        return delete_option($option_name);
    }
}
