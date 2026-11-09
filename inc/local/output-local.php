<?php
/**
 * 本站模式 — 前端资源统一加载 + 对话窗口 HTML 输出
 *
 * 统管本站模式所有 CSS/JS 的加载策略和对话窗口骨架。
 *
 * @package WXS_Zibll_PostChat
 * @since 1.1.0
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================== 资源加载（wp_enqueue_scripts）====================

/**
 * 本站模式统一资源加载入口
 *
 * 加载策略：
 * - wxs-local-mode.css：所有前台页面加载（轻量：变量 + 摘要/气泡/推荐基础结构）
 * - 对话 CSS/JS：仅在对话功能开启时加载
 * - 引导 CSS/JS：仅在用户引导开启时加载
 */
add_action( 'wp_enqueue_scripts', 'wxs_local_enqueue_assets' );
function wxs_local_enqueue_assets() {
    // 1. 主样式（始终加载，所有前台页面）
    wp_enqueue_style(
        'wxs-local-mode',
        WXS_POSTCHAT_URL . 'assets/css/wxs-local-mode.css',
        [],
        WXS_POSTCHAT_VERSION
    );

    // 2. 对话资源（对话功能开启时加载）
    $enable_chat = wxs_postchat_get_option( 'local_enable_chat', false );
    if ( $enable_chat ) {
        wp_enqueue_style(
            'wxs-ai-chat',
            WXS_POSTCHAT_URL . 'assets/css/wxs-ai-chat.css',
            [],
            WXS_POSTCHAT_VERSION
        );

        wp_enqueue_script(
            'wxs-marked',
            WXS_POSTCHAT_URL . 'assets/js/vendor/marked.min.js',
            [],
            WXS_POSTCHAT_VERSION,
            true
        );

        wp_enqueue_script(
            'wxs-ai-chat',
            WXS_POSTCHAT_URL . 'assets/js/wxs-ai-chat.js',
            [ 'wxs-marked' ],
            WXS_POSTCHAT_VERSION,
            true
        );
    }

    // 3. 用户引导资源（引导功能开启 + 触发条件满足时加载）
    $enable_guide = wxs_postchat_get_option( 'enable_user_guide', false );
    if ( $enable_guide ) {
        $trigger = wxs_postchat_get_option( 'guide_trigger', 'logged_in' );
        if ( $trigger === 'all' || is_user_logged_in() ) {
            wp_enqueue_style(
                'driver-css',
                WXS_POSTCHAT_URL . 'assets/driver.min.css',
                [],
                WXS_POSTCHAT_VERSION
            );

            wp_enqueue_script(
                'driver-js',
                WXS_POSTCHAT_URL . 'assets/driver.min.js',
                [],
                WXS_POSTCHAT_VERSION,
                true
            );

            wp_enqueue_script(
                'wxs-local-guide',
                WXS_POSTCHAT_URL . 'assets/js/wxs-local-guide.js',
                [ 'driver-js' ],
                WXS_POSTCHAT_VERSION,
                true
            );
        }
    }
}

// ==================== 配置变量注入（wp_footer priority 5）====================

/**
 * 注入前端配置变量（在 JS 文件加载后、执行前输出）
 *
 * 输出两个全局变量：
 * - wxsAiConfig：对话窗口配置
 * - wxsLocalGuide：本站模式用户引导配置
 */
add_action( 'wp_footer', 'wxs_local_render_config', 5 );
function wxs_local_render_config() {
    $enable_chat  = wxs_postchat_get_option( 'local_enable_chat', false );
    $enable_guide = wxs_postchat_get_option( 'enable_user_guide', false );

    if ( ! $enable_chat && ! $enable_guide ) {
        return;
    }

    // ========== 对话配置 wxsAiConfig ==========
    if ( $enable_chat ) {
        $is_mobile = wp_is_mobile();
        $chat_title = wxs_postchat_get_option( 'local_chat_title', get_bloginfo( 'name' ) . '助理' );
        // CSF switcher 未保存时返回空字符串，视为默认允许游客
        $guest_raw     = wxs_postchat_get_option( 'local_chat_guest_allowed', true );
        $guest_allowed = ( '' === $guest_raw || null === $guest_raw ) ? true : (bool) $guest_raw;
        $config = [
            'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
            'chatTitle'    => $chat_title,
            'chatDesc'     => wxs_postchat_get_option( 'local_chat_desc', '有任何疑问都可以问我~' ),
            'welcomeText'  => '你好！我是' . $chat_title . '，有什么可以帮你的？',
            'contextLimit' => (int) wxs_postchat_get_option( 'local_context_limit', 5 ),
            'btnSelector'  => wxs_postchat_get_option( 'local_btn_selector', '#ai-duihua' ),
            'btnSvgIcon'   => wxs_postchat_get_option( 'local_btn_svg_icon', '' ),
            'mobileMode'   => wxs_postchat_get_option( 'local_mobile_mode', 'fullscreen' ),
            'desktopMode'  => wxs_postchat_get_option( 'local_desktop_mode', 'sidebar' ),
            'typingSpeed'  => 15,
            'isMobile'     => $is_mobile,
            'isLoggedIn'   => is_user_logged_in(),
            'guestAllowed' => $guest_allowed,
            // 用户头像（未登录时为默认头像），用于消息行右侧头像
            'userAvatar'   => get_avatar_url( get_current_user_id(), [ 'size' => 64 ] ),
        ];

        // 默认输入内容（仅文章/帖子页）
        if ( is_singular( [ 'post', 'forum_post' ] ) ) {
            $default_input = wxs_postchat_get_option( 'local_default_input', '请问在《{title}》中' );
            if ( function_exists( 'wxs_postchat_replace_variables' ) ) {
                $config['defaultInput'] = wxs_postchat_replace_variables( $default_input );
            } else {
                $title = wxs_postchat_get_option( 'local_exclude_site_title', true )
                    ? preg_replace( '/\s*[-|–—]\s*' . preg_quote( get_bloginfo( 'name' ), '/' ) . '\s*$/u', '', get_the_title() )
                    : get_the_title();
                $config['defaultInput'] = str_replace( '{title}', $title, $default_input );
            }
        }

        echo '<script>var wxsAiConfig=' . wp_json_encode( $config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script>' . "\n";
    }

    // ========== 引导配置 wxsLocalGuide ==========
    if ( $enable_guide ) {
        $trigger = wxs_postchat_get_option( 'guide_trigger', 'logged_in' );
        if ( $trigger === 'all' || is_user_logged_in() ) {
            $guide_config = [
                'pluginUrl'       => WXS_POSTCHAT_URL,
                'pluginVer'       => WXS_POSTCHAT_VERSION,
                'guideCookieDays' => (int) wxs_postchat_get_option( 'guide_cookie_days', '7' ),
                'btnSelector'     => wxs_postchat_get_option( 'local_btn_selector', '#ai-duihua' ),
                'guideAiTitle'    => wxs_postchat_get_option( 'guide_ai_title', 'AI对话' ),
                'guideAiDesc'     => wxs_postchat_get_option( 'guide_ai_desc', '点击此按钮，可与智能AI客服对话哦' ),
            ];

            echo '<script>var wxsLocalGuide=' . wp_json_encode( $guide_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script>' . "\n";
        }
    }
}

/**
 * 输出对话窗口 HTML 骨架
 */
add_action( 'wp_footer', 'wxs_local_render_chat_window', 10 );
function wxs_local_render_chat_window() {
    $enable_chat = wxs_postchat_get_option( 'local_enable_chat', false );
    if ( ! $enable_chat ) {
        return;
    }

    $chat_title = esc_html( wxs_postchat_get_option( 'local_chat_title', get_bloginfo( 'name' ) . '助理' ) );
    $chat_desc  = esc_html( wxs_postchat_get_option( 'local_chat_desc', '有任何疑问都可以问我~' ) );
    ?>
    <!-- WXS AI 对话窗口（结构对齐子比消息中心私信窗口） -->
    <div id="wxs-ai-chat-overlay" class="wxs-ai-chat-overlay">
        <div class="wxs-ai-chat-box">
            <!-- 头部 -->
            <div class="wxs-ai-chat-header">
                <div class="wxs-ai-chat-header-info">
                    <div class="wxs-ai-chat-avatar">
                        <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="8" width="16" height="12" rx="3"/>
                            <path d="M12 8V5"/><circle cx="12" cy="4" r="1.2"/>
                            <circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>
                            <path d="M9.5 17.5h5"/>
                        </svg>
                    </div>
                    <div class="wxs-ai-chat-header-text">
                        <div class="wxs-ai-chat-title"><?php echo $chat_title; ?></div>
                        <div class="wxs-ai-chat-desc"><?php echo $chat_desc; ?></div>
                    </div>
                </div>
                <div class="wxs-ai-chat-tools">
                    <button type="button" class="but wxs-ai-chat-tool" id="wxs-ai-chat-history-btn" aria-label="历史记录" title="历史记录">历史</button>
                    <button type="button" class="but wxs-ai-chat-tool" id="wxs-ai-chat-reset" aria-label="结束对话" title="结束对话">结束对话</button>
                    <button type="button" class="wxs-ai-chat-close" id="wxs-ai-chat-close" aria-label="关闭">&times;</button>
                </div>
            </div>
            <!-- 消息区域 -->
            <div class="wxs-ai-chat-messages" id="wxs-ai-chat-messages">
                <div class="wxs-ai-msg wxs-ai-msg-bot">
                    <div class="wxs-ai-msg-avatar">
                        <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
                            <rect x="4" y="8" width="16" height="12" rx="3"/>
                            <path d="M12 8V5"/><circle cx="12" cy="4" r="1.2"/>
                            <circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>
                            <path d="M9.5 17.5h5"/>
                        </svg>
                    </div>
                    <div class="wxs-ai-msg-bubble">你好！我是<?php echo $chat_title; ?>，有什么可以帮你的？</div>
                </div>
            </div>
            <!-- 输入区域 -->
            <div class="wxs-ai-chat-footer">
                <textarea id="wxs-ai-chat-input" class="form-control wxs-ai-chat-input" placeholder="输入你的问题..." rows="2"></textarea>
                <button type="button" id="wxs-ai-chat-send" class="but c-blue wxs-ai-chat-send-btn">发送</button>
            </div>
        </div>
    </div>
    <?php
}
