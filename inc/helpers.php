<?php
/**
 * 共享工具函数（两种模式共用）
 *
 * @package WXS_Zibll_PostChat
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 清洗文章内容为纯文本，用于发送给 AI
 *
 * 移除 HTML 标签、短代码、代码块、脚本等，保留段落结构
 *
 * @param string   $content    原始文章内容
 * @param int|null $word_limit 字数上限；null 时读取洪墨模式的「字数限制」设置，0 表示不限制
 * @return string 清洗后的纯文本
 */
function wxs_postchat_clean_html_content( $content, $word_limit = null ) {
    $content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );

    // 移除 WordPress 块标记
    $content = preg_replace( '/<!--\s*\/?wp:(.*?)-->/', '', $content );
    $content = preg_replace( '/<!--(.*?)-->/', '', $content );

    // 移除代码块（防止代码内容触发 WAF 拦截）
    $content = preg_replace( '/<pre[^>]*>.*?<\/pre>/is', '', $content );
    $content = preg_replace( '/<code[^>]*>.*?<\/code>/is', '', $content );
    $content = preg_replace( '/<script[^>]*>.*?<\/script>/is', '', $content );
    $content = preg_replace( '/<style[^>]*>.*?<\/style>/is', '', $content );
    $content = preg_replace( '/<textarea[^>]*>.*?<\/textarea>/is', '', $content );

    // 移除短代码
    $content = strip_shortcodes( $content );

    // 保留段落分隔后去除 HTML
    $content = str_replace(
        [ '</p>', '</h1>', '</h2>', '</h3>', '</h4>', '</h5>', '</h6>', '<br>', '<br />' ],
        "\n",
        $content
    );
    $content = wp_strip_all_tags( $content, true );

    // 清理空白
    $content = preg_replace( '/\s+/', ' ', $content );
    $content = trim( $content );

    // 限制字数（未显式传入时沿用洪墨模式的「字数限制」设置）
    if ( null === $word_limit ) {
        $word_limit = intval( wxs_postchat_get_option( 'word_limit', '5000' ) );
    }
    if ( $word_limit > 0 && mb_strlen( $content, 'UTF-8' ) > $word_limit ) {
        $content = mb_substr( $content, 0, $word_limit, 'UTF-8' ) . '...';
    }

    return $content;
}

/**
 * 本站对话模式的默认人格提示词
 *
 * 设置页的默认值与服务端兜底共用同一份文本，避免两处文案不一致
 * 支持 {{question}} 用户问题、{{context}} 知识库检索结果两个变量
 *
 * @return string
 */
function wxs_postchat_default_local_prompt() {
    return <<<'PROMPT'
# 角色
你是一位专业的网站客服助手，必须严格基于【知识库】内容回答，知识库中只有文章，没有文章以外的内容。如果知识库中没有相关信息，请引导用户联系人工客服。

# 核心原则
1. **只回答与本站内容相关的问题**，拒绝回答无关话题（如天气、股票等）。
2. **不确定就不知道**：如果问题超出知识库范围或信息不全，主动道歉并建议联系人工客服。
3. **语气友好专业**：使用"您好""谢谢理解"等敬语，避免否定用户（不说"你错了"，可说"这可能存在误解"）。
4. **回答不要像文章**：主要以重要内容短句回答，不要长篇大论。

# 输出格式
- 短句分段，每段不超过3行。
- 重要步骤使用数字列表（如"1. 登录账号；2. 点击…"）。

# 知识库参考
{{context}}

# 用户问题
{{question}}
PROMPT;
}

/**
 * 计算相关推荐缓存时长（秒）
 *
 * 两种服务模式各自独立设置，通过 $key_prefix 区分：
 * - 洪墨模式：related_cache_ttl / related_cache_days
 * - 本站模式：local_recommend_cache_ttl / local_recommend_cache_days
 *
 * @param string $key_prefix 设置键前缀
 * @return int 缓存秒数
 */
function wxs_postchat_related_cache_ttl( $key_prefix = 'related' ) {
    $ttl = (string) wxs_postchat_get_option( $key_prefix . '_cache_ttl', '12' );
    if ( 'custom' === $ttl ) {
        $days = (int) wxs_postchat_get_option( $key_prefix . '_cache_days', 7 );
        return max( 1, $days ) * DAY_IN_SECONDS;
    }
    // 其余选项直接存小时数（12 / 168 / 720）
    return max( 1, (int) $ttl ) * HOUR_IN_SECONDS;
}

/**
 * 获取可用的 AI 渠道选项（供设置下拉选择）
 *
 * 从 wxs-ai-channels 插件动态读取已启用的渠道列表
 *
 * @return array [preset_id => name] 如 ['siliconflow' => '硅基流动', 'deepseek' => 'DeepSeek']
 */
function wxs_postchat_get_available_channels() {
    if ( ! function_exists( 'wxs_ai_channels_get_channels' ) ) {
        return [];
    }
    $channels = wxs_ai_channels_get_channels();
    $options = [];
    foreach ( $channels as $ch ) {
        if ( ! empty( $ch['preset_id'] ) && ! empty( $ch['name'] ) ) {
            $options[ $ch['preset_id'] ] = $ch['name'];
        }
    }
    return $options;
}

/**
 * 获取用户配置的模型优先级列表
 *
 * 根据主渠道、兜底渠道的设置，返回模型 slug 数组
 * 用于 wp_ai_client_prompt()->using_model_preference(...)
 *
 * @return array 模型 slug 列表，如 ['deepseek-ai/DeepSeek-V3', 'deepseek-v4-flash']
 */
function wxs_postchat_get_model_preferences() {
    if ( ! function_exists( 'wxs_ai_channels_get_channels' ) ) {
        return [];
    }

    $primary  = wxs_postchat_get_option( 'local_ai_primary_channel', '' );
    $fallback = wxs_postchat_get_option( 'local_ai_fallback_channel', '' );

    if ( empty( $primary ) && empty( $fallback ) ) {
        return [];
    }

    $channels = wxs_ai_channels_get_channels();
    $channel_map = [];
    foreach ( $channels as $ch ) {
        $channel_map[ $ch['preset_id'] ] = $ch['text_model'] ?? '';
    }

    $models = [];
    if ( ! empty( $primary ) && ! empty( $channel_map[ $primary ] ) ) {
        $models[] = $channel_map[ $primary ];
    }
    if ( ! empty( $fallback ) && ! empty( $channel_map[ $fallback ] ) && $fallback !== $primary ) {
        $models[] = $channel_map[ $fallback ];
    }

    return $models;
}
