<?php
/**
 * 前端JS/CSS输出 + 摘要注入
 *
 * @package WXS_Zibll_PostChat
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 清理标题中的站点名后缀
 *
 * @param string $title 原始标题
 * @return string 清理后的标题
 */
function wxs_postchat_clean_title( $title ) {
    // 检查是否开启排除站点名
    $exclude_site_title = wxs_postchat_get_option( 'exclude_site_title', true );
    if ( ! $exclude_site_title ) {
        return $title;
    }

    // 获取站点名称
    $site_name = get_bloginfo( 'name' );
    if ( empty( $site_name ) ) {
        return $title;
    }

    // 移除常见的标题格式：标题 - 站点名、标题 | 站点名、标题 – 站点名、标题 — 站点名
    $patterns = array(
        '/\s*[-|–|—]\s*' . preg_quote( $site_name, '/' ) . '\s*$/u',  // - 站点名
        '/\s*\|\s*' . preg_quote( $site_name, '/' ) . '\s*$/u',        // | 站点名
    );

    return trim( preg_replace( $patterns, '', $title ) );
}

/**
 * 检查当前页面URL是否在黑名单中
 *
 * @return bool true=在黑名单中，false=不在黑名单中
 */
function wxs_postchat_is_url_blacklisted() {
    $blacklist_urls = wxs_postchat_get_option( 'blacklist_urls', '' );
    if ( empty( $blacklist_urls ) ) {
        return false;
    }

    // 获取当前页面URL
    $current_url = '';
    global $post;
    if ( $post && ( is_singular( 'post' ) || is_singular( 'forum_post' ) ) ) {
        $current_url = get_permalink( $post->ID );
    }
    if ( empty( $current_url ) ) {
        return false;
    }

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

    // 检查当前URL是否匹配黑名单
    if ( ! empty( $blacklist_patterns ) ) {
        foreach ( $blacklist_patterns as $pattern ) {
            if ( preg_match( $pattern, $current_url ) ) {
                return true;
            }
        }
    }

    return false;
}

/**
 * 替换 default_input 中的变量占位符
 *
 * @param string $text 包含变量的文本
 * @return string 替换后的文本
 */
function wxs_postchat_replace_variables( $text ) {
    if ( empty( $text ) ) {
        return $text;
    }

    // 只在文章页和帖子页替换 {title}，其他页面移除占位符
    $title = '';
    if ( is_singular( 'post' ) || is_singular( 'forum_post' ) ) {
        $title = wxs_postchat_clean_title( get_the_title() );
    }

    // 变量映射表
    $replacements = array(
        '{title}' => $title,
    );

    // 执行替换
    return str_replace( array_keys( $replacements ), array_values( $replacements ), $text );
}

/**
 * 包裹文章内容，供PostChat JS定位（仅当选择器为默认值时不需要包裹，自定义选择器时跳过）
 */
add_filter( 'the_content', 'wxs_postchat_add_content_wrapper' );
function wxs_postchat_add_content_wrapper( $content ) {
    if ( ! is_single() ) {
        return $content;
    }
    $enable_summary = wxs_postchat_get_option( 'enable_summary', true );
    if ( ! $enable_summary ) {
        return $content;
    }
    // 如果用户配置了主题已有的选择器，无需额外包裹
    $post_type = get_post_type();
    if ( $post_type === 'post' ) {
        $selector = wxs_postchat_get_option( 'post_selector', '.single-post .wp-posts-content' );
    } elseif ( $post_type === 'forum_post' ) {
        $selector = wxs_postchat_get_option( 'forum_post_selector', '.forum-article .wp-posts-content' );
    } else {
        return $content;
    }
    if ( $selector !== '#postchat_postcontent' ) {
        return $content;
    }
    // 仅当选择器指向 #postchat_postcontent 时才包裹
    if ( $post_type === 'post' && wxs_postchat_get_option( 'enable_article_summary', true ) ) {
        $content = '<div id="postchat_postcontent">' . $content . '</div>';
    } elseif ( $post_type === 'forum_post' && function_exists( '_pz' ) && _pz( 'bbs_s', true ) && wxs_postchat_get_option( 'enable_forum_summary', true ) ) {
        $content = '<div id="postchat_postcontent">' . $content . '</div>';
    }
    return $content;
}

/**
 * 在页面底部输出PostChat JS/CSS配置
 */
add_action( 'wp_footer', 'wxs_postchat_render_footer' );
function wxs_postchat_render_footer() {
    $enable_summary = wxs_postchat_get_option( 'enable_summary', true );
    $enable_ai      = wxs_postchat_get_option( 'enable_ai', true );

    // 摘要和AI均未开启则不输出
    if ( ! $enable_summary && ! $enable_ai ) {
        return;
    }

    $api_key = wxs_postchat_get_option( 'api_key', '' );
    if ( empty( $api_key ) ) {
        return;
    }

    // data-postChat_key 使用完整key，不做裁剪
    $data_key = $api_key;

    // ========== 摘要页面类型判断 ==========
    $is_article    = is_singular( 'post' );
    $is_forum_post = is_singular( 'forum_post' );
    $forum_enabled = function_exists( '_pz' ) && _pz( 'bbs_s', true );

    // 判断当前页面是否应显示摘要
    $show_summary      = false;
    $page_post_url     = '';
    $page_post_selector = '';
    if ( $enable_summary ) {
        if ( $is_article && wxs_postchat_get_option( 'enable_article_summary', true ) ) {
            $show_summary      = true;
            $page_post_url     = wxs_postchat_get_option( 'post_url', '/^https?:\\/\\/[^/]+\\/\\d+\\.html.*/' );
            $page_post_selector = wxs_postchat_get_option( 'post_selector', '.single-post .wp-posts-content' );
        } elseif ( $is_forum_post && $forum_enabled && wxs_postchat_get_option( 'enable_forum_summary', true ) ) {
            $show_summary      = true;
            $page_post_url     = wxs_postchat_get_option( 'forum_post_url', '/\\/forum-post\\/\\d+\\.html$/' );
            $page_post_selector = wxs_postchat_get_option( 'forum_post_selector', '.forum-article .wp-posts-content' );
        }
    }

    // ========== PostChat官方摘要CSS ==========
    if ( $show_summary ) {
        if ( $enable_ai ) {
            echo '<link rel="stylesheet" href="https://ai.zhheo.com/static/public/postChatUser_summary.min.css">' . "\n";
        } else {
            echo '<link rel="stylesheet" href="https://ai.zhheo.com/static/public/tianli_gpt.min.css">' . "\n";
        }
    }

    // ========== 主题适配CSS ==========
    if ( $show_summary ) {
        $summary_css = wxs_postchat_get_option( 'summary_css', 'https://ai.zhheo.com/static/public/custom/theme_zibi.min.css' );
        if ( ! empty( $summary_css ) ) {
            echo '<link rel="stylesheet" href="' . esc_url( $summary_css ) . '">' . "\n";
        }
    }

    // ========== 摘要JS变量（仅摘要页面） ==========
    if ( $show_summary ) {
        // 从数据库读取私有摘要
        $summary = '';
        $private_summary = wxs_postchat_get_option( 'private_summary', false );
        if ( $private_summary ) {
            global $post;
            if ( $post ) {
                $summary = get_post_meta( $post->ID, '_wxs_postchat_summary', true );
            }
        }

        echo '<script>' . "\n";
        echo 'let tianliGPT_key = ' . wp_json_encode( $api_key ) . ';' . "\n";

        // 字符串变量：非空时才输出
        $str_vars = [
            'tianliGPT_postSelector' => $page_post_selector,
            'tianliGPT_Title'        => wxs_postchat_get_option( 'summary_title', '智能总结摘要' ),
            'tianliGPT_Name'         => wxs_postchat_get_option( 'summary_name', '王先生助理' ),
            'tianliGPT_postURL'      => $page_post_url,
            'tianliGPT_blacklist'    => wxs_postchat_get_option( 'blacklist_urls', '' ) ? add_query_arg( 'wxs_postchat_query', 'blacklist', home_url( '/' ) ) : '',
            'tianliGPT_injectDom'    => wxs_postchat_get_option( 'inject_dom', '' ),
            'tianliGPT_wordLimit'    => wxs_postchat_get_option( 'word_limit', '5000' ),
            'tianliGPT_theme'        => wxs_postchat_get_option( 'summary_theme', '' ),
            'tianliGPT_BeginningText' => $is_forum_post
                            ? wxs_postchat_get_option( 'forum_beginning_text', '这篇帖子介绍了' )
                            : wxs_postchat_get_option( 'beginning_text', '这篇文章介绍了' ),
        ];
        foreach ( $str_vars as $name => $value ) {
            if ( $value !== '' && $value !== null ) {
                echo 'let ' . $name . ' = ' . wp_json_encode( $value ) . ';' . "\n";
            }
        }

        // 布尔变量：始终输出
        echo 'let tianliGPT_typingAnimate = ' . ( wxs_postchat_get_option( 'typing_animate', true ) ? 'true' : 'false' ) . ';' . "\n";
        echo 'let tianliGPT_podcast = ' . ( wxs_postchat_get_option( 'podcast', true ) ? 'true' : 'false' ) . ';' . "\n";
        echo 'let tianliGPT_loadingText = ' . ( wxs_postchat_get_option( 'loading_text', false ) ? 'true' : 'false' ) . ';' . "\n";

        // 注入数据库摘要（核心：将PHP数据注入为JS变量）
        if ( ! empty( $summary ) ) {
            echo 'let tianliGPT_summary = ' . wp_json_encode( $summary ) . ';' . "\n";
        }

        echo '</script>' . "\n";
    }

    $is_mobile = wp_is_mobile();
    $is_single = is_single();

    // ========== postChatConfig（仅AI对话开启时输出） ==========
    if ( $enable_ai ) {
        $config = [];

        // 对话窗口 + 界面配置：始终输出
        $str_config = [
            'frameWidth'      => wxs_postchat_get_option( 'frame_width', '375px' ),
            'frameHeight'     => wxs_postchat_get_option( 'frame_height', '600px' ),
            'userTitle'       => wxs_postchat_get_option( 'user_title', '王先生笔记的助理' ),
            'userDesc'        => wxs_postchat_get_option( 'user_desc', '如果你对网站的内容有任何疑问，可以来问我哦～' ),
            'userIcon'        => wxs_postchat_get_option( 'user_icon', '' ),
        ];
        foreach ( $str_config as $key => $value ) {
            if ( $value !== '' && $value !== null ) {
                $config[ $key ] = $value;
            }
        }

        // PostChat悬浮按钮样式：仅 postchat 模式时输出
        $is_postchat_btn = ( wxs_postchat_get_option( 'enable_ai_btn', true ) && wxs_postchat_get_option( 'ai_btn_mode', 'hijack' ) === 'postchat' );
        $config['addButton'] = $is_postchat_btn;
        if ( $is_postchat_btn ) {
            $btn_style = [
                'backgroundColor' => wxs_postchat_get_option( 'background_color', '#3e86f6' ),
                'bottom'          => wxs_postchat_get_option( 'btn_bottom', '16px' ),
                'left'            => wxs_postchat_get_option( 'btn_left', '16px' ),
                'fill'            => wxs_postchat_get_option( 'fill_color', '#FFFFFF' ),
                'width'           => wxs_postchat_get_option( 'btn_width', '44px' ),
                'height'          => wxs_postchat_get_option( 'btn_height', '' ),
            ];
            foreach ( $btn_style as $key => $value ) {
                if ( $value !== '' && $value !== null ) {
                    $config[ $key ] = $value;
                }
            }
        }

        // 对话模式：根据设备类型读取后台配置
        $config['userMode'] = $is_mobile
            ? wxs_postchat_get_option( 'mobile_user_mode', 'iframe' )
            : wxs_postchat_get_option( 'desktop_user_mode', 'magic' );

        // defaultInput：仅在文章页/帖子页输出（其他页面让JS后备值处理），并替换变量
        $default_input = wxs_postchat_get_option( 'default_input', '请问在《{title}》中' );
        if ( ! empty( $default_input ) && ( $is_article || $is_forum_post ) ) {
            $config['defaultInput'] = wxs_postchat_replace_variables( $default_input );
        }

        // 布尔字段：仅文章页和帖子页才启用upLoadWeb，排除板块等页面；黑名单URL不上传知识库
        $enable_upload_web = ( $is_article || $is_forum_post ) && ! wxs_postchat_is_url_blacklisted();
        $config['upLoadWeb']      = $enable_upload_web ? (bool) wxs_postchat_get_option( 'upload_web', true ) : false;
        $config['hotWords']       = ( ! $is_mobile && $is_single ) ? (bool) wxs_postchat_get_option( 'hot_words', false ) : false;
        $config['showInviteLink'] = (bool) wxs_postchat_get_option( 'show_invite_link', true );

        // 数组字段：非空时解析为数组加入
        $array_fields = [
            'defaultChatQuestions'   => wxs_postchat_get_option( 'default_chat_questions', '' ),
            'defaultSearchQuestions' => wxs_postchat_get_option( 'default_search_questions', '' ),
            'blackDom'               => wxs_postchat_get_option( 'black_dom', '' ),
        ];
        foreach ( $array_fields as $key => $value ) {
            if ( $value !== '' && $value !== null ) {
                $config[ $key ] = array_map( 'trim', explode( ',', $value ) );
            }
        }

        // 数值字段：非空时转为整数加入
        $recommend = wxs_postchat_get_option( 'recommend', '' );
        if ( $recommend !== '' && $recommend !== null ) {
            $config['recommend'] = intval( $recommend );
        }

        echo '<script>' . "\n";
        echo 'var postChatConfig = ' . wp_json_encode( $config, JSON_UNESCAPED_UNICODE ) . ';' . "\n";
        echo '</script>' . "\n";
    }

    // ========== AI搜索CSS ==========
    $enable_ai_search = $enable_ai && wxs_postchat_get_option( 'enable_ai_search', true );
    if ( $enable_ai_search ) {
        echo '<link rel="stylesheet" href="' . esc_url( WXS_POSTCHAT_URL . 'assets/css/wxszbpc-ai-search.min.css?ver=' . WXS_POSTCHAT_VERSION ) . '">' . "\n";
    }

    // ========== 插件配置变量 wxszbpc_config ==========
    $wxszbpc_config = [
        'isHomePage' => is_front_page(),
        'isMobile'   => $is_mobile,
    ];

    // AI搜索标志
    if ( $enable_ai_search ) {
        $wxszbpc_config['enableAiSearch'] = true;
    }

    // AI对话按钮配置（仅劫持模式）
    $enable_ai_btn = $enable_ai && wxs_postchat_get_option( 'enable_ai_btn', true );
    $ai_btn_mode   = wxs_postchat_get_option( 'ai_btn_mode', 'hijack' );
    if ( $enable_ai_btn && $ai_btn_mode === 'hijack' ) {
        $ai_btn_selector = wxs_postchat_get_option( 'ai_btn_selector', '#ai-duihua' );
        if ( ! empty( $ai_btn_selector ) ) {
            $wxszbpc_config['aiBtnSelector'] = $ai_btn_selector;
            $wxszbpc_config['aiBtnSvgIcon']  = wxs_postchat_get_option( 'ai_btn_svg_icon', '#wxsicon-koubei' );
            // 劫持按钮点击时的预填内容（仅在文章页/帖子页输出，其他页面让JS后备值处理）
            if ( ! empty( $default_input ) && ( $is_article || $is_forum_post ) ) {
                $wxszbpc_config['defaultInput'] = wxs_postchat_replace_variables( $default_input );
            }
            // 排除标题中的站点名（用于JS后备值）
            $wxszbpc_config['excludeSiteTitle'] = (bool) wxs_postchat_get_option( 'exclude_site_title', true );
            $wxszbpc_config['siteName']         = get_bloginfo( 'name' );
        }
    }

    // 用户引导配置
    $enable_guide     = wxs_postchat_get_option( 'enable_user_guide', true );
    $guide_trigger    = wxs_postchat_get_option( 'guide_trigger', 'logged_in' );
    $should_guide     = $enable_guide && ( ( $guide_trigger === 'all' ) || ( $guide_trigger === 'logged_in' && is_user_logged_in() ) );
    if ( $should_guide ) {
        $cookie_days = intval( wxs_postchat_get_option( 'guide_cookie_days', '7' ) );
        $wxszbpc_config['guideCookieMs'] = $cookie_days * 86400 * 1000;
        $wxszbpc_config['pluginUrl']     = WXS_POSTCHAT_URL;
        $wxszbpc_config['pluginVer']     = '?ver=' . WXS_POSTCHAT_VERSION;
        // 引导需要的按钮模式和选择器
        $wxszbpc_config['guideBtnMode']  = $enable_ai_btn ? $ai_btn_mode : '';
        if ( $enable_ai_btn && $ai_btn_mode === 'hijack' ) {
            $wxszbpc_config['guideHijackSelector'] = wxs_postchat_get_option( 'ai_btn_selector', '#ai-duihua' );
        }
        // 移动端引导开关
        $wxszbpc_config['guideMobileSearch'] = (bool) wxs_postchat_get_option( 'guide_mobile_search', false );
        $wxszbpc_config['guideMobileAiBtn']  = (bool) wxs_postchat_get_option( 'guide_mobile_ai_btn', true );
        // 自定义引导文案
        $wxszbpc_config['guideSearchTitle'] = wxs_postchat_get_option( 'guide_search_title', '小贴士' );
        $wxszbpc_config['guideSearchDesc']  = wxs_postchat_get_option( 'guide_search_desc', '在此处填写想搜索的内容，点击右侧AI搜索，结果更加精准哦' );
        $wxszbpc_config['guideAiTitle']     = wxs_postchat_get_option( 'guide_ai_title', 'AI对话' );
        $wxszbpc_config['guideAiDesc']      = wxs_postchat_get_option( 'guide_ai_desc', '点击此按钮，可与智能AI客服对话哦' );
    }

    echo '<script>var wxszbpc_config=' . wp_json_encode( $wxszbpc_config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ';</script>' . "\n";

    // ========== 插件主脚本（AI搜索 + AI对话按钮） ==========
    $need_postchat_js = $enable_ai_search || ( $enable_ai_btn && $ai_btn_mode === 'hijack' && ! empty( $ai_btn_selector ) );
    if ( $need_postchat_js ) {
        echo '<script src="' . esc_url( WXS_POSTCHAT_URL . 'assets/js/wxszbpc-postchat.min.js?ver=' . WXS_POSTCHAT_VERSION ) . '"></script>' . "\n";
    }

    // ========== 主脚本 ==========
    if ( $show_summary && $enable_ai ) {
        // 摘要+AI：加载合并脚本
        echo '<script data-postChat_key="' . esc_attr( $data_key ) . '" src="https://ai.zhheo.com/static/public/postChatUser_summary.min.js"></script>' . "\n";
    } elseif ( $show_summary ) {
        // 仅摘要：使用tianliGPT_key变量传递key（官方规范）
        echo '<script src="https://ai.zhheo.com/static/public/tianli_gpt.min.js"></script>' . "\n";
    } elseif ( $enable_ai ) {
        // 仅AI对话：加载对话脚本
        echo '<script data-postChat_key="' . esc_attr( $data_key ) . '" src="https://ai.zhheo.com/static/public/postChatUser.min.js"></script>' . "\n";
    }

    // ========== 用户引导JS ==========
    if ( $should_guide ) {
        echo '<script src="' . esc_url( WXS_POSTCHAT_URL . 'assets/js/wxszbpc-user-guide.min.js?ver=' . WXS_POSTCHAT_VERSION ) . '"></script>' . "\n";
    }
}

