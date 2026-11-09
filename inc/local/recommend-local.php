<?php
/**
 * 本站模式 — 相关文章推荐（替换子比原生相关文章）
 *
 * 机制：和洪墨模式相同，通过 remove_action 移除子比原生 zib_single_after_box，
 * 用自己的函数替代，相关文章部分改为 Meilisearch 检索，使用子比主题原生样式渲染。
 *
 * @package WXS_Zibll_PostChat
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================== Hook 替换 ====================

/**
 * 替换子比主题的 zib_single_after_box，改用 Meilisearch 推荐
 * 注意：子比主题在 zib_require_end 中注册 zib_single_after_box，
 * 所以我们必须在更晚的时机执行 remove_action
 */
function wxs_local_init_related_replacement() {
    if ( ! wxs_postchat_get_option( 'local_enable_recommend', false ) ) {
        return;
    }
    // 子比主题相关文章功能必须开启，否则不执行替换
    if ( ! function_exists( '_pz' ) || ! _pz( 'post_related_s' ) ) {
        return;
    }
    remove_action( 'zib_single_after', 'zib_single_after_box' );
    add_action( 'zib_single_after', 'wxs_local_single_after_box' );
}
add_action( 'wp', 'wxs_local_init_related_replacement' );

/**
 * 替换版的文章底部区块
 * 保留子比原有的一言、作者描述、上下篇，仅替换相关文章部分
 */
function wxs_local_single_after_box() {
    // 一言
    if ( _pz( 'yiyan_single_box' ) ) {
        zib_yiyan( 'yiyan-box main-bg theme-box text-center box-body radius8 main-shadow' );
    }

    // 作者描述
    if ( _pz( 'post_authordesc_s' ) ) {
        $args = [
            'user_id'     => get_the_author_meta( 'ID' ),
            'show_button' => false,
            'show_img_bg' => false,
            'class'       => 'author',
        ];
        zib_get_user_card_box( $args, true );
    }

    // 上下篇文章
    if ( _pz( 'post_prevnext_s' ) ) {
        zib_posts_prevnext();
    }

    // Meilisearch 相关推荐（替换原生 zib_posts_related）
    if ( _pz( 'post_related_s' ) ) {
        // 优先使用插件设置，未设置时回退子比主题设置
        $related_title = wxs_postchat_get_option( 'local_recommend_title', '' );
        $related_count = (int) wxs_postchat_get_option( 'local_recommend_count', 0 );
        if ( '' === trim( (string) $related_title ) ) {
            $related_title = _pz( 'related_title', '相关推荐' );
        }
        if ( $related_count <= 0 ) {
            $related_count = (int) _pz( 'post_related_n', 6 );
        }
        wxs_local_posts_related( $related_title, $related_count, _pz( 'post_related_orderby', 'views' ) );
    }
}

// ==================== Meilisearch 相关推荐 ====================

/**
 * 基于 Meilisearch 检索的相关文章推荐
 * 使用子比主题原生样式渲染，和洪墨 AI 推荐位置一致
 *
 * @param string $related_title 版块标题
 * @param int    $limit         显示数量
 * @param string $orderby       排序方式（兜底用）
 */
function wxs_local_posts_related( $related_title = '相关推荐', $limit = 6, $orderby = 'views' ) {
    // 在 zib_single_after 钩子中 $post 可能已被重置，用 get_queried_object 获取
    $current_post = get_queried_object();
    if ( ! $current_post || ! ( $current_post instanceof WP_Post ) ) {
        global $post;
        $current_post = $post;
    }
    if ( ! $current_post ) {
        return;
    }

    $limit = intval( $limit );
    if ( $limit < 1 ) {
        $limit = 6;
    }

    // 优先从 transient 缓存获取
    $cache_key = 'wxs_recommend_' . $current_post->ID;
    $cached_ids = get_transient( $cache_key );

    // 防御：旧版缓存可能存了非数组数据，视为缓存未命中
    if ( false !== $cached_ids && ! is_array( $cached_ids ) ) {
        delete_transient( $cache_key );
        $cached_ids = false;
    }

    if ( false === $cached_ids ) {
        // 缓存未命中：Meilisearch 检索
        $cached_ids = wxs_local_fetch_recommend_ids( $current_post, $limit );

        // 仅有数据时缓存，空结果不缓存（下次访问重试）
        if ( ! empty( $cached_ids ) ) {
            set_transient( $cache_key, $cached_ids, wxs_postchat_related_cache_ttl( 'local_recommend' ) );
        }
    }

    // Meilisearch 返回为空时回退到子比原生
    if ( empty( $cached_ids ) ) {
        if ( function_exists( 'zib_posts_related' ) ) {
            zib_posts_related( $related_title, $limit, $orderby );
        }
        return;
    }

    // 使用子比原生样式渲染
    wxs_local_render_related( $cached_ids, $related_title, $limit );
}

/**
 * 从 Meilisearch 检索相关文章 ID
 */
function wxs_local_fetch_recommend_ids( $post, $limit ) {
    // 检查 zib_ms 类是否可用
    if ( ! class_exists( 'zib_ms' ) ) {
        return [];
    }

    try {
        $ms_args = wxs_local_get_ms_args();
        if ( empty( $ms_args ) ) {
            return [];
        }
        $ms = new zib_ms( $ms_args );

        if ( ! isset( $ms->postIndex ) || ! $ms->postIndex ) {
            return [];
        }

        $query = mb_substr( $post->post_title, 0, 20, 'UTF-8' );

        // 多取一些候选并缓存，便于后续调整推荐数量时无需重新检索
        $fetch_limit = max( $limit + 3, 12 );

        $result = $ms->postIndex->search( $query, [
            'attributesToRetrieve' => [ 'id' ],
            'limit'                => $fetch_limit,
        ] );

        $hits = [];
        if ( is_object( $result ) && method_exists( $result, 'getHits' ) ) {
            $hits = $result->getHits();
        } elseif ( is_object( $result ) && method_exists( $result, 'toArray' ) ) {
            $arr = $result->toArray();
            $hits = $arr['hits'] ?? [];
        } elseif ( is_array( $result ) ) {
            $hits = $result['hits'] ?? [];
        }

        $ids = [];
        foreach ( $hits as $hit ) {
            $pid = intval( is_array( $hit ) ? ( $hit['id'] ?? 0 ) : ( $hit->id ?? 0 ) );
            if ( $pid <= 0 || $pid === $post->ID ) {
                continue;
            }
            $p = get_post( $pid );
            if ( $p && $p->post_status === 'publish' ) {
                $ids[] = $pid;
            }
            if ( count( $ids ) >= $fetch_limit ) {
                break;
            }
        }

        return $ids;
    } catch ( \Exception $e ) {
        return [];
    }
}

/**
 * 使用子比主题原生样式渲染相关文章
 * 默认使用简洁列表模式（最兼容）
 */
function wxs_local_render_related( $post_ids, $related_title, $limit ) {
    // 防御：确保 $post_ids 是数组
    if ( ! is_array( $post_ids ) ) {
        if ( function_exists( 'zib_posts_related' ) ) {
            zib_posts_related( $related_title, $limit );
        }
        return;
    }

    // 缓存中可能多于当前设置的显示数量，按设置截断
    $post_ids = array_slice( $post_ids, 0, max( 1, intval( $limit ) ) );

    global $post;
    $original_post = $post;
    $posts_list = '';

    // 检测子比主题支持的模式
    $related_type = function_exists( '_pz' ) ? _pz( 'post_related_type' ) : '';
    $use_mini = ( $related_type === 'list' && function_exists( 'zib_posts_mini_while' ) );

    foreach ( $post_ids as $pid ) {
        $p = get_post( $pid );
        if ( ! $p || $p->post_status !== 'publish' ) {
            continue;
        }
        $post = $p;
        setup_postdata( $post );

        if ( $use_mini ) {
            $posts_list .= zib_posts_mini_while( [ 'echo' => false, 'show_number' => false ] );
        } else {
            // 默认：圆点列表（最兼容）
            $subtitle = function_exists( 'get_the_subtitle' ) ? get_the_subtitle() : '';
            $posts_list .= '<li><a class="icon-circle" href="' . esc_url( get_permalink( $pid ) ) . '">'
                . esc_html( get_the_title( $pid ) ) . $subtitle . '</a></li>';
        }
    }

    $post = $original_post;
    wp_reset_postdata();

    if ( empty( $posts_list ) ) {
        // 没有内容时回退到子比原生
        if ( function_exists( 'zib_posts_related' ) ) {
            zib_posts_related( $related_title, $limit );
        }
        return;
    }

    // 输出 HTML（子比原生结构）
    echo '<div class="theme-box relates">';
    echo '<div class="box-body notop"><div class="title-theme">' . esc_html( $related_title ) . '</div></div>';
    echo '<div class="zib-widget">';
    echo $use_mini ? '' : '<ul class="no-thumb">';
    echo $posts_list;
    echo $use_mini ? '' : '</ul>';
    echo '</div></div>';
}

// ==================== 缓存清除 ====================

add_action( 'save_post', 'wxs_local_clear_recommend_cache', 10, 2 );
function wxs_local_clear_recommend_cache( $post_id, $post ) {
    if ( wp_is_post_revision( $post_id ) ) {
        return;
    }
    delete_transient( 'wxs_recommend_' . $post_id );
}