<?php
/**
 * Plugin Name: Soft AI Chat (All-in-One) - Enhanced Payment & Social & Live Chat & Coupons & Canned Responses
 * Plugin URI:  https://soft.io.vn/soft-ai-chat
 * Description: AI Chat Widget & Sales Bot. Supports RAG + WooCommerce + Coupons + VietQR/PayPal + Facebook/Zalo + Live Chat + Canned Responses + Auto Suggestions.
 * Version:     3.5.3
 * Author:      Tung Pham
 * License:     GPL-2.0+
 * Text Domain: soft-ai-chat
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// ---------------------------------------------------------
// 0. ACTIVATION: CREATE DATABASE TABLES
// ---------------------------------------------------------

register_activation_hook(__FILE__, 'soft_ai_chat_activate');

function soft_ai_chat_activate() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Table 1: Chat Logs
    $table_logs = $wpdb->prefix . 'soft_ai_chat_logs';
    $sql_logs = "CREATE TABLE $table_logs (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        time datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        user_ip varchar(100) DEFAULT '' NOT NULL,
        provider varchar(50) DEFAULT '' NOT NULL,
        model varchar(100) DEFAULT '' NOT NULL,
        question text NOT NULL,
        answer longtext NOT NULL,
        source varchar(50) DEFAULT 'widget' NOT NULL, 
        is_read tinyint(1) DEFAULT 0 NOT NULL,
        PRIMARY KEY  (id),
        KEY time (time),
        KEY user_ip (user_ip)
    ) $charset_collate;";
    dbDelta($sql_logs);

    // Table 2: Canned Messages (NEW)
    $table_canned = $wpdb->prefix . 'soft_ai_canned_msgs';
    $sql_canned = "CREATE TABLE $table_canned (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        shortcut varchar(50) NOT NULL,
        content text NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";
    dbDelta($sql_canned);
}

// ---------------------------------------------------------
// 1. SETTINGS & ADMIN MENU
// ---------------------------------------------------------

add_action('admin_menu', 'soft_ai_chat_add_admin_menu');
add_action('admin_init', 'soft_ai_chat_settings_init');
add_action('admin_enqueue_scripts', 'soft_ai_chat_admin_enqueue');

function soft_ai_chat_add_admin_menu() {
    add_menu_page('Soft AI Chat', 'Soft AI Chat', 'manage_options', 'soft-ai-chat', 'soft_ai_chat_options_page', 'dashicons-format-chat', 80);
    add_submenu_page('soft-ai-chat', 'Live Chat (Support)', '🔴 Live Chat', 'manage_options', 'soft-ai-live-chat', 'soft_ai_live_chat_page');
    add_submenu_page('soft-ai-chat', 'Canned Responses', 'Câu trả lời mẫu', 'manage_options', 'soft-ai-canned-responses', 'soft_ai_canned_responses_page');
    add_submenu_page('soft-ai-chat', 'Settings', 'Settings', 'manage_options', 'soft-ai-chat', 'soft_ai_chat_options_page');
    add_submenu_page('soft-ai-chat', 'Chat History', 'Chat Logs', 'manage_options', 'soft-ai-chat-history', 'soft_ai_chat_history_page');
}

function soft_ai_chat_admin_enqueue($hook_suffix) {
    if ($hook_suffix === 'toplevel_page_soft-ai-chat') {
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

function soft_ai_chat_settings_init() {
    register_setting('softAiChat', 'soft_ai_chat_settings');

    // Section 1: AI Configuration
    add_settings_section('soft_ai_chat_main', __('General & AI Configuration', 'soft-ai-chat'), null, 'softAiChat');
    add_settings_field('save_history', __('Save Chat History', 'soft-ai-chat'), 'soft_ai_render_checkbox', 'softAiChat', 'soft_ai_chat_main', ['field' => 'save_history']);
    add_settings_field('provider', __('Select AI Provider', 'soft-ai-chat'), 'soft_ai_chat_provider_render', 'softAiChat', 'soft_ai_chat_main');
    add_settings_field('groq_api_key', __('Groq API Key', 'soft-ai-chat'), 'soft_ai_render_password', 'softAiChat', 'soft_ai_chat_main', ['field' => 'groq_api_key', 'class' => 'row-groq']);
    add_settings_field('openai_api_key', __('OpenAI API Key', 'soft-ai-chat'), 'soft_ai_render_password', 'softAiChat', 'soft_ai_chat_main', ['field' => 'openai_api_key', 'class' => 'row-openai']);
    add_settings_field('gemini_api_key', __('Google Gemini API Key', 'soft-ai-chat'), 'soft_ai_render_password', 'softAiChat', 'soft_ai_chat_main', ['field' => 'gemini_api_key', 'class' => 'row-gemini']);
    add_settings_field('model', __('AI Model Name', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_main', ['field' => 'model', 'default' => 'llama-3.3-70b-versatile','desc' => 'Recommended: <code>llama-3.3-70b-versatile</code> (Groq), <code>gpt-4o-mini</code> (OpenAI)']);
    add_settings_field('temperature', __('Creativity', 'soft-ai-chat'), 'soft_ai_render_number', 'softAiChat', 'soft_ai_chat_main', ['field' => 'temperature', 'default' => 0.5, 'step' => 0.1, 'max' => 1]);
    add_settings_field('max_tokens', __('Max Tokens', 'soft-ai-chat'), 'soft_ai_render_number', 'softAiChat', 'soft_ai_chat_main', ['field' => 'max_tokens', 'default' => 4096]);
    add_settings_field('system_prompt', __('Custom Persona', 'soft-ai-chat'), 'soft_ai_render_textarea', 'softAiChat', 'soft_ai_chat_main', ['field' => 'system_prompt']);
    
    // Section 2: Payment Integration
    add_settings_section('soft_ai_chat_payment', __('Payment Integration (Chat Only)', 'soft-ai-chat'), null, 'softAiChat');
    add_settings_field('vietqr_bank', __('VietQR Bank Code', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_payment', ['field' => 'vietqr_bank']);
    add_settings_field('vietqr_acc', __('VietQR Account No', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_payment', ['field' => 'vietqr_acc']);
    add_settings_field('vietqr_name', __('Account Name (Optional)', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_payment', ['field' => 'vietqr_name']);
    add_settings_field('paypal_me', __('PayPal.me Username', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_payment', ['field' => 'paypal_me']);

    // Section 3: UI
    add_settings_section('soft_ai_chat_ui', __('User Interface', 'soft-ai-chat'), null, 'softAiChat');
    add_settings_field('chat_title', __('Chat Window Title', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_ui', ['field' => 'chat_title', 'default' => 'Trợ lý AI']);
    add_settings_field('welcome_msg', __('Welcome Message', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_ui', ['field' => 'welcome_msg', 'default' => 'Xin chào! Bạn cần tìm gì ạ?', 'width' => '100%']);
    add_settings_field('theme_color', __('Widget Color', 'soft-ai-chat'), 'soft_ai_chat_themecolor_render', 'softAiChat', 'soft_ai_chat_ui');

    // Section 4: Social Integration
    add_settings_section('soft_ai_chat_social', __('Social Media Integration', 'soft-ai-chat'), 'soft_ai_chat_social_desc', 'softAiChat');
    
    // Facebook
    add_settings_field('fb_sep', '<strong>--- Facebook Messenger ---</strong>', 'soft_ai_render_sep', 'softAiChat', 'soft_ai_chat_social');
    add_settings_field('enable_fb_widget', __('Show FB Chat Bubble', 'soft-ai-chat'), 'soft_ai_render_checkbox', 'softAiChat', 'soft_ai_chat_social', ['field' => 'enable_fb_widget']);
    add_settings_field('fb_page_id', __('Facebook Page ID', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_social', ['field' => 'fb_page_id', 'desc' => 'Required for Chatbox Widget (Find in Page > About).']);
    add_settings_field('fb_app_access_token', __('Facebook App Access Token', 'soft-ai-chat'), 'soft_ai_render_token', 'softAiChat', 'soft_ai_chat_social', ['field' => 'fb_app_access_token', 'desc' => 'Required for extended API features (Optional).']);
    add_settings_field('fb_page_token', __('Facebook Page Access Token', 'soft-ai-chat'), 'soft_ai_render_token', 'softAiChat', 'soft_ai_chat_social', ['field' => 'fb_page_token', 'desc' => 'Required for AI Auto-Reply.']);
    add_settings_field('fb_verify_token', __('Facebook Verify Token', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_social', ['field' => 'fb_verify_token', 'default' => 'soft_ai_verify']);

    // Zalo
    add_settings_field('zalo_sep', '<strong>--- Zalo OA ---</strong>', 'soft_ai_render_sep', 'softAiChat', 'soft_ai_chat_social');
    add_settings_field('enable_zalo_widget', __('Show Zalo Widget', 'soft-ai-chat'), 'soft_ai_render_checkbox', 'softAiChat', 'soft_ai_chat_social', ['field' => 'enable_zalo_widget']);
    add_settings_field('zalo_oa_id', __('Zalo OA ID', 'soft-ai-chat'), 'soft_ai_render_text', 'softAiChat', 'soft_ai_chat_social', ['field' => 'zalo_oa_id', 'desc' => 'Required for Chat Widget.']);
    add_settings_field('zalo_access_token', __('Zalo OA Access Token', 'soft-ai-chat'), 'soft_ai_render_token', 'softAiChat', 'soft_ai_chat_social', ['field' => 'zalo_access_token', 'desc' => 'Required for AI Auto-Reply.']);
}

// --- Generic Render Helpers ---
function soft_ai_render_sep() { echo ''; }
function soft_ai_render_text($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? ($args['default'] ?? '');
    $width = $args['width'] ?? '400px';
    echo "<input type='text' name='soft_ai_chat_settings[{$args['field']}]' value='" . esc_attr($val) . "' style='width: {$width};'>";
    if(isset($args['desc'])) echo "<p class='description'>{$args['desc']}</p>";
}
function soft_ai_render_textarea($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? '';
    echo "<textarea name='soft_ai_chat_settings[{$args['field']}]' rows='5' style='width: 100%;'>" . esc_textarea($val) . "</textarea>";
    if(isset($args['desc'])) echo "<p class='description'>{$args['desc']}</p>";
}
function soft_ai_render_password($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? '';
    $cls = $args['class'] ?? '';
    echo "<div class='api-key-row {$cls}'><input type='password' name='soft_ai_chat_settings[{$args['field']}]' value='" . esc_attr($val) . "' style='width:400px;'>";
    if(isset($args['desc'])) echo "<p class='description'>{$args['desc']}</p>";
    echo "</div>";
}
function soft_ai_render_token($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? '';
    $cls = $args['class'] ?? '';
    echo "<div class='api-key-token-row {$cls}'><input type='password' name='soft_ai_chat_settings[{$args['field']}]' value='" . esc_attr($val) . "' style='width:400px;'>";
    if(isset($args['desc'])) echo "<p class='description'>{$args['desc']}</p>";
    echo "</div>";
}
function soft_ai_render_number($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = $options[$args['field']] ?? ($args['default'] ?? 0);
    $step = $args['step'] ?? 1;
    $max = $args['max'] ?? 99999;
    echo "<input type='number' step='{$step}' max='{$max}' name='soft_ai_chat_settings[{$args['field']}]' value='" . esc_attr($val) . "' style='width:100px;'>";
}
function soft_ai_render_checkbox($args) {
    $options = get_option('soft_ai_chat_settings');
    $val = isset($options[$args['field']]) ? $options[$args['field']] : '0';
    echo '<label><input type="checkbox" name="soft_ai_chat_settings['.$args['field'].']" value="1" ' . checked($val, '1', false) . ' /> Enable</label>';
}

function soft_ai_chat_provider_render() {
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

function soft_ai_chat_themecolor_render() {
    $options = get_option('soft_ai_chat_settings');
    $val = $options['theme_color'] ?? '#027DDD';
    echo '<input type="text" name="soft_ai_chat_settings[theme_color]" value="' . esc_attr($val) . '" class="soft-ai-color-field" />';
}

function soft_ai_chat_social_desc() {
    echo '<p>Configure webhooks to connect AI to social platforms. Use the "Page ID" and "OA ID" to display chat bubbles on your site.</p>';
    echo '<p><strong>Webhooks URL:</strong><br>FB: <code>' . rest_url('soft-ai-chat/v1/webhook/facebook') . '</code><br>Zalo: <code>' . rest_url('soft-ai-chat/v1/webhook/zalo') . '</code></p>';
}

function soft_ai_chat_options_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1>Soft AI Chat Configuration</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('softAiChat');
            do_settings_sections('softAiChat');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

// ---------------------------------------------------------
// 1.2. CANNED RESPONSES PAGE (CRUD)
// ---------------------------------------------------------

function soft_ai_canned_responses_page() {
    if (!current_user_can('manage_options')) return;
    global $wpdb;
    $table = $wpdb->prefix . 'soft_ai_canned_msgs';

    // HANDLE ADD/UPDATE
    if (isset($_POST['sac_save_canned']) && check_admin_referer('sac_save_canned_nonce')) {
        $shortcut = sanitize_text_field($_POST['shortcut']);
        $content = sanitize_textarea_field($_POST['content']);
        $edit_id = intval($_POST['edit_id']);

        if ($shortcut && $content) {
            if ($edit_id > 0) {
                $wpdb->update($table, ['shortcut' => $shortcut, 'content' => $content], ['id' => $edit_id]);
                echo '<div class="updated"><p>Updated successfully!</p></div>';
            } else {
                $wpdb->insert($table, ['shortcut' => $shortcut, 'content' => $content]);
                echo '<div class="updated"><p>Added successfully!</p></div>';
            }
        }
    }

    // HANDLE DELETE
    if (isset($_GET['action']) && $_GET['action'] == 'delete' && isset($_GET['id'])) {
        $del_id = intval($_GET['id']);
        $wpdb->delete($table, ['id' => $del_id]);
        echo '<div class="updated"><p>Deleted.</p></div>';
    }

    // PREPARE EDIT DATA
    $edit_data = null;
    if (isset($_GET['action']) && $_GET['action'] == 'edit' && isset($_GET['id'])) {
        $edit_data = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", intval($_GET['id'])));
    }

    $responses = $wpdb->get_results("SELECT * FROM $table ORDER BY id DESC");
    ?>
    <div class="wrap">
        <h1>Quản lý Câu trả lời mẫu (Canned Responses)</h1>
        <p>Tạo các câu trả lời nhanh để sử dụng trong Live Chat.</p>

        <div style="display:flex; gap: 20px;">
            <div style="width: 350px; background:#fff; padding:20px; border:1px solid #ccc;">
                <h3><?php echo $edit_data ? 'Sửa câu mẫu' : 'Thêm mới'; ?></h3>
                <form method="post" action="<?php echo remove_query_arg(['action', 'id']); ?>">
                    <?php wp_nonce_field('sac_save_canned_nonce'); ?>
                    <input type="hidden" name="edit_id" value="<?php echo $edit_data ? $edit_data->id : 0; ?>">
                    
                    <p>
                        <label><strong>Tên gợi nhớ (Shortcut):</strong></label><br>
                        <input type="text" name="shortcut" value="<?php echo $edit_data ? esc_attr($edit_data->shortcut) : ''; ?>" style="width:100%;" required placeholder="Ví dụ: chào, giá, stk...">
                    </p>
                    <p>
                        <label><strong>Nội dung tin nhắn:</strong></label><br>
                        <textarea name="content" rows="6" style="width:100%;" required><?php echo $edit_data ? esc_textarea($edit_data->content) : ''; ?></textarea>
                    </p>
                    <p>
                        <button type="submit" name="sac_save_canned" class="button button-primary">Lưu lại</button>
                        <?php if($edit_data): ?>
                            <a href="?page=soft-ai-canned-responses" class="button">Hủy</a>
                        <?php endif; ?>
                    </p>
                </form>
            </div>

            <div style="flex:1;">
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th width="150">Shortcut</th>
                            <th>Nội dung</th>
                            <th width="120">Hành động</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if($responses): foreach($responses as $r): ?>
                        <tr>
                            <td><strong><?php echo esc_html($r->shortcut); ?></strong></td>
                            <td><?php echo nl2br(esc_html($r->content)); ?></td>
                            <td>
                                <a href="?page=soft-ai-canned-responses&action=edit&id=<?php echo $r->id; ?>" class="button button-small">Sửa</a>
                                <a href="?page=soft-ai-canned-responses&action=delete&id=<?php echo $r->id; ?>" class="button button-small button-link-delete" onclick="return confirm('Xóa?')">Xóa</a>
                            </td>
                        </tr>
                        <?php endforeach; else: echo '<tr><td colspan="3">Chưa có câu mẫu nào.</td></tr>'; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php
}

// ---------------------------------------------------------
// 1.5. LIVE CHAT PAGE (UPDATED WITH CANNED RESPONSES UI)
// ---------------------------------------------------------

function soft_ai_live_chat_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <style>
        /* Toggle Switch CSS */
        .sac-switch { position: relative; display: inline-block; width: 50px; height: 24px; vertical-align: middle; margin-left: 10px; }
        .sac-switch input { opacity: 0; width: 0; height: 0; }
        .sac-slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; -webkit-transition: .4s; transition: .4s; border-radius: 24px; }
        .sac-slider:before { position: absolute; content: ""; height: 16px; width: 16px; left: 4px; bottom: 4px; background-color: white; -webkit-transition: .4s; transition: .4s; border-radius: 50%; }
        input:checked + .sac-slider { background-color: #0073aa; }
        input:focus + .sac-slider { box-shadow: 0 0 1px #0073aa; }
        input:checked + .sac-slider:before { -webkit-transform: translateX(26px); -ms-transform: translateX(26px); transform: translateX(26px); }
        .sac-mode-label { font-size: 13px; font-weight: normal; margin-left: 5px; color: #555; }

        /* Canned Responses UI */
        #sac-canned-popup { 
            display: none; position: absolute; bottom: 60px; left: 15px; width: 400px; 
            background: white; border: 1px solid #ccc; box-shadow: 0 -5px 20px rgba(0,0,0,0.1); 
            border-radius: 8px; z-index: 100; overflow: hidden;
        }
        #sac-canned-search { width: 100%; border: none; border-bottom: 1px solid #eee; padding: 10px; box-sizing: border-box; outline: none; }
        #sac-canned-list { max-height: 250px; overflow-y: auto; }
        .sac-canned-item { padding: 8px 12px; border-bottom: 1px solid #f9f9f9; cursor: pointer; font-size: 13px; }
        .sac-canned-item:hover { background: #f0f7fd; color: #0073aa; }
        .sac-canned-shortcut { font-weight: bold; color: #555; display: inline-block; width: 80px; }

        /* Auto Suggestion Chips */
        #sac-suggestions { 
            padding: 8px 15px; 
            background: #fffbe6; 
            display: none; 
            border-top: 1px solid #ffe58f; 
            white-space: nowrap; 
            overflow-x: auto; 
            position: absolute;
            bottom: 100%;
            left: 0;
            right: 0;
            z-index: 99;
        }
        .sac-suggest-chip { 
            display: inline-flex; align-items: center;
            background: #fff7e6; color: #d46b08; border: 1px solid #ffd591; 
            padding: 4px 10px; border-radius: 15px; font-size: 12px; 
            margin-right: 8px; cursor: pointer; 
            transition: all 0.2s;
        }
        .sac-suggest-chip:hover { background: #ffc069; color: #fff; border-color: #fa8c16; }
        .sac-suggest-label { font-weight:bold; margin-right: 5px; }
    </style>

    <div class="wrap" style="height: auto; display: flex; flex-direction: column;">
        <h1 style="margin-bottom: 20px;">🔴 Live Chat (Human Support)</h1>
        
        <div style="display: flex; flex: 1; gap: 20px; height: 100%; overflow: hidden;">
            <div style="height: 100vh; width: 250px; background: #fff; border: 1px solid #ccd0d4; overflow-y: auto;" id="sac-admin-sessions">
                <div style="padding: 10px; border-bottom: 1px solid #eee; font-weight: bold; background: #f8f9fa;">Recent Users</div>
                <div id="sac-session-list">
                    <div style="padding:20px; text-align:center; color:#999;">Loading...</div>
                </div>
            </div>

            <div style="height: 100vh; flex: 1; display: flex; flex-direction: column; background: #fff; border: 1px solid #ccd0d4; position: relative;">
                <div style="padding: 10px 20px; border-bottom: 1px solid #eee; background: #f0f0f1; font-weight: bold; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <span id="sac-current-user-title">Select a user to chat</span>
                        <span id="sac-mode-control" style="display:none; margin-left: 20px; border-left: 1px solid #ccc; padding-left: 15px;">
                            <label class="sac-switch">
                                <input type="checkbox" id="sac-mode-toggle" onchange="toggleAiMode()">
                                <span class="sac-slider"></span>
                            </label>
                            <span class="sac-mode-label" id="sac-mode-text">AI Auto-Bot</span>
                        </span>
                    </div>
                    <div style="display:flex; gap: 10px;">
                        <button class="button button-small" style="color: #a00; border-color: #a00;" id="sac-delete-btn" onclick="deleteConversation()" style="display:none;">🗑️ Delete History</button>
                        <button class="button button-small" onclick="loadLiveSessions()">Refresh Users</button>
                    </div>
                </div>
                
                <div id="sac-admin-messages" style="flex: 1; padding: 20px; overflow-y: auto; background: #f6f7f7;">
                    <div style="text-align:center; color:#aaa; margin-top: 50px;">Select a user from the left to start chatting.</div>
                </div>

                <div style="padding: 15px; background: #fff; border-top: 1px solid #ddd; display: flex; gap: 10px; position: relative;">
                    <div id="sac-suggestions"></div>

                    <div id="sac-canned-popup">
                        <input type="text" id="sac-canned-search" placeholder="🔍 Tìm câu mẫu (gõ từ khóa)..." onkeyup="filterCanned()">
                        <div id="sac-canned-list">Fetching...</div>
                    </div>

                    <button class="button" onclick="toggleCanned()" title="Câu trả lời mẫu">⚡ Câu mẫu</button>
                    <input type="text" id="sac-admin-input" placeholder="Type your reply..." style="flex: 1;" onkeypress="if(event.key==='Enter') sendAdminReply()">
                    <button class="button button-primary" onclick="sendAdminReply()">Send</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    var currentChatIp = null;
    var adminPollInterval = null;
    var allCannedMsgs = [];

    function loadLiveSessions() {
        jQuery.get(ajaxurl, { action: 'sac_get_sessions' }, function(response) {
            if(response.success) {
                var html = '';
                response.data.forEach(function(sess) {
                    var activeClass = (currentChatIp === sess.ip) ? 'background:#e6f7ff;' : '';
                    var badge = sess.unread > 0 ? '<span style="background:red; color:white; border-radius:50%; padding:2px 6px; font-size:10px; margin-left:5px;">'+sess.unread+'</span>' : '';
                    html += '<div onclick="openAdminChat(\''+sess.ip+'\')" style="padding: 12px; border-bottom: 1px solid #eee; cursor: pointer; '+activeClass+'">';
                    html += '<strong>' + sess.ip + '</strong>'+badge+'<br><small style="color:#666">' + sess.time + '</small>';
                    html += '</div>';
                });
                jQuery('#sac-session-list').html(html);
            }
        });
    }

    function openAdminChat(ip) {
        currentChatIp = ip;
        jQuery('#sac-current-user-title').text('Chatting with: ' + ip);
        jQuery('#sac-mode-control').show(); 
        jQuery('#sac-delete-btn').show(); 
        
        // Ensure canned msgs are loaded for auto-suggest
        if(allCannedMsgs.length === 0) {
            jQuery.get(ajaxurl, { action: 'sac_get_canned_msgs' }, function(res) {
                if(res.success) allCannedMsgs = res.data;
            });
        }

        loadLiveMessages();
        loadLiveSessions(); 
        
        if(adminPollInterval) clearInterval(adminPollInterval);
        adminPollInterval = setInterval(loadLiveMessages, 3000);
    }

    function loadLiveMessages() {
        if(!currentChatIp) return;
        jQuery.get(ajaxurl, { action: 'sac_get_messages', ip: currentChatIp }, function(response) {
            if(response.success) {
                var html = '';
                var lastUserMsg = '';

                response.data.messages.forEach(function(msg) {
                    var align = msg.is_admin ? 'text-align:right;' : 'text-align:left;';
                    var bg = msg.is_admin ? 'background:#0073aa; color:white;' : 'background:#e5e5e5; color:#333;';
                    html += '<div style="margin-bottom: 10px; ' + align + '">';
                    html += '<div style="display:inline-block; padding: 8px 12px; border-radius: 15px; max-width: 70%; ' + bg + '">' + msg.content + '</div>';
                    html += '<div style="font-size:10px; color:#999; margin-top:2px;">' + msg.time + '</div>';
                    html += '</div>';
                    
                    // Capture last user message for auto-suggest
                    if(!msg.is_admin) lastUserMsg = msg.content; 
                });
                var container = document.getElementById('sac-admin-messages');
                container.innerHTML = html;
                
                // Auto Suggest Check
                // checkAutoSuggestions(lastUserMsg);

                var isLive = response.data.is_live; 
                var toggle = document.getElementById('sac-mode-toggle');
                var label = document.getElementById('sac-mode-text');
                
                toggle.checked = isLive;
                label.innerText = isLive ? "🔴 Live Chat Mode (AI OFF)" : "🤖 AI Auto-Bot (AI ON)";
                label.style.color = isLive ? "#d63031" : "#555";
                label.style.fontWeight = isLive ? "bold" : "normal";
            }
        });
    }

    // 1. Lắng nghe sự kiện gõ phím của Admin
    jQuery('#sac-admin-input').on('keyup', function() {
        var currentInput = jQuery(this).val();
        checkAutoSuggestions(currentInput); // Kiểm tra gợi ý dựa trên nội dung đang gõ
    });

    function checkAutoSuggestions(msg) {
        var container = document.getElementById('sac-suggestions');
        // Nếu ô nhập liệu trống hoặc không có danh sách câu mẫu thì ẩn gợi ý
        if (!msg || allCannedMsgs.length === 0) {
            container.style.display = 'none';
            return;
        }

        var found = [];
        var lowerMsg = msg.toLowerCase();

        // Duyệt qua tất cả câu mẫu
        allCannedMsgs.forEach(function(item) {
            // Nếu nội dung Admin đang gõ có chứa shortcut của câu mẫu
            if (lowerMsg.includes(item.shortcut.toLowerCase())) {
                found.push(item);
            }
        });

        if (found.length > 0) {
            var html = '';
            found.forEach(function(item) {
                // Xử lý chuỗi để tránh lỗi khi chèn vào thuộc tính onclick
                var safeContent = item.content.replace(/"/g, '&quot;').replace(/'/g, "\\'");
                
                // Hiển thị toàn bộ nội dung câu mẫu
                html += `<div class="sac-suggest-chip" onclick="insertCanned('${safeContent}')" style="max-width: 400px; white-space: normal; height: auto; padding: 8px 12px; border-radius: 8px;">
                    <span class="sac-suggest-label" style="display:block;">💡 Khớp từ tắt [${item.shortcut}]:</span>
                    <div style="font-size: 13px; line-height: 1.4;">${item.content}</div>
                </div>`;
            });
            container.innerHTML = html;
            container.style.display = 'flex'; // Sử dụng flex để các chip trông gọn hơn
            container.style.flexWrap = 'wrap';
        } else {
            container.style.display = 'none';
        }
    }

    function toggleAiMode() {
        if(!currentChatIp) return;
        var isChecked = document.getElementById('sac-mode-toggle').checked;
        var newMode = isChecked ? 'live' : 'ai';
        jQuery.post(ajaxurl, { action: 'sac_toggle_mode', ip: currentChatIp, mode: newMode }, function(response) { loadLiveMessages(); });
    }

    function deleteConversation() {
        if(!currentChatIp) return;
        if(!confirm('Are you sure you want to delete ALL history with ' + currentChatIp + '? This cannot be undone.')) return;
        jQuery.post(ajaxurl, { action: 'sac_delete_conversation', ip: currentChatIp }, function(response) {
            if(response.success) {
                currentChatIp = null;
                jQuery('#sac-admin-messages').html('<div style="text-align:center; color:#aaa; margin-top: 50px;">Conversation deleted. Select another user.</div>');
                jQuery('#sac-current-user-title').text('Select a user to chat');
                jQuery('#sac-mode-control').hide();
                jQuery('#sac-delete-btn').hide();
                document.getElementById('sac-suggestions').style.display = 'none';
                if(adminPollInterval) clearInterval(adminPollInterval);
                loadLiveSessions();
            } else { alert('Error deleting conversation.'); }
        });
    }

    function sendAdminReply() {
        var txt = jQuery('#sac-admin-input').val().trim();
        if(!txt || !currentChatIp) return;
        jQuery('#sac-admin-input').val(''); 
        document.getElementById('sac-suggestions').style.display = 'none'; // Hide suggestion after sending
        jQuery.post(ajaxurl, { action: 'sac_send_reply', ip: currentChatIp, message: txt }, function(response) { loadLiveMessages(); });
    }

    // --- Canned Responses Logic ---
    function toggleCanned() {
        var p = document.getElementById('sac-canned-popup');
        if(p.style.display === 'block') { p.style.display = 'none'; return; }
        p.style.display = 'block';
        document.getElementById('sac-canned-search').focus();
        
        if(allCannedMsgs.length === 0) {
            jQuery.get(ajaxurl, { action: 'sac_get_canned_msgs' }, function(res) {
                if(res.success) {
                    allCannedMsgs = res.data;
                    renderCannedList(allCannedMsgs);
                }
            });
        } else {
            renderCannedList(allCannedMsgs);
        }
    }

    function renderCannedList(list) {
        var html = '';
        list.forEach(function(item) {
            // Encode content to allow safe passing to function
            var safeContent = item.content.replace(/"/g, '&quot;').replace(/'/g, "\\'");
            html += `<div class="sac-canned-item" onclick="insertCanned('${safeContent}')">
                <span class="sac-canned-shortcut">[${item.shortcut}]</span> ${item.content.substring(0, 40)}...
            </div>`;
        });
        if(list.length === 0) html = '<div style="padding:10px;color:#999;">Không tìm thấy.</div>';
        document.getElementById('sac-canned-list').innerHTML = html;
    }

    function filterCanned() {
        var q = document.getElementById('sac-canned-search').value.toLowerCase();
        var filtered = allCannedMsgs.filter(function(item) {
            return item.shortcut.toLowerCase().includes(q) || item.content.toLowerCase().includes(q);
        });
        renderCannedList(filtered);
    }

    function insertCanned(text) {
        // Decode simple entities if necessary, but here we just pass pure text
        var decoded = text.replace(/&quot;/g, '"');
        jQuery('#sac-admin-input').val(decoded);
        document.getElementById('sac-canned-popup').style.display = 'none';
        document.getElementById('sac-suggestions').style.display = 'none';
        document.getElementById('sac-admin-input').focus();
    }

    // Close popup if clicking outside
    jQuery(document).on('click', function(e) {
        if (!jQuery(e.target).closest('#sac-canned-popup, button[onclick="toggleCanned()"]').length) {
            jQuery('#sac-canned-popup').hide();
        }
    });

    jQuery(document).ready(function(){
        jQuery('#sac-delete-btn').hide(); 
        // Preload canned messages
        jQuery.get(ajaxurl, { action: 'sac_get_canned_msgs' }, function(res) {
            if(res.success) allCannedMsgs = res.data;
        });
        loadLiveSessions();
        setInterval(loadLiveSessions, 10000); 
    });
    </script>
    <?php
}

// ---------------------------------------------------------
// 1.6. ADMIN AJAX HANDLERS (UPDATED)
// ---------------------------------------------------------

add_action('wp_ajax_sac_get_sessions', 'soft_ai_ajax_get_sessions');
add_action('wp_ajax_sac_get_messages', 'soft_ai_ajax_get_messages');
add_action('wp_ajax_sac_send_reply', 'soft_ai_ajax_send_reply');
add_action('wp_ajax_sac_toggle_mode', 'soft_ai_ajax_toggle_mode');
add_action('wp_ajax_sac_delete_conversation', 'soft_ai_ajax_delete_conversation');
add_action('wp_ajax_sac_get_canned_msgs', 'soft_ai_ajax_get_canned_msgs'); // NEW

function soft_ai_ajax_get_sessions() {
    global $wpdb;
    $table = $wpdb->prefix . 'soft_ai_chat_logs';
    $results = $wpdb->get_results("
        SELECT user_ip as ip, MAX(time) as latest_time, 
        SUM(CASE WHEN is_read = 0 AND provider != 'live_admin' THEN 1 ELSE 0 END) as unread
        FROM $table 
        WHERE time > NOW() - INTERVAL 24 HOUR 
        GROUP BY user_ip 
        ORDER BY latest_time DESC
    ");
    $data = [];
    foreach($results as $r) {
        $data[] = ['ip' => $r->ip, 'time' => date('H:i', strtotime($r->latest_time)), 'unread' => $r->unread];
    }
    wp_send_json_success($data);
}

function soft_ai_ajax_get_messages() {
    global $wpdb;
    $ip = sanitize_text_field($_GET['ip']);
    $table = $wpdb->prefix . 'soft_ai_chat_logs';
    $wpdb->update($table, ['is_read' => 1], ['user_ip' => $ip]);
    $context = new Soft_AI_Context($ip, 'widget'); 
    $is_live = $context->get('live_chat_mode');
    $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE user_ip = %s ORDER BY time ASC LIMIT 100", $ip));
    
    $messages = [];
    foreach($logs as $log) {
        $is_admin = ($log->provider === 'live_admin');
        $content = $is_admin ? $log->answer : $log->question;
        if ($log->provider == 'live_user') { $content = $log->question; $is_admin = false; } 
        elseif ($log->provider == 'live_admin') { $content = $log->answer; $is_admin = true; } 
        elseif (!empty($log->answer) && !empty($log->question)) {
             $messages[] = ['content' => $log->question, 'is_admin' => false, 'time' => date('H:i', strtotime($log->time))];
             $messages[] = ['content' => $log->answer, 'is_admin' => true, 'time' => date('H:i', strtotime($log->time))];
             continue;
        }
        $messages[] = ['content' => $content, 'is_admin' => $is_admin, 'time' => date('H:i', strtotime($log->time))];
    }
    wp_send_json_success(['messages' => $messages, 'is_live' => (bool)$is_live]);
}

function soft_ai_ajax_toggle_mode() {
    $ip = sanitize_text_field($_POST['ip']);
    $mode = sanitize_text_field($_POST['mode']);
    if (!$ip) wp_send_json_error();
    $context = new Soft_AI_Context($ip, 'widget');
    $context->set('live_chat_mode', ($mode === 'live'));
    wp_send_json_success();
}

function soft_ai_ajax_delete_conversation() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
    global $wpdb;
    $ip = sanitize_text_field($_POST['ip']);
    $table = $wpdb->prefix . 'soft_ai_chat_logs';
    $wpdb->delete($table, ['user_ip' => $ip]);
    wp_send_json_success();
}

function soft_ai_ajax_send_reply() {
    global $wpdb;
    $ip = sanitize_text_field($_POST['ip']);
    $msg = sanitize_text_field($_POST['message']);
    if (!$ip || !$msg) wp_send_json_error();
    $wpdb->insert($wpdb->prefix . 'soft_ai_chat_logs', [
        'time' => current_time('mysql'), 'user_ip' => $ip, 'provider' => 'live_admin', 'model' => 'human',
        'question' => '', 'answer' => $msg, 'source' => 'widget', 'is_read' => 1
    ]);
    $context = new Soft_AI_Context($ip, 'widget');
    $context->set('live_chat_mode', true);
    wp_send_json_success();
}

// NEW AJAX HANDLER FOR CANNED MESSAGES
function soft_ai_ajax_get_canned_msgs() {
    global $wpdb;
    $table = $wpdb->prefix . 'soft_ai_canned_msgs';
    $results = $wpdb->get_results("SELECT id, shortcut, content FROM $table ORDER BY shortcut ASC");
    wp_send_json_success($results);
}


// ---------------------------------------------------------
// 1.7. HISTORY PAGE (REDESIGNED: CONVERSATION VIEW + MARKDOWN)
// ---------------------------------------------------------

function soft_ai_chat_history_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'soft_ai_chat_logs';

    // Xóa toàn bộ
    if (isset($_POST['clear_all_logs']) && check_admin_referer('clear_all_logs')) {
        $wpdb->query("TRUNCATE TABLE $table_name");
        echo '<div class="updated"><p>All logs cleared.</p></div>';
    }

    // Xóa 1 thread (Conversation)
    if (isset($_POST['delete_thread']) && isset($_POST['thread_ip']) && check_admin_referer('delete_thread_' . $_POST['thread_ip'])) {
        $del_ip = sanitize_text_field($_POST['thread_ip']);
        $wpdb->delete($table_name, ['user_ip' => $del_ip]);
        echo '<div class="updated"><p>Conversation deleted.</p></div>';
    }

    // XEM CHI TIẾT 1 CUỘC TRÒ CHUYỆN
    $view_ip = isset($_GET['view_ip']) ? sanitize_text_field($_GET['view_ip']) : null;
    
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Chat History (Logs)</h1>
        
        <?php if(!$view_ip): ?>
            <form method="post" style="display:inline-block; margin-left:10px;">
                <?php wp_nonce_field('clear_all_logs'); ?>
                <input type="hidden" name="clear_all_logs" value="1">
                <button type="submit" class="page-title-action" style="border:1px solid #d63638; color:#d63638; background:white; cursor:pointer;" onclick="return confirm('Delete ALL logs?')">Clear All History</button>
            </form>
        <?php else: ?>
            <a href="<?php echo admin_url('admin.php?page=soft-ai-chat-history'); ?>" class="page-title-action">← Back to List</a>
        <?php endif; ?>

        <hr class="wp-header-end">

        <style>
            /* Base Styles */
            .sac-container { margin-top: 20px; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
            
            /* List View Styles */
            .sac-thread-list { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); border-radius: 4px; overflow: hidden; }
            .sac-thread-item { display: flex; align-items: center; padding: 15px 20px; border-bottom: 1px solid #f0f0f1; transition: background 0.2s; position: relative; }
            .sac-thread-item:last-child { border-bottom: none; }
            .sac-thread-item:hover { background: #f6f7f7; }
            
            .sac-avatar { width: 45px; height: 45px; background: #e0e0e0; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #777; font-size: 18px; margin-right: 15px; }
            .sac-thread-info { flex: 1; }
            .sac-ip-title { font-size: 16px; font-weight: 600; color: #1d2327; margin-bottom: 4px; display: block; text-decoration: none; }
            .sac-ip-title:hover { color: #2271b1; }
            .sac-thread-meta { font-size: 13px; color: #646970; }
            .sac-badge { background: #f0f0f1; color: #50575e; padding: 2px 8px; border-radius: 12px; font-size: 11px; margin-left: 5px; font-weight: 500; border: 1px solid #dcdcde; }
            
            .sac-action-btn { margin-left: 10px; text-decoration: none; padding: 6px 12px; border: 1px solid #2271b1; color: #2271b1; border-radius: 4px; font-size: 13px; transition: all 0.2s; }
            .sac-action-btn:hover { background: #2271b1; color: #fff; }
            .sac-del-btn { border-color: #d63638; color: #d63638; background: transparent; cursor: pointer; }
            .sac-del-btn:hover { background: #d63638; color: #fff; }

            /* Pagination */
            .sac-pagination { margin-top: 20px; display: flex; justify-content: center; gap: 5px; }
            .sac-page-num { padding: 5px 10px; border: 1px solid #ddd; background: #fff; text-decoration: none; color: #333; border-radius: 3px; }
            .sac-page-num.current { background: #2271b1; color: #fff; border-color: #2271b1; }

            /* Chat Detail View (Bubbles) */
            .sac-chat-window { max-width: 800px; margin: 0 auto; background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
            .sac-chat-header { background: #f8f9fa; padding: 15px 20px; border-bottom: 1px solid #eee; display: flex; justify-content: space-between; align-items: center; }
            .sac-chat-body { padding: 20px; background: #fff; max-height: 70vh; overflow-y: auto; display: flex; flex-direction: column; gap: 15px; }
            
            .sac-bubble-row { display: flex; width: 100%; margin-bottom: 10px; }
            .sac-bubble-row.user { justify-content: flex-end; }
            .sac-bubble-row.bot { justify-content: flex-start; }
            
            .sac-bubble { max-width: 70%; padding: 10px 15px; border-radius: 18px; position: relative; font-size: 14px; line-height: 1.5; word-wrap: break-word; }
            .sac-bubble-row.user .sac-bubble { background: #0073aa; color: #fff; border-bottom-right-radius: 4px; }
            .sac-bubble-row.bot .sac-bubble { background: #f0f0f1; color: #1d2327; border-bottom-left-radius: 4px; border: 1px solid #e5e5e5; }
            .sac-bubble-row.bot .sac-bubble strong { color: #0073aa; }
            .sac-bubble-row.bot .sac-bubble img { max-width: 100%; height: auto; border-radius: 8px; margin-top: 5px; }
            .sac-bubble-row.admin .sac-bubble { background: #e6f7ff; border: 1px solid #91d5ff; color: #0050b3; } /* For Live Chat Replies */

            .sac-time { font-size: 11px; color: #888; margin-top: 4px; display: block; opacity: 0.8; }
            .sac-bubble-row.user .sac-time { text-align: right; color: rgba(255,255,255,0.8); }

        </style>

        <div class="sac-container">
            <?php 
            if ($view_ip): 
                // --- VIEW DETAIL (TRANSCRIPT) ---
                $logs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE user_ip = %s ORDER BY time ASC", $view_ip));
                
                // Load Marked.js for rendering
                echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/marked/11.1.1/marked.min.js"></script>';
                ?>
                <div class="sac-chat-window">
                    <div class="sac-chat-header">
                        <h2 style="margin:0; font-size: 16px;">Chat with: <strong><?php echo esc_html($view_ip); ?></strong></h2>
                        <form method="post" style="margin:0;">
                             <?php wp_nonce_field('delete_thread_' . $view_ip); ?>
                             <input type="hidden" name="delete_thread" value="1">
                             <input type="hidden" name="thread_ip" value="<?php echo esc_attr($view_ip); ?>">
                             <button type="submit" class="button button-link-delete" onclick="return confirm('Delete this conversation?')">Delete Thread</button>
                        </form>
                    </div>
                    <div class="sac-chat-body">
                        <?php if ($logs): foreach ($logs as $log): 
                            // Determine types to show properly
                            $is_admin_reply = ($log->provider === 'live_admin');
                            
                            // User Message
                            if (!empty($log->question) && $log->provider !== 'live_admin') {
                                $qid = 'q-' . $log->id;
                                echo '<div class="sac-bubble-row user">
                                        <div class="sac-bubble">
                                            <div id="'.$qid.'"></div>
                                            <span class="sac-time">' . date('H:i, d/M', strtotime($log->time)) . '</span>
                                        </div>
                                        <textarea id="raw-'.$qid.'" style="display:none;">'.esc_textarea($log->question).'</textarea>
                                        <script>
                                            document.getElementById("'.$qid.'").innerHTML = marked.parse(document.getElementById("raw-'.$qid.'").value);
                                        </script>
                                      </div>';
                            }
                            
                            // Bot/Admin Response
                            if (!empty($log->answer)) {
                                $cls = $is_admin_reply ? 'admin' : 'bot';
                                $aid = 'a-' . $log->id;
                                // We keep raw HTML/Markdown here
                                $raw_answer = $log->answer; 
                                
                                echo '<div class="sac-bubble-row bot '.$cls.'">
                                        <div class="sac-bubble">
                                             <div id="'.$aid.'"></div>
                                             <span class="sac-time">' . date('H:i, d/M', strtotime($log->time)) . '</span>
                                        </div>
                                        <textarea id="raw-'.$aid.'" style="display:none;">'.esc_textarea($raw_answer).'</textarea>
                                        <script>
                                            var raw = document.getElementById("raw-'.$aid.'").value;
                                            document.getElementById("'.$aid.'").innerHTML = marked.parse(raw);
                                        </script>
                                      </div>';
                            }
                        endforeach; else: echo '<p style="text-align:center; color:#999;">No messages found.</p>'; endif; ?>
                    </div>
                </div>

            <?php else: 
                // --- VIEW LIST (GROUP BY IP) ---
                // Pagination Logic
                $per_page = 15;
                $paged = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
                $offset = ($paged - 1) * $per_page;
                
                // Count unique IPs
                $total_threads = $wpdb->get_var("SELECT COUNT(DISTINCT user_ip) FROM $table_name");
                $total_pages = ceil($total_threads / $per_page);
                
                // Get Grouped Data
                $threads = $wpdb->get_results($wpdb->prepare("
                    SELECT user_ip, MAX(time) as last_time, COUNT(*) as msg_count 
                    FROM $table_name 
                    GROUP BY user_ip 
                    ORDER BY last_time DESC 
                    LIMIT %d OFFSET %d
                ", $per_page, $offset));
                ?>
                
                <div class="sac-thread-list">
                    <?php if ($threads): foreach ($threads as $th): 
                        $first_char = strtoupper(substr($th->user_ip, 0, 1));
                        $is_numeric_ip = filter_var($th->user_ip, FILTER_VALIDATE_IP);
                        $display_name = $is_numeric_ip ? "Visitor (" . $th->user_ip . ")" : $th->user_ip;
                        $time_ago = human_time_diff(strtotime($th->last_time), current_time('timestamp')) . ' ago';
                    ?>
                    <div class="sac-thread-item">
                        <div class="sac-avatar"><?php echo $is_numeric_ip ? '🌐' : '👤'; ?></div>
                        <div class="sac-thread-info">
                            <a href="?page=soft-ai-chat-history&view_ip=<?php echo urlencode($th->user_ip); ?>" class="sac-ip-title">
                                <?php echo esc_html($display_name); ?>
                            </a>
                            <div class="sac-thread-meta">
                                Last active: <?php echo $time_ago; ?> 
                                <span class="sac-badge"><?php echo $th->msg_count; ?> msgs</span>
                            </div>
                        </div>
                        <div style="display:flex; gap:5px;">
                            <a href="?page=soft-ai-chat-history&view_ip=<?php echo urlencode($th->user_ip); ?>" class="sac-action-btn">View Chat</a>
                            <form method="post" style="display:inline;">
                                <?php wp_nonce_field('delete_thread_' . $th->user_ip); ?>
                                <input type="hidden" name="delete_thread" value="1">
                                <input type="hidden" name="thread_ip" value="<?php echo esc_attr($th->user_ip); ?>">
                                <button type="submit" class="sac-action-btn sac-del-btn" onclick="return confirm('Delete this conversation?')">×</button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; else: ?>
                        <div style="padding:40px; text-align:center; color:#999;">No chat history found.</div>
                    <?php endif; ?>
                </div>

                <?php if ($total_pages > 1): ?>
                <div class="sac-pagination-wrapper">
                    <div class="sac-pagination">
                        <?php 
                        echo paginate_links([
                            'base' => add_query_arg('paged', '%#%'),
                            'total' => $total_pages,
                            'current' => $paged,
                            'prev_text' => '«',
                            'next_text' => '»'
                        ]); 
                        ?>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>
    </div>
    <?php
}

// ---------------------------------------------------------
// 2. CONTEXT & STATE MANAGER
// ---------------------------------------------------------

class Soft_AI_Context {
    public $user_id;
    public $source;
    
    public function __construct($user_id, $source) {
        $this->user_id = $user_id;
        $this->source = $source;
    }

    public function get($key) {
        if ($this->source === 'widget') {
            return (function_exists('WC') && WC()->session) ? WC()->session->get('soft_ai_' . $key) : null;
        } else {
            $data = get_transient('soft_ai_sess_' . $this->user_id);
            return isset($data[$key]) ? $data[$key] : null;
        }
    }
    
    public function set($key, $val) {
        if ($this->source === 'widget') {
            if (function_exists('WC') && WC()->session) WC()->session->set('soft_ai_' . $key, $val);
        } else {
            $data = get_transient('soft_ai_sess_' . $this->user_id) ?: [];
            $data[$key] = $val;
            set_transient('soft_ai_sess_' . $this->user_id, $data, 24 * HOUR_IN_SECONDS);
        }
    }

    public function add_to_cart($product_id, $qty = 1, $variation_id = 0, $variation = []) {
        if ($this->source === 'widget' && function_exists('WC')) {
            WC()->cart->add_to_cart($product_id, $qty, $variation_id, $variation);
        } else {
            $cart = $this->get('cart') ?: [];
            $cart_key = $variation_id ? $variation_id : $product_id;
            
            if (isset($cart[$cart_key])) {
                $cart[$cart_key]['qty'] += $qty;
            } else {
                $cart[$cart_key] = [
                    'qty' => $qty, 
                    'product_id' => $product_id, 
                    'variation_id' => $variation_id,
                    'variation' => $variation 
                ];
            }
            $this->set('cart', $cart);
        }
    }

    public function empty_cart() {
        if ($this->source === 'widget' && function_exists('WC')) WC()->cart->empty_cart();
        else {
            $this->set('cart', []);
            $this->set('coupons', []); // Clear coupons on empty
        }
    }

    public function get_cart_count() {
        if ($this->source === 'widget' && function_exists('WC')) return WC()->cart->get_cart_contents_count();
        else {
            $c = 0; $cart = $this->get('cart') ?: [];
            foreach($cart as $i) $c += $i['qty'];
            return $c;
        }
    }

    public function get_cart_total_string() {
        if ($this->source === 'widget' && function_exists('WC')) return WC()->cart->get_cart_total();
        else {
            $total = 0; $cart = $this->get('cart') ?: [];
            foreach($cart as $pid => $item) {
                $p = function_exists('wc_get_product') ? wc_get_product($pid) : null;
                if($p) $total += ($p->get_price() * $item['qty']);
            }
            return function_exists('wc_price') ? wc_price($total) : number_format($total) . 'đ';
        }
    }
}

// ---------------------------------------------------------
// 3. CORE LOGIC
// ---------------------------------------------------------

function soft_ai_clean_content($content) {
    if (!is_string($content)) return '';
    $content = strip_shortcodes($content);
    $content = preg_replace('/\[\/?et_pb_[^\]]+\]/', '', $content);
    $content = wp_strip_all_tags($content);
    $content = preg_replace('/\s+/', ' ', $content);
    return mb_substr(trim($content), 0, 1500);
}

function soft_ai_chat_get_context($question) {
    $args = ['post_type' => ['post', 'page', 'product'], 'post_status' => 'publish', 'posts_per_page' => 4, 's' => $question, 'orderby' => 'relevance'];
    $posts = get_posts($args);

    $context = "";
    if ($posts) {
        foreach ($posts as $post) {
            $info = "";
            if ($post->post_type === 'product' && function_exists('wc_get_product')) {
                $p = wc_get_product($post->ID);
                if ($p) $info = " | Price: " . $p->get_price_html() . " | Status: " . $p->get_stock_status();
            }
            $clean_body = soft_ai_clean_content($post->post_content);
            $context .= "--- Source: {$post->post_title} ---\nLink: " . get_permalink($post->ID) . $info . "\nContent: $clean_body\n\n";
        }
    }
    return $context ?: "No specific website content found for this query.";
}

function soft_ai_log_chat($question, $answer, $source = 'widget', $provider_override = '', $model_override = '') {
    global $wpdb;
    $opt = get_option('soft_ai_chat_settings');
    
    // Nếu là Live Chat messages, luôn lưu. Nếu AI chat thường, check setting.
    $is_live = (strpos($provider_override, 'live_') !== false);
    if (empty($opt['save_history']) && !$is_live) return;

    $wpdb->insert($wpdb->prefix . 'soft_ai_chat_logs', [
        'time' => current_time('mysql'),
        'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
        'provider' => $provider_override ?: ($opt['provider'] ?? 'unknown'),
        'model' => $model_override ?: ($opt['model'] ?? 'unknown'),
        'question' => $question,
        'answer' => $answer,
        'source' => $source
    ]);
}

function soft_ai_clean_text_for_social($content) {
    $content = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $content = preg_replace('/^\|?\s*[-:]+\s*(\|\s*[-:]+\s*)+\|?\s*$/m', '', $content);
    $content = preg_replace('/^\|\s*/m', '', $content);
    $content = preg_replace('/\s*\|$/m', '', $content);
    $content = str_replace('|', ' - ', $content);
    $content = preg_replace('/!\[([^\]]*)\]\(([^)]+)\)/', '$2', $content);
    $content = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1: $2', $content);
    $content = str_replace(['**', '__', '`'], '', $content);
    $content = preg_replace('/^#+\s*/m', '', $content);
    return trim(wp_strip_all_tags($content));
}

/**
 * Main AI Engine + State Machine
 */
function soft_ai_generate_answer($question, $platform = 'widget', $user_id = '') {
    global $wpdb;
    if (empty($user_id)) $user_id = get_current_user_id() ?: md5($_SERVER['REMOTE_ADDR']);
    $context = new Soft_AI_Context($user_id, $platform);
    
    // --- 0. CHECK LIVE CHAT STATUS ---
    // Keywords to enter Live Mode
    $human_keywords = ['human', 'người thật', 'nhân viên', 'tư vấn viên', 'gặp người', 'chat với người', 'support', 'live chat'];
    $exit_keywords = ['thoát', 'exit', 'bye', 'bot', 'gặp bot'];
    
    $clean_q = mb_strtolower(trim($question));

    if (in_array($clean_q, $human_keywords)) {
        $context->set('live_chat_mode', true);
        $msg = "Đã chuyển sang chế độ Chat với Nhân Viên 🔴.\nVui lòng nhắn tin, nhân viên sẽ trả lời bạn sớm nhất có thể.";
        // Log this switching event
        soft_ai_log_chat($question, $msg, $platform, 'system_switch');
        return $msg;
    }

    if (in_array($clean_q, $exit_keywords)) {
        $context->set('live_chat_mode', false);
        return "Đã quay lại chế độ AI Bot 🤖. Bạn cần giúp gì không?";
    }

    // If in Live Mode, DO NOT call AI
    if ($context->get('live_chat_mode')) {
        // Save user message to DB so Admin can see it
        soft_ai_log_chat($question, '', $platform, 'live_user', 'human');
        // Return null/empty tells the controller to NOT reply with AI, just wait.
        // But for UX, we might want to return nothing to widget, just "sent".
        return "[WAIT_FOR_HUMAN]"; 
    }

    // --- 0.5. CHECK CANNED RESPONSES (NEW FEATURE) ---
    // Kiểm tra xem câu hỏi có khớp với Shortcut nào không (Exact match hoặc Contain)
    $table_canned = $wpdb->prefix . 'soft_ai_canned_msgs';
    $canned_match = $wpdb->get_row($wpdb->prepare(
        "SELECT content FROM $table_canned WHERE LOWER(shortcut) = %s OR %s LIKE CONCAT('%%', LOWER(shortcut), '%%') LIMIT 1",
        $clean_q, $clean_q
    ));

    if ($canned_match) {
        $msg = $canned_match->content;
        // Nếu là Social, dọn dẹp markdown
        if ($platform === 'facebook' || $platform === 'zalo') {
            return soft_ai_clean_text_for_social($msg);
        }
        return $msg;
    }

    // ----------------------------------

    // 1. Flow Interruption (Huỷ bỏ)
    $current_step = $context->get('bot_collecting_info_step');
    $cancel_keywords = ['huỷ', 'hủy', 'cancel', 'thôi', 'stop', 'thoát'];
    if (in_array($clean_q, $cancel_keywords)) {
        $context->set('bot_collecting_info_step', null);
        return "Đã hủy thao tác hiện tại. Mình có thể giúp gì khác không?";
    }

    // 2. Handle Ongoing Steps
    if ($current_step && class_exists('WooCommerce')) {
        $response = soft_ai_handle_ordering_steps($question, $current_step, $context);
        if ($platform === 'facebook' || $platform === 'zalo') {
            return soft_ai_clean_text_for_social($response);
        }
        return $response;
    }

    // 3. Fast-Track Checkout
    $checkout_triggers = ['thanh toán', 'thanh toan', 'xác nhận', 'xac nhan', 'chốt đơn', 'chot don', 'đặt hàng', 'dat hang', 'mua ngay', 'pay'];
    $is_checkout_intent = false;
    foreach ($checkout_triggers as $trigger) {
        if (strpos($clean_q, $trigger) !== false) {
            $is_checkout_intent = true;
            break;
        }
    }

    if ($is_checkout_intent && class_exists('WooCommerce')) {
        $response = soft_ai_process_order_logic(['action' => 'checkout'], $context);
        if ($platform === 'facebook' || $platform === 'zalo') return soft_ai_clean_text_for_social($response);
        return $response;
    }

    // 4. Setup AI
    $options = get_option('soft_ai_chat_settings');
    $provider = $options['provider'] ?? 'groq';
    $model = $options['model'] ?? 'llama-3.3-70b-versatile';
    
    // 5. Prompt Engineering & RAG
    $site_context = soft_ai_chat_get_context($question);
    $user_instruction = $options['system_prompt'] ?? '';
    
    $system_prompt = "You are a helpful AI Consultant for this website.\n" .
                     ($user_instruction ? "Persona: $user_instruction\n" : "") .
                     "Website Content:\n" . $site_context . "\n\n" .
                     "INSTRUCTIONS:\n" . 
                     "1. Answer the user's question based on the Website Content provided.\n" .
                     "2. If the user asks about products, provide information (price, details) and the Link.\n" .
                     "3. Do NOT try to sell, add to cart, or process orders. Just provide information.\n" .
                     "4. Answer in Vietnamese. Keep it concise and friendly.";

    // 6. Call API
    $ai_response = soft_ai_chat_call_api($provider, $model, $system_prompt, $question, $options);
    if (is_wp_error($ai_response)) return "Lỗi hệ thống: " . $ai_response->get_error_message();

    // 7. Clean & Parse JSON
    $clean_response = trim($ai_response);
    if (preg_match('/```json\s*(.*?)\s*```/s', $clean_response, $matches)) {
        $clean_response = $matches[1];
    } elseif (preg_match('/```\s*(.*?)\s*```/s', $clean_response, $matches)) {
        $clean_response = $matches[1];
    }

    $intent = json_decode($clean_response, true);
    
    if (json_last_error() === JSON_ERROR_NONE && isset($intent['action']) && class_exists('WooCommerce')) {
        $response = soft_ai_process_order_logic($intent, $context);
        if ($platform === 'facebook' || $platform === 'zalo') return soft_ai_clean_text_for_social($response);
        return $response;
    }

    // 8. Return Text
    if ($platform === 'facebook' || $platform === 'zalo') {
        return soft_ai_clean_text_for_social($clean_response);
    }
    return $clean_response;
}

// Logic Dispatcher
function soft_ai_process_order_logic($intent, $context) {
    $action = $intent['action'];
    $source = $context->source;

    switch ($action) {
        case 'list_coupons':
            $args = ['post_type' => 'shop_coupon', 'post_status' => 'publish', 'posts_per_page' => 5];
            $coupons = get_posts($args);
            if (empty($coupons)) return "Hiện tại không có mã giảm giá nào ạ.";

            $msg = "Dạ, shop đang có các mã ưu đãi này:\n";
            foreach ($coupons as $c) {
                $code = $c->post_title;
                $wc_coupon = new WC_Coupon($code);
                // Basic validation checks
                if ($wc_coupon->get_usage_limit() > 0 && $wc_coupon->get_usage_count() >= $wc_coupon->get_usage_limit()) continue;
                if ($wc_coupon->get_date_expires() && $wc_coupon->get_date_expires()->getTimestamp() < time()) continue;

                $desc = $c->post_excerpt ? "({$c->post_excerpt})" : "";
                $amount = strip_tags(wc_price($wc_coupon->get_amount()));
                if($wc_coupon->get_discount_type() == 'percent') $amount = $wc_coupon->get_amount() . '%';
                
                $msg .= "- **$code** $desc: Giảm $amount\n";
            }
            return $msg . "\nBạn muốn dùng mã nào cứ nhắn 'Dùng mã [CODE]' nhé!";

        case 'apply_coupon':
            $code = strtoupper(sanitize_text_field($intent['code'] ?? ''));
            if (!$code) return "Bạn chưa nhập mã. Vui lòng nhập mã cụ thể.";

            if ($source === 'widget' && function_exists('WC')) {
                if (!WC()->cart->has_discount($code)) {
                    $res = WC()->cart->apply_coupon($code);
                    if ($res === true) {
                        return "✅ Đã áp dụng mã **$code** thành công! Tổng đơn hiện tại: " . WC()->cart->get_cart_total();
                    } else {
                        return "❌ Mã này không hợp lệ hoặc không áp dụng được cho đơn này ạ.";
                    }
                } else {
                    return "Mã này đã được áp dụng rồi ạ.";
                }
            } else {
                // For Social/Transient users, store for later
                $saved_coupons = $context->get('coupons') ?: [];
                if (!in_array($code, $saved_coupons)) {
                    $saved_coupons[] = $code;
                    $context->set('coupons', $saved_coupons);
                    return "✅ Đã lưu mã **$code**. Sẽ áp dụng khi tạo đơn.";
                }
                return "Mã này đã lưu rồi ạ.";
            }

        case 'list_products':
            $args = ['limit' => 12, 'status' => 'publish', 'orderby' => 'date', 'order' => 'DESC'];
            $products = wc_get_products($args);

            if (empty($products)) return "Dạ hiện tại shop chưa cập nhật sản phẩm lên web ạ.";

            $msg = "Dạ, bên em đang có những sản phẩm nổi bật này ạ:<br>";
            if ($source !== 'widget') $msg = "Dạ, bên em đang có những sản phẩm nổi bật này ạ:\n";

            foreach ($products as $p) {
                $price = $p->get_price_html();
                $name = $p->get_name();
                
                if ($source === 'widget') {
                    $img_id = $p->get_image_id();
                    $img_url = $img_id ? wp_get_attachment_image_url($img_id, 'thumbnail') : wc_placeholder_img_src();
                    $msg .= "
                    <div style='display:flex; align-items:center; gap:10px; margin-top:10px; border:1px solid #f0f0f0; padding:8px; border-radius:8px; background:#fff;'>
                        <img src='{$img_url}' style='width:50px; height:50px; object-fit:cover; border-radius:6px; flex-shrink:0;'>
                        <div style='font-size:13px; line-height:1.4;'>
                            <div style='font-weight:bold; color:#333;'>{$name}</div>
                            <div style='color:#d63031; font-weight:600;'>{$price}</div>
                        </div>
                    </div>";
                } else {
                    $plain_price = strip_tags(wc_price($p->get_price()));
                    $msg .= "- {$name} ({$plain_price})\n";
                }
            }
            $suffix = ($source === 'widget') ? "<br>Bạn quan tâm món nào nhắn tên để em tư vấn nhé!" : "\nBạn quan tâm món nào nhắn tên để em tư vấn nhé!";
            return $msg . $suffix;

        case 'find_product':
            $query = sanitize_text_field($intent['query'] ?? '');
            $products = wc_get_products(['status' => 'publish', 'limit' => 1, 's' => $query]);
            
            if (!empty($products)) {
                $p = $products[0];
                if (!$p->is_in_stock()) return "Sản phẩm " . $p->get_name() . " hiện đang hết hàng ạ.";

                $context->set('pending_product_id', $p->get_id());
                $attributes = $p->get_attributes();
                $attr_keys = array_keys($attributes); 
                
                if (!empty($attr_keys) && $p->is_type('variable')) {
                    $context->set('attr_queue', $attr_keys);
                    $context->set('attr_answers', []); 
                    $context->set('bot_collecting_info_step', 'process_attribute_loop'); 
                    $question = soft_ai_ask_next_attribute($context, $p);
                    return ($source == 'widget') ? "Tìm thấy: <b>" . $p->get_name() . "</b>.<br>" . $question : "Tìm thấy: " . $p->get_name() . ".\n" . $question;
                } else {
                    $context->set('bot_collecting_info_step', 'ask_quantity');
                    return "Đã tìm thấy " . $p->get_name() . ". Bạn muốn lấy số lượng bao nhiêu?";
                }
            }
            return "Xin lỗi, mình không tìm thấy sản phẩm nào khớp với '$query'.";

        case 'check_cart':
            $count = $context->get_cart_count();
            return $count > 0 
                ? "Giỏ hàng có $count sản phẩm (" . $context->get_cart_total_string() . "). Gõ 'Thanh toán' để đặt hàng nhé." 
                : "Giỏ hàng của bạn đang trống.";

        case 'checkout':
            if ($context->get_cart_count() == 0) return "Giỏ hàng trống. Hãy chọn sản phẩm trước nhé!";
            
            $has_info = false;
            $name = '';
            
            if ($source === 'widget' && WC()->customer->get_billing_first_name() && WC()->customer->get_billing_email()) {
                $has_info = true; 
                $name = WC()->customer->get_billing_first_name();
            } else {
                $saved = $context->get('user_info');
                if (!empty($saved['name']) && !empty($saved['email'])) { 
                    $has_info = true; 
                    $name = $saved['name']; 
                }
            }

            if ($has_info) {
                return soft_ai_present_payment_gateways($context, "Chào $name! Bạn muốn thanh toán qua đâu?");
            } else {
                $context->set('bot_collecting_info_step', 'fullname');
                return "Để đặt hàng, cho em xin Họ và Tên của bạn ạ?";
            }
            break;
    }
    return "Tôi chưa hiểu yêu cầu này. Bạn có thể nói rõ hơn không?";
}

// State Machine Handlers
function soft_ai_handle_ordering_steps($message, $step, $context) {
    $clean_message = trim($message);
    $source = $context->source;

    switch ($step) {
        case 'process_attribute_loop':
            $current_slug = $context->get('current_asking_attr');
            $clean_message = trim($message);
            $valid_options = $context->get('valid_options_for_' . $current_slug);
            $is_valid = false;
            
            if (empty($valid_options)) {
                $is_valid = true; 
            } else {
                foreach ($valid_options as $opt) {
                    if (mb_strtolower(trim($opt)) === mb_strtolower($clean_message)) {
                        $is_valid = true;
                        $clean_message = $opt; 
                        break;
                    }
                }
            }

            if (!$is_valid) {
                $label = wc_attribute_label($current_slug);
                $list_str = implode(', ', $valid_options);
                return "⚠️ Dạ shop không có $label '{$message}' ạ.\nVui lòng chỉ chọn một trong các loại sau: **$list_str**";
            }

            $answers = $context->get('attr_answers') ?: [];
            $answers[$current_slug] = $clean_message;
            $context->set('attr_answers', $answers);
            $context->set('valid_options_for_' . $current_slug, null); 

            $p = wc_get_product($context->get('pending_product_id'));
            return soft_ai_ask_next_attribute($context, $p);

        case 'ask_quantity':
            $qty = intval($clean_message);
            if ($qty <= 0) return "Số lượng phải lớn hơn 0. Vui lòng nhập lại:";
            
            $pid = $context->get('pending_product_id');
            $p = wc_get_product($pid);
            
            if ($p) {
                $var_id = 0; $var_data = [];
                
                if ($p->is_type('variable')) {
                    $collected = $context->get('attr_answers') ?: [];
                    $var_data = [];
                    foreach ($collected as $attr_key => $user_val_name) {
                        $slug_val = $user_val_name; 
                        if (taxonomy_exists($attr_key)) {
                            $term = get_term_by('name', $user_val_name, $attr_key);
                            if ($term) $slug_val = $term->slug;
                        } else {
                            $slug_val = sanitize_title($user_val_name);
                        }
                        $var_data['attribute_' . $attr_key] = $slug_val;
                    }
                    $data_store = new WC_Product_Data_Store_CPT();
                    $var_id = $data_store->find_matching_product_variation($p, $var_data);
                    if (!$var_id) return "Xin lỗi, phiên bản bạn chọn hiện không tồn tại hoặc đã hết hàng. Vui lòng chọn lại.";
                }

                $context->add_to_cart($pid, $qty, $var_id, $var_data);
                $context->set('bot_collecting_info_step', null);
                $total = $context->get_cart_total_string();
                return "✅ Đã thêm vào giỏ ($qty cái). Tổng tạm tính: $total.\nGõ 'Thanh toán' để chốt đơn hoặc hỏi mua tiếp.";
            }
            return "Có lỗi xảy ra với sản phẩm. Vui lòng tìm lại.";

        case 'fullname':
            $context->set('temp_name', $clean_message);
            if ($source === 'widget') WC()->customer->set_billing_first_name($clean_message);
            $context->set('bot_collecting_info_step', 'phone');
            return "Chào $clean_message, cho em xin Số điện thoại liên hệ?";

        case 'phone':
            if (!preg_match('/^[0-9]{9,12}$/', $clean_message)) return "Số điện thoại không hợp lệ. Vui lòng nhập lại:";
            $context->set('temp_phone', $clean_message);
            if ($source === 'widget') WC()->customer->set_billing_phone($clean_message);
            $context->set('bot_collecting_info_step', 'email');
            return "Dạ, cho em xin địa chỉ Email để gửi thông tin đơn hàng và thanh toán ạ?";

        case 'email':
            if (!is_email($clean_message)) return "Email không hợp lệ. Vui lòng nhập lại (ví dụ: ten@gmail.com):";
            $context->set('temp_email', $clean_message);
            if ($source === 'widget') WC()->customer->set_billing_email($clean_message);
            $context->set('bot_collecting_info_step', 'address');
            return "Cuối cùng, cho em xin Địa chỉ giao hàng cụ thể ạ?";

        case 'address':
            $context->set('temp_address', $clean_message);
            if ($source === 'widget') {
                WC()->customer->set_billing_address_1($clean_message);
                WC()->customer->save();
            } else {
                $context->set('user_info', [
                    'name' => $context->get('temp_name'),
                    'phone' => $context->get('temp_phone'),
                    'email' => $context->get('temp_email'),
                    'address' => $clean_message
                ]);
            }
            return soft_ai_present_payment_gateways($context, "Đã lưu địa chỉ. Bạn chọn hình thức thanh toán nào?");

        case 'payment_method':
            $method_key = mb_strtolower($clean_message);
            if (strpos($method_key, 'vietqr') !== false || strpos($method_key, 'chuyển khoản') !== false || strpos($method_key, 'qr') !== false) {
                return soft_ai_finalize_order($context, 'vietqr_custom');
            }
            if (strpos($method_key, 'paypal') !== false) {
                return soft_ai_finalize_order($context, 'paypal_custom');
            }

            $gateways = WC()->payment_gateways->get_available_payment_gateways();
            $selected = null;
            foreach ($gateways as $g) {
                if (stripos($g->title, $clean_message) !== false || stripos($g->id, $clean_message) !== false) { 
                    $selected = $g; break; 
                }
            }
            if (!$selected && (stripos($clean_message, 'cod') !== false || stripos($clean_message, 'mặt') !== false)) {
                $selected = $gateways['cod'] ?? null;
            }

            if (!$selected) return "Phương thức chưa đúng. Vui lòng nhập lại (ví dụ: VietQR, PayPal, COD).";
            return soft_ai_finalize_order($context, $selected);
    }
    return "";
}

function soft_ai_ask_next_attribute($context, $product) {
    $queue = $context->get('attr_queue');
    if (empty($queue)) {
        $context->set('bot_collecting_info_step', 'ask_quantity');
        return "Dạ bạn đã chọn đủ thông tin. Bạn muốn lấy số lượng bao nhiêu ạ?";
    }
    $current_slug = array_shift($queue);
    $context->set('attr_queue', $queue); 
    $context->set('current_asking_attr', $current_slug); 
    
    $terms = wc_get_product_terms($product->get_id(), $current_slug, array('fields' => 'names'));
    $options_text = "";
    if (!empty($terms) && !is_wp_error($terms)) {
        $context->set('valid_options_for_' . $current_slug, $terms);
        $options_text = "\n(" . implode(', ', $terms) . ")";
    } else {
        $context->set('valid_options_for_' . $current_slug, []);
    }
    $label = wc_attribute_label($current_slug);
    return "Bạn chọn **$label** loại nào?$options_text";
}

function soft_ai_present_payment_gateways($context, $msg) {
    $gateways = WC()->payment_gateways->get_available_payment_gateways();
    $opts = get_option('soft_ai_chat_settings');
    $list = "";
    $prefix = ($context->source == 'widget') ? "<br>• " : "\n- ";

    foreach ($gateways as $g) $list .= $prefix . $g->get_title();
    
    if (!empty($opts['vietqr_bank']) && !empty($opts['vietqr_acc'])) $list .= $prefix . "VietQR (Chuyển khoản nhanh)";
    if (!empty($opts['paypal_me'])) $list .= $prefix . "PayPal";

    $context->set('bot_collecting_info_step', 'payment_method');
    return $msg . $list;
}

function soft_ai_finalize_order($context, $gateway_or_code) {
    try {
        $order = wc_create_order();
        $opts = get_option('soft_ai_chat_settings');

        if ($context->source === 'widget' && function_exists('WC')) {
            foreach (WC()->cart->get_cart() as $values) $order->add_product($values['data'], $values['quantity']);
        } else {
            $cart = $context->get('cart') ?: [];
            foreach ($cart as $key => $item) {
                $pid = isset($item['product_id']) ? $item['product_id'] : $key;
                $vid = isset($item['variation_id']) ? $item['variation_id'] : 0;
                $p = wc_get_product($vid ? $vid : $pid);
                if ($p) $order->add_product($p, $item['qty']);
            }
            // Apply coupons stored in context for non-widget
            $stored_coupons = $context->get('coupons') ?: [];
            foreach($stored_coupons as $code) {
                $order->apply_coupon($code);
            }
        }

        $name    = $context->get('temp_name');
        $phone   = $context->get('temp_phone');
        $email   = $context->get('temp_email');
        $address = $context->get('temp_address');

        if ($context->source === 'widget' && function_exists('WC') && WC()->customer) {
            if (empty($name))    $name = WC()->customer->get_billing_first_name();
            if (empty($phone))   $phone = WC()->customer->get_billing_phone();
            if (empty($email))   $email = WC()->customer->get_billing_email();
            if (empty($address)) $address = WC()->customer->get_billing_address_1();
        }

        $parts = explode(' ', trim($name));
        $last_name  = (count($parts) > 1) ? array_pop($parts) : '';
        $first_name = implode(' ', $parts);
        if (empty($first_name)) $first_name = $name;

        $billing_info = [
            'first_name' => $first_name, 'last_name'  => $last_name, 'phone' => $phone,
            'email' => $email ?: 'no-email@example.com', 'address_1'  => $address, 'country' => 'VN',
        ];
        $order->set_address($billing_info, 'billing');
        $order->set_address($billing_info, 'shipping');

        $extra_msg = "";
        
        if ($gateway_or_code === 'vietqr_custom') {
            $order->set_payment_method('bacs');
            $order->set_payment_method_title('VietQR (Chat)');
            $order->calculate_totals();
            
            $bacs_accounts = get_option('woocommerce_bacs_accounts');
            $bank = ''; $acc = ''; $name_acc = '';
            if (!empty($bacs_accounts) && is_array($bacs_accounts)) {
                $account = $bacs_accounts[0];
                $bank = str_replace(' ', '', $account['bank_name']); 
                $acc  = str_replace(' ', '', $account['account_number']);
                $name_acc = str_replace(' ', '%20', $account['account_name']);
            } else {
                 $bank = str_replace(' ', '', $opts['vietqr_bank'] ?? '');
                 $acc  = str_replace(' ', '', $opts['vietqr_acc'] ?? '');
                 $name_acc = str_replace(' ', '%20', $opts['vietqr_name'] ?? '');
            }
            $amt = intval($order->get_total()); 
            $desc = "DH" . $order->get_id(); 
            if ($bank && $acc) {
                $qr_url = "https://img.vietqr.io/image/{$bank}-{$acc}-compact.jpg?amount={$amt}&addInfo={$desc}&accountName={$name_acc}";
                $extra_msg = "\n\n⬇️ **Quét mã để thanh toán:**\n![VietQR]($qr_url)";
                if ($context->source == 'widget') $extra_msg = "<br><br><b>Quét mã để thanh toán:</b><br><img src='$qr_url' style='max-width:100%; border-radius:8px;'>";
            }
        } elseif ($gateway_or_code === 'paypal_custom') {
            $order->set_payment_method('paypal');
            $order->set_payment_method_title('PayPal (Chat Link)');
            $order->calculate_totals();
            $raw_user = $opts['paypal_me'] ?? '';
            $raw_user = str_replace(['https://', 'http://', 'paypal.me/', '/'], '', $raw_user);
            $currency = get_woocommerce_currency(); 
            $amt = $order->get_total();
            $pp_link = "https://paypal.me/{$raw_user}/{$amt}{$currency}";
            $extra_msg = "\n\n👉 [Nhấn để thanh toán PayPal]($pp_link)";
            if ($context->source == 'widget') $extra_msg = "<br><br><a href='$pp_link' target='_blank' style='background:#0070ba;color:white;padding:10px 15px;border-radius:5px;text-decoration:none;font-weight:bold;'>Thanh toán ngay với PayPal</a>";
        } else {
            $order->set_payment_method($gateway_or_code);
            $order->calculate_totals();
        }

        $order->update_status('on-hold', "Order created via Soft AI Chat. IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'Unknown'));
        $context->empty_cart();
        $context->set('bot_collecting_info_step', null);
        return "🎉 ĐẶT HÀNG THÀNH CÔNG!\nMã đơn: #" . $order->get_id() . "\nEmail xác nhận đã gửi tới " . $billing_info['email'] . "." . $extra_msg;

    } catch (Exception $e) {
        return "Lỗi khi tạo đơn: " . $e->getMessage();
    }
}

// ---------------------------------------------------------
// 4. API CALLER
// ---------------------------------------------------------

function soft_ai_chat_call_api($provider, $model, $sys, $user, $opts) {
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
    
    if (isset($data['choices'][0]['message']['content'])) return $data['choices'][0]['message']['content'];
    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) return $data['candidates'][0]['content']['parts'][0]['text'];

    return "API Error: " . wp_remote_retrieve_body($response);
}

// ---------------------------------------------------------
// 5. REST API & WEBHOOKS
// ---------------------------------------------------------

add_action('rest_api_init', function () {
    register_rest_route('soft-ai-chat/v1', '/ask', [
        'methods' => 'POST',
        'callback' => 'soft_ai_chat_handle_widget_request',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('soft-ai-chat/v1', '/poll', [
        'methods' => 'POST',
        'callback' => 'soft_ai_chat_poll_messages',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('soft-ai-chat/v1', '/webhook/facebook', [
        'methods' => ['GET', 'POST'],
        'callback' => 'soft_ai_chat_webhook_facebook',
        'permission_callback' => '__return_true',
    ]);
    register_rest_route('soft-ai-chat/v1', '/webhook/zalo', [
        'methods' => 'POST',
        'callback' => 'soft_ai_chat_webhook_zalo',
        'permission_callback' => '__return_true',
    ]);
});

function soft_ai_chat_handle_widget_request($request) {
    if (function_exists('WC') && !WC()->session) {
        $session_class = apply_filters('woocommerce_session_handler', 'WC_Session_Handler');
        WC()->session = new $session_class();
        WC()->session->init();
        if (!WC()->cart) { WC()->cart = new WC_Cart(); WC()->cart->get_cart(); }
        if (!WC()->customer) { WC()->customer = new WC_Customer(get_current_user_id()); }
    }

    $params = $request->get_json_params();
    $question = sanitize_text_field($params['question'] ?? '');
    
    if (!$question) return new WP_Error('no_input', 'Empty Question', ['status' => 400]);

    $answer = soft_ai_generate_answer($question, 'widget');
    
    // If answer is the special wait flag, return empty to frontend so it just waits
    if ($answer === '[WAIT_FOR_HUMAN]') {
        return rest_ensure_response(['answer' => '', 'live_mode' => true]);
    }

    soft_ai_log_chat($question, $answer, 'widget');
    return rest_ensure_response(['answer' => $answer]);
}

function soft_ai_chat_poll_messages($request) {
    global $wpdb;
    // Client polls for new admin messages
    $ip = $_SERVER['REMOTE_ADDR'];
    $table = $wpdb->prefix . 'soft_ai_chat_logs';
    $last_id = (int) ($request->get_json_params()['last_id'] ?? 0);
    
    // Fetch only admin replies newer than last_id
    $new_msgs = $wpdb->get_results($wpdb->prepare("SELECT id, answer, time FROM $table WHERE user_ip = %s AND provider = 'live_admin' AND id > %d ORDER BY time ASC", $ip, $last_id));

    $data = [];
    foreach($new_msgs as $m) {
        $data[] = ['id' => $m->id, 'text' => $m->answer];
    }

    return rest_ensure_response(['messages' => $data]);
}

function soft_ai_chat_webhook_facebook($request) {
    $options = get_option('soft_ai_chat_settings');
    $verify_token = $options['fb_verify_token'] ?? 'soft_ai_verify';

    if ($request->get_method() === 'GET') {
        $params = $request->get_query_params();
        if (isset($params['hub_verify_token']) && $params['hub_verify_token'] === $verify_token) {
            echo $params['hub_challenge']; exit;
        }
        return new WP_Error('forbidden', 'Invalid Token', ['status' => 403]);
    }

    $body = $request->get_json_params();
    if (isset($body['object']) && $body['object'] === 'page') {
        foreach ($body['entry'] as $entry) {
            foreach ($entry['messaging'] as $event) {
                if (isset($event['message']['text']) && !isset($event['message']['is_echo'])) {
                    $sender = $event['sender']['id'];
                    $reply = soft_ai_generate_answer($event['message']['text'], 'facebook', $sender);
                    if ($reply !== '[WAIT_FOR_HUMAN]') {
                         soft_ai_send_fb_message($sender, $reply, $options['fb_page_token']);
                         soft_ai_log_chat($event['message']['text'], $reply, 'facebook');
                    }
                }
            }
        }
        return rest_ensure_response(['status' => 'EVENT_RECEIVED']);
    }
    return new WP_Error('bad_req', 'Invalid FB Data', ['status' => 404]);
}

function soft_ai_send_fb_message($recipient, $text, $token) {
    if (!$token) return;
    $chunks = str_split($text, 1900);
    foreach ($chunks as $chunk) {
        wp_remote_post("https://graph.facebook.com/v21.0/me/messages?access_token=$token", [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['recipient' => ['id' => $recipient], 'message' => ['text' => $chunk]])
        ]);
    }
}

function soft_ai_chat_webhook_zalo($request) {
    $body = $request->get_json_params();
    if (isset($body['event_name']) && $body['event_name'] === 'user_send_text') {
        $sender = $body['sender']['id'];
        $reply = soft_ai_generate_answer($body['message']['text'], 'zalo', $sender);
        
        if ($reply !== '[WAIT_FOR_HUMAN]') {
            $token = get_option('soft_ai_chat_settings')['zalo_access_token'] ?? '';
            if ($token) {
                wp_remote_post("https://openapi.zalo.me/v3.0/oa/message/cs", [
                    'headers' => ['access_token' => $token, 'Content-Type' => 'application/json'],
                    'body' => json_encode(['recipient' => ['user_id' => $sender], 'message' => ['text' => $reply]])
                ]);
            }
            soft_ai_log_chat($body['message']['text'], $reply, 'zalo');
        }
        return rest_ensure_response(['status' => 'success']);
    }
    return rest_ensure_response(['status' => 'ignored']);
}

// ---------------------------------------------------------
// 6. FRONTEND WIDGETS (UPDATED HIGHLIGHT)
// ---------------------------------------------------------

add_action('wp_footer', 'soft_ai_chat_inject_widget');
add_action('wp_footer', 'soft_ai_social_widgets_render');

function soft_ai_social_widgets_render() {
    $options = get_option('soft_ai_chat_settings');
    // Zalo
    if (!empty($options['enable_zalo_widget']) && !empty($options['zalo_oa_id'])) {
        $zalo_id = esc_attr($options['zalo_oa_id']);
        $welcome = esc_attr($options['welcome_msg'] ?? 'Xin chào!');
        echo <<<HTML
        <div class="zalo-chat-widget" data-oaid="{$zalo_id}" data-welcome-message="{$welcome}" data-autopopup="0" data-width="350" data-height="420"></div>
        <script src="https://sp.zalo.me/plugins/sdk.js"></script>
HTML;
    }
    // FB
    if (!empty($options['enable_fb_widget']) && !empty($options['fb_page_id'])) {
        $fb_id = esc_attr($options['fb_page_id']);
        echo <<<HTML
        <div id="fb-root"></div>
        <div id="fb-customer-chat" class="fb-customerchat"></div>
        <script>
        var chatbox = document.getElementById('fb-customer-chat');
        chatbox.setAttribute("page_id", "{$fb_id}");
        chatbox.setAttribute("attribution", "biz_inbox");
        window.fbAsyncInit = function() { FB.init({ xfbml : true, version : 'v18.0' }); };
        (function(d, s, id) {
            var js, fjs = d.getElementsByTagName(s)[0];
            if (d.getElementById(id)) return;
            js = d.createElement(s); js.id = id;
            js.src = 'https://connect.facebook.net/vi_VN/sdk/xfbml.customerchat.js';
            fjs.parentNode.insertBefore(js, fjs);
        }(document, 'script', 'facebook-jssdk'));
        </script>
HTML;
    }
}

function soft_ai_chat_inject_widget() {
    $options = get_option('soft_ai_chat_settings');
    if (is_admin() || empty($options['provider'])) return;

    $color = $options['theme_color'] ?? '#027DDD';
    $welcome = $options['welcome_msg'] ?? 'Xin chào! Bạn cần tìm gì ạ?';
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
        .zalo-chat-widget + #sac-trigger { bottom: 90px; }
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
        
        /* Bot Message Style */
        .sac-msg.bot { align-self: flex-start; background: #fff; border: 1px solid #e5e5e5; color: #333; border-bottom-left-radius: 2px; }
        .sac-msg.bot p { margin: 0 0 8px 0; } .sac-msg.bot p:last-child { margin: 0; }
        .sac-msg.bot img { max-width: 100%; border-radius: 8px; margin-top: 5px; }
        .sac-msg.bot strong { color: <?php echo esc_attr($color); ?>; }

        /* Admin Highlight Style */
        .sac-msg.admin { 
            align-self: flex-start; 
            background: #e6f7ff; 
            border: 1px solid #91d5ff; 
            color: #0050b3; 
            border-bottom-left-radius: 2px; 
            position: relative;
            padding-top: 20px; /* Space for label */
        }

        .sac-input-area { padding: 12px; border-top: 1px solid #eee; background: white; display: flex; gap: 8px; }
        #sac-input { flex: 1; padding: 10px; border: 1px solid #ddd; border-radius: 20px; outline: none; transition: border 0.2s; }
        #sac-input:focus { border-color: <?php echo esc_attr($color); ?>; }
        #sac-send { width: 40px; height: 40px; background: <?php echo esc_attr($color); ?>; color: white; border: none; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; padding: 0 !important;}
        #sac-send:disabled { background: #ccc; cursor: not-allowed; }
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
            <input type="text" id="sac-input" placeholder="Hỏi gì đó..." onkeypress="handleEnter(event)">
            <button id="sac-send" onclick="sendSac()"><span style="font-size:16px;">➤</span></button>
        </div>
    </div>

    <script>
        const apiUrl = '<?php echo esc_url(rest_url('soft-ai-chat/v1/ask')); ?>';
        const pollUrl = '<?php echo esc_url(rest_url('soft-ai-chat/v1/poll')); ?>';
        let lastMsgId = 0;
        let pollInterval = null;

        function toggleSac() {
            const win = document.getElementById('sac-window');
            const isHidden = win.style.display === '' || win.style.display === 'none';
            win.style.display = isHidden ? 'flex' : 'none';
            if (isHidden) {
                setTimeout(() => document.getElementById('sac-input').focus(), 100);
                startPolling();
            } else {
                stopPolling();
            }
        }
        
        function handleEnter(e) { if (e.key === 'Enter') sendSac(); }

        function startPolling() {
            if(pollInterval) clearInterval(pollInterval);
            pollInterval = setInterval(async () => {
                try {
                    const res = await fetch(pollUrl, {
                         method: 'POST',
                         headers: { 'Content-Type': 'application/json' },
                         body: JSON.stringify({ last_id: lastMsgId })
                    });
                    const data = await res.json();
                    if(data.messages && data.messages.length > 0) {
                        const msgs = document.getElementById('sac-messages');
                        data.messages.forEach(m => {
                            lastMsgId = Math.max(lastMsgId, parseInt(m.id));
                            // Use 'admin' class for highlighted messages
                            msgs.innerHTML += `<div class="sac-msg admin">${marked.parse(m.text)}</div>`;
                        });
                        msgs.scrollTop = msgs.scrollHeight;
                    }
                } catch(e) {}
            }, 5000); // Poll every 5s
        }

        function stopPolling() {
            if(pollInterval) clearInterval(pollInterval);
        }

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
                } else if(data.live_mode) {
                    // Do nothing, just sent. 
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