<?php
/**
 * CSF 选项配置文件
 * 
 * @package WXS_Zibll_PostChat
 */

// 防止直接访问
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * 创建插件 CSF 选项页面
 */
function wxs_postchat_options() {
    if ( ! class_exists( 'CSF' ) ) {
        return;
    }

    $prefix = WXS_POSTCHAT_PREFIX;
    $footer_text = sprintf(
        '作者：天无神话 | 版本:v%s <i class="fa fa-fw fa-heart-o" aria-hidden="true"></i> PostChat AI',
        esc_html( WXS_POSTCHAT_VERSION )
    );

    // 创建选项页面
    CSF::createOptions( $prefix, [
        'menu_title'      => 'PostChat AI',
        'menu_slug'       => 'wxs-zibll-postchat',
        'framework_title' => 'PostChat AI v' . WXS_POSTCHAT_VERSION,
        'footer_text'     => $footer_text,
        'footer_credit'   => 'PostChat AI作者：天无神话 ',
        'theme'           => 'light',
        'menu_icon'       => 'dashicons-format-status',
        'menu_position'   => 32,
        'show_bar_menu'   => false,
    ] );

    // ========== 插件首页 ==========
    $invite_url = 'https://ai.zhheo.com/console/login?InviteID=63772604';
    $qq_group   = 'https://jq.qq.com/?_wv=1027&k=eiGEOg3i';
    $qq_author  = 'https://qm.qq.com/q/aKlJlhh4VU';

    CSF::createSection( $prefix, [
        'title'  => '插件首页',
        'icon'   => 'fa fa-home',
        'fields' => [
            [
                'type'    => 'heading',
                'content' => '子比PostChat — 基于洪墨AI的摘要与对话集成插件',
            ],
            [
                'type'    => 'submessage',
                'style'   => 'info',
                'content' => '基于洪墨AI平台，为子比主题提供 AI 智能摘要、AI 对话、AI 搜索等功能的深度集成插件。',
            ],
            [
                'type'    => 'heading',
                'content' => '洪墨AI平台（必需）',
            ],
            [
                'type'    => 'submessage',
                'style'   => 'warning',
                'content' => '本插件所有 AI 功能均基于 <strong>洪墨AI（PostChat）</strong> 平台提供，使用前需注册并获取 API Key。<br><br>'
                    . '通过邀请链接注册可获得额外奖励：<br>'
                    . '• 被邀请用户开通 <strong>1年</strong>，双方各获得 <code>31天</code> 奖励<br>'
                    . '• 被邀请用户开通 <strong>1个月</strong>，双方各获得 <code>7天</code> 奖励<br><br>'
                    . '<a href="' . esc_url( $invite_url ) . '" target="_blank" class="button button-primary">🎁 注册洪墨AI（邀请链接）</a>',
            ],
            [
                'type'    => 'heading',
                'content' => '功能特性',
            ],
            [
                'type'    => 'submessage',
                'style'   => 'success',
                'content' => '🤖 AI 智能摘要（支持私有摘要）&emsp;💬 AI 对话（支持按钮劫持 / 悬浮按钮）<br>'
                    . '🔍 AI 搜索（集成搜索表单）&emsp;🎙️ AI 播客（文章语音播放）<br>'
                    . '📝 批量生成缺失摘要&emsp;📰 支持文章 + 论坛帖子<br>'
                    . '🎨 子比主题深度适配&emsp;👋 新用户引导提示',
            ],
            [
                'type'    => 'heading',
                'content' => '联系与交流',
            ],
            [
                'type'    => 'submessage',
                'style'   => 'info',
                'content' => '<strong>作者QQ：</strong>2031301686 &nbsp;<a href="' . esc_url( $qq_author ) . '" target="_blank">添加好友</a><br>'
                    . '<strong>QQ群：</strong><a href="' . esc_url( $qq_group ) . '" target="_blank">399019539</a><br>'
                    . '<strong>开源地址：</strong><a href="https://github.com/twsh0305/wxs-zibll-postchat" target="_blank">github.com/twsh0305/wxs-zibll-postchat</a><br>'
                    . '<strong>插件主页：</strong><a href="https://wxsnote.cn/7673.html" target="_blank">wxsnote.cn/7673.html</a>',
            ],
        ],
    ] );

    // ========== 摘要设置 ==========
    CSF::createSection( $prefix, [
        'title'  => '摘要设置',
        'icon'   => 'fa fa-file-text',
        'fields' => [
            [
                'id'      => 'enable_summary',
                'type'    => 'switcher',
                'title'   => '启用AI摘要',
                'desc'    => 'AI摘要总开关',
                'default' => false,
            ],
            [
                'id'         => 'enable_article_summary',
                'type'       => 'switcher',
                'title'      => '文章摘要',
                'desc'       => '在文章页面显示AI摘要',
                'default'    => true,
                'dependency' => [ 'enable_summary', '==', true ],
            ],
            [
                'id'         => 'enable_forum_summary',
                'type'       => 'switcher',
                'title'      => '论坛帖子摘要',
                'desc'       => ( function_exists( '_pz' ) && _pz( 'bbs_s', true ) )
                    ? '在论坛帖子页面显示AI摘要'
                    : '<span style="color:#e74c3c;">当前论坛功能未开启，此选项无效</span>',
                'default'    => false,
                'dependency' => [ 'enable_summary', '==', true ],
            ],
            [
                'id'      => 'private_summary',
                'type'    => 'switcher',
                'title'   => '私有摘要模式',
                'desc'    => '开启后，摘要将在文章发布/更新时通过API生成并存储到数据库，前端直接读取，这样摘要输出更快。需配置 API Secret。',
                'default' => false,
            ],
            [
                'id'      => 'api_key',
                'type'    => 'text',
                'title'   => 'PostChat Key',
                'desc'    => 'AI摘要和AI对话共用此Key，在 <a href="https://ai.zhheo.com/console/" target="_blank">洪墨AI后台</a> 我的项目中获取',
                'default' => '3P-xxxxxx',
            ],
            [
                'id'         => 'api_secret',
                'type'       => 'text',
                'title'      => 'API Secret',
                'desc'       => '私有摘要模式必填，<a href="https://ai.zhheo.com/console/settings" target="_blank">洪墨AI后台用户设置</a> 获取API Secret',
                'default'    => '',
                'dependency' => [ 'private_summary', '==', true ],
            ],
            [
                'type'    => 'subheading',
                'content' => '摘要显示配置',
            ],
            [
                'id'         => 'post_selector',
                'type'       => 'text',
                'title'      => '文章内容选择器',
                'desc'       => 'PostChat JS 用于定位文章正文的 CSS 选择器（子比主题默认 .single-post .wp-posts-content）',
                'default'    => '.single-post .wp-posts-content',
                'dependency' => [ 'enable_summary|enable_article_summary', '==|==', 'true|true' ],
            ],
            [
                'id'         => 'forum_post_selector',
                'type'       => 'text',
                'title'      => '帖子内容选择器',
                'desc'       => 'PostChat JS 用于定位帖子正文的 CSS 选择器（子比主题默认 .forum-article .wp-posts-content）',
                'default'    => '.forum-article .wp-posts-content',
                'dependency' => [ 'enable_summary|enable_forum_summary', '==|==', 'true|true' ],
            ],
            [
                'id'      => 'summary_title',
                'type'    => 'text',
                'title'   => '摘要标题',
                'default' => '智能总结摘要',
            ],
            [
                'id'      => 'summary_name',
                'type'    => 'text',
                'title'   => '摘要助手名称',
                'default' => get_bloginfo( 'name' ) . '助理',
            ],
            [
                'id'         => 'post_url',
                'type'       => 'text',
                'title'      => '文章URL匹配规则',
                'desc'       => '正则表达式或 * 表示全部匹配，仅在文章页加载',
                'default'    => '/^https?:\\/\\/[^/]+\\/\\d+\\.html.*/',
                'dependency' => [ 'enable_summary|enable_article_summary', '==|==', 'true|true' ],
            ],
            [
                'id'         => 'forum_post_url',
                'type'       => 'text',
                'title'      => '论坛帖子URL匹配',
                'desc'       => ( function_exists( '_pz' ) && _pz( 'bbs_s', true ) )
                    ? '正则表达式，仅在论坛帖子页加载'
                    : '<span style="color:#e74c3c;">当前论坛功能未开启，此选项无效</span>',
                'default'    => '/\\/forum-post\\/\\d+\\.html$/',
                'dependency' => [ 'enable_summary|enable_forum_summary', '==|==', 'true|true' ],
            ],
            [
                'id'      => 'blacklist_urls',
                'type'    => 'textarea',
                'title'   => '黑名单URL列表',
                'desc'    => '每行填写一个URL或通配符，支持 * 通配符。例如：<br><code>https://example.com/private/*</code><br><code>https://example.com/secret.html</code><br>留空则不启用黑名单功能',
                'default' => '',
            ],
            [
                'id'      => 'word_limit',
                'type'    => 'text',
                'title'   => '字数限制',
                'desc'    => '提交给API的文章内容字数上限，默认1000，最大5000。更改此值会重新计费已有摘要',
                'default' => '5000',
            ],
            [
                'id'      => 'typing_animate',
                'type'    => 'switcher',
                'title'   => '打字动画',
                'default' => true,
            ],
            [
                'id'      => 'summary_theme',
                'type'    => 'select',
                'title'   => '摘要主题',
                'desc'    => '选择文章摘要的显示主题风格',
                'default' => '',
                'options' => [
                    ''         => '默认主题',
                    'simple'   => '简约主题',
                    'yanzhi'   => '胭脂主题',
                    'menghuan' => '梦幻主题',
                ],
            ],
            [
                'id'      => 'podcast',
                'type'    => 'switcher',
                'title'   => 'AI播客',
                'desc'    => '自动生成文章播客音频（调用豆包API），成本较高，不建议文章较多的网站启用。需先在 <a href="https://ai.zhheo.com" target="_blank">洪墨AI后台</a> 配置火山引擎参数。<a href="https://ai.zhheo.com/docs/podcast.html" target="_blank">查看文档</a>',
                'default' => false,
            ],
            [
                'id'      => 'summary_css',
                'type'    => 'text',
                'title'   => '主题适配CSS',
                'desc'    => '用于修复摘要在当前主题下的样式冲突（如子比主题标题横线问题），留空则不加载，你可以下载以下css文件，并放在主题设置自定义CSS样式中或自定义css文件中并引用',
                'default' => 'https://ai.zhheo.com/static/public/custom/theme_zibi.min.css',
            ],
            [
                'id'      => 'beginning_text',
                'type'    => 'text',
                'title'   => '文章开头文字',
                'desc'    => 'API返回摘要的开头替换文字（用于文章）',
                'default' => '这篇文章介绍了',
            ],
            [
                'id'      => 'forum_beginning_text',
                'type'    => 'text',
                'title'   => '帖子开头文字',
                'desc'    => 'API返回摘要的开头替换文字（用于论坛帖子）',
                'default' => '这篇帖子介绍了',
            ],
            [
                'id'    => 'inject_dom',
                'type'  => 'text',
                'title' => '摘要注入位置',
                'desc'  => '自定义摘要注入的DOM选择器，留空则默认插入到文章选择器开头。设为 none 则不注入DOM但仍请求摘要（用于播客、推荐等）',
            ],
            [
                'id'      => 'loading_text',
                'type'    => 'switcher',
                'title'   => '显示加载文字',
                'desc'    => '加载摘要时是否显示"加载中..."字样',
                'default' => false,
            ],
        ],
    ] );

    // ========== AI对话设置 ==========
    CSF::createSection( $prefix, [
        'title'  => 'AI对话设置',
        'icon'   => 'fa fa-comments',
        'fields' => [
            [
                'id'      => 'enable_ai',
                'type'    => 'switcher',
                'title'   => '启用AI对话',
                'desc'    => '启用PostChat AI对话功能（AI搜索、对话按钮、用户引导等均依赖此开关）',
                'default' => false,
            ],
            [
                'id'         => 'enable_ai_search',
                'type'       => 'switcher',
                'title'      => '启用AI搜索',
                'desc'       => '在搜索表单下方和首页搜索框添加AI搜索按钮，依赖AI对话功能',
                'default'    => false,
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'type'       => 'subheading',
                'content'    => 'AI对话按钮',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'enable_ai_btn',
                'type'       => 'switcher',
                'title'      => '启用AI对话按钮',
                'desc'       => '在页面上显示一个可触发AI对话的按钮',
                'default'    => true,
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'ai_btn_mode',
                'type'       => 'select',
                'title'      => '按钮模式',
                'desc'       => '选择AI对话按钮的实现方式',
                'default'    => 'postchat',
                'options'    => [
                    'hijack'   => '主题按钮劫持（使用主题右侧悬浮按钮触发）',
                    'postchat' => 'PostChat悬浮按钮（使用PostChat自带按钮）',
                ],
                'dependency' => [ 'enable_ai|enable_ai_btn', '==|==', 'true|true' ],
            ],
            [
                'type'       => 'notice',
                'style'      => 'info',
                'content'    => '使用方法：在zibll子比主题设置 → 全局&功能 → 右侧悬浮按钮 → 悬浮按钮 → 更多按钮 → 添加按钮，简介填写「AI对话」，按钮图标随意，URL填写下方配置的「查找按钮URL」值（默认 #ai-duihua）',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|hijack' ],
            ],
            [
                'id'         => 'ai_btn_selector',
                'type'       => 'text',
                'title'      => '查找按钮URL',
                'desc'       => '主题悬浮按钮中配置的URL值，用于查找并劫持该按钮为AI对话触发器，若页面中找不到则不执行',
                'default'    => '#ai-duihua',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|hijack' ],
            ],
            [
                'id'         => 'ai_btn_svg_icon',
                'type'       => 'text',
                'title'      => '按钮 SVG 图标',
                'desc'       => '填写 xlink:href 值（如 #wxsicon-koubei）用于使用你的自定义图标库js文件中的图标，留空则使用你选择的悬浮按钮主题原始图标。',
                'default'    => '',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|hijack' ],
            ],
            [
                'type'       => 'notice',
                'style'      => 'info',
                'content'    => '<strong>⚠️ 主题原始图标自定义使用方法：</strong>需先在zibll子比主题设置 → 全局&功能 → 右侧悬浮按钮 → 悬浮按钮 → 更多按钮 → 按钮图标 → 选择图标中自定义SVG图标代码中填写以下 SVG 图标代码：
<div style="background:#f5f5f5;padding:10px 15px;margin:10px 0;border-radius:4px;position:relative;font-family:monospace;font-size:13px;word-break:break-all;">
<span id="wxs-svg-icon-code">&lt;svg class="icon" aria-hidden="true"&gt;&lt;use xlink:href="#wxsicon-koubei"&gt;&lt;symbol id="wxsicon-koubei" viewBox="0 0 1024 1024"&gt;&lt;path d="M800.9216 173.312H223.232c-54.272 0-98.2528 45.3632-98.2528 101.2736v387.6864c0 55.9104 43.9808 101.2736 98.2528 101.2736h63.2832c15.0528 0 27.2384 12.544 27.2384 28.0576v41.5744c0 22.3744 24.2176 35.7376 42.2912 23.3984l129.0752-88.32c4.4544-3.072 9.728-4.6592 15.0528-4.6592h300.6976c54.272 0 98.2528-45.3632 98.2528-101.2736V274.5856c0.0512-55.9616-43.9296-101.2736-98.2016-101.2736z m-273.664 459.0592H497.664c-135.68 0-246.1184-113.7664-246.1184-253.5936 0-21.76 17.152-39.424 38.2976-39.424s38.2976 17.664 38.2976 39.424c0 96.3584 76.032 174.6944 169.5232 174.6944h29.5936c93.4912 0 169.5232-78.3872 169.5232-174.6944 0-21.76 17.152-39.424 38.2976-39.424 21.1456 0 38.2976 17.664 38.2976 39.424-0.0512 139.8272-110.4384 253.5936-246.1184 253.5936z" fill="#FF8E12"&gt;&lt;/path&gt;&lt;path d="M800.9216 173.312H223.232c-54.272 0-98.2528 45.3632-98.2528 101.2736v387.6864c0 55.9104 43.9808 101.2736 98.2528 101.2736h63.2832c15.0528 0 27.2384 12.544 27.2384 28.0576v41.5744c0 22.3744 24.2176 35.7376 42.2912 23.3984l129.0752-88.32c4.4544-3.072 9.728-4.6592 15.0528-4.6592h122.7264c152.2176-109.7216 251.8016-291.6864 251.8016-497.6128 0-20.736-1.024-41.216-2.9696-61.44-17.8688-19.2512-42.9568-31.232-70.8096-31.232z m-273.664 459.0592H497.664c-135.68 0-246.1184-113.7664-246.1184-253.5936 0-21.76 17.152-39.424 38.2976-39.424s38.2976 17.664 38.2976 39.424c0 96.3584 76.0832 174.6944 169.5232 174.6944h29.5936c93.4912 0 169.5232-78.3872 169.5232-174.6944 0-21.76 17.152-39.424 38.2976-39.424 21.1456 0 38.2976 17.664 38.2976 39.424-0.0512 139.8272-110.4384 253.5936-246.1184 253.5936z" fill="#FCA315"&gt;&lt;/path&gt;&lt;path d="M124.9792 274.5856v382.6688c89.9072-0.0512 175.0528-20.8384 251.2896-58.0096-74.3936-43.6736-124.7232-126.1056-124.7232-220.4672 0-21.76 17.152-39.424 38.2976-39.424s38.2976 17.664 38.2976 39.424c0 83.0976 56.6272 152.7808 132.1472 170.3936 121.9072-87.2448 210.3296-220.7232 241.3056-375.8592H223.232c-54.272 0-98.2528 45.312-98.2528 101.2736z" fill="#FCB138"&gt;&lt;/path&gt;&lt;path d="M124.9792 274.5856v148.48c44.6464-11.9296 87.2448-29.184 127.1296-50.9952 3.072-18.5856 18.7392-32.768 37.6832-32.768 4.3008 0 8.448 0.768 12.288 2.0992 64.768-44.3392 120.576-101.5808 163.8912-168.1408H223.232c-54.272 0.0512-98.2528 45.3632-98.2528 101.3248z" fill="#FFC65E"&gt;&lt;/path&gt;&lt;/symbol&gt;&lt;/use&gt;&lt;/svg&gt;</span>
<button type="button" onclick="var code=document.getElementById(\'wxs-svg-icon-code\').innerText;navigator.clipboard.writeText(code.replace(/&lt;/g,\'<\').replace(/&gt;/g,\'>\'));this.innerText=\'已复制!\';setTimeout(()=>this.innerText=\'复制代码\',1500)" class="but jb-blue" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:12px;padding:4px 10px;">复制代码</button>
</div>',
                'dependency' => array( 'ai_btn_svg_icon', '==', '' ),
            ],
            [
                'type'       => 'subheading',
                'content'    => 'PostChat悬浮按钮样式',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|postchat' ],
            ],
            [
                'id'         => 'background_color',
                'type'       => 'color',
                'title'      => '按钮背景色',
                'default'    => '#3e86f6',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|postchat' ],
            ],
            [
                'id'         => 'fill_color',
                'type'       => 'color',
                'title'      => '按钮图标色',
                'default'    => '#FFFFFF',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|postchat' ],
            ],
            [
                'id'         => 'btn_bottom',
                'type'       => 'text',
                'title'      => '底部距离',
                'desc'       => '填写负值则变为距离顶部的距离，如 -16px',
                'default'    => '16px',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|postchat' ],
            ],
            [
                'id'         => 'btn_left',
                'type'       => 'text',
                'title'      => '左侧距离',
                'desc'       => '填写负值则变为距离右侧的距离，如 -16px',
                'default'    => '16px',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|postchat' ],
            ],
            [
                'id'         => 'btn_width',
                'type'       => 'text',
                'title'      => '按钮宽度',
                'default'    => '44px',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|postchat' ],
            ],
            [
                'id'         => 'btn_height',
                'type'       => 'text',
                'title'      => '按钮高度',
                'dependency' => [ 'enable_ai|enable_ai_btn|ai_btn_mode', '==|==|==', 'true|true|postchat' ],
            ],
            [
                'type'       => 'subheading',
                'content'    => '对话窗口配置',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'user_title',
                'type'       => 'text',
                'title'      => '助理标题',
                'default'    => get_bloginfo( 'name' ) . '的助理',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'user_desc',
                'type'       => 'text',
                'title'      => '助理描述',
                'default'    => '如果你对网站的内容有任何疑问，可以来问我哦～',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'mobile_user_mode',
                'type'       => 'select',
                'title'      => '移动端对话模式',
                'desc'       => '移动端打开AI对话时使用的模式',
                'default'    => 'iframe',
                'options'    => [
                    'iframe' => 'iframe 模式（嵌入式窗口）',
                    'magic'  => 'Magic 模式（弹出式面板）',
                ],
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'desktop_user_mode',
                'type'       => 'select',
                'title'      => '电脑端对话模式',
                'desc'       => '电脑端打开AI对话时使用的模式',
                'default'    => 'magic',
                'options'    => [
                    'magic'  => 'Magic 模式（弹出式面板）',
                    'iframe' => 'iframe 模式（嵌入式窗口）',
                ],
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'frame_width',
                'type'       => 'text',
                'title'      => '对话框宽度',
                'desc'       => '仅iframe模式有效',
                'default'    => '375px',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'frame_height',
                'type'       => 'text',
                'title'      => '对话框高度',
                'desc'       => '仅iframe模式有效',
                'default'    => '600px',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'user_icon',
                'type'       => 'text',
                'title'      => 'AI图标URL',
                'desc'       => '自定义聊天AI的图标地址（仅Magic模式有效）',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'type'       => 'subheading',
                'content'    => '功能开关',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'default_input',
                'type'       => 'text',
                'title'      => '默认输入内容',
                'desc'       => '用户点击按钮后自动填充的引导文字。<strong>支持变量：</strong><code>{title}</code> 文章标题',
                'default'    => '请问在《{title}》中',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'exclude_site_title',
                'type'       => 'switcher',
                'title'      => '排除标题中的站点名',
                'desc'       => '开启后，{title} 变量和 JS 后备值将自动移除标题中的「 - 网站名」等后缀',
                'default'    => true,
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'upload_web',
                'type'       => 'switcher',
                'title'      => '上传网页内容',
                'desc'       => '访客浏览文章时自动将内容上传到AI知识库，上限前1000+后1000字',
                'default'    => true,
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'hot_words',
                'type'       => 'switcher',
                'title'      => '热词推荐',
                'desc'       => '仅PC端文章页显示',
                'default'    => false,
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'show_invite_link',
                'type'       => 'switcher',
                'title'      => '显示邀请链接',
                'desc'       => '用户点击PostChat图标后通过邀请链接进入，可获得会员时长奖励',
                'default'    => true,
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'type'       => 'subheading',
                'content'    => '高级配置',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'default_chat_questions',
                'type'       => 'text',
                'title'      => '默认聊天问题',
                'desc'       => '多个问题用英文逗号分隔（仅Magic模式有效）',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'default_search_questions',
                'type'       => 'text',
                'title'      => '默认搜索问题',
                'desc'       => '多个问题用英文逗号分隔（仅Magic模式有效）',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'black_dom',
                'type'       => 'text',
                'title'      => '屏蔽容器',
                'desc'       => '屏蔽的DOM选择器，多个用英文逗号分隔（仅未开启摘要时有效）',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
            [
                'id'         => 'recommend',
                'type'       => 'text',
                'title'      => '推荐文章数量',
                'desc'       => '默认为5，最大为10，设为0不显示推荐文章',
                'dependency' => [ 'enable_ai', '==', true ],
            ],
        ],
    ] );

    // ========== 用户引导设置 ==========
    CSF::createSection( $prefix, [
        'title'  => '用户引导',
        'icon'   => 'fa fa-map-signs',
        'fields' => [
            [
                'id'      => 'enable_user_guide',
                'type'    => 'switcher',
                'title'   => '启用用户引导',
                'desc'    => '首次访问时显示操作引导（基于 driver.js），通过 Cookie 控制不重复显示',
                'default' => false,
            ],
            [
                'id'         => 'guide_trigger',
                'type'       => 'select',
                'title'      => '加载时机',
                'desc'       => '推荐「仅登录用户」，全局模式对 SEO 无实质影响（遮罩由 JS 动态生成），但可减少不必要的资源加载',
                'default'    => 'logged_in',
                'options'    => [
                    'logged_in' => '仅登录用户（推荐）',
                    'all'       => '全局（所有访客）',
                ],
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'id'         => 'guide_cookie_days',
                'type'       => 'text',
                'title'      => 'Cookie存在时长（天）',
                'desc'       => '引导完成后，Cookie过期前不再重复显示',
                'default'    => '7',
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'type'       => 'submessage',
                'style'      => 'warning',
                'content'    => '<strong>⚠️ 搜索框引导依赖主题设置</strong><br>'
                    . '搜索框仅在主题开启「顶部多功能组件」时存在。请确认：<br>'
                    . '前往 <strong>子比主题 → 页面&显示 → 顶部多功能组件 → 显示规则</strong> 检查设置。<br>'
                    . '若移动端需要搜索框引导，显示规则需选择「全部显示」或「仅在移动端显示」，否则移动端搜索框不存在，引导将自动跳过。',
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'type'       => 'subheading',
                'content'    => '移动端引导开关',
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'id'         => 'guide_mobile_search',
                'type'       => 'switcher',
                'title'      => '移动端搜索框引导',
                'desc'       => '移动端是否显示搜索框引导步骤（需主题顶部多功能组件在移动端可见）',
                'default'    => false,
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'id'         => 'guide_mobile_ai_btn',
                'type'       => 'switcher',
                'title'      => '移动端AI按钮引导',
                'desc'       => '移动端是否显示AI对话按钮引导步骤',
                'default'    => true,
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'type'       => 'subheading',
                'content'    => '引导文案自定义',
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'id'         => 'guide_search_title',
                'type'       => 'text',
                'title'      => '搜索框引导标题',
                'default'    => '小贴士',
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'id'         => 'guide_search_desc',
                'type'       => 'text',
                'title'      => '搜索框引导描述',
                'default'    => '在此处填写想搜索的内容，点击右侧AI搜索，结果更加精准哦',
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'id'         => 'guide_ai_title',
                'type'       => 'text',
                'title'      => 'AI按钮引导标题',
                'default'    => 'AI对话',
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
            [
                'id'         => 'guide_ai_desc',
                'type'       => 'text',
                'title'      => 'AI按钮引导描述',
                'default'    => '点击此按钮，可与智能AI客服对话哦',
                'dependency' => [ 'enable_user_guide', '==', true ],
            ],
        ],
    ] );

    // ========== 相关推荐 ==========
    CSF::createSection( $prefix, [
        'title'  => '相关推荐',
        'icon'   => 'fa fa-th-list',
        'fields' => [
            [
                'id'      => 'enable_related_posts',
                'type'    => 'switcher',
                'title'   => '启用AI相关推荐',
                'desc'    => '开启后将替换主题原生相关文章，使用 PostChat AI 推荐 + 本地算法回退方案。关闭后恢复主题默认相关文章。',
                'default' => true,
            ],
            [
                'type'       => 'submessage',
                'style'      => 'info',
                'content'    => '<strong>功能说明：</strong><br>'
                    . '• 使用 PostChat 推荐 API 获取 AI 相关文章，并缓存到 Redis（12小时 TTL）<br>'
                    . '• 缓存未命中时立即渲染本地算法结果，后台异步请求 API<br>'
                    . '• AI 推荐不足时自动用本地算法补足<br>'
                    . '• AI 推荐的文章带「AI推荐」角标',
                'dependency' => [ 'enable_related_posts', '==', true ],
            ],
        ],
    ] );

    // ========== 文章摘要管理 ==========
    CSF::createSection( $prefix, [
        'title'  => '文章摘要管理',
        'icon'   => 'fa fa-database',
        'fields' => [
            [
                'type'    => 'callback',
                'function' => 'wxs_postchat_render_post_summary',
            ],
        ],
    ] );

    // ========== 帖子摘要管理（论坛开启时才显示） ==========
    if ( function_exists( '_pz' ) && _pz( 'bbs_s', true ) ) {
        CSF::createSection( $prefix, [
            'title'  => '帖子摘要管理',
            'icon'   => 'fa fa-comments',
            'fields' => [
                [
                    'type'    => 'callback',
                    'function' => 'wxs_postchat_render_forum_summary',
                ],
            ],
        ] );
    }
}

/**
 * 文章摘要管理回调
 */
function wxs_postchat_render_post_summary() {
    wxs_postchat_render_summary_panel( 'post', '文章' );
}

/**
 * 帖子摘要管理回调
 */
function wxs_postchat_render_forum_summary() {
    wxs_postchat_render_summary_panel( 'forum_post', '帖子' );
}

/**
 * 通用摘要管理面板（批量生成 + 列表）
 * 
 * @param string $post_type 内容类型
 * @param string $label     显示名称
 */
function wxs_postchat_render_summary_panel( $post_type, $label ) {
    // 批量生成 nonce
    $batch_nonce = wp_create_nonce( 'wxs_postchat_batch_nonce' );
    // 编辑摘要 nonce
    $edit_nonce = wp_create_nonce( 'wxs_postchat_edit_nonce' );
    // 删除摘要 nonce
    $delete_nonce = wp_create_nonce( 'wxs_postchat_delete_nonce' );
    // DOM ID 后缀，避免文章/帖子两个面板 ID 冲突
    $sfx = esc_attr( $post_type );

    // ---------- 批量生成区域 ----------
    echo '<div id="wxs-batch-panel-' . $sfx . '" style="margin-bottom:20px;padding:16px;background:#fffbe6;border-left:4px solid #faad14;border-radius:4px;">';
    echo '<strong>⚡ 批量生成缺失摘要</strong>';
    echo '<p style="margin:8px 0 4px;font-size:13px;color:#666;">逐条调用 PostChat API 生成，每条间隔可调，不会修改' . esc_html( $label ) . '更新时间。需要 API Key 和 API Secret。</p>';
    echo '<div style="margin:10px 0;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">';
    echo '<label style="font-size:13px;">请求间隔：<select id="wxs-batch-delay-' . $sfx . '"><option value="3">3秒</option><option value="5" selected>5秒</option><option value="8">8秒</option><option value="10">10秒</option><option value="15">15秒</option></select></label>';
    echo '<button type="button" id="wxs-batch-start-' . $sfx . '" class="button button-primary" style="min-width:120px;">🔍 检测缺失' . esc_html( $label ) . '</button>';
    echo '<button type="button" id="wxs-batch-stop-' . $sfx . '" class="button" style="min-width:80px;display:none;">⏹ 停止</button>';
    echo '</div>';
    echo '<div id="wxs-batch-progress-' . $sfx . '" style="display:none;margin-top:12px;">';
    echo '<div style="background:#e8e8e8;border-radius:4px;height:22px;overflow:hidden;"><div id="wxs-batch-bar-' . $sfx . '" style="height:100%;background:#52c41a;border-radius:4px;transition:width 0.3s;width:0%;"></div></div>';
    echo '<div id="wxs-batch-status-' . $sfx . '" style="margin-top:6px;font-size:13px;color:#555;"></div>';
    echo '</div>';
    echo '<div id="wxs-batch-log-' . $sfx . '" style="display:none;margin-top:10px;max-height:200px;overflow-y:auto;font-size:12px;background:#fafafa;border:1px solid #e8e8e8;border-radius:4px;padding:8px;"></div>';
    echo '</div>';

    // 批量生成JS
    echo '<script>';
    echo '(function(){"use strict";';
    echo 'var nonce="' . esc_js( $batch_nonce ) . '",ajaxUrl="' . esc_js( admin_url( 'admin-ajax.php' ) ) . '",postType="' . esc_js( $post_type ) . '",typeLabel="' . esc_js( $label ) . '";';
    echo 'var ids=[],current=0,total=0,success=0,fail=0,running=false;';
    echo 'var btnStart=document.getElementById("wxs-batch-start-"+postType),btnStop=document.getElementById("wxs-batch-stop-"+postType);';
    echo 'var elProgress=document.getElementById("wxs-batch-progress-"+postType),elBar=document.getElementById("wxs-batch-bar-"+postType);';
    echo 'var elStatus=document.getElementById("wxs-batch-status-"+postType),elLog=document.getElementById("wxs-batch-log-"+postType);';
    echo 'var delaySelect=document.getElementById("wxs-batch-delay-"+postType);';
    echo 'function log(msg,ok){var d=document.createElement("div");d.style.color=ok?"#52c41a":"#ff4d4f";d.textContent=msg;elLog.prepend(d);}';
    echo 'function updateBar(){var pct=total?Math.round(current/total*100):0;elBar.style.width=pct+"%";elStatus.textContent="进度: "+current+"/"+total+"（成功"+success+" 失败"+fail+" 跳过"+skip+"）";}';
    echo 'function processNext(){if(!running||current>=ids.length){running=false;btnStart.disabled=false;btnStart.textContent="\ud83d\udd0d 检测缺失"+typeLabel;btnStop.style.display="none";if(current>=ids.length&&total>0){elStatus.textContent+=" \u2014 \u2705 全部完成！建议刷新页面查看";};return;}';
    echo 'var pid=ids[current],fd=new FormData();fd.append("action","wxs_postchat_batch_generate_single");fd.append("nonce",nonce);fd.append("post_id",pid);';
    echo 'fetch(ajaxUrl,{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){current++;var isSkip=false;if(r.success){success++;log("\u2713 #"+pid+" "+r.data.title+" \u2014 "+r.data.summary,true);}else{var msg=(r.data&&r.data.message)?r.data.message:(r.data||"\u672a\u77e5\u9519\u8bef");isSkip=r.data&&r.data.skip;if(isSkip){skip++;log("\u25cb #"+pid+" "+msg,false);}else{fail++;log("\u2717 #"+pid+" "+msg,false);}}updateBar();var delay=isSkip?200:(parseInt(delaySelect.value,10)*1000||5000);setTimeout(processNext,delay);}).catch(function(e){current++;fail++;log("\u2717 #"+pid+" \u7f51\u7edc\u9519\u8bef: "+e.message,false);updateBar();setTimeout(processNext,3000);});}';
    echo 'var skip=0;';
    echo 'btnStart.addEventListener("click",function(){if(running)return;btnStart.disabled=true;btnStart.textContent="\u6b63\u5728\u68c0\u6d4b...";elLog.innerHTML="";';
    echo 'var fd=new FormData();fd.append("action","wxs_postchat_batch_get_missing");fd.append("nonce",nonce);fd.append("post_type",postType);';
    echo 'fetch(ajaxUrl,{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){if(!r.success){alert(r.data||"\u83b7\u53d6\u5931\u8d25");btnStart.disabled=false;btnStart.textContent="\ud83d\udd0d 检测缺失"+typeLabel;return;}';
    echo 'ids=r.data.ids;total=ids.length;current=0;success=0;fail=0;skip=0;';
    echo 'if(total===0){alert("\u6240\u6709"+typeLabel+"\u90fd\u5df2\u6709\u6458\u8981\uff0c\u65e0\u9700\u6279\u91cf\u751f\u6210");btnStart.disabled=false;btnStart.textContent="\ud83d\udd0d 检测缺失"+typeLabel;return;}';
    echo 'if(!confirm("\u68c0\u6d4b\u5230 "+total+" \u7bc7"+typeLabel+"\u7f3a\u5c11\u6458\u8981\uff0c\u6bcf\u6761\u95f4\u9694 "+delaySelect.value+" \u79d2\uff0c\u9884\u8ba1\u8017\u65f6\u7ea6 "+Math.ceil(total*parseInt(delaySelect.value,10)/60)+" \u5206\u949f\u3002\n\n\u6bcf\u751f\u6210\u4e00\u6761\u6458\u8981\u4f1a\u6d88\u8017 PostChat \u989d\u5ea6\uff0c\u786e\u8ba4\u5f00\u59cb\uff1f")){btnStart.disabled=false;btnStart.textContent="\ud83d\udd0d 检测缺失"+typeLabel;return;}';
    echo 'running=true;elProgress.style.display="block";elLog.style.display="block";btnStart.textContent="\u23f3 生成中...";btnStop.style.display="inline-block";updateBar();processNext();';
    echo '}).catch(function(e){alert("\u8bf7\u6c42\u5931\u8d25: "+e.message);btnStart.disabled=false;btnStart.textContent="\ud83d\udd0d 检测缺失"+typeLabel;});});';
    echo 'btnStop.addEventListener("click",function(){running=false;elStatus.textContent+=" \u2014 \u23f9 \u5df2\u624b\u52a8\u505c\u6b62";});';
    echo '})();</' . 'script>';

    // ---------- 摘要列表 ----------
    // 分页参数
    $page_key = 'summary_page_' . $post_type;
    $per_page_key = 'summary_per_page_' . $post_type;
    $paged    = isset( $_GET[ $page_key ] ) ? max( 1, intval( $_GET[ $page_key ] ) ) : 1;
    $per_page = isset( $_GET[ $per_page_key ] ) ? intval( $_GET[ $per_page_key ] ) : 20;
    $per_page = in_array( $per_page, [ 10, 20, 50, 100 ] ) ? $per_page : 20;
    $base_url = admin_url( 'admin.php?page=wxs-zibll-postchat' );
    $tab_id = sanitize_title( $label . '摘要管理' );

    // 批量删除nonce
    $batch_delete_nonce = wp_create_nonce( 'wxs_postchat_batch_delete_nonce' );

    // 工具栏：搜索弹窗按钮 + 显示数量选择 + 批量删除
    echo '<div style="margin-bottom:15px;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">';
    echo '<button type="button" id="wxs-search-btn-' . $sfx . '" class="button">🔍 搜索管理</button>';
    echo '<label style="display:flex;align-items:center;gap:5px;">每页显示：<select id="wxs-per-page-' . $sfx . '">';
    foreach ( [ 10, 20, 50, 100 ] as $opt ) {
        $selected = ( $per_page === $opt ) ? ' selected' : '';
        echo '<option value="' . $opt . '"' . $selected . '>' . $opt . '</option>';
    }
    echo '</select></label>';
    echo '<button type="button" id="wxs-batch-delete-btn-' . $sfx . '" class="button" disabled style="color:#e74c3c;">🗑️ 批量删除</button>';
    echo '<span id="wxs-selected-count-' . $sfx . '" style="color:#666;font-size:13px;"></span>';
    echo '</div>';

    $args = [
        'post_type'      => $post_type,
        'post_status'    => 'publish',
        'posts_per_page' => $per_page,
        'paged'          => $paged,
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

    $query = new WP_Query( $args );
    $total = $query->found_posts;
    $pages = $query->max_num_pages;

    // 统计信息
    echo '<div style="margin-bottom:15px;padding:12px 16px;background:#f0f6fc;border-left:4px solid #3e86f6;border-radius:4px;">';
    echo '<strong>📊 统计：</strong>共 <code>' . intval( $total ) . '</code> 篇' . esc_html( $label ) . '已生成AI摘要';
    echo ' | 当前第 <code>' . intval( $paged ) . '</code> / <code>' . intval( $pages ?: 1 ) . '</code> 页';
    echo '</div>';

    if ( ! $query->have_posts() ) {
        echo '<div style="padding:20px;text-align:center;color:#666;">暂无已保存的' . esc_html( $label ) . 'AI摘要</div>';
        wp_reset_postdata();
        return;
    }

    // 表格（添加多选列）
    echo '<table class="widefat striped" style="border-collapse:collapse;">';
    echo '<thead><tr>';
    echo '<th style="width:30px;"><input type="checkbox" id="wxs-select-all-' . $sfx . '"></th>';
    echo '<th style="width:50px;">ID</th>';
    echo '<th style="width:280px;">' . esc_html( $label ) . '标题</th>';
    echo '<th>AI摘要内容</th>';
    echo '<th style="width:140px;">更新时间</th>';
    echo '<th style="width:110px;">操作</th>';
    echo '</tr></thead><tbody id="wxs-summary-tbody-' . $sfx . '">';

    while ( $query->have_posts() ) {
        $query->the_post();
        $post_id = get_the_ID();
        $summary = get_post_meta( $post_id, '_wxs_postchat_summary', true );
        $modified = get_the_modified_date( 'Y-m-d H:i' );

        echo '<tr data-post-id="' . intval( $post_id ) . '">';
        echo '<td><input type="checkbox" class="wxs-row-cb-' . $sfx . '" value="' . intval( $post_id ) . '"></td>';
        echo '<td>' . intval( $post_id ) . '</td>';
        echo '<td><a href="' . esc_url( get_permalink( $post_id ) ) . '" target="_blank" title="查看' . esc_attr( $label ) . '">' . esc_html( get_the_title() ) . '</a></td>';
        echo '<td style="font-size:13px;color:#444;line-height:1.6;"><span class="wxs-summary-text" data-post-id="' . intval( $post_id ) . '">' . esc_html( mb_strimwidth( $summary, 0, 150, '...', 'UTF-8' ) ) . '</span><input type="hidden" class="wxs-summary-full" data-post-id="' . intval( $post_id ) . '" value="' . esc_attr( $summary ) . '"></td>';
        echo '<td style="font-size:12px;color:#888;">' . esc_html( $modified ) . '</td>';
        echo '<td><a href="javascript:void(0);" class="wxs-edit-btn-' . $sfx . '" data-post-id="' . intval( $post_id ) . '" data-title="' . esc_attr( get_the_title() ) . '" style="color:#3e86f6;margin-right:10px;">编辑</a><a href="javascript:void(0);" class="wxs-delete-btn-' . $sfx . '" data-post-id="' . intval( $post_id ) . '" data-title="' . esc_attr( get_the_title() ) . '" style="color:#e74c3c;">删除</a></td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    wp_reset_postdata();

    // 分页导航
    if ( $pages > 1 ) {
        echo '<div style="margin-top:15px;text-align:center;">';
        for ( $i = 1; $i <= $pages; $i++ ) {
            $url = add_query_arg( [ $page_key => $i, $per_page_key => $per_page ], $base_url ) . '#tab=' . esc_attr( $tab_id );
            if ( $i === $paged ) {
                echo '<span style="display:inline-block;margin:0 3px;padding:4px 10px;background:#3e86f6;color:#fff;border-radius:3px;">' . $i . '</span>';
            } else {
                echo '<a href="' . esc_url( $url ) . '" style="display:inline-block;margin:0 3px;padding:4px 10px;background:#f0f0f0;color:#333;border-radius:3px;text-decoration:none;">' . $i . '</a>';
            }
        }
        echo '</div>';
    }

    // ---------- 搜索管理弹窗 ----------
    echo '<div id="wxs-search-modal-' . $sfx . '" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100000;align-items:center;justify-content:center;">';
    echo '<div style="background:#fff;border-radius:8px;width:90%;max-width:900px;max-height:85vh;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.15);display:flex;flex-direction:column;">';
    echo '<div style="padding:16px 20px;border-bottom:1px solid #e8e8e8;display:flex;justify-content:space-between;align-items:center;flex-shrink:0;">';
    echo '<strong style="font-size:15px;">🔍 搜索管理 - ' . esc_html( $label ) . '摘要</strong>';
    echo '<a href="javascript:void(0);" class="wxs-search-close-' . $sfx . '" style="color:#999;font-size:20px;text-decoration:none;">&times;</a>';
    echo '</div>';
    echo '<div style="padding:15px 20px;border-bottom:1px solid #e8e8e8;display:flex;gap:10px;align-items:center;flex-wrap:wrap;flex-shrink:0;">';
    echo '<input type="text" id="wxs-search-input-' . $sfx . '" placeholder="输入ID或标题搜索…" style="width:250px;padding:8px 12px;border:1px solid #ddd;border-radius:4px;">';
    echo '<button type="button" id="wxs-search-submit-' . $sfx . '" class="button">搜索</button>';
    echo '<span id="wxs-search-result-' . $sfx . '" style="color:#666;font-size:13px;"></span>';
    echo '</div>';
    echo '<div id="wxs-search-list-' . $sfx . '" style="flex:1;overflow-y:auto;padding:0 20px 20px;"></div>';
    echo '</div></div>';

    // ---------- 编辑摘要弹窗 ----------
    echo '<div id="wxs-edit-modal-' . $sfx . '" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100001;align-items:center;justify-content:center;">';
    echo '<div style="background:#fff;border-radius:8px;width:90%;max-width:600px;max-height:80vh;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,0.15);">';
    echo '<div style="padding:16px 20px;border-bottom:1px solid #e8e8e8;display:flex;justify-content:space-between;align-items:center;">';
    echo '<strong id="wxs-edit-title-' . $sfx . '" style="font-size:15px;"></strong>';
    echo '<a href="javascript:void(0);" id="wxs-edit-close-' . $sfx . '" style="color:#999;font-size:20px;text-decoration:none;">&times;</a>';
    echo '</div>';
    echo '<div style="padding:20px;">';
    echo '<input type="hidden" id="wxs-edit-post-id-' . $sfx . '">';
    echo '<textarea id="wxs-edit-content-' . $sfx . '" rows="8" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:4px;font-size:14px;resize:vertical;box-sizing:border-box;"></textarea>';
    echo '<div style="margin-top:15px;text-align:right;">';
    echo '<button type="button" id="wxs-edit-cancel-' . $sfx . '" class="button" style="margin-right:8px;">取消</button>';
    echo '<button type="button" id="wxs-edit-save-' . $sfx . '" class="button button-primary">保存摘要</button>';
    echo '</div></div></div></div>';

    // ---------- 完整JS逻辑 ----------
    echo '<script>';
    echo '(function(){"use strict";';
    echo 'var sfx="' . esc_js( $sfx ) . '",postType="' . esc_js( $post_type ) . '";';
    echo 'var ajaxUrl="' . esc_js( admin_url( 'admin-ajax.php' ) ) . '";';
    echo 'var editNonce="' . esc_js( $edit_nonce ) . '",deleteNonce="' . esc_js( $delete_nonce ) . '",batchDeleteNonce="' . esc_js( $batch_delete_nonce ) . '";';

    // 编辑弹窗逻辑
    echo 'var editModal=document.getElementById("wxs-edit-modal-"+sfx);';
    echo 'var editTitle=document.getElementById("wxs-edit-title-"+sfx);';
    echo 'var editPostId=document.getElementById("wxs-edit-post-id-"+sfx);';
    echo 'var editContent=document.getElementById("wxs-edit-content-"+sfx);';
    echo 'function openEditModal(id,title,content){editPostId.value=id;editTitle.textContent="编辑摘要 - #"+id+" "+title;editContent.value=content;editModal.style.display="flex";}';
    echo 'function closeEditModal(){editModal.style.display="none";}';
    echo 'document.getElementById("wxs-edit-close-"+sfx).addEventListener("click",closeEditModal);';
    echo 'document.getElementById("wxs-edit-cancel-"+sfx).addEventListener("click",closeEditModal);';
    echo 'editModal.addEventListener("click",function(e){if(e.target===editModal)closeEditModal();});';
    echo 'document.getElementById("wxs-edit-save-"+sfx).addEventListener("click",function(){';
    echo 'var btn=this;btn.disabled=true;btn.textContent="保存中...";';
    echo 'var fd=new FormData();fd.append("action","wxs_postchat_edit_summary");fd.append("nonce",editNonce);fd.append("post_id",editPostId.value);fd.append("summary",editContent.value);';
    echo 'fetch(ajaxUrl,{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){btn.disabled=false;btn.textContent="保存摘要";';
    echo 'if(r.success){closeEditModal();alert("摘要保存成功！");}else{alert(r.data||"保存失败");}}).catch(function(e){btn.disabled=false;btn.textContent="保存摘要";alert("请求失败: "+e.message);});});';

    // 删除逻辑（主列表）
    echo 'function bindDelete(){document.querySelectorAll(".wxs-delete-btn-"+sfx).forEach(function(btn){';
    echo 'btn.onclick=function(){var id=this.dataset.postId,title=this.dataset.title;';
    echo 'if(!confirm("确认删除 #"+id+" "+title+" 的AI摘要？\\n删除后可通过批量生成重新获取"))return;';
    echo 'var tr=this.closest("tr");var fd=new FormData();fd.append("action","wxs_postchat_delete_summary");fd.append("nonce",deleteNonce);fd.append("post_id",id);';
    echo 'fetch(ajaxUrl,{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){';
    echo 'if(r.success){tr.style.opacity="0";setTimeout(function(){tr.remove();updateSelectedCount();},300);alert("摘要删除成功！");}else{alert(r.data||"删除失败");}}).catch(function(e){alert("请求失败: "+e.message);});};});}';
    echo 'bindDelete();';

    // 编辑按钮绑定（主列表）
    echo 'function bindEdit(){document.querySelectorAll(".wxs-edit-btn-"+sfx).forEach(function(btn){';
    echo 'btn.onclick=function(){var id=this.dataset.postId,title=this.dataset.title;';
    echo 'var full=document.querySelector(".wxs-summary-full[data-post-id=\x27"+id+"\x27]");';
    echo 'openEditModal(id,title,full?full.value:"");};});}';
    echo 'bindEdit();';

    // 多选逻辑
    echo 'var selectAll=document.getElementById("wxs-select-all-"+sfx);';
    echo 'var batchDeleteBtn=document.getElementById("wxs-batch-delete-btn-"+sfx);';
    echo 'var selectedCount=document.getElementById("wxs-selected-count-"+sfx);';
    echo 'function updateSelectedCount(){var cbs=document.querySelectorAll(".wxs-row-cb-"+sfx+":checked");var count=cbs.length;';
    echo 'selectedCount.textContent=count>0?"已选 "+count+" 项":"";batchDeleteBtn.disabled=count===0;}';
    echo 'selectAll.addEventListener("change",function(){var cbs=document.querySelectorAll(".wxs-row-cb-"+sfx);cbs.forEach(function(cb){cb.checked=selectAll.checked;});updateSelectedCount();});';
    echo 'document.getElementById("wxs-summary-tbody-"+sfx).addEventListener("change",function(e){if(e.target.classList.contains("wxs-row-cb-"+sfx))updateSelectedCount();});';

    // 批量删除
    echo 'batchDeleteBtn.addEventListener("click",function(){var cbs=document.querySelectorAll(".wxs-row-cb-"+sfx+":checked");if(!cbs.length)return;';
    echo 'var ids=[];cbs.forEach(function(cb){ids.push(cb.value);});';
    echo 'if(!confirm("确认删除选中的 "+ids.length+" 条摘要？"))return;';
    echo 'batchDeleteBtn.disabled=true;batchDeleteBtn.textContent="删除中...";';
    echo 'var fd=new FormData();fd.append("action","wxs_postchat_batch_delete_summary");fd.append("nonce",batchDeleteNonce);fd.append("post_ids",ids.join(","));fd.append("post_type",postType);';
    echo 'fetch(ajaxUrl,{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){batchDeleteBtn.disabled=true;batchDeleteBtn.textContent="🗑️ 批量删除";';
    echo 'if(r.success){alert("成功删除 "+r.data.deleted+" 条摘要");location.reload();}else{alert(r.data||"删除失败");}}).catch(function(e){batchDeleteBtn.disabled=false;batchDeleteBtn.textContent="🗑️ 批量删除";alert("请求失败: "+e.message);});});';

    // 每页显示数量切换
    echo 'document.getElementById("wxs-per-page-"+sfx).addEventListener("change",function(){';
    echo 'var perPage=this.value;var url="' . esc_url( $base_url ) . '&' . $page_key . '=1&' . $per_page_key . '="+perPage+"#tab=' . esc_attr( $tab_id ) . '";';
    echo 'window.location.href=url;});';

    // 搜索弹窗逻辑
    echo 'var searchModal=document.getElementById("wxs-search-modal-"+sfx);';
    echo 'var searchInput=document.getElementById("wxs-search-input-"+sfx);';
    echo 'var searchList=document.getElementById("wxs-search-list-"+sfx);';
    echo 'var searchResult=document.getElementById("wxs-search-result-"+sfx);';
    echo 'document.getElementById("wxs-search-btn-"+sfx).addEventListener("click",function(){searchModal.style.display="flex";searchInput.focus();});';
    echo 'document.querySelectorAll(".wxs-search-close-"+sfx).forEach(function(el){el.addEventListener("click",function(){searchModal.style.display="none";});});';
    echo 'searchModal.addEventListener("click",function(e){if(e.target===searchModal)searchModal.style.display="none";});';

    // 搜索功能
    echo 'function doSearch(){var kw=searchInput.value.trim();if(!kw){searchList.innerHTML="<div style=\"padding:20px;text-align:center;color:#666;\">请输入搜索关键词</div>";return;}';
    echo 'searchResult.textContent="搜索中...";';
    echo 'var fd=new FormData();fd.append("action","wxs_postchat_search_summary");fd.append("nonce",editNonce);fd.append("keyword",kw);fd.append("post_type",postType);';
    echo 'fetch(ajaxUrl,{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){searchResult.textContent="";';
    echo 'if(r.success&&r.data.length){searchResult.textContent="找到 "+r.data.length+" 条结果";';
    echo 'var html="<table class=\"widefat striped\" style=\"margin-top:10px;\"><thead><tr><th>ID</th><th>标题</th><th>摘要</th><th>操作</th></tr></thead><tbody>";';
    echo 'r.data.forEach(function(item){html+="<tr><td>"+item.id+"</td><td><a href=\x27"+item.url+"\x27 target=\x27_blank\x27>"+item.title+"</a></td>";';
    echo 'html+="<td style=\x27font-size:12px;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;\x27 title=\x27"+item.summary+"\x27>"+item.summary.substring(0,80)+"...</td>";';
    echo 'html+="<td><a href=\x27javascript:;\x27 class=\x27wxs-modal-edit\x27 data-id=\x27"+item.id+"\x27 data-title=\x27"+item.title+"\x27 data-summary=\x27"+item.full_summary+"\x27 style=\x27color:#3e86f6;margin-right:10px;\x27>编辑</a>";';
    echo 'html+="<a href=\x27javascript:;\x27 class=\x27wxs-modal-delete\x27 data-id=\x27"+item.id+"\x27 style=\x27color:#e74c3c;\x27>删除</a></td></tr>";});';
    echo 'html+="</tbody></table>";searchList.innerHTML=html;bindModalActions();}';
    echo 'else{searchList.innerHTML="<div style=\"padding:20px;text-align:center;color:#666;\">未找到匹配结果</div>";}}).catch(function(e){searchResult.textContent="";alert("请求失败: "+e.message);});}';
    echo 'document.getElementById("wxs-search-submit-"+sfx).addEventListener("click",doSearch);';
    echo 'searchInput.addEventListener("keypress",function(e){if(e.key==="Enter")doSearch();});';

    // 搜索弹窗内的编辑删除
    echo 'function bindModalActions(){';
    echo 'document.querySelectorAll(".wxs-modal-edit").forEach(function(btn){btn.onclick=function(){openEditModal(this.dataset.id,this.dataset.title,this.dataset.summary);};});';
    echo 'document.querySelectorAll(".wxs-modal-delete").forEach(function(btn){btn.onclick=function(){var id=this.dataset.id;';
    echo 'if(!confirm("确认删除该摘要？"))return;';
    echo 'var tr=this.closest("tr");var fd=new FormData();fd.append("action","wxs_postchat_delete_summary");fd.append("nonce",deleteNonce);fd.append("post_id",id);';
    echo 'fetch(ajaxUrl,{method:"POST",body:fd}).then(function(r){return r.json()}).then(function(r){';
    echo 'if(r.success){tr.remove();alert("删除成功，页面将在刷新后生效");}else{alert(r.data||"删除失败");}}).catch(function(e){alert("请求失败: "+e.message);});};});}';

    echo '})();</script>';

    // CSF标签页激活脚本：解码hash并触发CSF框架处理标签页切换
    echo '<script>';
    echo 'jQuery(function($){';
    echo 'var hash=window.location.hash;';
    echo 'if(hash&&hash.indexOf("tab=")!==-1){';
    echo 'var slug=decodeURIComponent(hash.replace("#tab=",""));';
    echo 'var $link=$(\'[data-tab-id="\'+slug+\'"]\');';
    echo 'if($link.length){';
    echo '$link.closest(".csf-tab-item").addClass("csf-tab-expanded").siblings().removeClass("csf-tab-expanded");';
    echo '$links=$(".csf-nav-options a");';
    echo '$links.removeClass("csf-active");';
    echo '$link.addClass("csf-active");';
    echo 'var $section=$(\'[data-section-id="\'+slug+\'"]\');';
    echo 'if($section.length){';
    echo '$section.removeClass("hidden").siblings(".csf-section").addClass("hidden");';
    echo '}}}});';
    echo '</script>';
}

