<?php
/**
 * Plugin Name: Soft AI Chat (Lite)
 * Plugin URI:  https://soft.io.vn/soft-ai-chat
 * Description: AI Support Widget (Lite Version). Answers questions based on website content (RAG).
 * Version:     1.1.0
 * Author:      Tung Pham
 * License:     GPL-2.0+
 * Text Domain: soft-ai-chat-lite
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// ---------------------------------------------------------
// 0. ACTIVATION: CREATE DATABASE TABLE
// ---------------------------------------------------------

register_activation_hook(__FILE__, 'soft_ai_lite_activate');

function soft_ai_lite_activate() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'soft_ai_chat_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        user_ip varchar(100) DEFAULT '' NOT NULL,
        provider varchar(50) DEFAULT '' NOT NULL,
        model varchar(100) DEFAULT '' NOT NULL,
        question text NOT NULL,
        answer longtext NOT NULL,
        source varchar(50) DEFAULT 'widget' NOT NULL, 
        PRIMARY KEY  (id),
        KEY time (time)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// ---------------------------------------------------------
// 1. SETTINGS & ADMIN MENU
// ---------------------------------------------------------

add_action('admin_menu', 'soft_ai_lite_add_admin_menu');
add_action('admin_init', 'soft_ai_lite_settings_init');
add_action('admin_enqueue_scripts', 'soft_ai_lite_admin_enqueue');

function soft_ai_lite_add_admin_menu() {
    add_menu_page('Soft AI Chat Lite', 'Soft AI Chat Lite', 'manage_options', 'soft-ai-chat-lite', 'soft_ai_lite_options_page', 'dashicons-format-chat', 80);
    add_submenu_page('soft-ai-chat-lite', 'Settings', 'Settings', 'manage_options', 'soft-ai-chat-lite', 'soft_ai_lite_options_page');
    add_submenu_page('soft-ai-chat-lite', 'Chat History', 'Chat History', 'manage_options', 'soft-ai-chat-history', 'soft_ai_lite_history_page');
}

function soft_ai_lite_admin_enqueue($hook_suffix) {
    if ($hook_suffix === 'toplevel_page_soft-ai-chat-lite') {
        wp_enqueue_style('wp-color-picker');
        wp_enqueue_script('wp-color-picker');
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($){ 
                function toggleFields() {
                    var provider = $('#soft_ai_provider_select').val();
                    $('.api-key-row').closest('tr').hide();
                    $('.row-' + provider).closest('tr').show();
                }
                $('#soft_ai_provider_select').change(toggleFields);
                toggleFields();
                $('.soft-ai-color-field').wpColorPicker();
            });
        ");
    }
}

function soft_ai_lite_settings_init() {
    register_setting('softAiChatLite', 'soft_ai_chat_settings');

    // Section 1: AI Configuration
    add_settings_section('soft_ai_chat_main', __('General & AI Configuration', 'soft-ai-chat-lite'), null, 'softAiChatLite');
    add_settings_field('save_history', __('Save Chat History', 'soft-ai-chat-lite'), 'soft_ai_lite_render_checkbox', 'softAiChatLite', 'soft_ai_chat_main', ['field' => 'save_history']);
    add_settings_field('provider', __('Select AI Provider', 'soft-ai-chat-lite'), 'soft_ai_lite_provider_render', 'softAiChatLite', 'soft_ai_chat_main');
    add_settings_field('groq_api_key', __('Groq API Key', 'soft-ai-chat-lite'), 'soft_ai_lite_render_password', 'softAiChatLite', 'soft_ai_chat_main', ['field' => 'groq_api_key', 'class' => 'row-groq']);
    add_settings_field('openai_api_key', __('OpenAI API Key', 'soft-ai-chat-lite'), 'soft_ai_lite_render_password', 'softAiChatLite', 'soft_ai_chat_main', ['field' => 'openai_api_key', 'class' => 'row-openai']);
    add_settings_field('gemini_api_key', __('Google Gemini API Key', 'soft-ai-chat-lite'), 'soft_ai_lite_render_password', 'softAiChatLite', 'soft_ai_chat_main', ['field' => 'gemini_api_key', 'class' => 'row-gemini']);
    add_settings_field('model', __('AI Model Name', 'soft-ai-chat-lite'), 'soft_ai_lite_render_text', 'softAiChatLite', 'soft_ai_chat_main', ['field' => 'model', 'default' => 'llama-3.3-70b-versatile', 'desc' => '
        <strong>Recommended Model IDs (Copy & Paste):</strong><br>
        🟢 <b>Groq:</b> <code>llama-3.3-70b-versatile</code> (Best), <code>openai/gpt-oss-120b</code><br>
        🔵 <b>OpenAI:</b> <code>gpt-4o-mini</code> (Fast), <code>gpt-4o</code> (Smart)<br>
        🟣 <b>Gemini:</b> <code>gemini-1.5-flash</code> (Fast), <code>gemini-1.5-pro</code>
    ']);
    add_settings_field('temperature', __('Creativity', 'soft-ai-chat-lite'), 'soft_ai_lite_render_number', 'softAiChatLite', 'soft_ai_chat_main', ['field' => 'temperature', 'default' => 0.5, 'step' => 0.1, 'max' => 1]);
    add_settings_field('max_tokens', __('Max Tokens', 'soft-ai-chat-lite'), 'soft_ai_lite_render_number', 'softAiChatLite', 'soft_ai_chat_main', ['field' => 'max_tokens', 'default' => 4096]);
    add_settings_field('system_prompt', __('Custom Persona', 'soft-ai-chat-lite'), 'soft_ai_lite_render_textarea', 'softAiChatLite', 'soft_ai_chat_main', ['field' => 'system_prompt', 'desc' => 'System instructions for the AI.']);
    
    // Section 2: UI
    add_settings_section('soft_ai_chat_ui', __('User Interface', 'soft-ai-chat-lite'), null, 'softAiChatLite');
    add_settings_field('chat_title', __('Chatbox Title', 'soft-ai-chat-lite'), 'soft_ai_lite_render_text', 'softAiChatLite', 'soft_ai_chat_ui', ['field' => 'chat_title', 'default' => 'Trợ lý AI', 'width' => '100%']);
    add_settings_field('welcome_msg', __('Welcome Message', 'soft-ai-chat-lite'), 'soft_ai_lite_render_text', 'softAiChatLite', 'soft_ai_chat_ui', ['field' => 'welcome_msg', 'default' => 'Xin chào! Bạn cần hỗ trợ thông tin gì ạ?', 'width' => '100%']);
    add_settings_field('theme_color', __('Widget Color', 'soft-ai-chat-lite'), 'soft_ai_lite_themecolor_render', 'softAiChatLite', 'soft_ai_chat_ui');
}

// --- Render Helpers ---
function soft_ai_lite_render_text($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? ($args['default'] ?? '');
    $width = $args['width'] ?? '400px';
    echo "<input type='text' name='soft_ai_chat_settings[{$args['field']}]' value='" . esc_attr($val) . "' style='width: {$width};'>";
    if(isset($args['desc'])) echo "<p class='description'>{$args['desc']}</p>";
}
function soft_ai_lite_render_textarea($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? '';
    echo "<textarea name='soft_ai_chat_settings[{$args['field']}]' rows='5' style='width: 100%;'>" . esc_textarea($val) . "</textarea>";
    if(isset($args['desc'])) echo "<p class='description'>{$args['desc']}</p>";
}
function soft_ai_lite_render_password($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? '';
    $cls = $args['class'] ?? '';
    echo "<div class='api-key-row {$cls}'><input type='password' name='soft_ai_chat_settings[{$args['field']}]' value='" . esc_attr($val) . "' style='width:400px;'></div>";
}
function soft_ai_lite_render_number($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? ($args['default'] ?? 0);
    $step = $args['step'] ?? 1;
    $max = $args['max'] ?? 99999;
    echo "<input type='number' step='{$step}' max='{$max}' name='soft_ai_chat_settings[{$args['field']}]' value='" . esc_attr($val) . "' style='width:100px;'>";
}
function soft_ai_lite_render_checkbox($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = isset($options[$args['field']]) ? $options[$args['field']] : '0';
    echo '<label><input type="checkbox" name="soft_ai_chat_settings['.$args['field'].']" value="1" ' . checked($val, '1', false) . ' /> Enable</label>';
}

function soft_ai_lite_provider_render() {
    $options = get_option('soft_ai_chat_settings');
    $val = $options['provider'] ?? 'groq';
    ?>
    <select name="soft_ai_chat_settings[provider]" id="soft_ai_provider_select">
        <option value="groq" <?php selected($val, 'groq'); ?>>Groq (Llama 3/Mixtral)</option>
        <option value="openai" <?php selected($val, 'openai'); ?>>OpenAI (GPT-4o/Turbo)</option>
        <option value="gemini" <?php selected($val, 'gemini'); ?>>Google Gemini</option>
    </select>
    <?php
}

function soft_ai_lite_themecolor_render() {
    $options = get_option('soft_ai_chat_settings');
    $val = $options['theme_color'] ?? '#027DDD';
    echo '<input type="text" name="soft_ai_chat_settings[theme_color]" value="' . esc_attr($val) . '" class="soft-ai-color-field" />';
}

function soft_ai_lite_options_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Soft AI Chat Lite Configuration</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('softAiChatLite');
            do_settings_sections('softAiChatLite');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// ---------------------------------------------------------
// 1.5. HISTORY PAGE
// ---------------------------------------------------------

function soft_ai_lite_history_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'soft_ai_chat_logs';

    if (isset($_POST['delete_log']) && check_admin_referer('delete_log_' . $_POST['log_id'])) {
        $wpdb->delete($table_name, ['id' => intval($_POST['log_id'])]);
        echo '<div class="updated"><p>Log deleted.</p></div>';
    }
    if (isset($_POST['clear_all_logs']) && check_admin_referer('clear_all_logs')) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="updated"><p>All logs cleared.</p></div>';
    }

    $per_page = 20;
    $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
    $offset = ($paged - 1) * $per_page;
    $total_items = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    $total_pages = ceil($total_items / $per_page);
    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name ORDER BY time DESC LIMIT %d OFFSET %d", $per_page, $offset));
    ?>
    <div class="wrap">
        <h1>Chat History</h1>
        <form method="post" style="margin-bottom: 20px; text-align:right;">
            <?php wp_nonce_field('clear_all_logs'); ?>
            <input type="hidden" name="clear_all_logs" value="1">
            <button type="submit" class="button button-link-delete" onclick="return confirm('Delete ALL logs?')">Clear All History</button>
        </form>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th width="120">Time</th>
                    <th width="100">Provider</th>
                    <th width="20%">Question</th>
                    <th>Answer</th>
                    <th width="60">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($logs): foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html($log->time); ?></td>
                    <td><?php echo esc_html($log->provider); ?></td>
                    <td><?php echo esc_html($log->question); ?></td>
                    <td><div style="max-height:80px;overflow-y:auto;"><?php echo esc_html($log->answer); ?></div></td>
                    <td>
                        <form method="post">
                            <?php wp_nonce_field('delete_log_' . $log->id); ?>
                            <input type="hidden" name="delete_log" value="1"><input type="hidden" name="log_id" value="<?php echo $log->id; ?>">
                            <button class="button button-small">Del</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; else: echo '<tr><td colspan="5">No history found.</td></tr>'; endif; ?>
            </tbody>
        </table>
        <?php if ($total_pages > 1): echo paginate_links(['base' => add_query_arg('paged', '%#%'), 'total' => $total_pages, 'current' => $paged]); endif; ?>
    </div>
    <?php
}

// ---------------------------------------------------------
// 2. CORE LOGIC (RAG ONLY - NO ORDERING)
// ---------------------------------------------------------

function soft_ai_lite_clean_content($content) {
    if (!is_string($content)) return '';
    $content = strip_shortcodes($content);
    $content = preg_replace('/\[\/?et_pb_[^\]]+\]/', '', $content);
    $content = wp_strip_all_tags($content);
    $content = preg_replace('/\s+/', ' ', $content);
    return mb_substr(trim($content), 0, 1500);
}

function soft_ai_lite_get_context($question) {
    // Search posts/pages/products to provide context, but do not trigger sales logic.
    $args = ['post_type' => ['post', 'page', 'product'], 'post_status' => 'publish', 'posts_per_page' => 3, 's' => $question, 'orderby' => 'relevance'];
    $posts = get_posts($args);

    $context = "";
    if ($posts) {
        foreach ($posts as $post) {
            $info = "";
            if ($post->post_type === 'product' && function_exists('wc_get_product')) {
                $p = wc_get_product($post->ID);
                if ($p) $info = " | Price: " . $p->get_price_html() . " | Status: " . $p->get_stock_status();
            }
            $clean_body = soft_ai_lite_clean_content($post->post_content);
            $context .= "--- Source: {$post->post_title} ---\nLink: " . get_permalink($post->ID) . $info . "\nContent: $clean_body\n\n";
        }
    }
    return $context ?: "No specific website content found for this query.";
}

function soft_ai_lite_log_chat($question, $answer) {
    global $wpdb;
    $opt = get_option('soft_ai_chat_settings');
    if (empty($opt['save_history'])) return;
    $wpdb->insert($wpdb->prefix . 'soft_ai_chat_logs', [
        'time' => current_time('mysql'),
        'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'provider' => $opt['provider'] ?? 'unknown',
        'model' => $opt['model'] ?? 'unknown',
        'question' => $question,
        'answer' => $answer,
        'source' => 'widget'
    ]);
}

/**
 * Main AI Engine (Lite/Consulting)
 */
function soft_ai_lite_generate_answer($question) {
    $options = get_option('soft_ai_chat_settings');
    $provider = $options['provider'] ?? 'groq';
    $model = $options['model'] ?? 'llama-3.3-70b-versatile';
    
    // 1. Context Retrieval
    $site_context = soft_ai_lite_get_context($question);
    $user_instruction = $options['system_prompt'] ?? '';
    
    // 2. Prompt Engineering (No Ordering Intent)
    $system_prompt = "You are a helpful AI Consultant for this website.\n" .
                     ($user_instruction ? "Persona: $user_instruction\n" : "") .
                     "Website Content:\n" . $site_context . "\n\n" .
                     "INSTRUCTIONS:\n" . 
                     "1. Answer the user's question based on the Website Content provided.\n" .
                     "2. If the user asks about products, provide information (price, details) and the Link.\n" .
                     "3. Do NOT try to sell, add to cart, or process orders. Just provide information.\n" .
                     "4. Answer in Vietnamese. Keep it concise and friendly.";

    // 3. Call API
    $ai_response = soft_ai_lite_call_api($provider, $model, $system_prompt, $question, $options);
    
    if (is_wp_error($ai_response)) return "Lỗi hệ thống: " . $ai_response->get_error_message();

    return trim($ai_response);
}

// ---------------------------------------------------------
// 3. API CALLER
// ---------------------------------------------------------

function soft_ai_lite_call_api($provider, $model, $sys, $user, $opts) {
    $api_key = $opts[$provider . '_api_key'] ?? '';
    if (!$api_key) return new WP_Error('missing_key', 'API Key Missing');

    $url = '';
    $headers = ['Content-Type' => 'application/json'];
    $body = [];

    switch ($provider) {
        case 'groq':
            $url = 'https://api.groq.com/openai/v1/chat/completions';
            $headers['Authorization'] = 'Bearer ' . $api_key;
            $body = [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]],
                'temperature' => (float)$opts['temperature'],
                'max_tokens' => (int)$opts['max_tokens']
            ];
            break;
        case 'openai':
            $url = 'https://api.openai.com/v1/chat/completions';
            $headers['Authorization'] = 'Bearer ' . $api_key;
            $body = [
                'model' => $model,
                'messages' => [['role' => 'system', 'content' => $sys], ['role' => 'user', 'content' => $user]],
                'temperature' => (float)$opts['temperature'],
                'max_tokens' => (int)$opts['max_tokens']
            ];
            break;
        case 'gemini':
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$api_key}";
            $body = [
                'system_instruction' => ['parts' => [['text' => $sys]]],
                'contents' => [['role' => 'user', 'parts' => [['text' => $user]]]],
                'generationConfig' => ['temperature' => (float)$opts['temperature'], 'maxOutputTokens' => (int)$opts['max_tokens']]
            ];
            break;
    }

    $response = wp_remote_post($url, [
        'headers' => $headers, 
        'body' => json_encode($body), 
        'timeout' => 60
    ]);

    if (is_wp_error($response)) return $response;
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($data['choices'][0]['message']['content'])) {
        return $data['choices'][0]['message']['content'];
    }
    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        return $data['candidates'][0]['content']['parts'][0]['text'];
    }

    return "API Error: " . wp_remote_retrieve_body($response);
}

// ---------------------------------------------------------
// 4. REST API
// ---------------------------------------------------------

add_action('rest_api_init', function () {
    register_rest_route('soft-ai-chat/v1', '/ask', [
        'methods' => 'POST',
        'callback' => 'soft_ai_lite_handle_widget_request',
        'permission_callback' => '__return_true',
    ]);
});

function soft_ai_lite_handle_widget_request($request) {
    $params = $request->get_json_params();
    $question = sanitize_text_field($params['question'] ?? '');
    
    if (!$question) return new WP_Error('no_input', 'Empty Question', ['status' => 400]);

    $answer = soft_ai_lite_generate_answer($question);
    soft_ai_lite_log_chat($question, $answer);
    
    return rest_ensure_response(['answer' => $answer]);
}

// ---------------------------------------------------------
// 5. FRONTEND WIDGET
// ---------------------------------------------------------

add_action('wp_footer', 'soft_ai_lite_inject_widget');

function soft_ai_lite_inject_widget() {
    $options = get_option('soft_ai_chat_settings');
    if (is_admin() || empty($options['provider'])) return;

    $color = $options['theme_color'] ?? '#027DDD';
    $welcome = $options['welcome_msg'] ?? 'Xin chào! Bạn cần hỗ trợ thông tin gì ạ?';
    $chat_title = $options['chat_title'] ?? 'Trợ lý AI';
    ?>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/marked/11.1.1/marked.min.js"></script>
    <style>
        #sac-trigger {
            position: fixed; bottom: 20px; right: 20px; width: 60px; height: 60px;
            background: <?php echo esc_attr($color); ?>; color: white; border-radius: 50%;
            display: flex; align-items: center; justify-content: center; cursor: pointer;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2); z-index: 999999; transition: all 0.3s;
            font-size: 28px;
        }
        #sac-trigger:hover { transform: scale(1.1); box-shadow: 0 6px 20px rgba(0,0,0,0.3); }
        #sac-window {
            position: fixed; bottom: 90px; right: 20px; width: 360px; height: 500px;
            max-height: calc(100vh - 120px); background: #fff; border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2); display: none; flex-direction: column;
            z-index: 999999; overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            border: 1px solid #f0f0f0;
        }
        .sac-header { background: <?php echo esc_attr($color); ?>; color: white; padding: 15px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
        .sac-close { cursor: pointer; font-size: 18px; opacity: 0.8; }
        .sac-close:hover { opacity: 1; }
        #sac-messages { flex: 1; padding: 15px; overflow-y: auto; background: #f8f9fa; display: flex; flex-direction: column; gap: 12px; font-size: 14px; }
        .sac-msg { padding: 10px 14px; border-radius: 12px; line-height: 1.5; max-width: 85%; word-wrap: break-word; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .sac-msg.user { align-self: flex-end; background: #222; color: white; border-bottom-right-radius: 2px; }
        .sac-msg.bot { align-self: flex-start; background: #fff; border: 1px solid #e5e5e5; color: #333; border-bottom-left-radius: 2px; }
        .sac-msg.bot p { margin: 0 0 8px 0; } .sac-msg.bot p:last-child { margin: 0; }
        .sac-msg.bot a { color: <?php echo esc_attr($color); ?>; text-decoration: none; font-weight: 500; }
        .sac-msg.bot a:hover { text-decoration: underline; }
        .sac-input-area { padding: 12px; border-top: 1px solid #eee; background: white; display: flex; gap: 8px; }
        #sac-input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 20px; outline: none; transition: border 0.2s; }
        #sac-input:focus { border-color: <?php echo esc_attr($color); ?>; }
        #sac-send { width: 40px; height: 40px; background: <?php echo esc_attr($color); ?>; color: white; border: none; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0 !important;}
        #sac-send:disabled { background: #ccc; cursor: not-allowed; }
        /* Typing indicator */
        .typing-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #888; margin-right: 3px; animation: typing 1.4s infinite ease-in-out both; }
        .typing-dot:nth-child(1) { animation-delay: -0.32s; } .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        @keyframes typing { 0%, 80%, 100% { transform: scale(0); } 40% { transform: scale(1); } }
    </style>

    <div id="sac-trigger" onclick="toggleSac()">💬</div>
    <div id="sac-window">
        <div class="sac-header">
            <span><?php echo esc_html($chat_title); ?></span>
            <span class="sac-close" onclick="toggleSac()">✕</span>
        </div>
        <div id="sac-messages">
            <div class="sac-msg bot"><?php echo esc_html($welcome); ?></div>
        </div>
        <div class="sac-input-area">
            <input type="text" id="sac-input" placeholder="Hỏi thông tin..." onkeypress="handleEnter(event)">
            <button id="sac-send" onclick="sendSac()"><span style="font-size:16px;">➤</span></button>
        </div>
    </div>

    <script>
        const apiUrl = '<?php echo esc_url(rest_url('soft-ai-chat/v1/ask')); ?>';
        function toggleSac() {
            const win = document.getElementById('sac-window');
            const isHidden = win.style.display === '' || win.style.display === 'none';
            win.style.display = isHidden ? 'flex' : 'none';
            if (isHidden) setTimeout(() => document.getElementById('sac-input').focus(), 100);
        }
        
        function handleEnter(e) { if (e.key === 'Enter') sendSac(); }

        async function sendSac() {
            const input = document.getElementById('sac-input');
            const msgs = document.getElementById('sac-messages');
            const btn = document.getElementById('sac-send');
            const txt = input.value.trim();
            if (!txt) return;

            msgs.innerHTML += `<div class="sac-msg user">${txt.replace(/</g, "&lt;")}</div>`;
            const loadingId = 'sac-load-' + Date.now();
            msgs.innerHTML += `<div class="sac-msg bot" id="${loadingId}"><span class="typing-dot"></span><span class="typing-dot"></span><span class="typing-dot"></span></div>`;
            msgs.scrollTop = msgs.scrollHeight;
            input.value = ''; input.disabled = true; btn.disabled = true;

            try {
                const res = await fetch(apiUrl, {
                    method: 'POST', 
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>' },
                    body: JSON.stringify({ question: txt })
                });
                const data = await res.json();
                document.getElementById(loadingId).remove();
                
                if (data.answer) {
                    msgs.innerHTML += `<div class="sac-msg bot">${marked.parse(data.answer)}</div>`;
                } else {
                    msgs.innerHTML += `<div class="sac-msg bot" style="color:red">Lỗi: ${data.message || 'Unknown'}</div>`;
                }
            } catch (err) {
                document.getElementById(loadingId)?.remove();
                msgs.innerHTML += `<div class="sac-msg bot" style="color:red">Mất kết nối server.</div>`;
            }
            
            input.disabled = false; btn.disabled = false; input.focus();
            msgs.scrollTop = msgs.scrollHeight;
        }
    </script>
    <?php
}