<?php
/**
 * Plugin Name: 子比PostChat
 * Plugin URI: https://wxsnote.cn/7673.html
 * Description: 基于子比主题的PostChat AI摘要与对话集成插件，支持私有摘要存储与前端注入。
 * Version: 1.0.0
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
define( 'WXS_POSTCHAT_VERSION', '1.0.0' );
define( 'WXS_POSTCHAT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WXS_POSTCHAT_URL', plugin_dir_url( __FILE__ ) );
define( 'WXS_POSTCHAT_PREFIX', 'wxs_postchat_settings' );
define( 'WXS_POSTCHAT_DB_VERSION', '1.0.0' );

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

// 加载功能文件
require_once WXS_POSTCHAT_DIR . 'inc/options.php';
require_once WXS_POSTCHAT_DIR . 'inc/output.php';
require_once WXS_POSTCHAT_DIR . 'inc/listener.php';

// 在主文件 scope 注册停用钩子（符合 WP 插件开发规范）
register_deactivation_hook( __FILE__, 'wxs_postchat_deactivation_cleanup' );

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

    // 后续版本升级逻辑示例：
    // if ( version_compare( $current_db_version, '1.1.0', '<' ) ) {
    //     // 执行 1.1.0 升级操作
    // }

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
