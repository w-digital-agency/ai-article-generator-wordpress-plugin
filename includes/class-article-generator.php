<?php
class AAG_Article_Generator {
    private $encryption_handler;
    private $image_handler;
    private $ai_provider;
    private $upload_dir;
    private $image_urls = [];

    public function __construct() {
        if (class_exists('AAG_Encryption_Handler')) {
            $this->encryption_handler = new AAG_Encryption_Handler();
        }
        
        if (class_exists('AAG_Image_Handler')) {
            $this->image_handler = new AAG_Image_Handler();
        }
        
        $upload_dir = wp_upload_dir();
        $this->upload_dir = $upload_dir['basedir'] . '/aag-markdown';
        
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }
        
        add_action('admin_init', array($this, 'handle_article_generation'));
        add_action('admin_init', array($this, 'handle_markdown_upload'));
    }

    private function get_decrypted_key($option_name) {
        if (!isset($this->encryption_handler)) {
            $this->encryption_handler = new AAG_Encryption_Handler();
        }
        
        $encrypted_key = get_option($option_name);
        if (empty($encrypted_key)) {
            return '';
        }
        
        return $this->encryption_handler->decrypt($encrypted_key);
    }

    private function get_ai_provider($provider = 'default') {
        if ($provider === 'default') {
            $provider = get_option('aag_default_provider', 'deepseek');
        }

        switch ($provider) {
            case 'deepseek':
                $api_key = $this->get_decrypted_key('aag_deepseek_api_key');
                if (empty($api_key)) {
                    return new WP_Error('missing_api_key', 'Deepseek API key is not configured. Please set it in the plugin settings.');
                }
                return new AAG_Deepseek_Provider($api_key);
                
            case 'perplexity':
                $api_key = $this->get_decrypted_key('aag_perplexity_api_key');
                if (empty($api_key)) {
                    return new WP_Error('missing_api_key', 'Perplexity API key is not configured. Please set it in the plugin settings.');
                }
                $model = get_option('aag_perplexity_model', 'pplx-7b-chat');
                return new AAG_Perplexity_Provider($api_key, $model);
                
            case 'grok':
                $api_key = $this->get_decrypted_key('aag_grok_api_key');
                if (empty($api_key)) {
                    return new WP_Error('missing_api_key', 'Grok API key is not configured. Please set it in the plugin settings.');
                }
                return new AAG_Grok_Provider($api_key);
                
            case 'openrouter':
                $api_key = $this->get_decrypted_key('aag_openrouter_api_key');
                if (empty($api_key)) {
                    return new WP_Error('missing_api_key', 'OpenRouter API key is not configured. Please set it in the plugin settings.');
                }
                return new AAG_OpenRouter_Provider(
                    $api_key,
                    get_option('aag_openrouter_model', 'openai/gpt-4')
                );
                
            default:
                return $this->get_ai_provider('deepseek');
        }
    }

    private function get_ai_provider_new() {
        $provider_name = get_option('aag_default_provider', 'deepseek');
        $provider = null;
        
        try {
            switch ($provider_name) {
                case 'deepseek':
                    $api_key = $this->get_decrypted_key('aag_deepseek_api_key');
                    $provider = new AAG_Deepseek_Provider($api_key);
                    break;
                case 'perplexity':
                    $api_key = $this->get_decrypted_key('aag_perplexity_api_key');
                    $provider = new AAG_Perplexity_Provider($api_key);
                    break;
                case 'grok':
                    $api_key = $this->get_decrypted_key('aag_grok_api_key');
                    $provider = new AAG_Grok_Provider($api_key);
                    break;
                case 'openrouter':
                    $api_key = $this->get_decrypted_key('aag_openrouter_api_key');
                    $provider = new AAG_OpenRouter_Provider($api_key);
                    break;
                default:
                    throw new Exception('Invalid AI provider selected');
            }
            
            // Set custom timeout if configured
            $timeout = get_option('aag_request_timeout');
            if ($timeout) {
                $provider->set_timeout((int)$timeout);
            }
            
            return $provider;
        } catch (Exception $e) {
            error_log('Error initializing AI provider: ' . $e->getMessage());
            return null;
        }
    }

    public function handle_article_generation() {
        // Add nonce verification
        if (!isset($_POST['aag_generate_article']) || 
            !wp_verify_nonce($_POST['aag_nonce'], 'aag_generate_article')) {
            wp_die(__('Security check failed', 'auto-article-generator'));
        }

        // Add capability check
        if (!current_user_can('edit_posts')) {
            wp_die(__('You do not have permission to create posts.', 'auto-article-generator'));
        }

        // Validate input data
        $validation_result = $this->validate_input($_POST);
        if (is_wp_error($validation_result)) {
            wp_die($validation_result->get_error_message());
        }

        try {
            // Rate limiting
            if ($this->is_rate_limited()) {
                wp_die(__('Too many requests. Please wait before generating another article.', 'auto-article-generator'));
            }

            // Generate content with validated data
            $title = $this->generate_seo_title($validation_result);
            if (is_wp_error($title)) {
                wp_die($title->get_error_message());
            }

            $content = $this->generate_content($validation_result);
            if (is_wp_error($content)) {
                wp_die($content->get_error_message());
            }

            // Create post with sanitized content
            $post_id = $this->create_draft_post($title, $content);
            
            if ($post_id) {
                // Log successful generation
                $this->log_generation_success($post_id);
                wp_redirect(admin_url('post.php?post=' . $post_id . '&action=edit'));
                exit;
            }

        } catch (Exception $e) {
            $this->log_generation_error($e->getMessage());
            wp_die($e->getMessage());
        }
    }

    private function generate_title_prompt($keyword, $topic) {
        return "Generate an SEO-optimized title for an article about {$topic}. " .
               "The title must include the keyword '{$keyword}' naturally and be:" .
               "\n- Between 50-60 characters long" .
               "\n- Engaging and click-worthy" .
               "\n- Include power words when relevant" .
               "\n- Front-load the keyword when possible" .
               "\n- Be grammatically correct" .
               "\nReturn ONLY the title, nothing else.";
    }

    private function generate_content_prompt($keyword, $topic, $language, $style) {
        $style_instructions = $this->get_style_instructions($style);
        $lang_instructions = $this->get_language_instructions($language);

        return "Write a comprehensive article about {$topic}. " .
               "Optimize for the keyword '{$keyword}' with proper SEO practices:" .
               "\n- Use the keyword in the first paragraph" .
               "\n- Include LSI keywords naturally" .
               "\n- Use proper heading hierarchy (H2, H3, etc.)" .
               "\n- Include relevant internal linking opportunities" .
               "\n- Write meta description-worthy first paragraph" .
               "\n{$style_instructions}" .
               "\n{$lang_instructions}";
    }

    private function get_style_instructions($style) {
        $styles = [
            'informative' => 'Write in a clear, factual tone. Focus on providing valuable information with data and examples.',
            'conversational' => 'Write in a friendly, engaging tone. Use "you" and "your", ask questions, and make it relatable.',
            'professional' => 'Write in a formal, authoritative tone. Use industry-specific terminology and cite credible sources.',
            'casual' => 'Write in a relaxed, informal tone. Use everyday language and relatable examples.',
            'academic' => 'Write in a scholarly tone. Use academic language, cite research, and maintain objectivity.',
            'persuasive' => 'Write in a compelling tone. Use persuasive language, emotional appeals, and clear calls to action.'
        ];

        return $styles[$style] ?? $styles['informative'];
    }

    private function get_language_name($code) {
        $languages = [
            'en-US' => 'American English',
            'en-GB' => 'British English',
            'zh-TW' => 'Traditional Chinese',
            'zh-CN' => 'Simplified Chinese'
        ];

        return $languages[$code] ?? 'English';
    }

    private function get_language_instructions($language) {
        $instructions = [
            'en-US' => 'Write in American English, using American spelling and terminology.',
            'en-GB' => 'Write in British English, using British spelling and terminology.',
            'zh-TW' => '請使用繁體中文撰寫，採用台灣用語和表達方式。',
            'zh-CN' => '请使用简体中文撰写，采用中国大陆用语和表达方式。'
        ];

        return $instructions[$language] ?? $instructions['en-US'];
    }

    private function generate_seo_title($prompt) {
        try {
            $provider = $this->ai_provider;
            $title = $provider->generate_content($prompt);
            
            if (is_wp_error($title)) {
                return $title;
            }
            
            return wp_strip_all_tags(trim($title));
        } catch (Exception $e) {
            return new WP_Error('generation_error', $e->getMessage());
        }
    }

    private function generate_content($prompt) {
        try {
            $provider = $this->ai_provider;
            $content = $provider->generate_content($prompt);
            
            if (is_wp_error($content)) {
                return $content;
            }
            
            return $content;
        } catch (Exception $e) {
            return new WP_Error('generation_error', $e->getMessage());
        }
    }

    private function create_draft_post($title, $content) {
        // Ensure title and content are UTF-8 encoded
        if (!mb_check_encoding($title, 'UTF-8')) {
            $title = mb_convert_encoding($title, 'UTF-8', mb_detect_encoding($title));
        }
        
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));
        }
        
        $html = $this->format_markdown_content($content);

        $post_data = array(
            'post_title'    => $title,
            'post_content'  => $html,
            'post_status'   => 'draft',
            'post_type'     => 'post',
            'post_author'   => get_current_user_id()
        );

        $post_id = wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            $this->generate_tags($post_id);
            return $post_id;
        }

        return false;
    }

    private function save_to_markdown($content, $filename) {
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
        }

        $file_path = $this->upload_dir . '/' . sanitize_file_name($filename) . '.md';
        $result = file_put_contents($file_path, $content);

        if ($result === false) {
            return new WP_Error('file_save_error', 'Failed to save markdown file');
        }

        return $file_path;
    }

    private function secure_file_operations($file_path) {
        // Validate file path
        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'] . '/aag-markdown';
        
        // Prevent directory traversal
        $real_path = realpath($file_path);
        $real_base = realpath($base_path);
        
        if ($real_path === false || $real_base === false || 
            strpos($real_path, $real_base) !== 0) {
            return new WP_Error('invalid_path', 'Invalid file path');
        }
        
        // Check file extension
        if (!preg_match('/\.md$/', $file_path)) {
            return new WP_Error('invalid_extension', 'Invalid file extension');
        }
        
        return true;
    }

    public function handle_markdown_upload() {
        if (!isset($_POST['upload_markdown'])) {
            return;
        }
        
        // Verify nonce and capabilities
        if (!wp_verify_nonce($_POST['markdown_nonce'], 'upload_markdown_nonce') || 
            !current_user_can('upload_files')) {
            wp_die('Security check failed');
        }

        if (!isset($_FILES['markdown_files'])) {
            wp_die('No files uploaded');
        }

        $files = $_FILES['markdown_files'];
        $uploaded_files = [];

        foreach ($files['name'] as $key => $value) {
            // Validate file upload
            if ($files['error'][$key] !== UPLOAD_ERR_OK) {
                continue;
            }

            $tmp_name = $files['tmp_name'][$key];
            $name = sanitize_file_name($files['name'][$key]);
            
            // Validate file type and size
            $file_type = wp_check_filetype($name, ['md' => 'text/markdown']);
            if (!$file_type['type']) {
                continue;
            }

            if (filesize($tmp_name) > 5 * MB_IN_BYTES) { // 5MB limit
                continue;
            }

            $file_path = $this->upload_dir . '/' . $name;
            
            // Secure file operations
            $security_check = $this->secure_file_operations($file_path);
            if (is_wp_error($security_check)) {
                continue;
            }

            if (move_uploaded_file($tmp_name, $file_path)) {
                $uploaded_files[] = $file_path;
            }
        }

        // Process uploaded files
        foreach ($uploaded_files as $file_path) {
            $post_id = $this->create_draft_from_markdown($file_path);
            if (!is_wp_error($post_id)) {
                $this->generate_tags($post_id);
                // Clean up the file after processing
                @unlink($file_path);
            }
        }

        wp_redirect(admin_url('edit.php?post_type=post'));
        exit;
    }

    private function create_draft_from_markdown($file_path) {
        $content = file_get_contents($file_path);
        if ($content === false) {
            return new WP_Error('file_read_error', 'Failed to read markdown file');
        }

        $lines = explode("\n", $content);
        $title = '';
        $body = [];
        $in_frontmatter = false;

        foreach ($lines as $line) {
            if (trim($line) === '---') {
                $in_frontmatter = !$in_frontmatter;
                continue;
            }

            if ($in_frontmatter) {
                if (strpos($line, 'title:') === 0) {
                    $title = trim(substr($line, 6));
                }
            } else {
                $body[] = $line;
            }
        }

        if (empty($title)) {
            foreach ($body as $line) {
                if (strpos($line, '# ') === 0) {
                    $title = trim(substr($line, 2));
                    break;
                }
            }
            if (empty($title)) {
                $title = basename($file_path, '.md');
            }
        }

        $formatted_content = $this->format_markdown_content(implode("\n", $body));

        $post_data = array(
            'post_title' => $title,
            'post_content' => $formatted_content,
            'post_status' => 'draft',
            'post_type' => 'post'
        );

        return wp_insert_post($post_data);
    }

    private function format_markdown_content($content) {
        if (!class_exists('Parsedown')) {
            require_once AAG_PLUGIN_PATH . 'includes/Parsedown.php';
        }
        
        // Ensure content is UTF-8 encoded
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', mb_detect_encoding($content));
        }
        
        $parsedown = new Parsedown();
        $html = $parsedown->text($content);
        
        // Reset image URLs array
        $this->image_urls = [];
        
        // Convert HTML to Gutenberg blocks
        $blocks = [];
        
        // Set internal encoding to UTF-8
        $prev_encoding = mb_internal_encoding();
        mb_internal_encoding('UTF-8');
        
        // Split content by HTML elements
        $dom = new DOMDocument();
        $dom->encoding = 'UTF-8';
        @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        
        $xpath = new DOMXPath($dom);
        
        foreach ($dom->childNodes as $node) {
            $blocks[] = $this->convert_node_to_block($node);
        }
        
        // Restore previous encoding
        mb_internal_encoding($prev_encoding);
        
        return implode("\n", array_filter($blocks));
    }

    private function convert_node_to_block($node) {
        if ($node->nodeType === XML_TEXT_NODE) {
            $text = trim($node->textContent);
            if (empty($text)) return '';
            return '<!-- wp:paragraph --><p>' . $text . '</p><!-- /wp:paragraph -->';
        }
        
        switch ($node->nodeName) {
            case 'h1':
                return '<!-- wp:heading {"level":1} --><h1>' . $node->textContent . '</h1><!-- /wp:heading -->';
            case 'h2':
                return '<!-- wp:heading --><h2>' . $node->textContent . '</h2><!-- /wp:heading -->';
            case 'h3':
                return '<!-- wp:heading {"level":3} --><h3>' . $node->textContent . '</h3><!-- /wp:heading -->';
            case 'p':
                return '<!-- wp:paragraph --><p>' . $node->textContent . '</p><!-- /wp:paragraph -->';
            case 'ul':
                $items = [];
                foreach ($node->childNodes as $li) {
                    if ($li->nodeName === 'li') {
                        $items[] = $li->textContent;
                    }
                }
                return '<!-- wp:list --><ul>' . implode('', array_map(function($item) {
                    return '<li>' . $item . '</li>';
                }, $items)) . '</ul><!-- /wp:list -->';
            case 'ol':
                $items = [];
                foreach ($node->childNodes as $li) {
                    if ($li->nodeName === 'li') {
                        $items[] = $li->textContent;
                    }
                }
                return '<!-- wp:list {"ordered":true} --><ol>' . implode('', array_map(function($item) {
                    return '<li>' . $item . '</li>';
                }, $items)) . '</ol><!-- /wp:list -->';
            case 'table':
                $html = '';
                $dom = new DOMDocument();
                $dom->appendChild($dom->importNode($node, true));
                $html = $dom->saveHTML();
                return '<!-- wp:table --><figure class="wp-block-table"><table>' . 
                       str_replace(['<table>', '</table>'], '', $html) . 
                       '</table></figure><!-- /wp:table -->';
            case 'pre':
                return '<!-- wp:code --><pre class="wp-block-code"><code>' . 
                       htmlspecialchars($node->textContent) . 
                       '</code></pre><!-- /wp:code -->';
            case 'blockquote':
                return '<!-- wp:quote --><blockquote class="wp-block-quote"><p>' . 
                       $node->textContent . 
                       '</p></blockquote><!-- /wp:quote -->';
            case 'img':
                $src = $node->getAttribute('src');
                $alt = $node->getAttribute('alt');
                $attribution = $node->getAttribute('data-attribution');
                $this->image_urls[] = [
                    'url' => $src,
                    'alt' => $alt,
                    'attribution' => $attribution
                ];
                return $this->create_image_block($src, $alt);
            
            case 'figure':
                $img = $node->getElementsByTagName('img')->item(0);
                $caption = $node->getElementsByTagName('figcaption')->item(0);
                if ($img) {
                    $src = $img->getAttribute('src');
                    $alt = $img->getAttribute('alt');
                    $attribution = $img->getAttribute('data-attribution');
                    $this->image_urls[] = [
                        'url' => $src,
                        'alt' => $alt,
                        'attribution' => $attribution,
                        'caption' => $caption ? $caption->textContent : ''
                    ];
                    return $this->create_image_block(
                        $src,
                        $alt,
                        $caption ? $caption->textContent : ''
                    );
                }
                return '';

            default:
                return '';
        }
    }

    private function create_image_block($src, $alt = '', $caption = '') {
        $attachment_id = false;
        
        // Find image in the collected URLs
        foreach ($this->image_urls as $image) {
            if ($image['url'] === $src) {
                $attachment_id = $this->image_handler->process_image(
                    $src,
                    $image['attribution'] ?? ''
                );
                break;
            }
        }
        
        if (!$attachment_id) {
            return '';
        }
        
        $block = '<!-- wp:image';
        $attributes = [
            'id' => $attachment_id,
            'sizeSlug' => 'large'
        ];
        
        if (!empty($alt)) {
            $attributes['alt'] = $alt;
        }
        
        if (!empty($caption)) {
            $attributes['caption'] = $caption;
        }
        
        $block .= ' ' . json_encode($attributes);
        $block .= wp_get_attachment_image($attachment_id, 'large', false, ['alt' => $alt]);
        
        if (!empty($caption)) {
            $block .= '<figcaption>' . esc_html($caption) . '</figcaption>';
        }
        
        $block .= '<!-- /wp:image -->';
        
        return $block;
    }

    private function process_image($url) {
        // Skip if not a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        // Get the image file
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return false;
        }

        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            return false;
        }

        // Get image info
        $image_info = wp_check_filetype(basename($url));
        if (empty($image_info['ext'])) {
            return false;
        }

        // Generate unique filename
        $filename = wp_unique_filename(
            wp_upload_dir()['path'],
            'ai-generated-' . time() . '.' . $image_info['ext']
        );

        // Save the image file
        $upload_path = wp_upload_dir()['path'] . '/' . $filename;
        file_put_contents($upload_path, $image_data);

        // Prepare attachment data
        $attachment = array(
            'post_mime_type' => $image_info['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );

        // Insert attachment
        $attach_id = wp_insert_attachment($attachment, $upload_path);
        if (is_wp_error($attach_id)) {
            return false;
        }

        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $upload_path);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    private function generate_tags($post_id) {
        // Check if TaxoPress is active
        if (!function_exists('simpletags_init')) {
            return;
        }

        // Get post content
        $post = get_post($post_id);
        if (!$post) {
            return;
        }

        // Use TaxoPress auto-terms feature if available
        if (class_exists('SimpleTags_Client_Autoterms')) {
            $autoterms = new SimpleTags_Client_Autoterms();
            $autoterms->auto_terms_post($post_id, '', 0);
        }

        // Alternative: Use WordPress term extraction API
        $content = strip_tags($post->post_content . ' ' . $post->post_title);
        $response = wp_remote_post('http://api.wordpress.org/tags/suggest', [
            'body' => [
                'text' => $content
            ]
        ]);

        if (!is_wp_error($response)) {
            $suggested_tags = json_decode(wp_remote_retrieve_body($response));
            if (!empty($suggested_tags)) {
                wp_set_post_tags($post_id, $suggested_tags, true);
            }
        }
    }

    private function validate_input($data) {
        if (empty($data)) {
            return new WP_Error('empty_input', 'Input data cannot be empty');
        }

        // Sanitize and validate keyword/topic
        $keyword = isset($data['keyword']) ? sanitize_text_field($data['keyword']) : '';
        $topic = isset($data['topic']) ? sanitize_text_field($data['topic']) : '';
        
        if (empty($keyword) && empty($topic)) {
            return new WP_Error('missing_required', 'Either keyword or topic is required');
        }

        // Validate language code
        $language = isset($data['language']) ? sanitize_text_field($data['language']) : 'en-US';
        $valid_languages = ['en-US', 'en-GB', 'zh-TW', 'zh-CN'];
        if (!in_array($language, $valid_languages)) {
            return new WP_Error('invalid_language', 'Invalid language selection');
        }

        // Validate writing style
        $style = isset($data['style']) ? sanitize_text_field($data['style']) : 'informative';
        $valid_styles = ['informative', 'conversational', 'professional', 'casual', 'academic', 'persuasive'];
        if (!in_array($style, $valid_styles)) {
            return new WP_Error('invalid_style', 'Invalid writing style');
        }

        return [
            'keyword' => $keyword,
            'topic' => $topic,
            'language' => $language,
            'style' => $style
        ];
    }

    // Add rate limiting
    private function is_rate_limited() {
        $user_id = get_current_user_id();
        $transient_key = 'aag_rate_limit_' . $user_id;
        $generation_count = get_transient($transient_key);
        
        if ($generation_count === false) {
            set_transient($transient_key, 1, HOUR_IN_SECONDS);
            return false;
        }
        
        if ($generation_count >= 10) { // Max 10 generations per hour
            return true;
        }
        
        set_transient($transient_key, $generation_count + 1, HOUR_IN_SECONDS);
        return false;
    }

    private function log_generation_success($post_id) {
        error_log(sprintf(
            'Article generated successfully. Post ID: %d, User: %d',
            $post_id,
            get_current_user_id()
        ));
    }

    private function log_generation_error($message) {
        error_log(sprintf(
            'Article generation failed. Error: %s, User: %d',
            $message,
            get_current_user_id()
        ));
    }
}