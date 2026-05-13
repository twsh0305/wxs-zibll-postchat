<?php
/**
 * GitHub 更新检查器
 * 
 * 通过 GitHub Releases API 检测新版本，注入 WordPress 更新系统。
 * 
 * @package Zibll_PostChat
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * GitHub 更新检查器
 */
class WXS_PostChat_Updater {

    /** @var string 插件在 WP 中的 slug */
    private $plugin_slug;

    /** @var string 插件主文件路径 */
    private $plugin_file;

    /** @var string GitHub 仓库名（格式：owner/repo） */
    private $github_repo;

    /** @var string 当前插件版本 */
    private $current_version;

    /**
     * GitHub API 端点列表（按优先级排序，国内服务器直连失败后自动切代理）
     */
    private $api_endpoints = [];

    /**
     * GitHub 下载代理前缀（edgeone 镜像加速）
     */
    private $download_proxy = 'https://edgeone.gh-proxy.com/';

    /**
     * @param string $plugin_file 插件主文件 __FILE__
     * @param string $github_repo GitHub 仓库（如 twsh0305/wxs-zibll-postchat）
     */
    public function __construct( $plugin_file, $github_repo ) {
        $this->plugin_file    = $plugin_file;
        $this->plugin_slug    = plugin_basename( $plugin_file );
        $this->github_repo    = $github_repo;
        $this->current_version = WXS_POSTCHAT_VERSION;

        // 国内服务器优先直连，失败自动切 edgeone 代理
        $this->api_endpoints = [
            "https://api.github.com/repos/{$this->github_repo}/releases/latest",
            "{$this->download_proxy}https://api.github.com/repos/{$this->github_repo}/releases/latest",
        ];

        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'check_update' ] );
        add_filter( 'plugins_api', [ $this, 'plugin_info' ], 10, 3 );
    }

    /**
     * 检查是否有新版本，注入到 WP 更新瞬态
     *
     * @param object $transient 更新瞬态对象
     * @return object
     */
    public function check_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $latest = $this->get_latest_release();
        if ( ! $latest ) {
            return $transient;
        }

        if ( version_compare( $this->current_version, $latest['version'], '<' ) ) {
            $transient->response[ $this->plugin_slug ] = (object) [
                'slug'        => $this->plugin_slug,
                'new_version' => $latest['version'],
                'url'         => $latest['url'],
                'package'     => $latest['download_url'],
            ];
        }

        return $transient;
    }

    /**
     * 提供"查看详情"弹窗的插件信息
     *
     * @param false|object $result 默认结果
     * @param string       $action 操作类型
     * @param object       $args   请求参数
     * @return false|object
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }

        if ( ! isset( $args->slug ) || $args->slug !== $this->plugin_slug ) {
            return $result;
        }

        $latest = $this->get_latest_release();
        if ( ! $latest ) {
            return $result;
        }

        return (object) [
            'name'          => '子比PostChat',
            'slug'          => $this->plugin_slug,
            'version'       => $latest['version'],
            'author'        => '<a href="https://wxsnote.cn">天无神话</a>',
            'homepage'      => 'https://wxsnote.cn/7673.html',
            'download_link' => $latest['download_url'],
            'sections'      => [
                'description' => '基于子比主题的 PostChat AI 摘要与对话集成插件，支持私有摘要存储与前端注入。',
            ],
        ];
    }

    /**
     * 从 GitHub API 获取最新 Release 信息（缓存 12 小时）
     *
     * 国内服务器友好：先直连 api.github.com，失败自动切 edgeone 代理
     *
     * @return array|false 成功返回 ['version', 'url', 'download_url']，失败返回 false
     */
    private function get_latest_release() {
        $cache = get_transient( 'wxs_postchat_github_release' );
        if ( false !== $cache ) {
            return $cache ?: false;
        }

        $body = null;

        foreach ( $this->api_endpoints as $url ) {
            $response = wp_remote_get( $url, [
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                ],
                'timeout' => 15,
            ] );

            if ( is_wp_error( $response ) ) {
                continue;
            }

            if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
                continue;
            }

            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            if ( ! empty( $body['tag_name'] ) ) {
                break;
            }
        }

        if ( empty( $body['tag_name'] ) ) {
            set_transient( 'wxs_postchat_github_release', '', HOUR_IN_SECONDS );
            return false;
        }

        $version = ltrim( $body['tag_name'], 'vV' );

        // 下载包固定走 edgeone 代理，避免国内服务器无法下载
        $download_url = $body['zipball_url'] ?? '';
        if ( $download_url && strpos( $download_url, 'api.github.com' ) !== false ) {
            $download_url = $this->download_proxy . $download_url;
        }

        $result = [
            'version'      => $version,
            'url'          => $body['html_url'] ?? '',
            'download_url' => $download_url,
        ];

        set_transient( 'wxs_postchat_github_release', $result, 12 * HOUR_IN_SECONDS );
        return $result;
    }
}
