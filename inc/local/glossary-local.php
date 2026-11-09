<?php
/**
 * 本站模式 — AI 自动生成专业词汇解释
 *
 * 功能：AI 判断文章中的专业/生僻词汇并生成解释，
 * 存入 post_meta，前台通过 the_content filter 注入 hover 气泡标记。
 * 仅电脑端 + 文章/帖子单页 + 功能开启时生效。
 *
 * @package WXS_Zibll_PostChat
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ==================== 前台注入词汇标记 ====================

add_filter( 'the_content', 'wxs_local_inject_glossary', 20 );
function wxs_local_inject_glossary( $content ) {
    if ( wp_is_mobile() || ! is_singular( [ 'post', 'forum_post' ] ) ) {
        return $content;
    }
    if ( ! wxs_postchat_get_option( 'local_enable_glossary', false ) ) {
        return $content;
    }

    $post_id = get_the_ID();
    $glossary = get_post_meta( $post_id, '_wxs_postchat_glossary', true );

    if ( empty( $glossary ) ) {
        wxs_local_trigger_glossary_on_visit( $post_id );
        return $content;
    }

    $items = json_decode( $glossary, true );
    if ( empty( $items ) || ! is_array( $items ) ) {
        return $content;
    }

    // 使用字符串分词方式，按HTML标签分割，只处理 p/li 内的纯文本
    // PREG_SPLIT_DELIM_CAPTURE 保留标签作为独立元素
    $parts = preg_split( '/(<[^>]*>)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );

    $inside_target = false;   // 当前是否在 p 或 li 内
    $excluded_depth = 0;      // 排除标签（code/pre/script/style）的嵌套深度

    $replaced = [];
    foreach ( $items as $item ) {
        if ( ! empty( $item['term'] ) ) {
            $replaced[ $item['term'] ] = false;
        }
    }

    // 辅助函数：从HTML标签字符串中提取标签名
    $get_tag_name = function( $tag_str, $is_close = false ) {
        $inner = substr( $tag_str, $is_close ? 2 : 1 );
        // 去掉 > 结尾及后面的属性
        $end = strpos( $inner, '>' );
        $inner = $end !== false ? substr( $inner, 0, $end ) : $inner;
        if ( ! $is_close ) {
            // 去掉属性部分（第一个空格后的内容）
            $space = strpos( $inner, ' ' );
            if ( $space !== false ) {
                $inner = substr( $inner, 0, $space );
            }
        }
        return strtolower( trim( $inner ) );
    };

    foreach ( $parts as $i => $part ) {
        if ( $part === '' ) {
            continue;
        }

        // 判断是否为HTML标签
        if ( $part[0] === '<' ) {
            $is_close  = ( substr( $part, 0, 2 ) === '</' );
            $self_close = ( substr( $part, -2 ) === '/>' );

            // 提取标签名
            $tag = $get_tag_name( $part, $is_close );

            // 处理排除标签的嵌套深度
            $excluded_tags = [ 'code', 'pre', 'script', 'style' ];
            if ( $excluded_depth > 0 ) {
                if ( $is_close && in_array( $tag, $excluded_tags, true ) ) {
                    $excluded_depth--;
                } elseif ( ! $is_close && ! $self_close && in_array( $tag, $excluded_tags, true ) ) {
                    $excluded_depth++;
                }
            }

            // 跟踪 p/li 标签（仅在非排除层内）
            if ( $excluded_depth === 0 ) {
                if ( $is_close && in_array( $tag, [ 'p', 'li' ], true ) ) {
                    $inside_target = false;
                } elseif ( ! $is_close && ! $self_close ) {
                    if ( in_array( $tag, [ 'p', 'li' ], true ) ) {
                        $inside_target = true;
                    }
                    // 遇到 div 等容器标签重置
                    if ( in_array( $tag, [ 'div', 'blockquote', 'table', 'figure' ], true ) ) {
                        $inside_target = false;
                    }
                }
            }

            continue;
        }

        // 非标签部分：仅在 p/li 内且不在排除标签内时处理
        if ( ! $inside_target || $excluded_depth > 0 ) {
            continue;
        }

        $text = $part;
        $has_change = false;
        $matches = [];

        foreach ( $items as $item ) {
            if ( empty( $item['term'] ) || empty( $item['def'] ) || ! empty( $replaced[ $item['term'] ] ) ) {
                continue;
            }
            $pos = mb_strpos( $text, $item['term'], 0, 'UTF-8' );
            if ( $pos !== false ) {
                $matches[] = [
                    'pos'  => $pos,
                    'len'  => mb_strlen( $item['term'], 'UTF-8' ),
                    'term' => $item['term'],
                    'def'  => $item['def'],
                ];
            }
        }

        if ( ! empty( $matches ) ) {
            // 从右到左排序，避免替换后位置偏移
            usort( $matches, function( $a, $b ) {
                return $b['pos'] - $a['pos'];
            } );
            foreach ( $matches as $m ) {
                $replacement = '<span class="wxs-ai-glossary" data-tip="' . esc_attr( $m['def'] ) . '">' . esc_html( $m['term'] ) . '</span>';
                $text = mb_substr( $text, 0, $m['pos'], 'UTF-8' ) . $replacement . mb_substr( $text, $m['pos'] + $m['len'], null, 'UTF-8' );
                $replaced[ $m['term'] ] = true;
            }
            $has_change = true;
        }

        if ( $has_change ) {
            $parts[ $i ] = $text;
        }
    }

    return implode( '', $parts );
}

// ==================== 前台访问触发异步生成 ====================

function wxs_local_trigger_glossary_on_visit( $post_id ) {
    $lock_key = 'wxs_glossary_visit_' . $post_id;
    if ( get_transient( $lock_key ) ) {
        return;
    }
    set_transient( $lock_key, 1, 600 );

    $content_hash = md5( get_post_field( 'post_content', $post_id ) . get_the_title( $post_id ) );
    wp_schedule_single_event( time() + 5, 'wxs_local_generate_glossary_event', [ $post_id, $content_hash ] );
}

// ==================== 保存文章时触发 ====================

add_action( 'wp_insert_post', 'wxs_local_glossary_on_publish', 25, 3 );
function wxs_local_glossary_on_publish( $post_ID, $post, $update ) {
    static $is_processing = [];
    if ( isset( $is_processing[ $post_ID ] ) ) {
        return;
    }
    if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_ID ) || wp_is_post_autosave( $post_ID ) ) {
        return;
    }
    if ( $post->post_status !== 'publish' ) {
        return;
    }
    if ( ! in_array( $post->post_type, [ 'post', 'forum_post' ], true ) ) {
        return;
    }
    if ( ! wxs_postchat_get_option( 'local_enable_glossary', false ) ) {
        return;
    }

    $is_processing[ $post_ID ] = true;
    $has_glossary = ! empty( get_post_meta( $post_ID, '_wxs_postchat_glossary', true ) );

    if ( $update && $has_glossary ) {
        $regen = isset( $_POST['wxs_regenerate_glossary'] ) && $_POST['wxs_regenerate_glossary'] === '1';
        if ( ! $regen ) {
            unset( $is_processing[ $post_ID ] );
            return;
        }
    }

    $content_hash = md5( $post->post_content . $post->post_title );
    $force_regen = ( $update && $has_glossary );
    $hash_to_pass = $force_regen ? '' : $content_hash;
    wp_schedule_single_event( time() + 8, 'wxs_local_generate_glossary_event', [ $post_ID, $hash_to_pass ] );
    unset( $is_processing[ $post_ID ] );
}

// ==================== 异步生成词汇（WP-Cron）====================

add_action( 'wxs_local_generate_glossary_event', 'wxs_local_generate_glossary', 10, 2 );
function wxs_local_generate_glossary( $post_ID, $content_hash = '' ) {
    if ( ! function_exists( 'wp_ai_client_prompt' ) || ! function_exists( 'wp_supports_ai' ) || ! wp_supports_ai() ) {
        return;
    }

    // 哈希校验防重复
    if ( ! empty( $content_hash ) ) {
        $stored_hash = get_post_meta( $post_ID, '_wxs_postchat_glossary_hash', true );
        if ( $content_hash === $stored_hash && ! empty( get_post_meta( $post_ID, '_wxs_postchat_glossary', true ) ) ) {
            return;
        }
    }

    $post = get_post( $post_ID );
    if ( ! $post || $post->post_status !== 'publish' ) {
        return;
    }

    // 先移除子比隐藏内容，避免从付费/会员/登录可见内容中提取词汇
    $clean_content = wxs_local_strip_hidden_content( $post->post_content );
    $clean_content = wxs_postchat_clean_html_content( $clean_content, 5000 );
    if ( mb_strlen( $clean_content, 'UTF-8' ) < 100 ) {
        return;
    }

    $max_terms = (int) wxs_postchat_get_option( 'local_glossary_max', 8 );
    if ( $max_terms <= 0 ) {
        $max_terms = 8;
    }

    $prompt = "请阅读以下文章，找出其中最多{$max_terms}个专业术语或生僻词汇，"
        . "为每个词提供简短解释（20-50字）。\n"
        . "严格按照以下JSON数组格式返回，不要有任何多余文字：\n"
        . "[{\"term\":\"词汇\",\"def\":\"解释\"}]\n\n"
        . "文章标题：" . $post->post_title . "\n"
        . "文章内容：\n" . $clean_content;

    $builder = wp_ai_client_prompt( $prompt )
        ->using_system_instruction( '你是一个专业术语解释助手，请严格按JSON格式返回结果。' )
        ->using_temperature( 0.3 )
        ->using_max_tokens( 800 );

    $model_prefs = wxs_postchat_get_model_preferences();
    if ( ! empty( $model_prefs ) ) {
        $builder = $builder->using_model_preference( ...$model_prefs );
    }

    $result = $builder->generate_text();

    if ( is_wp_error( $result ) || empty( $result ) ) {
        return;
    }

    // 提取 JSON 数组
    $result = trim( (string) $result );
    if ( preg_match( '/\[.*\]/s', $result, $matches ) ) {
        $json_str = $matches[0];
    } else {
        return;
    }

    $items = json_decode( $json_str, true );
    if ( ! is_array( $items ) || empty( $items ) ) {
        return;
    }

    // 验证并清洗数据
    $valid = [];
    foreach ( $items as $item ) {
        if ( ! empty( $item['term'] ) && ! empty( $item['def'] ) ) {
            $valid[] = [
                'term' => sanitize_text_field( $item['term'] ),
                'def'  => sanitize_text_field( $item['def'] ),
            ];
        }
    }

    if ( empty( $valid ) ) {
        return;
    }

    update_post_meta( $post_ID, '_wxs_postchat_glossary', wp_json_encode( $valid, JSON_UNESCAPED_UNICODE ) );
    $real_hash = $content_hash ?: md5( $post->post_content . $post->post_title );
    update_post_meta( $post_ID, '_wxs_postchat_glossary_hash', $real_hash );
}

// ==================== Meta Box：重新生成词汇 ====================

add_action( 'add_meta_boxes', 'wxs_local_register_glossary_meta_box' );
function wxs_local_register_glossary_meta_box() {
    if ( ! wxs_postchat_get_option( 'local_enable_glossary', false ) ) {
        return;
    }
    $post_types = [ 'post' ];
    if ( function_exists( '_pz' ) && _pz( 'bbs_s', true ) ) {
        $post_types[] = 'forum_post';
    }
    add_meta_box(
        'wxs_ai_glossary_box',
        'AI 词汇解释',
        'wxs_local_render_glossary_meta_box',
        $post_types,
        'side',
        'default'
    );
}

function wxs_local_render_glossary_meta_box( $post ) {
    $glossary = get_post_meta( $post->ID, '_wxs_postchat_glossary', true );
    $items = ! empty( $glossary ) ? json_decode( $glossary, true ) : [];
    $has = is_array( $items ) && ! empty( $items );

    echo '<p style="margin:0 0 8px;">';
    if ( $has ) {
        echo '<span style="color:#52c41a;">✓ 已有 ' . count( $items ) . ' 个词汇</span>';
        $terms = array_column( $items, 'term' );
        echo '<br><small style="color:#888;">' . esc_html( implode( '、', array_slice( $terms, 0, 5 ) ) ) . ( count( $terms ) > 5 ? '...' : '' ) . '</small>';
    } else {
        echo '<span style="color:#faad14;">○ 暂无词汇（发布后自动生成）</span>';
    }
    echo '</p>';

    echo '<label style="display:flex;align-items:center;gap:6px;cursor:pointer;">';
    echo '<input type="checkbox" name="wxs_regenerate_glossary" value="1">';
    echo '<span>保存时重新生成专业词汇</span>';
    echo '</label>';
    echo '<p class="description" style="margin-top:4px;">勾选后更新文章将重新调用AI识别词汇，覆盖现有内容。</p>';
}

// ==================== 后台词汇管理页面 ====================

add_action( 'admin_menu', 'wxs_local_glossary_admin_menu', 99 );
function wxs_local_glossary_admin_menu() {
    if ( wxs_postchat_get_mode() !== 'local' ) {
        return;
    }
    add_submenu_page(
        'wxs-zibll-postchat',
        '词汇管理',
        '词汇管理',
        'manage_options',
        'wxs-glossary-manage',
        'wxs_local_glossary_admin_page'
    );
}

function wxs_local_glossary_admin_page() {
    global $wpdb;
    // 查询所有含词汇的文章（服务端渲染默认列表）
    $meta_rows = $wpdb->get_results(
        "SELECT pm.post_id, pm.meta_value, p.post_title
         FROM {$wpdb->postmeta} pm
         INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_status = 'publish'
         WHERE pm.meta_key = '_wxs_postchat_glossary' AND pm.meta_value != ''
         ORDER BY pm.post_id DESC
         LIMIT 200"
    );
    $glossary_posts = [];
    foreach ( $meta_rows as $row ) {
        $items = json_decode( $row->meta_value, true );
        if ( ! is_array( $items ) || empty( $items ) ) {
            continue;
        }
        $terms = array_column( $items, 'term' );
        $glossary_posts[] = [
            'id'      => (int) $row->post_id,
            'title'   => $row->post_title,
            'count'   => count( $items ),
            'preview' => implode( '、', array_slice( $terms, 0, 3 ) ) . ( count( $terms ) > 3 ? '...' : '' ),
        ];
    }
    ?>
    <div class="wrap">
        <h1>专业词汇管理</h1>
        <p class="description">管理各文章的 AI 生成词汇解释。可搜索文章，编辑/删除/新增词汇。</p>
        <div id="wxs-glossary-app" style="margin-top:20px;">
            <div style="margin-bottom:15px;display:flex;gap:10px;align-items:center;">
                <input type="text" id="wxs-glossary-search" class="regular-text"
                       placeholder="输入文章标题搜索..." autocomplete="off">
                <button type="button" class="button" id="wxs-glossary-search-btn">搜索</button>
                <button type="button" class="button" id="wxs-glossary-show-all">显示全部</button>
            </div>
            <?php if ( ! empty( $glossary_posts ) ) : ?>
            <table class="widefat striped" id="wxs-glossary-default-list">
                <thead>
                    <tr>
                        <th style="width:60px;">ID</th>
                        <th>文章标题</th>
                        <th>词汇预览</th>
                        <th style="width:60px;">数量</th>
                        <th style="width:120px;">操作</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( $glossary_posts as $gp ) : ?>
                    <tr>
                        <td><?php echo $gp['id']; ?></td>
                        <td><?php echo esc_html( $gp['title'] ); ?></td>
                        <td><small><?php echo esc_html( $gp['preview'] ); ?></small></td>
                        <td><?php echo $gp['count']; ?></td>
                        <td>
                            <a href="#" class="wxs-gl-edit" data-id="<?php echo $gp['id']; ?>">编辑</a>
                            | <a href="#" class="wxs-gl-delete-post" data-id="<?php echo $gp['id']; ?>" style="color:#a00;">删除</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php else : ?>
            <p style="color:#888;">暂无已生成词汇的文章。文章发布后会自动生成。</p>
            <?php endif; ?>
            <div id="wxs-glossary-post-list" style="margin-top:15px;"></div>
            <div id="wxs-glossary-editor" style="display:none;margin-top:20px;">
                <h3 id="wxs-glossary-post-title"></h3>
                <table class="widefat" id="wxs-glossary-table">
                    <thead><tr><th>词汇</th><th>解释</th><th style="width:80px;">操作</th></tr></thead>
                    <tbody></tbody>
                </table>
                <p style="margin-top:10px;">
                    <button type="button" class="button" id="wxs-glossary-add">+ 新增词汇</button>
                    <button type="button" class="button button-primary" id="wxs-glossary-save">保存修改</button>
                    <button type="button" class="button" id="wxs-glossary-back">← 返回列表</button>
                    <span id="wxs-glossary-status" style="margin-left:10px;"></span>
                </p>
            </div>
        </div>
    </div>
    <script>
    jQuery(function($){
        var currentPostId = 0;
        var nonce = '<?php echo wp_create_nonce( "wxs_glossary_manage" ); ?>';

        // 搜索
        function doSearch(){
            var q = $('#wxs-glossary-search').val().trim();
            if(!q){ return; }
            $('#wxs-glossary-post-list').html('<p>搜索中...</p>');
            $('#wxs-glossary-default-list').hide();
            $.post(ajaxurl, {
                action: 'wxs_glossary_search_posts', s: q, _nonce: nonce
            }, function(r){
                if(!r.success){ $('#wxs-glossary-post-list').html('<p>'+r.data+'</p>'); return; }
                if(!r.data.length){ $('#wxs-glossary-post-list').html('<p>未找到相关文章</p>'); return; }
                var html = '<table class="widefat striped"><thead><tr><th style="width:60px;">ID</th><th>文章标题</th><th>词汇预览</th><th style="width:60px;">数量</th><th style="width:120px;">操作</th></tr></thead><tbody>';
                $.each(r.data, function(i, p){
                    html += '<tr><td>'+p.id+'</td>';
                    html += '<td>'+escHtml(p.title)+'</td>';
                    html += '<td><small>'+(p.preview||'-')+'</small></td>';
                    html += '<td>'+p.count+'</td>';
                    html += '<td><a href="#" class="wxs-gl-edit" data-id="'+p.id+'">编辑</a>';
                    if(p.count > 0) html += ' | <a href="#" class="wxs-gl-delete-post" data-id="'+p.id+'" style="color:#a00;">删除</a>';
                    html += '</td></tr>';
                });
                html += '</tbody></table>';
                $('#wxs-glossary-post-list').html(html);
            });
        }
        $('#wxs-glossary-search-btn').on('click', doSearch);
        $('#wxs-glossary-search').on('keypress', function(e){ if(e.which===13) doSearch(); });

        // 显示全部
        $('#wxs-glossary-show-all').on('click', function(){
            $('#wxs-glossary-search').val('');
            $('#wxs-glossary-post-list').empty();
            $('#wxs-glossary-default-list').show();
            $('#wxs-glossary-editor').hide();
        });

        // 编辑词汇
        $(document).on('click', '.wxs-gl-edit', function(e){
            e.preventDefault();
            currentPostId = $(this).data('id');
            loadGlossary(currentPostId);
        });

        // 删除整篇文章词汇
        $(document).on('click', '.wxs-gl-delete-post', function(e){
            e.preventDefault();
            if(!confirm('确定删除该文章的所有词汇？')) return;
            var id = $(this).data('id');
            var $row = $(this).closest('tr');
            $.post(ajaxurl, {
                action: 'wxs_glossary_save', post_id: id, items: '[]', _nonce: nonce
            }, function(r){
                if(r.success) $row.fadeOut(300, function(){ $(this).remove(); });
            });
        });

        function loadGlossary(postId){
            $('#wxs-glossary-default-list').hide();
            $('#wxs-glossary-post-list').hide();
            $.post(ajaxurl, {
                action: 'wxs_glossary_get', post_id: postId, _nonce: nonce
            }, function(r){
                if(!r.success){ alert(r.data); return; }
                $('#wxs-glossary-post-title').text('编辑词汇：' + r.data.title + ' (ID:'+postId+')');
                renderTable(r.data.items);
                $('#wxs-glossary-editor').show();
            });
        }

        function renderTable(items){
            var tb = $('#wxs-glossary-table tbody');
            tb.empty();
            $.each(items, function(i, item){
                tb.append(rowHtml(item.term, item.def));
            });
        }

        function rowHtml(term, def){
            return '<tr><td><input type="text" class="regular-text wxs-gl-term" value="'+escHtml(term)+'"></td>'
                + '<td><input type="text" class="large-text wxs-gl-def" value="'+escHtml(def)+'"></td>'
                + '<td><button type="button" class="button wxs-gl-del">删除</button></td></tr>';
        }

        function escHtml(s){ return $('<span>').text(s).html(); }

        // 新增词汇
        $('#wxs-glossary-add').on('click', function(){
            $('#wxs-glossary-table tbody').append(rowHtml('',''));
        });

        // 删除单条
        $(document).on('click', '.wxs-gl-del', function(){
            $(this).closest('tr').remove();
        });

        // 返回列表
        $('#wxs-glossary-back').on('click', function(){
            $('#wxs-glossary-editor').hide();
            $('#wxs-glossary-default-list').show();
            $('#wxs-glossary-post-list').show();
        });

        // 保存
        $('#wxs-glossary-save').on('click', function(){
            var items = [];
            $('#wxs-glossary-table tbody tr').each(function(){
                var t = $(this).find('.wxs-gl-term').val().trim();
                var d = $(this).find('.wxs-gl-def').val().trim();
                if(t && d) items.push({term:t, def:d});
            });
            $('#wxs-glossary-status').text('保存中...');
            $.post(ajaxurl, {
                action: 'wxs_glossary_save',
                post_id: currentPostId,
                items: JSON.stringify(items),
                _nonce: nonce
            }, function(r){
                $('#wxs-glossary-status').text(r.success ? '已保存' : '保存失败');
                setTimeout(function(){ $('#wxs-glossary-status').text(''); }, 2000);
            });
        });
    });
    </script>
    <?php
}

// ==================== AJAX 接口 ====================

add_action( 'wp_ajax_wxs_glossary_search_posts', 'wxs_glossary_ajax_search_posts' );
function wxs_glossary_ajax_search_posts() {
    check_ajax_referer( 'wxs_glossary_manage', '_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '无权限' );
    }
    $s = sanitize_text_field( wp_unslash( $_POST['s'] ?? '' ) );
    if ( empty( $s ) ) {
        wp_send_json_error( '请输入搜索关键词' );
    }
    $posts = get_posts( [
        'post_type'      => [ 'post', 'forum_post' ],
        'post_status'    => 'publish',
        's'              => $s,
        'posts_per_page' => 20,
    ] );
    $data = [];
    foreach ( $posts as $p ) {
        $glossary = get_post_meta( $p->ID, '_wxs_postchat_glossary', true );
        $items = ! empty( $glossary ) ? json_decode( $glossary, true ) : [];
        $count = is_array( $items ) ? count( $items ) : 0;
        $terms = is_array( $items ) ? array_column( $items, 'term' ) : [];
        $preview = ! empty( $terms )
            ? implode( '、', array_slice( $terms, 0, 3 ) ) . ( count( $terms ) > 3 ? '...' : '' )
            : '';
        $data[] = [
            'id'      => $p->ID,
            'title'   => $p->post_title,
            'count'   => $count,
            'preview' => $preview,
        ];
    }
    wp_send_json_success( $data );
}

add_action( 'wp_ajax_wxs_glossary_get', 'wxs_glossary_ajax_get' );
function wxs_glossary_ajax_get() {
    check_ajax_referer( 'wxs_glossary_manage', '_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '无权限' );
    }
    $post_id = (int) ( $_POST['post_id'] ?? 0 );
    $post = get_post( $post_id );
    if ( ! $post ) {
        wp_send_json_error( '文章不存在' );
    }
    $glossary = get_post_meta( $post_id, '_wxs_postchat_glossary', true );
    $items = ! empty( $glossary ) ? json_decode( $glossary, true ) : [];
    if ( ! is_array( $items ) ) {
        $items = [];
    }
    wp_send_json_success( [ 'title' => $post->post_title, 'items' => $items ] );
}

add_action( 'wp_ajax_wxs_glossary_save', 'wxs_glossary_ajax_save' );
function wxs_glossary_ajax_save() {
    check_ajax_referer( 'wxs_glossary_manage', '_nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( '无权限' );
    }
    $post_id = (int) ( $_POST['post_id'] ?? 0 );
    if ( ! get_post( $post_id ) ) {
        wp_send_json_error( '文章不存在' );
    }
    $raw = wp_unslash( $_POST['items'] ?? '[]' );
    $items = json_decode( $raw, true );
    if ( ! is_array( $items ) ) {
        $items = [];
    }
    $valid = [];
    foreach ( $items as $item ) {
        $t = sanitize_text_field( $item['term'] ?? '' );
        $d = sanitize_text_field( $item['def'] ?? '' );
        if ( $t && $d ) {
            $valid[] = [ 'term' => $t, 'def' => $d ];
        }
    }
    if ( empty( $valid ) ) {
        delete_post_meta( $post_id, '_wxs_postchat_glossary' );
        delete_post_meta( $post_id, '_wxs_postchat_glossary_hash' );
    } else {
        update_post_meta( $post_id, '_wxs_postchat_glossary', wp_json_encode( $valid, JSON_UNESCAPED_UNICODE ) );
    }
    wp_send_json_success();
}
