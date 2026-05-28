<?php
/**
 * 相关文章 - PostChat AI 推荐（异步 + Redis 缓存）+ 本地算法回退
 * 
 * 核心机制：
 * 1. 页面加载时只读 Redis 缓存（微秒级），绝不阻塞
 * 2. 缓存命中且有数据 → 渲染 AI 推荐（带 AI 角标）
 * 3. 缓存命中但为空   → 渲染本地算法（API曾返回空，等TTL过期后重试）
 * 4. 缓存未命中       → 立即渲染本地算法 + shutdown 钩子异步请求 API 写入 Redis
 *    └ fastcgi_finish_request() 先发送响应，API 调用在浏览器收到页面后执行
 * 
 * API: POST api.ai.zhheo.com/api/v2/recommend/generate/external
 * 返回: { code:200, data:{ total:N, items:[{ id, title, summary, url, score, reason }] } }
 * 
 * @package WXS_Zibll_PostChat
 * @since 2026-02-03
 * @updated 2026-02-13  接入 PostChat 推荐 API + 异步 Redis 缓存 + AI 角标
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 异步任务队列（当前请求中收集，shutdown 时统一执行）
$GLOBALS['wxs_related_async_queue'] = [];

// ==================== Redis 缓存操作 ====================

/**
 * 从 Redis 读取推荐缓存
 * 
 * @param int  $post_id 文章ID
 * @param bool &$found  是否命中缓存（区分 false 和缓存的空数组）
 * @return mixed 缓存数据或 false
 */
function wxs_related_cache_get($post_id, &$found = false) {
    return wp_cache_get('wxs_rel_' . $post_id, 'wxs_related', false, $found);
}

/**
 * 写入推荐缓存到 Redis（12小时 TTL）
 * 
 * @param int   $post_id  文章ID
 * @param array $post_ids 推荐的文章ID数组
 */
function wxs_related_cache_set($post_id, $post_ids) {
    wp_cache_set('wxs_rel_' . $post_id, $post_ids, 'wxs_related', 12 * HOUR_IN_SECONDS);
}

/**
 * 删除推荐缓存
 * 
 * @param int $post_id 文章ID
 */
function wxs_related_cache_delete($post_id) {
    wp_cache_delete('wxs_rel_' . $post_id, 'wxs_related');
}

/**
 * 文章更新时自动清除推荐缓存
 * 
 * @param int $post_id 文章ID
 */
function wxs_clear_related_cache($post_id) {
    if (get_post_type($post_id) === 'post') {
        wxs_related_cache_delete($post_id);
    }
}
add_action('save_post', 'wxs_clear_related_cache');

// ==================== PostChat 推荐 API ====================

/**
 * 调用 PostChat 推荐 API 获取相关文章列表
 * 
 * @param string $post_url 当前文章的 permalink
 * @param int    $count    推荐数量（默认6，最大10）
 * @return array|false     成功返回 items 数组，失败返回 false
 */
function wxs_get_postchat_recommendations($post_url, $count = 6) {
    $api_key = '';
    if (function_exists('wxs_postchat_get_option')) {
        $api_key = wxs_postchat_get_option('api_key', '');
    }
    if (empty($api_key)) {
        return false;
    }

    // 外部 API 需要去掉 Key 的数字前缀（如 3P-xxx → P-xxx）
    $clean_key = preg_replace('/^\d+/', '', $api_key);

    $response = wp_remote_post('https://api.ai.zhheo.com/api/v2/recommend/generate/external', [
        'body'    => wp_json_encode([
            'key'   => $clean_key,
            'url'   => $post_url,
            'count' => intval($count),
        ]),
        'timeout' => 10,
        'headers' => ['Content-Type' => 'application/json'],
    ]);

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        return false;
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);

    if (!isset($data['code']) || $data['code'] !== 200 || empty($data['data']['items'])) {
        return false;
    }

    return $data['data']['items'];
}

/**
 * 将 API 返回的文章 URL 转换为 WordPress 文章 ID
 * 策略：url_to_postid → 域名替换重试 → 从路径提取数字ID
 * 
 * @param string $url API 返回的文章 URL
 * @return int|false   WordPress 文章 ID，失败返回 false
 */
function wxs_url_to_post_id($url) {
    // 1. 直接尝试 url_to_postid（生产环境域名一致时直接命中）
    $post_id = url_to_postid($url);
    if ($post_id) {
        return $post_id;
    }

    // 2. 域名不一致时，替换为本站域名重试
    $site_url    = home_url();
    $parsed_api  = wp_parse_url($url);
    $parsed_site = wp_parse_url($site_url);

    if (isset($parsed_api['host'], $parsed_site['host']) && $parsed_api['host'] !== $parsed_site['host']) {
        $local_url = $parsed_site['scheme'] . '://' . $parsed_site['host'];
        if (isset($parsed_site['port'])) {
            $local_url .= ':' . $parsed_site['port'];
        }
        $local_url .= isset($parsed_api['path']) ? $parsed_api['path'] : '/';
        if (isset($parsed_api['query'])) {
            $local_url .= '?' . $parsed_api['query'];
        }

        $post_id = url_to_postid($local_url);
        if ($post_id) {
            return $post_id;
        }
    }

    // 3. 从 URL 路径提取数字 ID（兼容 /3015.html 格式）
    if (preg_match('/\/(\d+)\.html/', $url, $matches)) {
        $potential_id = intval($matches[1]);
        if (get_post_status($potential_id)) {
            return $potential_id;
        }
    }

    return false;
}

// ==================== 异步获取（shutdown 钩子） ====================

/**
 * shutdown 钩子：在响应发送后异步获取 AI 推荐并写入 Redis
 * 通过 fastcgi_finish_request() 先将页面返回给浏览器，再执行 API 调用
 */
function wxs_async_fetch_recommendations() {
    $queue = $GLOBALS['wxs_related_async_queue'];
    if (empty($queue)) {
        return;
    }

    // 核心：先将 HTTP 响应发送给浏览器（Nginx + PHP-FPM 环境）
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }

    foreach ($queue as $task) {
        $items = wxs_get_postchat_recommendations($task['url'], $task['count']);
        $post_ids = [];

        if ($items) {
            foreach ($items as $item) {
                if (empty($item['url'])) {
                    continue;
                }
                $pid = wxs_url_to_post_id($item['url']);
                if ($pid && $pid != $task['post_id'] && get_post_status($pid) === 'publish') {
                    $post_ids[] = $pid;
                }
            }
        }

        // 写入 Redis（即使为空也缓存，避免反复请求）
        wxs_related_cache_set($task['post_id'], $post_ids);

        // 清除防重锁
        wp_cache_delete('wxs_rel_lock_' . $task['post_id'], 'wxs_related');
    }
}
add_action('shutdown', 'wxs_async_fetch_recommendations');

// ==================== 本地回退算法 ====================

/**
 * 获取文章的最子级分类（层级最深的分类）
 * 
 * @param int $post_id 文章ID
 * @return int|false 最子级分类ID，无分类返回false
 */
function wxs_get_deepest_category($post_id) {
    $categories = get_the_category($post_id);

    if (empty($categories)) {
        return false;
    }

    $deepest_cat = null;
    $max_depth = -1;

    foreach ($categories as $cat) {
        $depth = 0;
        $parent_id = $cat->parent;
        while ($parent_id > 0) {
            $depth++;
            $parent_cat = get_category($parent_id);
            $parent_id = $parent_cat ? $parent_cat->parent : 0;
        }

        if ($depth > $max_depth) {
            $max_depth = $depth;
            $deepest_cat = $cat;
        }
    }

    return $deepest_cat ? $deepest_cat->term_id : false;
}

/**
 * 计算标签匹配数
 * 
 * @param int   $post_id      待计算的文章ID
 * @param array $current_tags 当前文章的标签ID数组
 * @return int 匹配的标签数量
 */
function wxs_count_tag_matches($post_id, $current_tags) {
    if (empty($current_tags)) {
        return 0;
    }

    $post_tags = wp_get_post_terms($post_id, 'post_tag', array('fields' => 'ids'));

    if (is_wp_error($post_tags) || empty($post_tags)) {
        return 0;
    }

    return count(array_intersect($post_tags, $current_tags));
}

/**
 * 获取本地相关文章ID（纯计算，不渲染）
 * 
 * @param int   $limit       需要的数量
 * @param array $exclude_ids 需要排除的文章ID
 * @return array 文章ID数组
 */
function wxs_get_local_related_ids($limit, $exclude_ids = array()) {
    global $post;

    $current_post_id = $post->ID;
    $exclude_ids = array_merge(array($current_post_id), $exclude_ids);

    // 获取当前文章的标签
    $tags = get_the_terms($post, 'post_tag');
    $current_tags = is_array($tags) ? array_column($tags, 'term_id') : array();

    // 获取最子级分类
    $deepest_cat_id = wxs_get_deepest_category($current_post_id);
    if (!$deepest_cat_id) {
        return array();
    }

    // 基于最子级分类查询候选文章
    $query_limit = $limit * 5;
    $posts_args = array(
        'showposts'           => $query_limit,
        'ignore_sticky_posts' => 1,
        'post_type'           => 'post',
        'post_status'         => 'publish',
        'post__not_in'        => $exclude_ids,
        'orderby'             => 'date',
        'order'               => 'DESC',
        'no_found_rows'       => true,
        'cat'                 => $deepest_cat_id,
    );

    $new_query = new WP_Query($posts_args);
    $candidates = array();

    while ($new_query->have_posts()) {
        $new_query->the_post();
        $candidate_id = get_the_ID();

        $tag_matches = wxs_count_tag_matches($candidate_id, $current_tags);

        $candidates[] = array(
            'id'          => $candidate_id,
            'tag_matches' => $tag_matches,
            'views'       => (int) get_post_view_count('', ''),
        );
    }
    wp_reset_query();
    wp_reset_postdata();

    // 分离有匹配和无匹配的文章
    $matched = array_filter($candidates, function($c) { return $c['tag_matches'] > 0; });
    $unmatched = array_filter($candidates, function($c) { return $c['tag_matches'] === 0; });

    usort($matched, function($a, $b) {
        if ($a['tag_matches'] !== $b['tag_matches']) {
            return $b['tag_matches'] - $a['tag_matches'];
        }
        return $b['views'] - $a['views'];
    });

    usort($unmatched, function($a, $b) {
        $threshold = max($a['views'], $b['views']) * 0.1;
        if (abs($a['views'] - $b['views']) > $threshold) {
            return $b['views'] - $a['views'];
        }
        return rand(-1, 1);
    });

    $candidates = array_merge(array_values($matched), array_values($unmatched));
    $candidates = array_slice($candidates, 0, $limit);

    return array_column($candidates, 'id');
}

/**
 * 计算同分类相关文章总数（用于分页）
 * 
 * @param int   $post_id     当前文章ID
 * @param array $exclude_ids 额外排除的文章ID
 * @return int  总数
 */
function wxs_get_related_total_count($post_id, $exclude_ids = array()) {
    $deepest_cat_id = wxs_get_deepest_category($post_id);
    if (!$deepest_cat_id) {
        return 0;
    }

    $not_in = array_merge(array($post_id), $exclude_ids);

    $count_query = new WP_Query(array(
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'cat'            => $deepest_cat_id,
        'post__not_in'   => $not_in,
        'posts_per_page' => 1,
        'no_found_rows'  => false,
        'fields'         => 'ids',
    ));

    return (int) $count_query->found_posts;
}

/**
 * 本地相关文章（回退方案，直接渲染）
 * 
 * @param string $related_title 版块标题
 * @param int    $limit         显示数量
 * @param string $orderby       二次排序方式
 */
function wxs_posts_related_local($related_title, $limit, $orderby) {
    $thumb_s = _pz('post_related_type') == 'img';
    $current_post_id = get_the_ID();
    $deepest_cat_id = wxs_get_deepest_category($current_post_id);

    if (!$deepest_cat_id) {
        zib_posts_related($related_title, $limit, $orderby);
        return;
    }

    $post_ids = wxs_get_local_related_ids($limit);

    // 分页参数
    $pagination_args = array();
    $total_count = wxs_get_related_total_count($current_post_id);
    if ($total_count > $limit) {
        $pagination_args = array(
            'current_post_id' => $current_post_id,
            'total_count'     => $total_count,
            'per_page'        => $limit,
            'paged'           => 1,
        );
    }

    wxs_render_related_posts($post_ids, $related_title, $thumb_s, array(), $pagination_args);
}

// ==================== 渲染输出 ====================

/**
 * 渲染相关文章 HTML（共用渲染函数）
 * 
 * @param array  $post_ids       文章ID数组
 * @param string $related_title  版块标题
 * @param bool   $thumb_s        是否图片模式
 * @param array  $ai_post_ids    AI 推荐的文章ID数组（这些显示角标）
 */
function wxs_render_related_posts($post_ids, $related_title, $thumb_s, $ai_post_ids = array(), $pagination_args = array()) {
    global $post;
    $original_post = $post;
    $posts_list = '';
    $has_ai = !empty($ai_post_ids);

    foreach ($post_ids as $pid) {
        $p = get_post($pid);
        if (!$p || $p->post_status !== 'publish') {
            continue;
        }
        $post = $p;
        setup_postdata($post);

        $is_ai_item = in_array($pid, $ai_post_ids);

        if (_pz('post_related_type') == 'list') {
            $item_html = zib_posts_mini_while(array('echo' => false, 'show_number' => false));
            if ($is_ai_item && $item_html) {
                // 在缩略图容器内注入 AI 角标
                $item_html = preg_replace(
                    '/<div class="item-thumbnail">/',
                    '<div class="item-thumbnail" style="position:relative"><span class="wxs-ai-badge">AI推荐</span>',
                    $item_html,
                    1
                );
            }
            $posts_list .= $item_html;
        } else {
            if ($thumb_s) {
                $title = get_the_title($pid) . get_the_subtitle(false);
                $time_ago = zib_get_time_ago(get_the_date('Y-m-d H:i:s', $pid));
                $info = '<item>' . $time_ago . '</item><item class="pull-right">' . zib_get_svg('view') . ' ' . get_post_view_count('', '') . '</item>';
                $img = zib_post_thumbnail('', 'fit-cover', true);
                $img = $img ? $img : zib_get_spare_thumb();
                $card = array(
                    'type'         => 'style-3',
                    'class'        => 'mb10',
                    'img'          => $img,
                    'alt'          => $title,
                    'link'         => array(
                        'url'    => get_permalink($pid),
                        'target' => '',
                    ),
                    'text1'        => $title,
                    'text2'        => zib_str_cut($title, 0, 45, '...'),
                    'text3'        => $info,
                    'lazy'         => true,
                    'height_scale' => 70,
                );
                $posts_list .= '<div class="swiper-slide mr10" style="position:relative">';
                if ($is_ai_item) {
                    $posts_list .= '<span class="wxs-ai-badge">AI推荐</span>';
                }
                $posts_list .= zib_graphic_card($card);
                $posts_list .= '</div>';
            } else {
                $ai_tag = $is_ai_item ? '<span class="wxs-ai-badge wxs-ai-badge-inline">AI推荐</span> ' : '';
                $posts_list .= '<li><a class="icon-circle" href="' . get_permalink($pid) . '">' . $ai_tag . get_the_title($pid) . get_the_subtitle() . '</a></li>';
            }
        }
    }

    $post = $original_post;
    wp_reset_postdata();

    $has_pagination = !empty($pagination_args);

    // 输出 HTML 结构
    echo '<div class="theme-box relates' . ($thumb_s ? ' relates-thumb' : '') . '">';

    // AI 角标内联样式（有 AI 推荐项时输出一次）
    if ($has_ai) {
        echo '<style>.wxs-ai-badge{position:absolute;top:0;left:0;z-index:2;font-size:10px;line-height:1;padding:2px 5px 2px 4px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;border-radius:0 0 6px 0;font-weight:500;letter-spacing:.5px;pointer-events:none}.wxs-ai-badge-inline{position:static;margin-right:4px;vertical-align:middle;border-radius:3px}</style>';
    }

    echo '<div class="box-body notop">
                <div class="title-theme">' . $related_title . '</div>
            </div>';

    // 外层容器（有分页时添加 ajaxpager 类，供主题 JS 识别替换目标）
    echo '<div class="zib-widget' . ($has_pagination ? ' wxs-related-pager ajaxpager' : '') . '">';

    // 有分页时用 ajax-item 包裹，便于 AJAX 整体替换内容
    if ($has_pagination) echo '<div class="ajax-item">';

    echo $thumb_s ? '<div class="swiper-container swiper-scroll"><div class="swiper-wrapper">' : '<ul class="no-thumb">';
    if (!$posts_list) {
        echo '<li>暂无相关文章</li>';
    } else {
        echo $posts_list;
    }
    echo $thumb_s ? '</div><div class="swiper-button-prev"></div><div class="swiper-button-next"></div></div>' : '</ul>';

    if ($has_pagination) echo '</div>'; // close .ajax-item

    // 数字分页按钮（上限3页）
    if ($has_pagination) {
        $p_total    = min($pagination_args['total_count'], $pagination_args['per_page'] * 3);
        $p_per_page = $pagination_args['per_page'];
        $p_paged    = isset($pagination_args['paged']) ? $pagination_args['paged'] : 1;
        $p_post_id  = $pagination_args['current_post_id'];
        $p_exclude  = implode(',', array_map('intval', $post_ids));

        $ajax_url = admin_url('/admin-ajax.php') . '?action=wxs_related_paged&post_id=' . $p_post_id . '&exclude=' . $p_exclude;
        $pagination_html = zib_get_ajax_number_paginate(
            $p_total,
            $p_paged,
            $p_per_page,
            $ajax_url,
            'ajax-pag',
            'next-page ajax-next',
            'paged',
            '.wxs-related-pager'
        );
        if ($pagination_html) {
            echo $pagination_html;
        }
    }

    echo '</div></div>';
}

// ==================== 主入口 ====================

/**
 * 相关文章主函数 - AI 优先 + 本地补足
 * 
 * 流程：
 * ┌─ Redis 命中 + 有数据 → AI 优先 + 本地补足至 limit 条  [0延迟]
 * ├─ Redis 命中 + 空数据 → 纯本地算法                      [0延迟]
 * └─ Redis 未命中        → 纯本地算法 + 异步请求API         [0延迟]
 * 
 * @param string $related_title 版块标题
 * @param int    $limit         显示数量
 * @param string $orderby       二次排序方式（回退算法用）
 */
function wxs_posts_related($related_title = '相关推荐', $limit = 6, $orderby = 'views') {
    global $post;

    $current_post_id = $post->ID;
    $thumb_s = _pz('post_related_type') == 'img';

    // 从 Redis 读取缓存（$found 区分「未缓存」和「缓存了空数组」）
    $found  = false;
    $cached = wxs_related_cache_get($current_post_id, $found);

    if ($found && !empty($cached) && is_array($cached)) {
        // 缓存命中且有 AI 推荐数据
        $ai_ids = array_values($cached);
        $all_ids = $ai_ids;

        // AI 不足 limit 条时，用本地算法补足
        if (count($ai_ids) < $limit) {
            $need = $limit - count($ai_ids);
            $local_ids = wxs_get_local_related_ids($need, $ai_ids);
            $all_ids = array_merge($ai_ids, $local_ids);
        }

        // 分页参数
        $pagination_args = array();
        $total_count = wxs_get_related_total_count($current_post_id);
        if ($total_count > $limit) {
            $pagination_args = array(
                'current_post_id' => $current_post_id,
                'total_count'     => $total_count,
                'per_page'        => $limit,
                'paged'           => 1,
            );
        }

        wxs_render_related_posts($all_ids, $related_title, $thumb_s, $ai_ids, $pagination_args);
        return;
    }

    if ($found) {
        // 缓存命中但为空（API 曾返回空结果）→ 用本地算法，等 TTL 过期后重试
        wxs_posts_related_local($related_title, $limit, $orderby);
        return;
    }

    // 缓存未命中 → 立即渲染本地算法（不阻塞页面）
    wxs_posts_related_local($related_title, $limit, $orderby);

    // 检查防重锁，避免并发请求重复调 API
    $lock = wp_cache_get('wxs_rel_lock_' . $current_post_id, 'wxs_related');
    if (!$lock) {
        // 设置 2 分钟防重锁
        wp_cache_set('wxs_rel_lock_' . $current_post_id, 1, 'wxs_related', 120);

        // 加入异步队列，shutdown 时执行
        $GLOBALS['wxs_related_async_queue'][] = [
            'post_id' => $current_post_id,
            'url'     => get_permalink($current_post_id),
            'count'   => $limit,
        ];
    }
}

// ==================== AJAX 分页 Handler ====================

/**
 * AJAX 分页处理
 * 第1页：重建 AI+本地混合内容（含 AI 角标）
 * 第2页+：本地算法推荐，排除第1页已显示的文章
 */
function wxs_ajax_related_paged() {
    $post_id     = isset($_REQUEST['post_id']) ? intval($_REQUEST['post_id']) : 0;
    $paged       = isset($_REQUEST['paged']) ? intval($_REQUEST['paged']) : 1;
    $exclude_str = isset($_REQUEST['exclude']) ? sanitize_text_field($_REQUEST['exclude']) : '';
    $per_page    = intval(_pz('post_related_n'));
    if ($per_page < 1) $per_page = 6;

    if (!$post_id || $paged < 1) {
        exit;
    }

    $deepest_cat_id = wxs_get_deepest_category($post_id);
    if (!$deepest_cat_id) {
        exit;
    }

    $thumb_s    = _pz('post_related_type') == 'img';
    $list_mode  = _pz('post_related_type') == 'list';
    $items_html = '';
    $page_ids   = array(); // 当前页面渲染的文章ID

    if ($paged <= 1) {
        // ===== 第1页：重建 AI + 本地混合内容 =====
        global $post;
        $post = get_post($post_id);
        setup_postdata($post);

        $found = false;
        $cached = wxs_related_cache_get($post_id, $found);
        $ai_post_ids = array();
        $render_ids  = array();

        if ($found && !empty($cached) && is_array($cached)) {
            $ai_post_ids = array_values($cached);
            $render_ids  = $ai_post_ids;
            if (count($ai_post_ids) < $per_page) {
                $need = $per_page - count($ai_post_ids);
                $local_ids = wxs_get_local_related_ids($need, $ai_post_ids);
                $render_ids = array_merge($ai_post_ids, $local_ids);
            }
        } else {
            $render_ids = wxs_get_local_related_ids($per_page);
        }

        foreach ($render_ids as $pid) {
            $p = get_post($pid);
            if (!$p || $p->post_status !== 'publish') continue;
            $post = $p;
            setup_postdata($post);
            $is_ai = in_array($pid, $ai_post_ids);

            if ($list_mode) {
                $item = zib_posts_mini_while(array('echo' => false, 'show_number' => false));
                if ($is_ai && $item) {
                    $item = preg_replace(
                        '/<div class="item-thumbnail">/',
                        '<div class="item-thumbnail" style="position:relative"><span class="wxs-ai-badge">AI推荐</span>',
                        $item, 1
                    );
                }
                $items_html .= $item;
            } elseif ($thumb_s) {
                $title    = get_the_title($pid) . get_the_subtitle(false);
                $time_ago = zib_get_time_ago(get_the_date('Y-m-d H:i:s', $pid));
                $info     = '<item>' . $time_ago . '</item><item class="pull-right">' . zib_get_svg('view') . ' ' . get_post_view_count('', '') . '</item>';
                $img      = zib_post_thumbnail('', 'fit-cover', true);
                $img      = $img ? $img : zib_get_spare_thumb();
                $card     = array(
                    'type' => 'style-3', 'class' => 'mb10', 'img' => $img, 'alt' => $title,
                    'link' => array('url' => get_permalink($pid), 'target' => ''),
                    'text1' => $title, 'text2' => zib_str_cut($title, 0, 45, '...'),
                    'text3' => $info, 'lazy' => true, 'height_scale' => 70,
                );
                $items_html .= '<div class="swiper-slide mr10" style="position:relative">';
                if ($is_ai) $items_html .= '<span class="wxs-ai-badge">AI推荐</span>';
                $items_html .= zib_graphic_card($card) . '</div>';
            } else {
                $ai_tag = $is_ai ? '<span class="wxs-ai-badge wxs-ai-badge-inline">AI推荐</span> ' : '';
                $items_html .= '<li><a class="icon-circle" href="' . get_permalink($pid) . '">' . $ai_tag . get_the_title($pid) . get_the_subtitle() . '</a></li>';
            }
            $page_ids[] = $pid;
        }
        wp_reset_postdata();

    } else {
        // ===== 第2页+：本地算法推荐 =====
        $exclude_ids = array($post_id);
        if ($exclude_str) {
            $exclude_ids = array_merge($exclude_ids, array_map('intval', explode(',', $exclude_str)));
        }
        $exclude_ids = array_unique(array_filter($exclude_ids));

        // paged=2 → WP_Query paged=1（因为第1页的文章已排除）
        $query_paged = max(1, $paged - 1);

        $query_args = array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'cat'            => $deepest_cat_id,
            'post__not_in'   => $exclude_ids,
            'posts_per_page' => $per_page,
            'paged'          => $query_paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
        );

        $query = new WP_Query($query_args);
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();

                if ($list_mode) {
                    $items_html .= zib_posts_mini_while(array('echo' => false, 'show_number' => false));
                } elseif ($thumb_s) {
                    $title    = get_the_title() . get_the_subtitle(false);
                    $time_ago = zib_get_time_ago(get_the_date('Y-m-d H:i:s'));
                    $info     = '<item>' . $time_ago . '</item><item class="pull-right">' . zib_get_svg('view') . ' ' . get_post_view_count('', '') . '</item>';
                    $img      = zib_post_thumbnail('', 'fit-cover', true);
                    $img      = $img ? $img : zib_get_spare_thumb();
                    $card     = array(
                        'type'         => 'style-3',
                        'class'        => 'mb10',
                        'img'          => $img,
                        'alt'          => $title,
                        'link'         => array('url' => get_permalink(), 'target' => ''),
                        'text1'        => $title,
                        'text2'        => zib_str_cut($title, 0, 45, '...'),
                        'text3'        => $info,
                        'lazy'         => true,
                        'height_scale' => 70,
                    );
                    $items_html .= '<div class="swiper-slide mr10">' . zib_graphic_card($card) . '</div>';
                } else {
                    $items_html .= '<li><a class="icon-circle" href="' . get_permalink() . '">' . get_the_title() . get_the_subtitle() . '</a></li>';
                }
            }
        }
        wp_reset_postdata();
    }

    // 组装 ajax-item 包裹
    $html = '<div class="ajax-item">';
    if ($thumb_s) {
        $html .= '<div class="swiper-container swiper-scroll"><div class="swiper-wrapper">' . $items_html . '</div><div class="swiper-button-prev"></div><div class="swiper-button-next"></div></div>';
    } else {
        $html .= '<ul class="no-thumb">' . ($items_html ?: '<li>没有更多相关文章了</li>') . '</ul>';
    }
    $html .= '</div>';

    // 分页按钮 — 第1页时用当前渲染的ID作为排除项，第2页+沿用原始排除项（上限3页）
    $p_exclude   = (!empty($page_ids)) ? implode(',', array_map('intval', $page_ids)) : $exclude_str;
    $total_count = min(wxs_get_related_total_count($post_id), $per_page * 3);
    $ajax_url    = admin_url('/admin-ajax.php') . '?action=wxs_related_paged&post_id=' . $post_id . '&exclude=' . $p_exclude;
    $pagination_html = zib_get_ajax_number_paginate(
        $total_count,
        $paged,
        $per_page,
        $ajax_url,
        'ajax-pag',
        'next-page ajax-next',
        'paged',
        '.wxs-related-pager'
    );
    if ($pagination_html) {
        $html .= $pagination_html;
    }

    zib_ajax_send_ajaxpager($html, false, 'wxs-related-pager ajaxpager');
}
add_action('wp_ajax_wxs_related_paged', 'wxs_ajax_related_paged');
add_action('wp_ajax_nopriv_wxs_related_paged', 'wxs_ajax_related_paged');

// ==================== Hook 替换 ====================

/**
 * 通过移除并重新添加 action 来替换相关文章
 */
function wxs_init_related_posts_replacement() {
    // 检查开关：关闭时不替换主题原生相关文章
    if ( ! wxs_postchat_get_option( 'enable_related_posts', true ) ) {
        return;
    }
    remove_action('zib_single_after', 'zib_single_after_box');
    add_action('zib_single_after', 'wxs_single_after_box');
}
add_action('after_setup_theme', 'wxs_init_related_posts_replacement', 20);

/**
 * 改进版的文章底部区块
 * 替换原 zib_single_after_box，使用 PostChat AI 相关文章
 */
function wxs_single_after_box() {
    // 一言
    if (_pz('yiyan_single_box')) {
        zib_yiyan('yiyan-box main-bg theme-box text-center box-body radius8 main-shadow');
    }

    // 作者描述
    if (_pz('post_authordesc_s')) {
        $args = array(
            'user_id'     => get_the_author_meta('ID'),
            'show_button' => false,
            'show_img_bg' => false,
            'class'       => 'author',
        );
        zib_get_user_card_box($args, true);
    }

    // 上下篇文章
    if (_pz('post_prevnext_s')) {
        zib_posts_prevnext();
    }

    // 使用 PostChat AI 推荐相关文章
    if (_pz('post_related_s')) {
        wxs_posts_related(_pz('related_title'), _pz('post_related_n'), _pz('post_related_orderby', 'views'));
    }
}
