<?php
/**
 * 本站模式 — AI 对话后端
 * 
 * 功能：隐藏内容过滤 + Meilisearch RAG 检索 + WP AI Client 对话
 *
 * @package WXS_Zibll_PostChat
 * @since 1.1.0
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================== 隐藏内容过滤 ====================

/**
 * 移除文章中的子比隐藏内容块，防止AI泄露付费内容
 * 
 * 处理以下格式：
 * 1. 短代码：[hidecontent type="..."]...[/hidecontent]、[reply]...[/reply]
 * 2. Gutenberg block：<!-- wp:zibllblock/hide-content {"type":"reply"} -->...<!-- /wp:zibllblock/hide-content -->
 * 
 * 注意：区块的属性 JSON 是可选的，必须兼容「带属性」与「无属性」两种写法，
 * 否则付费/会员内容会漏进 AI 上下文。
 *
 * @param string $content 原始文章内容
 * @return string 移除隐藏块后的内容
 */
function wxs_local_strip_hidden_content( $content ) {
    if ( ! wxs_postchat_get_option( 'local_exclude_hidden', true ) ) {
        return $content;
    }

    $patterns = array(
        // 短代码：隐藏内容（reply/logged/payshow/password/vip1/vip2）
        '/\[hidecontent\b[^\]]*\].*?\[\/hidecontent\]/is',
        // 短代码：评论可见（旧写法）
        '/\[reply\b[^\]]*\].*?\[\/reply\]/is',
        // Gutenberg 区块：属性 JSON 可选（{"type":"reply"}），闭合标签前允许空白
        '/<!--\s*wp:zibllblock\/hide-content(?:\s+\{[^}]*\})?\s*\/?-->.*?<!--\s*\/wp:zibllblock\/hide-content\s*-->/is',
    );

    // 循环清理，兼容嵌套/多重隐藏块（最多 10 轮，避免异常内容导致死循环）
    foreach ( $patterns as $pattern ) {
        for ( $i = 0; $i < 10; $i++ ) {
            $replaced = preg_replace( $pattern, '', $content );
            if ( null === $replaced || $replaced === $content ) {
                break;
            }
            $content = $replaced;
        }
    }

    return $content;
}

// ==================== Meilisearch 配置读取 ====================

/**
 * 从主题设置读取 Meilisearch 配置
 *
 * 复用子比主题 zib_get_search_ms_opt() 获取 search_ms_opt 配置数组，
 * 包含 api_url、api_key、index_prefix 等字段。
 *
 * @return array
 */
function wxs_local_get_ms_args() {
    // 优先使用主题提供的函数（含同义词等扩展处理）
    if ( function_exists( 'zib_get_search_ms_opt' ) ) {
        return zib_get_search_ms_opt();
    }
    // 兑容：直接读取 search_ms_opt 字段
    $opt = _pz( 'search_ms_opt', [] );
    return is_array( $opt ) ? $opt : [];
}

// ==================== RAG 检索 ====================

/**
 * 通过 Meilisearch 检索与问题相关的文章内容片段
 *
 * @param string $question 用户问题
 * @param int    $limit    检索条数
 * @return string 拼接后的上下文文本
 */
function wxs_local_chat_search_context( $question, $limit = 5 ) {
    // 使用主题已有的 Meilisearch 实例
    $ms_args = wxs_local_get_ms_args();

    try {
        $ms = new zib_ms( $ms_args );

        $result = $ms->postIndex->search( $question, [
            'attributesToRetrieve' => [ 'id', 'post_title', 'post_content', 'post_excerpt' ],
            'limit'                => $limit,
        ] );

        $context_parts = [];
        $hits = $result->toArray()['hits'] ?? [];

        foreach ( $hits as $hit ) {
            $raw = $hit['post_content'] ?? '';
            // 过滤隐藏内容（防止付费/VIP内容泄露）
            $safe_content = wxs_local_strip_hidden_content( $raw );
            // 清除HTML标签并截取
            $content = mb_strimwidth( wp_strip_all_tags( $safe_content ), 0, 500, '...', 'UTF-8' );
            if ( ! empty( $content ) ) {
                $context_parts[] = "【{$hit['post_title']}】\n{$content}";
            }
        }

        return implode( "\n---\n", $context_parts );
    } catch ( \Exception $e ) {
        // Meilisearch 不可用时降级：返回空上下文（对话仍可工作）
        return '';
    }
}

// ==================== AJAX 对话端点 ====================

add_action( 'wp_ajax_wxs_local_chat', 'wxs_local_chat_handler' );
add_action( 'wp_ajax_nopriv_wxs_local_chat', 'wxs_local_chat_handler' );

// 动态获取对话 nonce（页面可能被 Nginx 缓存，不能在 PHP 中直接输出 nonce）
add_action( 'wp_ajax_wxs_local_get_nonce', 'wxs_local_get_nonce_handler' );
add_action( 'wp_ajax_nopriv_wxs_local_get_nonce', 'wxs_local_get_nonce_handler' );

/**
 * 返回对话所需的 nonce
 */
function wxs_local_get_nonce_handler() {
    wp_send_json_success( [ 'nonce' => wp_create_nonce( 'wxs_local_chat_nonce' ) ] );
}

/**
 * 解析前端传入的多轮对话历史
 *
 * 前端以 JSON 字符串提交 history 字段：[{role:'user'|'bot', content:'...'}, ...]
 * 按「上下文轮数上限」设置截取最近 N 轮（1轮 = user + bot），并清洗内容。
 *
 * @return array 形如 [['role'=>'user','content'=>'...'], ...]，关闭多轮时返回空数组
 */
function wxs_local_parse_chat_history() {
    $limit = (int) wxs_postchat_get_option( 'local_context_limit', 5 );
    if ( $limit <= 0 || empty( $_POST['history'] ) ) {
        return [];
    }

    $raw = json_decode( wp_unslash( $_POST['history'] ), true );
    if ( ! is_array( $raw ) ) {
        return [];
    }

    $clean = [];
    foreach ( $raw as $item ) {
        if ( ! is_array( $item ) || empty( $item['role'] ) || ! isset( $item['content'] ) ) {
            continue;
        }
        $role    = ( $item['role'] === 'user' ) ? 'user' : 'bot';
        $content = sanitize_textarea_field( wp_strip_all_tags( (string) $item['content'] ) );
        if ( $content === '' ) {
            continue;
        }
        // 单条历史截断，防止超长 token
        $clean[] = [ 'role' => $role, 'content' => mb_strimwidth( $content, 0, 800, '...', 'UTF-8' ) ];
    }

    // 仅保留最近 N 轮（N*2 条消息）
    $max_msgs = $limit * 2;
    if ( count( $clean ) > $max_msgs ) {
        $clean = array_slice( $clean, -$max_msgs );
    }

    return $clean;
}

/**
 * 服务端查询会话统计（轮数、token）
 * 日志开启时从 wxs_chat_logs 查询，关闭时用 transient 兜底
 *
 * @param string $session_id 会话 ID
 * @return array{rounds: int, tokens: int}
 */
function wxs_local_get_session_stats( $session_id ) {
    if ( empty( $session_id ) ) {
        return [ 'rounds' => 0, 'tokens' => 0 ];
    }

    $log_raw     = wxs_postchat_get_option( 'local_chat_log_enabled', true );
    $log_enabled = ( $log_raw === '' || $log_raw === null ) ? true : (bool) $log_raw;

    if ( $log_enabled ) {
        global $wpdb;
        $table = $wpdb->prefix . 'wxs_chat_logs';
        // 轮数按 user 消息计；token 统计全部记录（user + bot），与 transient 分支口径一致
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT SUM(CASE WHEN role = 'user' THEN 1 ELSE 0 END) AS round_count, COALESCE(SUM(tokens_used), 0) AS total_tokens FROM {$table} WHERE session_id = %s",
            $session_id
        ) );
        return [
            'rounds' => $row ? (int) $row->round_count : 0,
            'tokens' => $row ? (int) $row->total_tokens : 0,
        ];
    }

    // 日志关闭时读取 transient
    $stats = get_transient( 'wxs_sess_' . md5( $session_id ) );
    if ( ! is_array( $stats ) ) {
        $stats = [ 'rounds' => 0, 'tokens' => 0 ];
    }
    return $stats;
}

/**
 * AI 对话 AJAX 处理器
 * 
 * 流程：IP频率限制 → Meilisearch检索 → 构建prompt → WP AI Client生成 → 返回
 */
function wxs_local_chat_handler() {
    // CSRF 防护（nonce 由前端异步获取，失效时返回 JSON 便于前端刷新重试）
    if ( ! check_ajax_referer( 'wxs_local_chat_nonce', 'nonce', false ) ) {
        wp_send_json_error( [ 'message' => '页面校验已过期，请重试', 'code' => 'invalid_nonce' ] );
    }

    // 游客权限校验（CSF switcher 未保存时返回空字符串，视为默认允许游客）
    $user_id = get_current_user_id();
    $guest_raw = wxs_postchat_get_option( 'local_chat_guest_allowed', true );
    $guest_allowed = ( '' === $guest_raw || null === $guest_raw ) ? true : (bool) $guest_raw;
    if ( ! $guest_allowed && ! $user_id ) {
        wp_send_json_error( [ 'message' => '请先登录后再使用AI对话', 'code' => 'login_required' ] );
    }

    // 频率限制（IP级别，transient 持久化到数据库，每分钟最多10次）
    $ip       = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
    $rate_key = 'wxs_chat_rate_' . md5( $ip );
    $count    = (int) get_transient( $rate_key );

    if ( $count >= 10 ) {
        wp_send_json_error( [ 'message' => '请求过于频繁，请稍后再试' ] );
    }
    set_transient( $rate_key, $count + 1, 60 );

    // 每日对话次数校验
    $daily_limit = (int) wxs_postchat_get_option( 'local_chat_daily_limit', 1000 );
    if ( $daily_limit > 0 ) {
        $daily_key = 'wxs_chat_daily_' . ( $user_id ? $user_id : md5( $ip ) );
        $daily_count = (int) get_transient( $daily_key );
        if ( $daily_count >= $daily_limit ) {
            wp_send_json_error( [ 'message' => '今日对话次数已达上限（' . $daily_limit . '次），请明日再来', 'code' => 'daily_limit' ] );
        }
        set_transient( $daily_key, $daily_count + 1, DAY_IN_SECONDS );
    }

    // 获取并验证问题
    $question = isset( $_POST['question'] ) ? sanitize_textarea_field( wp_unslash( $_POST['question'] ) ) : '';
    if ( empty( $question ) ) {
        wp_send_json_error( [ 'message' => '请输入问题' ] );
    }

    // 单会话轮数/token校验（服务端查询，不信任客户端值）
    $session_id    = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
    $session_stats = wxs_local_get_session_stats( $session_id );
    $session_rounds = $session_stats['rounds'];
    $session_tokens = $session_stats['tokens'];

    $max_rounds = (int) wxs_postchat_get_option( 'local_chat_session_rounds', 10 );
    if ( $max_rounds > 0 && $session_rounds >= $max_rounds ) {
        wp_send_json_error( [ 'message' => '当前会话已达' . $max_rounds . '轮上限，请新建会话继续对话', 'code' => 'session_rounds_limit' ] );
    }

    $max_tokens = (int) wxs_postchat_get_option( 'local_chat_session_tokens', 10000 );
    if ( $max_tokens > 0 && $session_tokens >= $max_tokens ) {
        wp_send_json_error( [ 'message' => '当前会话上下文已达token上限，请新建会话继续对话', 'code' => 'session_tokens_limit' ] );
    }

    // 解析多轮对话历史（前端传入 JSON：[{role:'user'|'bot', content:'...'}, ...]）
    $history = wxs_local_parse_chat_history();

    // 1. Meilisearch 检索上下文
    $context = wxs_local_chat_search_context( $question );

    // 2. 构建 system prompt（从设置中读取人格模板，与设置页默认值共用同一份文本）
    $default_template = wxs_postchat_default_local_prompt();
    $template = wxs_postchat_get_option( 'local_system_prompt', $default_template );
    // 已保存但内容为空时同样回退到默认模板，避免把空提示词发给模型
    if ( '' === trim( (string) $template ) ) {
        $template = $default_template;
    }

    // 自定义知识库匹配（优先级高于文章检索）
    $custom_knowledge = function_exists( 'wxs_local_match_custom_knowledge' )
        ? wxs_local_match_custom_knowledge( $question ) : '';
    if ( ! empty( $custom_knowledge ) ) {
        $context = $custom_knowledge . "\n---\n" . $context;
    }

    $system = str_replace(
        [ '{{question}}', '{{context}}' ],
        [ $question, $context ],
        $template
    );

    // 隐藏内容保护：知识库片段中已剔除受限内容，同时禁止 AI 猜测/编造
    if ( wxs_postchat_get_option( 'local_exclude_hidden', true ) ) {
        $system .= "\n\n# 受限内容约束\n文章中的付费、会员、密码、登录可见、评论可见等隐藏内容不会出现在知识库中。若访客询问这些受限内容，请说明该部分需要满足相应条件（如登录、评论、付费或开通会员）后才能查看，禁止猜测、编造或复述受限内容。";
    }

    // 3. 直接调用 WP AI Client 原生接口
    if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
        wp_send_json_error( [ 'message' => 'AI 服务不可用，请检查 AI 渠道配置' ] );
    }

    // 多轮上下文：将历史对话拼到本次问题前，让 AI 记住前几轮内容
    $prompt = $question;
    if ( ! empty( $history ) ) {
        $history_text = '';
        foreach ( $history as $turn ) {
            $role_label = ( $turn['role'] === 'user' ) ? '访客' : '客服';
            $history_text .= "{$role_label}：{$turn['content']}\n";
        }
        $prompt = "# 历史对话（供你理解上下文，最新问题在最后）\n{$history_text}\n# 访客当前问题\n{$question}";
    }

    $builder = wp_ai_client_prompt( $prompt )
        ->using_system_instruction( $system )
        ->using_temperature( 0.7 )
        ->using_max_tokens( 2000 );

    $model_prefs = wxs_postchat_get_model_preferences();
    if ( ! empty( $model_prefs ) ) {
        $builder = $builder->using_model_preference( ...$model_prefs );
    }

    $result = $builder->generate_text();

    if ( is_wp_error( $result ) ) {
        // 仅记录详细错误到服务器日志，前台不暴露内部API信息
        error_log( '[WXS-PostChat] AI生成失败: ' . $result->get_error_message() );
        // 触发 wxs-ai-channels 的失败通知（如果可用）
        if ( function_exists( 'wxs_ai_notify_failure' ) ) {
            wxs_ai_notify_failure( $result->get_error_message(), [ 'tag' => 'postchat_chat' ] );
        }
        wp_send_json_error( [ 'message' => 'AI 服务暂时不可用，请稍后再试' ] );
    }

    $answer = (string) $result;

    // 对话记录存储
    $log_raw = wxs_postchat_get_option( 'local_chat_log_enabled', true );
    // CSF switcher 未保存时返回空字符串，视为默认开启；仅当明确设为 '0' 或 false 才关闭
    $log_enabled = ( $log_raw === '' || $log_raw === null ) ? true : (bool) $log_raw;
    if ( $log_enabled ) {
        // session_id 已在上方轮数校验处读取
        // 记录完整 IP，便于管理员排查来源（仅后台可见）
        wxs_local_save_chat_log( $session_id, $user_id, $ip, 'user', $question );
        wxs_local_save_chat_log( $session_id, $user_id, $ip, 'bot', $answer );
    } elseif ( ! empty( $session_id ) ) {
        // 日志关闭时用 transient 追踪会话统计（供轮数/token 校验使用）
        $trans_key = 'wxs_sess_' . md5( $session_id );
        $stats = get_transient( $trans_key );
        if ( ! is_array( $stats ) ) {
            $stats = [ 'rounds' => 0, 'tokens' => 0 ];
        }
        $stats['rounds']++;
        $stats['tokens'] += mb_strlen( $question . $answer, 'UTF-8' ) * 2;
        set_transient( $trans_key, $stats, HOUR_IN_SECONDS );
    }

    wp_send_json_success( [ 'answer' => $answer ] );
}

// ==================== 本站模式批量生成摘要 ====================

add_action( 'wp_ajax_wxs_postchat_batch_generate_single', 'wxs_local_batch_generate_single' );

/**
 * 本站模式：为单篇文章生成摘要（复用洪墨模式的 AJAX action 名，确保前端面板 JS 兼容）
 */
function wxs_local_batch_generate_single() {
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

    // 摘要功能未开启或文章在黑名单中时不再消耗 AI 额度
    if ( $post->post_type === 'forum_post' ) {
        if ( ! wxs_postchat_get_option( 'local_forum_summary', false ) ) {
            wp_send_json_error( [ 'message' => '帖子摘要功能未开启，已跳过', 'skip' => true ] );
        }
    } elseif ( $post->post_type === 'post' ) {
        if ( ! wxs_postchat_get_option( 'local_article_summary', true ) ) {
            wp_send_json_error( [ 'message' => '文章摘要功能未开启，已跳过', 'skip' => true ] );
        }
    } else {
        wp_send_json_error( [ 'message' => '不支持的内容类型' ] );
    }
    if ( wxs_local_is_summary_blacklisted( $post_id ) ) {
        wp_send_json_error( [ 'message' => '该文章在摘要黑名单中，已跳过', 'skip' => true ] );
    }

    // WP AI Client 可用性检查
    if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
        wp_send_json_error( [ 'message' => 'WP AI Client 不可用，请检查 AI 渠道配置' ] );
    }

    // 移除隐藏内容 + 清理 HTML（本站模式固定 5000 字上限，不受洪墨「字数限制」影响）
    $clean_content = wxs_local_strip_hidden_content( $post->post_content );
    $clean_content = wxs_postchat_clean_html_content( $clean_content, 5000 );

    // 正文过短跳过
    if ( mb_strlen( $clean_content, 'UTF-8' ) < 50 ) {
        wp_send_json_error( [ 'message' => '正文过短（不足50字），已跳过', 'skip' => true ] );
    }

    // 根据文章类型选择开头文字
    $is_forum  = ( $post->post_type === 'forum_post' );
    $beginning = $is_forum
        ? wxs_postchat_get_option( 'local_forum_beginning_text', '这篇帖子介绍了' )
        : wxs_postchat_get_option( 'local_beginning_text', '这篇文章介绍了' );

    $prompt = "请用一句话概括以下文章内容，以「{$beginning}」开头，不超过150字：\n\n标题：" . get_the_title( $post_id ) . "\n\n{$clean_content}";

    $builder = wp_ai_client_prompt( $prompt )
        ->using_system_instruction( '你是一个文章摘要助手，请精炼概括文章核心内容。' )
        ->using_temperature( 0.3 )
        ->using_max_tokens( 300 );

    $model_prefs = wxs_postchat_get_model_preferences();
    if ( ! empty( $model_prefs ) ) {
        $builder = $builder->using_model_preference( ...$model_prefs );
    }

    $result = $builder->generate_text();

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( [ 'message' => '生成失败：' . $result->get_error_message() ] );
    }
    if ( empty( $result ) ) {
        wp_send_json_error( [ 'message' => '摘要生成失败（空结果）' ] );
    }

    $summary = (string) $result;

    // 写入 meta
    $content_hash = md5( $post->post_content . $post->post_title );
    update_post_meta( $post_id, '_wxs_postchat_summary', $summary );
    update_post_meta( $post_id, '_wxs_postchat_content_hash', $content_hash );

    wp_send_json_success( [
        'post_id' => $post_id,
        'title'   => get_the_title( $post_id ),
        'summary' => mb_strimwidth( $summary, 0, 100, '...', 'UTF-8' ),
    ] );
}

// ==================== 摘要管理 AJAX Handlers（本站模式复用） ====================
// 以下函数在洪墨模式下由 listener.php 注册，本站模式下需在此注册同名 action。
// 这些是纯 meta CRUD 操作，与 AI 模式无关。

add_action( 'wp_ajax_wxs_postchat_batch_get_missing', 'wxs_local_ajax_batch_get_missing' );
function wxs_local_ajax_batch_get_missing() {
    check_ajax_referer( 'wxs_postchat_batch_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }

    $req_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : 'post';
    $post_types = ( $req_type === 'forum_post' ) ? [ 'forum_post' ] : [ 'post' ];

    // 对应内容类型的摘要功能未开启时，批量生成没有意义，直接提示
    if ( $req_type === 'forum_post' ) {
        if ( ! wxs_postchat_get_option( 'local_forum_summary', false ) ) {
            wp_send_json_error( [ 'message' => '帖子摘要功能未开启，请先在上方开启' ] );
        }
    } elseif ( ! wxs_postchat_get_option( 'local_article_summary', true ) ) {
        wp_send_json_error( [ 'message' => '文章摘要功能未开启，请先在上方开启' ] );
    }

    $args = [
        'post_type'      => $post_types,
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            'relation' => 'OR',
            [ 'key' => '_wxs_postchat_summary', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_wxs_postchat_summary', 'value' => '', 'compare' => '=' ],
        ],
    ];
    $query = new WP_Query( $args );
    $ids = $query->posts;

    // 排除黑名单
    $blacklist = wxs_postchat_get_option( 'local_blacklist_ids', '' );
    if ( ! empty( $blacklist ) ) {
        $bl_ids = array_map( 'intval', array_filter( explode( ',', $blacklist ) ) );
        $ids = array_diff( $ids, $bl_ids );
    }

    wp_send_json_success( [ 'ids' => array_values( $ids ), 'total' => count( $ids ) ] );
}

add_action( 'wp_ajax_wxs_postchat_edit_summary', 'wxs_local_ajax_edit_summary' );
function wxs_local_ajax_edit_summary() {
    check_ajax_referer( 'wxs_postchat_edit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }
    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    $summary = isset( $_POST['summary'] ) ? sanitize_textarea_field( wp_unslash( $_POST['summary'] ) ) : '';
    if ( ! $post_id || ! get_post( $post_id ) ) {
        wp_send_json_error( '无效的文章ID' );
    }
    update_post_meta( $post_id, '_wxs_postchat_summary', $summary );
    wp_send_json_success( [ 'post_id' => $post_id, 'summary' => $summary ] );
}

add_action( 'wp_ajax_wxs_postchat_delete_summary', 'wxs_local_ajax_delete_summary' );
function wxs_local_ajax_delete_summary() {
    check_ajax_referer( 'wxs_postchat_delete_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }
    $post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;
    if ( ! $post_id || ! get_post( $post_id ) ) {
        wp_send_json_error( '无效的文章ID' );
    }
    delete_post_meta( $post_id, '_wxs_postchat_summary' );
    delete_post_meta( $post_id, '_wxs_postchat_content_hash' );
    wp_send_json_success( [ 'post_id' => $post_id ] );
}

add_action( 'wp_ajax_wxs_postchat_search_summary', 'wxs_local_ajax_search_summary' );
function wxs_local_ajax_search_summary() {
    check_ajax_referer( 'wxs_postchat_edit_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }
    $keyword   = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
    $post_type = isset( $_POST['post_type'] ) ? sanitize_key( $_POST['post_type'] ) : 'post';
    // 仅允许查询支持摘要的内容类型
    if ( ! in_array( $post_type, [ 'post', 'forum_post' ], true ) ) {
        $post_type = 'post';
    }
    if ( empty( $keyword ) ) {
        wp_send_json_error( '请输入搜索关键词' );
    }
    $args = [
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'meta_query'     => [ [ 'key' => '_wxs_postchat_summary', 'value' => '', 'compare' => '!=' ] ],
        'orderby'        => 'modified',
        'order'          => 'DESC',
    ];
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
        $pid = get_the_ID();
        $summary = get_post_meta( $pid, '_wxs_postchat_summary', true );
        $results[] = [
            'id'           => $pid,
            'title'        => get_the_title(),
            'url'          => get_permalink( $pid ),
            'summary'      => mb_strimwidth( $summary, 0, 150, '...', 'UTF-8' ),
            'full_summary' => $summary,
        ];
    }
    wp_reset_postdata();
    wp_send_json_success( $results );
}

add_action( 'wp_ajax_wxs_postchat_batch_delete_summary', 'wxs_local_ajax_batch_delete_summary' );
function wxs_local_ajax_batch_delete_summary() {
    check_ajax_referer( 'wxs_postchat_batch_delete_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '权限不足' );
    }
    $post_ids  = isset( $_POST['post_ids'] ) ? sanitize_text_field( wp_unslash( $_POST['post_ids'] ) ) : '';
    if ( empty( $post_ids ) ) {
        wp_send_json_error( '未选择要删除的摘要' );
    }
    $ids = array_map( 'intval', explode( ',', $post_ids ) );
    $deleted = 0;
    foreach ( $ids as $pid ) {
        if ( $pid > 0 && delete_post_meta( $pid, '_wxs_postchat_summary' ) ) {
            delete_post_meta( $pid, '_wxs_postchat_content_hash' );
            $deleted++;
        }
    }
    wp_send_json_success( [ 'deleted' => $deleted ] );
}

// ==================== 对话记录存储与管理 ====================

/**
 * 保存单条对话记录到数据库
 */
function wxs_local_save_chat_log( $session_id, $user_id, $ip, $role, $message ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wxs_chat_logs';

    $wpdb->insert( $table, [
        'session_id' => $session_id,
        'user_id'    => (int) $user_id,
        'user_ip'    => $ip,
        'role'       => $role,
        'message'    => $message,
        'tokens_used' => mb_strlen( $message, 'UTF-8' ) * 2,
        'created_at' => current_time( 'mysql' ),
    ], [ '%s', '%d', '%s', '%s', '%s', '%d', '%s' ] );

    // 检查存储上限，超限时清理
    wxs_local_maybe_cleanup_logs( $user_id );
}

/**
 * 检查并清理超限的对话记录
 */
function wxs_local_maybe_cleanup_logs( $user_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wxs_chat_logs';

    // 单用户上限
    $per_user_max = (int) wxs_postchat_get_option( 'local_chat_log_max_per_user', 20 );
    if ( $per_user_max > 0 && $user_id > 0 ) {
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE user_id = %d", $user_id
        ) );
        if ( $count > $per_user_max ) {
            $delete_count = $count - $per_user_max;
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} WHERE user_id = %d ORDER BY created_at ASC LIMIT %d",
                $user_id, $delete_count
            ) );
        }
    }

    // 全平台上限（低频检查，1%概率触发）
    if ( wp_rand( 1, 100 ) > 1 ) {
        return;
    }
    $total_max = (int) wxs_postchat_get_option( 'local_chat_log_max_total', 200 );
    if ( $total_max > 0 ) {
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        if ( $total > $total_max ) {
            $delete_count = $total - $total_max;
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$table} ORDER BY created_at ASC LIMIT %d", $delete_count
            ) );
        }
    }
}

// ==================== 后台对话记录管理页面 ====================

add_action( 'admin_menu', 'wxs_local_chat_logs_admin_menu', 99 );
function wxs_local_chat_logs_admin_menu() {
    if ( wxs_postchat_get_mode() !== 'local' ) {
        return;
    }
    add_submenu_page(
        'wxs-zibll-postchat',
        '对话记录',
        '对话记录',
        'manage_options',
        'wxs-chat-logs',
        'wxs_local_chat_logs_admin_page'
    );
}

function wxs_local_chat_logs_admin_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'wxs_chat_logs';

    // 处理删除操作
    if ( isset( $_POST['wxs_delete_session'] ) && check_admin_referer( 'wxs_chat_logs_action' ) ) {
        $del_session = sanitize_text_field( wp_unslash( $_POST['wxs_delete_session'] ) );
        $wpdb->delete( $table, [ 'session_id' => $del_session ], [ '%s' ] );
        echo '<div class="notice notice-success"><p>会话已删除。</p></div>';
    }

    // 详情视图
    $detail_session = isset( $_GET['session'] ) ? sanitize_text_field( wp_unslash( $_GET['session'] ) ) : '';
    ?>
    <div class="wrap">
        <h1>AI对话记录</h1>
        <?php if ( $detail_session ) : ?>
            <?php wxs_local_render_chat_detail( $detail_session ); ?>
        <?php else : ?>
            <?php wxs_local_render_chat_list(); ?>
        <?php endif; ?>
    </div>
    <?php
}

function wxs_local_render_chat_list() {
    global $wpdb;
    $table = $wpdb->prefix . 'wxs_chat_logs';

    $per_page = 30;
    $page_num = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
    $offset   = ( $page_num - 1 ) * $per_page;

    $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
    $where  = '';
    if ( $search ) {
        $like  = '%' . $wpdb->esc_like( $search ) . '%';
        $where = $wpdb->prepare( " WHERE message LIKE %s OR user_ip LIKE %s OR session_id LIKE %s", $like, $like, $like );
    }

    $sessions = $wpdb->get_results(
        "SELECT session_id, MAX(user_id) as user_id,
         GROUP_CONCAT(DISTINCT NULLIF(user_ip, '') ORDER BY user_ip SEPARATOR ', ') as ip_list,
         MIN(created_at) as started_at, COUNT(*) as msg_count,
         SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN role='user' THEN message END ORDER BY created_at ASC SEPARATOR '|||'), '|||', 1) as first_msg
         FROM {$table}{$where} GROUP BY session_id ORDER BY started_at DESC LIMIT {$per_page} OFFSET {$offset}"
    );
    $total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT session_id) FROM {$table}{$where}" );
    ?>
    <form method="get" style="margin-bottom:15px;">
        <input type="hidden" name="page" value="wxs-chat-logs">
        <input type="text" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="搜索消息/IP/会话ID..." class="regular-text">
        <button type="submit" class="button">搜索</button>
    </form>
    <table class="widefat striped">
        <thead><tr>
            <th>会话ID</th><th>发起者</th><th>IP</th>
            <th>时间</th><th>消息数</th><th>首条消息</th><th>操作</th>
        </tr></thead>
        <tbody>
        <?php if ( empty( $sessions ) ) : ?>
            <tr><td colspan="7">暂无对话记录</td></tr>
        <?php else : foreach ( $sessions as $s ) :
            $username = '游客';
            if ( $s->user_id ) {
                $u = get_userdata( $s->user_id );
                $username = ( $u ? $u->display_name : '用户#' . $s->user_id ) . '（ID：' . (int) $s->user_id . '）';
            }
        ?>
            <tr>
                <td><code><?php echo esc_html( substr( $s->session_id, 0, 12 ) ); ?>...</code></td>
                <td><?php echo esc_html( $username ); ?></td>
                <td><?php echo esc_html( $s->ip_list ? $s->ip_list : '未知' ); ?></td>
                <td><?php echo esc_html( $s->started_at ); ?></td>
                <td><?php echo (int) $s->msg_count; ?></td>
                <td><?php echo esc_html( mb_strimwidth( $s->first_msg ?? '', 0, 40, '...', 'UTF-8' ) ); ?></td>
                <td>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=wxs-chat-logs&session=' . urlencode( $s->session_id ) ) ); ?>">详情</a>
                    <form method="post" style="display:inline;" onsubmit="return confirm('确定删除此会话？');">
                        <?php wp_nonce_field( 'wxs_chat_logs_action' ); ?>
                        <input type="hidden" name="wxs_delete_session" value="<?php echo esc_attr( $s->session_id ); ?>">
                        <button type="submit" class="button-link" style="color:#dc3232;">删除</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php
    $total_pages = ceil( $total / $per_page );
    if ( $total_pages > 1 ) {
        echo '<div class="tablenav"><div class="tablenav-pages">';
        echo paginate_links( [
            'base'    => add_query_arg( 'paged', '%#%' ),
            'format'  => '',
            'current' => $page_num,
            'total'   => $total_pages,
        ] );
        echo '</div></div>';
    }
}

function wxs_local_render_chat_detail( $session_id ) {
    global $wpdb;
    $table = $wpdb->prefix . 'wxs_chat_logs';

    $messages = $wpdb->get_results( $wpdb->prepare(
        "SELECT * FROM {$table} WHERE session_id = %s ORDER BY created_at ASC", $session_id
    ) );

    if ( empty( $messages ) ) {
        echo '<p>该会话不存在或已被删除。</p>';
        echo '<a href="' . esc_url( admin_url( 'admin.php?page=wxs-chat-logs' ) ) . '" class="button">← 返回列表</a>';
        return;
    }

    // 会话可能从游客开始、登录后继续，取最大的已登录用户 ID
    $session_user_id = 0;
    foreach ( $messages as $m ) {
        if ( (int) $m->user_id > $session_user_id ) {
            $session_user_id = (int) $m->user_id;
        }
    }
    $username = '游客';
    if ( $session_user_id ) {
        $u = get_userdata( $session_user_id );
        $username = $u ? $u->display_name : '用户#' . $session_user_id;
    }

    // 会话涉及的全部 IP 及出现次数
    $ip_rows = $wpdb->get_results( $wpdb->prepare(
        "SELECT user_ip, COUNT(*) AS cnt FROM {$table} WHERE session_id = %s AND user_ip <> '' GROUP BY user_ip ORDER BY cnt DESC",
        $session_id
    ) );
    $ip_items = [];
    foreach ( $ip_rows as $row ) {
        $ip_items[] = $row->user_ip . '（' . (int) $row->cnt . ' 条）';
    }
    ?>
    <p>
        <a href="<?php echo esc_url( admin_url( 'admin.php?page=wxs-chat-logs' ) ); ?>" class="button">← 返回列表</a>
        <span style="margin-left:15px;">发起者：<strong><?php echo esc_html( $username ); ?></strong><?php if ( $session_user_id ) : ?> / ID：<?php echo (int) $session_user_id; ?><?php endif; ?></span>
        <span style="margin-left:15px;">IP：<?php echo esc_html( $ip_items ? implode( '、', $ip_items ) : '未知' ); ?></span>
        <span style="margin-left:15px;">会话ID：<code><?php echo esc_html( $session_id ); ?></code></span>
    </p>
    <div style="max-width:700px;border:1px solid #ddd;border-radius:8px;padding:15px;background:#f9f9f9;">
    <?php foreach ( $messages as $msg ) : ?>
        <div style="margin-bottom:12px;display:flex;<?php echo $msg->role === 'user' ? 'justify-content:flex-end;' : ''; ?>">
            <div style="max-width:80%;padding:8px 12px;border-radius:8px;<?php
                echo $msg->role === 'user'
                    ? 'background:#1890ff;color:#fff;'
                    : 'background:#fff;border:1px solid #e8e8e8;';
            ?>">
                <div style="font-size:12px;color:<?php echo $msg->role === 'user' ? 'rgba(255,255,255,.7)' : '#999'; ?>;margin-bottom:4px;">
                    <?php echo $msg->role === 'user' ? '用户' : 'AI'; ?> · <?php echo esc_html( $msg->created_at ); ?>
                </div>
                <div style="white-space:pre-wrap;word-break:break-word;"><?php echo esc_html( $msg->message ); ?></div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>
    <?php
}
