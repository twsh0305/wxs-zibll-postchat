<?php
/**
 * 本站模式 — AI 摘要生成与 PHP 注入
 *
 * @package WXS_Zibll_PostChat
 * @since 1.1.0
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================== 摘要 PHP 注入 ====================

/**
 * 判断文章是否在摘要黑名单中（按文章ID）
 *
 * @param int $post_id 文章ID
 * @return bool
 */
function wxs_local_is_summary_blacklisted( $post_id ) {
    $blacklist = wxs_postchat_get_option( 'local_blacklist_ids', '' );
    if ( empty( $blacklist ) ) {
        return false;
    }
    $ids = array_map( 'intval', array_filter( explode( ',', $blacklist ) ) );
    return in_array( (int) $post_id, $ids, true );
}

/**
 * 通过 the_content filter 在文章开头插入摘要 HTML
 */
add_filter( 'the_content', 'wxs_local_inject_summary', 5 );
function wxs_local_inject_summary( $content ) {
    // 仅在文章/帖子单页
    if ( ! is_singular( [ 'post', 'forum_post' ] ) ) {
        return $content;
    }

    $post_type = get_post_type();

    // 开关检测
    if ( $post_type === 'post' && ! wxs_postchat_get_option( 'local_article_summary', true ) ) {
        return $content;
    }
    if ( $post_type === 'forum_post' ) {
        if ( ! wxs_postchat_get_option( 'local_forum_summary', false ) ) {
            return $content;
        }
        // 论坛功能需已启用
        if ( ! function_exists( '_pz' ) || ! _pz( 'bbs_s', true ) ) {
            return $content;
        }
    }

    // 黑名单检测（按文章ID）
    if ( wxs_local_is_summary_blacklisted( get_the_ID() ) ) {
        return $content;
    }

    // 读取已存储的摘要
    $summary = get_post_meta( get_the_ID(), '_wxs_postchat_summary', true );
    if ( empty( $summary ) ) {
        // 摘要不存在：触发异步生成，下次访问即可显示
        wxs_local_trigger_summary_on_visit( get_the_ID() );
        return $content;
    }

    $html = wxs_local_build_summary_html( $summary );
    return $html . $content;
}

/**
 * 构建摘要 HTML 结构
 *
 * @param string $summary 摘要文本
 * @return string HTML
 */
function wxs_local_build_summary_html( $summary ) {
    $title  = esc_html( wxs_postchat_get_option( 'local_summary_title', '智能总结摘要' ) );
    $name   = trim( (string) wxs_postchat_get_option( 'local_summary_name', get_bloginfo( 'name' ) . '助理' ) );
    $typing = wxs_postchat_get_option( 'local_typing_animate', true );
    $post_id = get_the_ID();

    // SVG 图标（简洁AI图标）
    $icon = '<svg class="wxs-ai-summary-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2a7 7 0 0 1 7 7c0 3-2 5-4 6v2H9v-2c-2-1-4-3-4-6a7 7 0 0 1 7-7z"/><path d="M9 21h6"/><path d="M10 17h4"/></svg>';

    $body_content = esc_html( $summary );
    $data_attrs   = ' data-wxs-summary="post_' . esc_attr( $post_id ) . '"';

    if ( $typing ) {
        // 打字动画：内容为空，data-full 存完整文本，data-wxs-typing 标记，由内联JS逐字填充
        $data_attrs .= ' data-wxs-typing data-full="' . esc_attr( $summary ) . '"';
        $body_content = '';
    }

    // 助手署名（设置了名称时显示在标题右侧）
    $name_html = '';
    if ( $name !== '' ) {
        $name_html = '<span class="wxs-ai-summary-name">by ' . esc_html( $name ) . '</span>';
    }

    // 摘要容器：data-nosnippet 防止搜索引擎将AI生成摘要用作搜索结果片段
    $html = '<div class="wxs-ai-summary" data-nosnippet>';
    $html .= '<div class="wxs-ai-summary-inner">';
    $html .= '<div class="wxs-ai-summary-header">';
    $html .= $icon;
    $html .= '<span class="wxs-ai-summary-label">' . $title . '</span>';
    $html .= $name_html;
    $html .= '</div>';
    $html .= '<div class="wxs-ai-summary-body"' . $data_attrs . '>' . $body_content . '</div>';
    $html .= '</div>'; // .wxs-ai-summary-inner
    $html .= '</div>'; // .wxs-ai-summary

    // 打字动画内联JS（仅在开启时输出，通过 data-wxs-typing 选择器定位，避免多实例冲突）
    if ( $typing ) {
        $html .= '<script>(function(){var el=document.querySelector(\'[data-wxs-summary="post_' . esc_js( $post_id ) . '"][data-wxs-typing]\');if(!el||!el.dataset.full)return;var text=el.dataset.full,i=0,speed=20;function t(){if(i>=text.length){el.textContent=text;return;}i+=Math.ceil(Math.random()*2+1);el.textContent=text.slice(0,i);setTimeout(t,speed);}t();})();</script>';
    }

    return $html;
}

// ==================== 前台访问触发异步生成 ====================

/**
 * 前台访问时摘要为空，触发异步生成（wp-cron）
 * 使用 transient 防止同一文章短时间内重复触发
 *
 * @param int $post_id 文章ID
 */
function wxs_local_trigger_summary_on_visit( $post_id ) {
    // 黑名单文章不触发自动生成
    if ( wxs_local_is_summary_blacklisted( $post_id ) ) {
        return;
    }
    // 防重复：10分钟内同一文章只触发一次
    $lock_key = 'wxs_summary_visit_' . $post_id;
    if ( get_transient( $lock_key ) ) {
        return;
    }
    set_transient( $lock_key, 1, 600 );

    $content_hash = md5( get_post_field( 'post_content', $post_id ) . get_the_title( $post_id ) );
    wp_schedule_single_event( time() + 3, 'wxs_local_generate_summary_event', [ $post_id, $content_hash ] );
}

// ==================== 摘要生成（save_post 钩子）====================

/**
 * 监听文章发布/更新，按条件触发异步生成摘要
 *
 * 逻辑：
 * - 新文章（无摘要）→ 自动安排生成
 * - 更新文章 → 仅在编辑器中勾选「重新生成AI摘要」复选框时才重新生成
 */
add_action( 'wp_insert_post', 'wxs_local_summary_on_publish', 20, 3 );
function wxs_local_summary_on_publish( $post_ID, $post, $update ) {
    // 防止无限循环和自动保存
    static $is_processing = [];
    if ( isset( $is_processing[ $post_ID ] ) ) {
        return;
    }
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_ID ) || wp_is_post_autosave( $post_ID ) ) {
        return;
    }

    // 仅处理已发布的文章和帖子
    if ( $post->post_status !== 'publish' ) {
        return;
    }

    // 检查文章类型开关
    if ( $post->post_type === 'post' ) {
        if ( ! wxs_postchat_get_option( 'local_article_summary', true ) ) {
            return;
        }
    } elseif ( $post->post_type === 'forum_post' ) {
        if ( ! wxs_postchat_get_option( 'local_forum_summary', false ) ) {
            return;
        }
        if ( ! function_exists( '_pz' ) || ! _pz( 'bbs_s', true ) ) {
            return;
        }
    } else {
        return;
    }

    // 黑名单文章不自动生成摘要
    if ( wxs_local_is_summary_blacklisted( $post_ID ) ) {
        return;
    }

    $is_processing[ $post_ID ] = true;

    $has_summary = ! empty( get_post_meta( $post_ID, '_wxs_postchat_summary', true ) );

    // 更新文章：仅在勾选复选框时才重新生成
    if ( $update && $has_summary ) {
        // phpcs:ignore WordPress.Security.NonceVerification -- nonce 已由 WP 编辑器验证
        $regen = isset( $_POST['wxs_regenerate_summary'] ) && $_POST['wxs_regenerate_summary'] === '1';
        if ( ! $regen ) {
            unset( $is_processing[ $post_ID ] );
            return;
        }
    }

    // 新文章无摘要 或 勾选了重新生成 → 安排异步生成
    $content_hash = md5( $post->post_content . $post->post_title );

    // 用户主动勾选重新生成时，传空hash跳过异步任务中的哈希校验
    $force_regen = ( $update && $has_summary );
    $hash_to_pass = $force_regen ? '' : $content_hash;

    $scheduled = wp_schedule_single_event( time() + 5, 'wxs_local_generate_summary_event', [ $post_ID, $hash_to_pass ] );

    unset( $is_processing[ $post_ID ] );
}

/**
 * 异步任务：生成并保存摘要
 */
add_action( 'wxs_local_generate_summary_event', 'wxs_local_generate_summary', 10, 2 );
function wxs_local_generate_summary( $post_ID, $content_hash = '' ) {
    // WP AI Client 可用性检查
    if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
        return;
    }

    $post = get_post( $post_ID );
    if ( ! $post || $post->post_status !== 'publish' ) {
        return;
    }

    // 二次校验内容哈希
    if ( ! empty( $content_hash ) ) {
        $stored_hash = get_post_meta( $post_ID, '_wxs_postchat_content_hash', true );
        if ( $content_hash === $stored_hash && ! empty( get_post_meta( $post_ID, '_wxs_postchat_summary', true ) ) ) {
            return;
        }
    }

    // 先移除隐藏内容，再清理HTML（本站模式固定 5000 字上限，不受洪墨「字数限制」影响）
    $clean_content = wxs_local_strip_hidden_content( $post->post_content );
    $clean_content = wxs_postchat_clean_html_content( $clean_content, 5000 );

    // 正文过短则跳过
    $content_len = mb_strlen( $clean_content, 'UTF-8' );
    if ( $content_len < 50 ) {
        return;
    }

    // 根据文章类型选择开头文字
    $is_forum = ( $post->post_type === 'forum_post' );
    $beginning = $is_forum
        ? wxs_postchat_get_option( 'local_forum_beginning_text', '这篇帖子介绍了' )
        : wxs_postchat_get_option( 'local_beginning_text', '这篇文章介绍了' );

    $prompt = "请用一句话概括以下文章内容，以「{$beginning}」开头，不超过150字：\n\n标题：{$post->post_title}\n\n{$clean_content}";



    // 直接使用 WP AI Client 原生接口
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
        return;
    }
    if ( empty( $result ) ) {
        return;
    }

    $summary = (string) $result;

    // 保存摘要和内容哈希
    $hash = ! empty( $content_hash ) ? $content_hash : md5( $post->post_content . $post->post_title );
    update_post_meta( $post_ID, '_wxs_postchat_summary', $summary );
    update_post_meta( $post_ID, '_wxs_postchat_content_hash', $hash );
}

// ==================== 文章编辑页 Meta Box ====================

/**
 * 注册「AI摘要」Meta Box（文章/帖子编辑页侧栏）
 */
add_action( 'add_meta_boxes', 'wxs_local_register_summary_meta_box' );
function wxs_local_register_summary_meta_box() {
    $post_types = [ 'post' ];
    if ( function_exists( '_pz' ) && _pz( 'bbs_s', true ) ) {
        $post_types[] = 'forum_post';
    }

    add_meta_box(
        'wxs_ai_summary_box',
        'AI 摘要',
        'wxs_local_render_summary_meta_box',
        $post_types,
        'side',
        'default'
    );
}

/**
 * 渲染 Meta Box 内容
 */
function wxs_local_render_summary_meta_box( $post ) {
    $summary = get_post_meta( $post->ID, '_wxs_postchat_summary', true );
    $has     = ! empty( $summary );

    echo '<p style="margin:0 0 8px;">';
    if ( $has ) {
        echo '<span style="color:#52c41a;">✓ 已有摘要</span>';
        echo '<br><small style="color:#888;">' . esc_html( mb_strimwidth( $summary, 0, 80, '...', 'UTF-8' ) ) . '</small>';
    } else {
        echo '<span style="color:#faad14;">○ 暂无摘要（发布后自动生成）</span>';
    }
    echo '</p>';

    echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
    echo '<input type="checkbox" name="wxs_regenerate_summary" value="1">';
    echo '<span>保存时重新生成AI摘要</span>';
    echo '</label>';
    echo '<p class="description" style="margin-top:4px;">勾选后更新文章将重新调用AI生成摘要，覆盖现有内容。</p>';
}
