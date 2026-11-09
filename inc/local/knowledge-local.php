<?php
/**
 * 本站模式 — 自定义知识库
 *
 * 功能：管理文章以外的额外知识，当用户提问匹配到关键词时，
 * 自动将对应内容作为额外上下文提供给 AI。
 *
 * @package WXS_Zibll_PostChat
 * @since 1.1.0
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 从自定义知识库中匹配用户问题相关的条目
 *
 * 匹配逻辑：将知识条目的标题拆分为关键词（支持中英文逗号分隔），
 * 用 mb_stripos 检测用户问题是否包含任一关键词。
 * 自定义知识优先级高于文章检索，放在 context 最前面。
 *
 * @param string $question 用户问题
 * @return string 匹配到的知识内容（用 --- 分隔拼接），无匹配返回空字符串
 */
function wxs_local_match_custom_knowledge( $question ) {
    // 优先从 wp_cache 获取
    $cache_key = 'wxs_local_knowledge_items';
    $items = wp_cache_get( $cache_key, 'wxs_local' );

    if ( false === $items ) {
        $items = wxs_postchat_get_option( 'local_knowledge_items', [] );
        wp_cache_set( $cache_key, $items, 'wxs_local', 3600 ); // 缓存1小时
    }

    if ( empty( $items ) || ! is_array( $items ) ) {
        return '';
    }

    $matched = [];

    foreach ( $items as $item ) {
        // 跳过未启用或内容不完整的条目
        if ( empty( $item['enabled'] ) || empty( $item['title'] ) || empty( $item['content'] ) ) {
            continue;
        }

        // 将标题拆分为多个关键词（支持中英文逗号）
        $keywords = array_map( 'trim', explode( ',', $item['title'] ) );
        $merged   = [];
        foreach ( $keywords as $k ) {
            $parts = array_map( 'trim', explode( '，', $k ) );
            $merged = array_merge( $merged, $parts );
        }
        $keywords = array_filter( $merged );

        foreach ( $keywords as $kw ) {
            if ( mb_stripos( $question, $kw ) !== false ) {
                $matched[] = "【{$item['title']}】\n{$item['content']}";
                break; // 一个条目只匹配一次
            }
        }
    }

    return implode( "\n---\n", $matched );
}

/**
 * 设置保存时清除知识库缓存
 */
add_action( 'csf_' . WXS_POSTCHAT_PREFIX . '_save_after', 'wxs_local_clear_knowledge_cache' );
function wxs_local_clear_knowledge_cache() {
    wp_cache_delete( 'wxs_local_knowledge_items', 'wxs_local' );
}
