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
            $provider = get_option('aag_default_provider', 'openrouter');
        }

        switch ($provider) {
            case 'openrouter':
                $api_key = $this->get_decrypted_key('aag_openrouter_api_key');
                if (empty($api_key)) {
                    return new WP_Error('missing_api_key', 'OpenRouter API key is not configured. Please set it in the plugin settings.');
                }
                $model = get_option('aag_openrouter_model', 'meta-llama/llama-3.1-8b-instruct:free');
                $provider_instance = new AAG_OpenRouter_Provider($api_key, $model);
                break;
                
            case 'openai':
                $api_key = $this->get_decrypted_key('aag_openai_api_key');
                if (empty($api_key)) {
                    return new WP_Error('missing_api_key', 'OpenAI API key is not configured. Please set it in the plugin settings.');
                }
                $model = get_option('aag_openai_model', 'gpt-4o-mini');
                $provider_instance = new AAG_OpenAI_Provider($api_key, $model);
                break;
                
            default:
                return new WP_Error('invalid_provider', 'Invalid AI provider selected. Please choose OpenRouter or OpenAI.');
        }

        // Set custom timeout if configured
        $timeout = get_option('aag_request_timeout');
        if ($timeout) {
            $provider_instance->set_timeout((int)$timeout);
        }

        return $provider_instance;
    }

    public function handle_article_generation() {
        if (!isset($_POST['aag_generate_article'])) {
            return;
        }

        // Add nonce verification
        if (!wp_verify_nonce($_POST['aag_nonce'], 'aag_generate_article')) {
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

            // Get AI provider
            $provider_name = isset($_POST['provider']) ? sanitize_text_field($_POST['provider']) : 'default';
            $this->ai_provider = $this->get_ai_provider($provider_name);
            
            if (is_wp_error($this->ai_provider)) {
                wp_die($this->ai_provider->get_error_message());
            }

            // Generate content with validated data
            $title_prompt = $this->generate_title_prompt($validation_result['keyword'], $validation_result['topic']);
            $title = $this->generate_seo_title($title_prompt);
            if (is_wp_error($title)) {
                wp_die($title->get_error_message());
            }

            $content_prompt = $this->generate_content_prompt(
                $validation_result['keyword'], 
                $validation_result['topic'], 
                $validation_result['language'], 
                $validation_result['style']
            );
            $content = $this->generate_content($content_prompt);
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

        return "Write a comprehensive, SEO-optimized article about {$topic}. " .
               "Target keyword: '{$keyword}'" .
               "\n\nSEO Requirements:" .
               "\n- Use the keyword in the first paragraph naturally" .
               "\n- Include LSI keywords and related terms" .
               "\n- Use proper heading hierarchy (H2, H3, etc.)" .
               "\n- Write 1500-2500 words" .
               "\n- Include actionable insights and examples" .
               "\n- End with a compelling conclusion" .
               "\n\nFormatting:" .
               "\n- Use markdown formatting" .
               "\n- Include bullet points and numbered lists where appropriate" .
               "\n- Add relevant subheadings" .
               "\n- Make it scannable and easy to read" .
               "\n\n{$style_instructions}" .
               "\n{$lang_instructions}" .
               "\n\nWrite the complete article now:";
    }

    private function get_style_instructions($style) {
        $styles = [
            'informative' => 'Writing Style: Clear, factual tone. Focus on providing valuable information with data and examples. Use authoritative language.',
            'conversational' => 'Writing Style: Friendly, engaging tone. Use "you" and "your", ask rhetorical questions, and make it relatable. Write as if talking to a friend.',
            'professional' => 'Writing Style: Formal, authoritative tone. Use industry-specific terminology and maintain professional credibility throughout.',
            'casual' => 'Writing Style: Relaxed, informal tone. Use everyday language, contractions, and relatable examples. Keep it light and approachable.',
            'academic' => 'Writing Style: Scholarly tone. Use academic language, cite research concepts, and maintain objectivity. Structure arguments logically.',
            'persuasive' => 'Writing Style: Compelling tone. Use persuasive language, emotional appeals, and clear calls to action. Focus on benefits and outcomes.'
        ];

        return $styles[$style] ?? $styles['informative'];
    }

    private function get_language_instructions($language) {
        $instructions = [
            'en-US' => 'Language: Write in American English, using American spelling and terminology.',
            'en-GB' => 'Language: Write in British English, using British spelling and terminology.',
            'zh-TW' => 'Language: 請使用繁體中文撰寫，採用台灣用語和表達方式。',
            'zh-CN' => 'Language: 请使用简体中文撰写，采用中国大陆用语和表达方式。'
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
        
        // Convert HTML to Gutenberg blocks
        return $this->convert_html_to_gutenberg($html);
    }

    private function convert_html_to_gutenberg($html) {
        // Simple conversion - can be enhanced
        $html = str_replace('<h1>', '<!-- wp:heading {"level":1} --><h1>', $html);
        $html = str_replace('</h1>', '</h1><!-- /wp:heading -->', $html);
        $html = str_replace('<h2>', '<!-- wp:heading --><h2>', $html);
        $html = str_replace('</h2>', '</h2><!-- /wp:heading -->', $html);
        $html = str_replace('<h3>', '<!-- wp:heading {"level":3} --><h3>', $html);
        $html = str_replace('</h3>', '</h3><!-- /wp:heading -->', $html);
        $html = str_replace('<p>', '<!-- wp:paragraph --><p>', $html);
        $html = str_replace('</p>', '</p><!-- /wp:paragraph -->', $html);
        $html = str_replace('<ul>', '<!-- wp:list --><ul>', $html);
        $html = str_replace('</ul>', '</ul><!-- /wp:list -->', $html);
        $html = str_replace('<ol>', '<!-- wp:list {"ordered":true} --><ol>', $html);
        $html = str_replace('</ol>', '</ol><!-- /wp:list -->', $html);
        
        return $html;
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

    private function secure_file_operations($file_path) {
        // Validate file path
        $upload_dir = wp_upload_dir();
        $base_path = $upload_dir['basedir'] . '/aag-markdown';
        
        // Prevent directory traversal
        $real_path = realpath(dirname($file_path));
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
    }

    private function validate_input($data) {
        if (empty($data)) {
            return new WP_Error('empty_input', 'Input data cannot be empty');
        }

        // Sanitize and validate keyword/topic
        $keyword = isset($data['keyword']) ? sanitize_text_field($data['keyword']) : '';
        $topic = isset($data['topic']) ? sanitize_textarea_field($data['topic']) : '';
        
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