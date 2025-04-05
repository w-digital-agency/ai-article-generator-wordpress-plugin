<?php

class AAG_Image_Handler {
    private $image_sizes;
    private $optimize_quality;
    private $max_width;
    private $vision_api_key;

    public function __construct() {
        $this->image_sizes = get_option('aag_image_sizes', [
            'small' => ['width' => 300, 'height' => 300],
            'medium' => ['width' => 600, 'height' => 600],
            'large' => ['width' => 1200, 'height' => 1200]
        ]);
        $this->optimize_quality = get_option('aag_image_quality', 82);
        $this->max_width = get_option('aag_max_image_width', 1600);
        $this->vision_api_key = get_option('aag_vision_api_key');
    }

    public function process_image($url, $attribution = '') {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Download image
        $temp_file = $this->download_image($url);
        if (!$temp_file) {
            return false;
        }

        // Optimize image
        $optimized_file = $this->optimize_image($temp_file);
        if (!$optimized_file) {
            unlink($temp_file);
            return false;
        }

        // Generate alt text if not provided
        $alt_text = $this->generate_alt_text($optimized_file);

        // Move to WordPress upload directory
        $upload_info = $this->move_to_uploads($optimized_file);
        if (!$upload_info) {
            unlink($optimized_file);
            return false;
        }

        // Create attachment
        $attachment_id = $this->create_attachment($upload_info, $alt_text, $attribution);
        if (!$attachment_id) {
            return false;
        }

        // Generate additional sizes
        $this->generate_image_sizes($attachment_id);

        return $attachment_id;
    }

    private function download_image($url) {
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return false;
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            return false;
        }

        $temp_file = wp_tempnam();
        if (file_put_contents($temp_file, $image_data)) {
            return $temp_file;
        }

        return false;
    }

    private function optimize_image($file_path) {
        if (!extension_loaded('gd')) {
            return $file_path;
        }

        $image_type = exif_imagetype($file_path);
        if (!$image_type) {
            return false;
        }

        // Load image based on type
        switch ($image_type) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($file_path);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($file_path);
                break;
            case IMAGETYPE_WEBP:
                $image = imagecreatefromwebp($file_path);
                break;
            default:
                return $file_path;
        }

        if (!$image) {
            return false;
        }

        // Resize if needed
        $width = imagesx($image);
        $height = imagesy($image);

        if ($width > $this->max_width) {
            $new_height = ($this->max_width / $width) * $height;
            $resized = imagescale($image, $this->max_width, $new_height, IMG_BICUBIC);
            imagedestroy($image);
            $image = $resized;
        }

        // Create optimized version
        $optimized_file = wp_tempnam();
        switch ($image_type) {
            case IMAGETYPE_JPEG:
                imagejpeg($image, $optimized_file, $this->optimize_quality);
                break;
            case IMAGETYPE_PNG:
                imagepng($image, $optimized_file, 9);
                break;
            case IMAGETYPE_WEBP:
                imagewebp($image, $optimized_file, $this->optimize_quality);
                break;
        }

        imagedestroy($image);
        unlink($file_path);

        return $optimized_file;
    }

    private function generate_alt_text($image_path) {
        // If Vision API key is available, use it
        if ($this->vision_api_key) {
            $alt_text = $this->get_vision_api_description($image_path);
            if ($alt_text) {
                return $alt_text;
            }
        }

        // Fallback: Generate from filename
        $filename = basename($image_path);
        $alt_text = preg_replace('/[^a-zA-Z0-9\s]/', ' ', $filename);
        $alt_text = ucwords(trim(str_replace(['jpg', 'jpeg', 'png', 'webp'], '', $alt_text)));

        return $alt_text;
    }

    private function get_vision_api_description($image_path) {
        $image_data = base64_encode(file_get_contents($image_path));
        
        $response = wp_remote_post('https://vision.googleapis.com/v1/images:annotate?key=' . $this->vision_api_key, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode([
                'requests' => [
                    [
                        'image' => ['content' => $image_data],
                        'features' => [
                            ['type' => 'LABEL_DETECTION', 'maxResults' => 5],
                            ['type' => 'OBJECT_LOCALIZATION', 'maxResults' => 5]
                        ]
                    ]
                ]
            ])
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $result = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($result['responses'][0])) {
            return false;
        }

        $labels = [];
        if (!empty($result['responses'][0]['labelAnnotations'])) {
            foreach ($result['responses'][0]['labelAnnotations'] as $label) {
                $labels[] = $label['description'];
            }
        }

        return implode(', ', array_slice($labels, 0, 3));
    }

    private function move_to_uploads($file_path) {
        $upload_dir = wp_upload_dir();
        $filename = wp_unique_filename($upload_dir['path'], basename($file_path));
        $new_file = $upload_dir['path'] . '/' . $filename;

        if (@copy($file_path, $new_file)) {
            unlink($file_path);
            return [
                'file' => $new_file,
                'url' => $upload_dir['url'] . '/' . $filename,
                'type' => wp_check_filetype($filename)['type']
            ];
        }

        return false;
    }

    private function create_attachment($upload_info, $alt_text, $attribution) {
        $attachment = [
            'post_mime_type' => $upload_info['type'],
            'post_title' => sanitize_file_name(basename($upload_info['file'])),
            'post_content' => '',
            'post_excerpt' => $attribution,
            'post_status' => 'inherit'
        ];

        $attach_id = wp_insert_attachment($attachment, $upload_info['file']);
        if (is_wp_error($attach_id)) {
            return false;
        }

        // Add alt text
        update_post_meta($attach_id, '_wp_attachment_image_alt', $alt_text);

        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload_info['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    private function generate_image_sizes($attachment_id) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        $file = get_attached_file($attachment_id);

        foreach ($this->image_sizes as $size_name => $dimensions) {
            $resized = image_make_intermediate_size(
                $file,
                $dimensions['width'],
                $dimensions['height'],
                true
            );

            if ($resized) {
                $metadata['sizes'][$size_name] = $resized;
            }
        }

        wp_update_attachment_metadata($attachment_id, $metadata);
    }
}
