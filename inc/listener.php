<?php
/**
 * 文章发布/更新监听 + API获取摘要 + 存储到post_meta
 * 
 * @package WXS_Zibll_PostChat
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 检查是否满足私有摘要启用条件
 * 
 * @return bool
 */
function wxs_postchat_should_enable_listener() {
    $enable_summary  = wxs_postchat_get_option( 'enable_summary', true );
    $private_summary = wxs_postchat_get_option( 'private_summary', false );
    $api_secret      = wxs_postchat_get_option( 'api_secret', '' );

    return ( $enable_summary && $private_summary && ! empty( $api_secret ) );
}

/**
 * 监听文章发布和更新事件
 */
function wxs_postchat_article_publish_listener( $post_ID, $post, $update ) {
    // 防止无限循环和多重调用
    static $is_processing = [];

    if ( isset( $is_processing[ $post_ID ] ) && $is_processing[ $post_ID ] ) {
        return;
    }

    // 跳过自动保存和修订版本
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_ID ) || wp_is_post_autosave( $post_ID ) ) {
        return;
    }

    // 检查启用条件
    if ( ! wxs_postchat_should_enable_listener() ) {
        return;
    }

    // 只处理已发布的文章和帖子
    if ( $post->post_status !== 'publish' ) {
        return;
    }
    if ( $post->post_type === 'post' ) {
        if ( ! wxs_postchat_get_option( 'enable_article_summary', true ) ) {
            return;
        }
    } elseif ( $post->post_type === 'forum_post' ) {
        if ( ! wxs_postchat_get_option( 'enable_forum_summary', true ) ) {
            return;
        }
        if ( ! function_exists( '_pz' ) || ! _pz( 'bbs_s', true ) ) {
            return;
        }
    } else {
        return;
    }

    $is_processing[ $post_ID ] = true;

    try {
        // 检查内容是否变化
        $content_hash = md5( $post->post_content . $post->post_title );
        $stored_hash  = get_post_meta( $post_ID, '_wxs_postchat_content_hash', true );

        if ( ! empty( get_post_meta( $post_ID, '_wxs_postchat_summary', true ) ) && $content_hash === $stored_hash ) {
            return;
        }

        // 安排异步任务生成摘要（5秒后执行）
        wp_schedule_single_event( time() + 5, 'wxs_postchat_generate_summary_event', [ $post_ID, $content_hash ] );
    } finally {
        $is_processing[ $post_ID ] = false;
    }
}
add_action( 'wp_insert_post', 'wxs_postchat_article_publish_listener', 20, 3 );

/**
 * 清理HTML内容，提取纯文本
 * 
 * @param string $content 原始HTML内容
 * @return string 清理后的纯文本
 */
function wxs_postchat_clean_html_content( $content ) {
    $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    // 移除WordPress块标记
    $content = preg_replace( '/<!--\s*\/?wp:(.*?)-->/', '', $content );
    $content = preg_replace( '/<!--(.*?)-->/', '', $content );

    // 移除代码块（防止代码内容触发WAF拦截）
    $content = preg_replace( '/<pre[^>]*>.*?<\/pre>/is', '', $content );
    $content = preg_replace( '/<code[^>]*>.*?<\/code>/is', '', $content );
    $content = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $content );
    $content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );
    $content = preg_replace( '/<textarea[^>]*>.*?<\/textarea>/is', '', $content );

    // 移除短代码
    $content = strip_shortcodes( $content );

    // 保留段落分隔后去除HTML
    $content = str_replace(
        [ '</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '<br>', '<br />' ],
        "\n",
        $content
    );
    $content = wp_strip_all_tags( $content, true );

    // 清理空白
    $content = preg_replace( '/\s+/', ' ', $content );
    $content = trim( $content );

    // 限制字数
    $word_limit = intval( wxs_postchat_get_option( 'word_limit', '5000' ) );
    if ( $word_limit > 0 && mb_strlen( $content, 'UTF-8' ) > $word_limit ) {
        $content = mb_substr( $content, 0, $word_limit, 'UTF-8' ) . '...';
    }

    return $content;
}

/**
 * 从PostChat API获取文章摘要
 *
 * @param string $title 文章标题
 * @param string $content 文章内容
 * @param string $url 文章URL
 * @param int    $post_id 文章ID（用于判断文章类型）
 * @return string|array 成功返回摘要字符串，失败返回 ['error'=>string, 'skip'=>bool]
 */
function wxs_postchat_get_summary_from_api( $title, $content, $url, $post_id = 0 ) {
    $api_key    = wxs_postchat_get_option( 'api_key', '' );
    $api_secret = wxs_postchat_get_option( 'api_secret', '' );

    $clean_content = wxs_postchat_clean_html_content( $content );
    $clean_title   = wxs_postchat_clean_html_content( $title );

    // 正文过短本地拦截（跳过无需等待间隔）
    $content_len = mb_strlen( $clean_content, 'UTF-8' );
    if ( $content_len < 20 ) {
        return [ 'error' => '正文过短（' . $content_len . '字，要求20字），已跳过', 'skip' => true ];
    }

    $body = [
        'content'    => $clean_content,
        'title'      => $clean_title,
        'url'        => $url,
        'key'        => $api_key,
        'language'   => get_locale(),
        'system'     => 'WordPress',
        'embedding'  => 'cover',
        'api_secret' => $api_secret,
    ];

    $response = wp_remote_post( 'https://api.ai.zhheo.com/api/v2/summary/generate/internal', [
        'body'    => wp_json_encode( $body ),
        'timeout' => 30,
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );

    if ( is_wp_error( $response ) ) {
        return [ 'error' => '网络错误: ' . $response->get_error_message() ];
    }

    $raw_body  = wp_remote_retrieve_body( $response );
    $http_code = wp_remote_retrieve_response_code( $response );

    // HTTP非200：根据状态码给友好提示
    if ( $http_code !== 200 ) {
        if ( $http_code === 403 ) {
            return [ 'error' => '请求被WAF拦截，可能提交内容包含敏感字符' ];
        }
        if ( $http_code === 429 ) {
            return [ 'error' => '请求频率过高，请加大间隔后重试' ];
        }
        return [ 'error' => 'HTTP ' . $http_code . ': ' . mb_strimwidth( $raw_body, 0, 200, '...', 'UTF-8' ) ];
    }

    $data = json_decode( $raw_body, true );

    // API业务错误：格式化显示错误码和msg
    if ( ! isset( $data['code'] ) || $data['code'] !== 200 ) {
        $code = $data['code'] ?? '?';
        $msg  = $data['msg'] ?? ( $data['message'] ?? '未知错误' );
        // 正文过短的API错误也标记为skip，无需等待间隔
        $is_skip = ( $code === 400 && strpos( $msg, '过短' ) !== false );
        return [ 'error' => '错误码：' . $code . '，' . $msg, 'skip' => $is_skip ];
    }

    if ( ! isset( $data['data']['summary'] ) ) {
        return [ 'error' => 'API返回数据缺少summary字段' ];
    }

    $summary = $data['data']['summary'];

    // 替换开头文字：根据文章类型选择
    $post_type     = $post_id ? get_post_type( $post_id ) : 'post';
    $is_forum_post = ( $post_type === 'forum_post' );
    $beginning_text = $is_forum_post
        ? wxs_postchat_get_option( 'forum_beginning_text', '这篇帖子介绍了' )
        : wxs_postchat_get_option( 'beginning_text', '这篇文章介绍了' );
    if ( ! empty( $beginning_text ) ) {
        if ( preg_match( '/^这篇文章[\x{4e00}-\x{9fa5}]{1,2}了/u', $summary ) ) {
            $summary = preg_replace( '/^这篇文章[\x{4e00}-\x{9fa5}]{1,2}了/u', $beginning_text, $summary );
        } elseif ( preg_match( '/^这篇文章通过/', $summary ) ) {
            $summary = preg_replace( '/^这篇文章通过/', $beginning_text . '通过', $summary );
        }
    }

    return $summary;
}

/**
 * 异步任务：生成并保存文章摘要
 * 
 * @param int $post_ID 文章ID
 * @param string $content_hash 内容哈希
 */
function wxs_postchat_generate_summary( $post_ID, $content_hash = '' ) {
    static $is_updating = false;

    if ( $is_updating ) {
        return;
    }

    if ( ! wxs_postchat_should_enable_listener() ) {
        return;
    }

    $post = get_post( $post_ID );
    if ( ! $post ) {
        return;
    }

    // 二次校验内容哈希
    if ( ! empty( $content_hash ) ) {
        $stored_hash = get_post_meta( $post_ID, '_wxs_postchat_content_hash', true );
        if ( $content_hash === $stored_hash ) {
            return;
        }
    }

    $result = wxs_postchat_get_summary_from_api(
        get_the_title( $post_ID ),
        $post->post_content,
        get_permalink( $post_ID ),
        $post_ID
    );

    // 数组表示错误，字符串表示成功
    if ( is_array( $result ) || ! is_string( $result ) || empty( $result ) ) {
        return;
    }
    $summary = $result;

    $is_updating = true;

    try {
        // 保存内容哈希
        $hash = ! empty( $content_hash ) ? $content_hash : md5( $post->post_content . $post->post_title );
        update_post_meta( $post_ID, '_wxs_postchat_content_hash', $hash );

        // 保存摘要到 post_meta
        update_post_meta( $post_ID, '_wxs_postchat_summary', $summary );
    } finally {
        $is_updating = false;
    }
}
add_action( 'wxs_postchat_generate_summary_event', 'wxs_postchat_generate_summary', 10, 2 );

/**
 * 插件停用时清理调度事件
 */
function wxs_postchat_deactivation_cleanup() {
    // 清理所有摘要任务
    $crons = _get_cron_array();
    $modified = false;

    if ( ! empty( $crons ) ) {
        foreach ( $crons as $timestamp => $cron ) {
            if ( isset( $cron['wxs_postchat_generate_summary_event'] ) ) {
                unset( $crons[ $timestamp ]['wxs_postchat_generate_summary_event'] );
                if ( empty( $crons[ $timestamp ] ) ) {
                    unset( $crons[ $timestamp ] );
                }
                $modified = true;
            }
        }
        if ( $modified ) {
            _set_cron_array( $crons );
        }
    }
}

/**
 * AJAX接口：获取缺失摘要的文章ID列表（支持post_type过滤）
 * 
 * @return void JSON响应 {success, data: {ids: [], total: int}}
 */
function wxs_postchat_ajax_batch_get_missing() {
    check_ajax_referer( 'wxs_postchat_batch_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }

    // 支持按post_type过滤
    $req_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : '';
    if ( $req_type === 'forum_post' ) {
        $post_types = [ 'forum_post' ];
    } elseif ( $req_type === 'post' ) {
        $post_types = [ 'post' ];
    } else {
        // 兼容旧调用：不传则查全部
        $post_types = [ 'post' ];
        $forum_enabled = function_exists( '_pz' ) && _pz( 'bbs_s', true );
        if ( $forum_enabled && wxs_postchat_get_option( 'enable_forum_summary', true ) ) {
            $post_types[] = 'forum_post';
        }
    }

    $args = [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [
                'key'     => '_wxs_postchat_summary',
                'compare' => 'NOT EXISTS',
            ],
            [
                'key'     => '_wxs_postchat_summary',
                'value'   => '',
                'compare' => '=',
            ],
        ],
    ];
    $query = new WP_Query( $args );

    // 排除黑名单URL
    $blacklist_urls = wxs_postchat_get_option( 'blacklist_urls', '' );
    $ids = $query->posts;

    if ( ! empty( $blacklist_urls ) && ! empty( $ids ) ) {
        // 解析黑名单规则
        $blacklist_patterns = [];
        $lines = preg_split( '/\r\n|\r|\n/', $blacklist_urls );
        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( ! empty( $line ) ) {
                // 将通配符*转换为正则表达式
                $pattern = preg_quote( $line, '/' );
                $pattern = str_replace( '\*', '.*', $pattern );
                $blacklist_patterns[] = '/^' . $pattern . '$/i';
            }
        }

        // 过滤掉匹配黑名单的文章
        if ( ! empty( $blacklist_patterns ) ) {
            $filtered_ids = [];
            foreach ( $ids as $post_id ) {
                $permalink = get_permalink( $post_id );
                $is_blacklisted = false;

                foreach ( $blacklist_patterns as $pattern ) {
                    if ( preg_match( $pattern, $permalink ) ) {
                        $is_blacklisted = true;
                        break;
                    }
                }

                if ( ! $is_blacklisted ) {
                    $filtered_ids[] = $post_id;
                }
            }
            $ids = $filtered_ids;
        }
    }

    wp_send_json_success( [
        'ids'   => $ids,
        'total' => count( $ids ),
    ] );
}
add_action( 'wp_ajax_wxs_postchat_batch_get_missing', 'wxs_postchat_ajax_batch_get_missing' );

/**
 * AJAX接口：为单篇文章生成摘要（不触发文章更新）
 * 
 * @return void JSON响应 {success, data: {post_id, title, summary}}
 */
function wxs_postchat_ajax_batch_generate_single() {
    check_ajax_referer( 'wxs_postchat_batch_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }

    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    if ( ! $post_id ) {
        wp_send_json_error( '无效的文章ID' );
    }

    $post = get_post( $post_id );
    if ( ! $post || $post->post_status !== 'publish' ) {
        wp_send_json_error( '文章不存在或未发布' );
    }

    // 检查API配置
    $api_key    = wxs_postchat_get_option( 'api_key', '' );
    $api_secret = wxs_postchat_get_option( 'api_secret', '' );
    if ( empty( $api_key ) || empty( $api_secret ) ) {
        wp_send_json_error( '请先配置 API Key 和 API Secret' );
    }

    // 调用API生成摘要（复用已有函数）
    $result = wxs_postchat_get_summary_from_api(
        get_the_title( $post_id ),
        $post->post_content,
        get_permalink( $post_id ),
        $post_id
    );

    // 数组表示错误，字符串表示成功
    if ( is_array( $result ) && isset( $result['error'] ) ) {
        $resp = [ 'message' => $result['error'] ];
        if ( ! empty( $result['skip'] ) ) {
            $resp['skip'] = true;
        }
        wp_send_json_error( $resp );
    }
    if ( ! is_string( $result ) || empty( $result ) ) {
        wp_send_json_error( [ 'message' => '摘要生成失败（未知错误）' ] );
    }
    $summary = $result;

    // 仅写入meta，不触发wp_insert_post
    $content_hash = md5( $post->post_content . $post->post_title );
    update_post_meta( $post_id, '_wxs_postchat_summary', $summary );
    update_post_meta( $post_id, '_wxs_postchat_content_hash', $content_hash );

    wp_send_json_success( [
        'post_id' => $post_id,
        'title'   => get_the_title( $post_id ),
        'summary' => mb_strimwidth( $summary, 0, 100, '...', 'UTF-8' ),
    ] );
}
add_action( 'wp_ajax_wxs_postchat_batch_generate_single', 'wxs_postchat_ajax_batch_generate_single' );

/**
 * AJAX接口：编辑AI摘要
 * 
 * @return void JSON响应 {success, data: {post_id, summary}}
 */
function wxs_postchat_ajax_edit_summary() {
    check_ajax_referer( 'wxs_postchat_edit_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }

    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    $summary  = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '';

    if ( ! $post_id ) {
        wp_send_json_error( '无效的文章ID' );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        wp_send_json_error( '文章不存在' );
    }

    // 更新摘要
    update_post_meta( $post_id, '_wxs_postchat_summary', $summary );

    wp_send_json_success( [
        'post_id' => $post_id,
        'summary' => $summary,
    ] );
}
add_action( 'wp_ajax_wxs_postchat_edit_summary', 'wxs_postchat_ajax_edit_summary' );

/**
 * AJAX接口：删除AI摘要
 * 
 * @return void JSON响应 {success, data: {post_id}}
 */
function wxs_postchat_ajax_delete_summary() {
    check_ajax_referer( 'wxs_postchat_delete_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }

    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

    if ( ! $post_id ) {
        wp_send_json_error( '无效的文章ID' );
    }

    $post = get_post( $post_id );
    if ( ! $post ) {
        wp_send_json_error( '文章不存在' );
    }

    // 删除摘要和内容hash
    delete_post_meta( $post_id, '_wxs_postchat_summary' );
    delete_post_meta( $post_id, '_wxs_postchat_content_hash' );

    wp_send_json_success( [
        'post_id' => $post_id,
    ] );
}
add_action( 'wp_ajax_wxs_postchat_delete_summary', 'wxs_postchat_ajax_delete_summary' );

/**
 * AJAX接口：搜索摘要
 * 
 * @return void JSON响应 {success, data: [{id, title, url, summary}]}
 */
function wxs_postchat_ajax_search_summary() {
    check_ajax_referer( 'wxs_postchat_edit_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }

    $keyword   = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
    $post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : 'post';

    if ( empty( $keyword ) ) {
        wp_send_json_error( '请输入搜索关键词' );
    }

    $args = [
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'meta_query'     => [
            [
                'key'     => '_wxs_postchat_summary',
                'value'   => '',
                'compare' => '!=',
            ],
        ],
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ];

    // 判断是ID还是标题搜索
    $keyword_int = intval( $keyword );
    if ( $keyword_int > 0 && strval( $keyword_int ) === $keyword ) {
        $args['p'] = $keyword_int;
    } else {
        $args['s'] = $keyword;
    }

    $query = new WP_Query( $args );
    $results = [];

    while ( $query->have_posts() ) {
        $query->the_post();
        $post_id = get_the_ID();
        $summary = get_post_meta( $post_id, '_wxs_postchat_summary', true );
        
        $results[] = [
            'id'           => $post_id,
            'title'        => get_the_title(),
            'url'          => get_permalink( $post_id ),
            'summary'      => mb_strimwidth( $summary, 0, 150, '...', 'UTF-8' ),
            'full_summary' => $summary,
        ];
    }
    wp_reset_postdata();

    wp_send_json_success( $results );
}
add_action( 'wp_ajax_wxs_postchat_search_summary', 'wxs_postchat_ajax_search_summary' );

/**
 * AJAX接口：批量删除摘要
 * 
 * @return void JSON响应 {success, data: {deleted: int}}
 */
function wxs_postchat_ajax_batch_delete_summary() {
    check_ajax_referer( 'wxs_postchat_batch_delete_nonce', 'nonce' );

    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }

    $post_ids  = isset( $_POST['post_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['post_ids'] ) ) : '';
    $post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : 'post';

    if ( empty( $post_ids ) ) {
        wp_send_json_error( '未选择要删除的摘要' );
    }

    $ids = array_map( 'intval', explode( ',', $post_ids ) );
    $deleted = 0;

    foreach ( $ids as $post_id ) {
        if ( $post_id <= 0 ) continue;
        
        // 验证文章类型
        if ( get_post_type( $post_id ) !== $post_type ) continue;

        // 删除摘要和内容hash
        if ( delete_post_meta( $post_id, '_wxs_postchat_summary' ) ) {
            delete_post_meta( $post_id, '_wxs_postchat_content_hash' );
            $deleted++;
        }
    }

    wp_send_json_success( [ 'deleted' => $deleted ] );
}
add_action( 'wp_ajax_wxs_postchat_batch_delete_summary', 'wxs_postchat_ajax_batch_delete_summary' );
