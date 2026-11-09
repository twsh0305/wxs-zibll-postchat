<?php
/**
 * Plugin Name: 子比PostChat
 * Plugin URI: https://wxsnote.cn/7673.html
 * Description: 基于子比主题的AI摘要与对话集成插件，支持洪墨AI云端和本站模式（WP AI Client + Meilisearch）。
 * Version: 2.1.10
 * Author: 天无神话
 * Author URI: https://wxsnote.cn
 * License: GPL v2 or later
 * Text Domain: wxs-zibll-postchat
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 定义插件常量
define( 'WXS_POSTCHAT_VERSION', '2.1.10' );
define( 'WXS_POSTCHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WXS_POSTCHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'WXS_POSTCHAT_PREFIX', 'wxs_postchat_settings' );
define( 'WXS_POSTCHAT_DB_VERSION', '1.1.0' );

/**
 * 核心配置获取函数
 * 
 * @param string $key 选项键名
 * @param mixed $default 默认值
 * @return mixed
 */
function wxs_postchat_get_option( $key = '', $default = null ) {
    $options = get_option( WXS_POSTCHAT_PREFIX, [] );
    if ( empty( $key ) ) {
        return $options;
    }
    return isset( $options[ $key ] ) ? $options[ $key ] : $default;
}

/**
 * 子比主题缺失提示
 */
function wxs_postchat_theme_required_notice() {
    ?>
    <div class="notice notice-error">
        <p>
            <strong>子比PostChat</strong> 插件需要 <strong>子比主题（Zibll）</strong> 才能运行。
            请先安装并启用<a href="https://www.zibll.com/pay-zibll?ref=2056" target="_blank">子比主题</a>。
        </p>
    </div>
    <?php
}

// 子比主题检测：非子比主题时显示提示并终止加载功能模块
if ( get_template() !== 'zibll' ) {
    add_action( 'admin_notices', 'wxs_postchat_theme_required_notice' );
    // 保留基础常量与 wxs_postchat_get_option() 供外部安全调用，不加载 inc 功能文件
    return;
}

/**
 * 获取当前服务模式
 *
 * @return string 'postchat' | 'local'
 */
function wxs_postchat_get_mode() {
    return wxs_postchat_get_option( 'service_mode', 'postchat' );
}

/**
 * 检测本站模式前置条件是否满足
 *
 * @return bool
 */
function wxs_postchat_local_ready() {
    // Meilisearch 启用检测（对应子比主题设置 → 搜索 → Meilisearch智能搜索）
    $ms_ok = function_exists( '_pz' ) && _pz( 'search_ms_s' );
    // WP AI Client 可用检测
    $ai_ok = function_exists( 'wp_ai_client_prompt' ) && function_exists( 'wp_supports_ai' ) && wp_supports_ai();
    return $ms_ok && $ai_ok;
}

// 加载功能文件（CSF 选项始终加载）
require_once WXS_POSTCHAT_DIR . 'inc/options.php';
// 共享工具函数（两种模式共用）
require_once WXS_POSTCHAT_DIR . 'inc/helpers.php';

// 根据模式条件加载对应功能文件
if ( wxs_postchat_get_mode() === 'local' ) {
    // 本站模式：WP AI Client + Meilisearch
    // 无论 ready 与否均加载本地文件，各功能内部自检 AI 客户端可用性
    require_once WXS_POSTCHAT_DIR . 'inc/local/summary-local.php';
    require_once WXS_POSTCHAT_DIR . 'inc/local/chat-local.php';
    require_once WXS_POSTCHAT_DIR . 'inc/local/output-local.php';
    require_once WXS_POSTCHAT_DIR . 'inc/local/knowledge-local.php';
    require_once WXS_POSTCHAT_DIR . 'inc/local/glossary-local.php';
    require_once WXS_POSTCHAT_DIR . 'inc/local/recommend-local.php';
} else {
    // 洪墨模式：PostChat 云端服务（现有逻辑不变）
    require_once WXS_POSTCHAT_DIR . 'inc/output.php';
    require_once WXS_POSTCHAT_DIR . 'inc/listener.php';
    // 相关文章推荐（仅 PostChat 模式，依赖远程 API）
    require_once WXS_POSTCHAT_DIR . 'inc/related-posts.php';
}

// 在主文件 scope 注册停用钩子（符合 WP 插件开发规范）
register_deactivation_hook( __FILE__, 'wxs_postchat_deactivation_cleanup' );
// 激活时执行数据库迁移（建表等），不在请求路径中做 schema 变更
register_activation_hook( __FILE__, 'wxs_postchat_maybe_upgrade' );

/**
 * 插件停用时清理所有调度事件
 */
if ( ! function_exists( 'wxs_postchat_deactivation_cleanup' ) ) {
    function wxs_postchat_deactivation_cleanup() {
        $crons = _get_cron_array();
        if ( empty( $crons ) ) {
            return;
        }
        $hooks = [
            'wxs_postchat_generate_summary_event',
            'wxs_local_generate_summary_event',
            'wxs_local_generate_glossary_event',
        ];
        $modified = false;
        foreach ( $crons as $timestamp => $cron ) {
            foreach ( $hooks as $hook ) {
                if ( isset( $cron[ $hook ] ) ) {
                    unset( $crons[ $timestamp ][ $hook ] );
                    $modified = true;
                }
            }
            if ( empty( $crons[ $timestamp ] ) ) {
                unset( $crons[ $timestamp ] );
            }
        }
        if ( $modified ) {
            _set_cron_array( $crons );
        }
    }
}

/**
 * 数据库版本升级检查
 * 当 WXS_POSTCHAT_DB_VERSION 与数据库中存储的版本不一致时，执行升级逻辑。
 * 新增升级步骤时：1) 递增 WXS_POSTCHAT_DB_VERSION 常量  2) 在 switch 中添加对应 case
 */
function wxs_postchat_maybe_upgrade() {
    $current_db_version = get_option( 'wxs_postchat_db_version', '0' );
    if ( version_compare( $current_db_version, WXS_POSTCHAT_DB_VERSION, '>=' ) ) {
        return;
    }

    global $wpdb;

    // 1.1.0: 创建对话记录表
    if ( version_compare( $current_db_version, '1.1.0', '<' ) ) {
        $table = $wpdb->prefix . 'wxs_chat_logs';
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(64) NOT NULL DEFAULT '',
            user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            user_ip VARCHAR(45) NOT NULL DEFAULT '',
            role VARCHAR(10) NOT NULL DEFAULT 'user',
            message TEXT NOT NULL,
            tokens_used INT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_session (session_id),
            KEY idx_user (user_id),
            KEY idx_created (created_at)
        ) {$charset_collate};";
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    update_option( 'wxs_postchat_db_version', WXS_POSTCHAT_DB_VERSION );
}
add_action( 'plugins_loaded', 'wxs_postchat_maybe_upgrade' );

// 通过子比主题 zib_require_end 钩子加载 CSF 设置
add_action( 'zib_require_end', 'wxs_postchat_options' );

/**
 * 注册自定义查询变量（黑名单JSON端点）
 */
add_filter( 'query_vars', 'wxs_postchat_register_query_var' );
function wxs_postchat_register_query_var( $vars ) {
    $vars[] = 'wxs_postchat_query';
    return $vars;
}

/**
 * 处理黑名单JSON请求的端点逻辑
 */
add_action( 'template_redirect', 'wxs_postchat_handle_blacklist_endpoint' );
function wxs_postchat_handle_blacklist_endpoint() {
    // 仅处理带有wxs_postchat_query参数的请求
    if ( get_query_var( 'wxs_postchat_query' ) !== 'blacklist' ) {
        return;
    }

    // 验证请求方法
    if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || $_SERVER['REQUEST_METHOD'] !== 'GET' ) {
        wp_die( 'Invalid request method', 400 );
    }

    // 验证来源（同域验证）
    $referer = isset( $_SERVER['HTTP_REFERER'] )
        ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) )
        : false;

    $host = isset( $_SERVER['HTTP_HOST'] )
        ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
        : '';

    // 安全验证：仅允许同域请求
    if ( $referer && ! empty( $host ) && strpos( $referer['host'], $host ) === false ) {
        wp_die( 'Invalid referer', 403 );
    }

    // 强制不缓存该响应
    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );

    // 获取黑名单URL列表
    $blacklist_urls = wxs_postchat_get_option( 'blacklist_urls', '' );
    $blackurls = [];

    if ( ! empty( $blacklist_urls ) ) {
        // 按行分割，过滤空行和空白
        $lines = preg_split( '/\r\n|\r|\n/', $blacklist_urls );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( ! empty( $line ) ) {
                $blackurls[] = $line;
            }
        }
    }

    // 输出JSON格式（符合PostChat官方规范）
    wp_send_json( [ 'blackurls' => $blackurls ] );
}

// GitHub 更新检查
require_once WXS_POSTCHAT_DIR . 'inc/updater.php';
new WXS_PostChat_Updater( __FILE__, 'twsh0305/wxs-zibll-postchat' );
