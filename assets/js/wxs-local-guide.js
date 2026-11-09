/**
 * 本站模式 — 用户引导
 *
 * 功能：首次访问时通过 driver.js 引导用户发现 AI 对话按钮。
 * 仅单步引导（AI对话按钮），不包含搜索引导。
 *
 * 读取全局变量：wxsLocalGuide（由 output-local.php 注入）
 *
 * @package WXS_Zibll_PostChat
 * @since 1.1.0
 */
(function () {
    'use strict';

    var cfg = window.wxsLocalGuide || {};
    if (!cfg.btnSelector || !window.driver) return;

    // Cookie 检查（避免重复显示）
    if (document.cookie.indexOf('wxs_local_guide_visited=true') !== -1) return;

    /**
     * 设置引导已完成 Cookie
     */
    function setCookie() {
        var days = parseInt(cfg.guideCookieDays, 10) || 7;
        var expires = new Date();
        expires.setTime(expires.getTime() + days * 86400000);
        document.cookie = 'wxs_local_guide_visited=true;expires=' + expires.toUTCString() + ';path=/';
    }
    /**
     * 查找子比悬浮按钮中的 AI 对话入口
     *
     * 按钮通过 href 匹配 btnSelector（默认 #ai-duihua），
     * 位于子比主题的 .float-right 右侧悬浮条中。
     *
     * @returns {HTMLElement|null}
     */
    function findAiButton() {
        // 精确匹配 href 属性
        var link = document.querySelector('.float-right a[href="' + cfg.btnSelector + '"]');
        if (link) return link;

        // 兜底：模糊匹配任意位置的 href
        link = document.querySelector('a[href="' + cfg.btnSelector + '"]');
        return link || null;
    }

    /**
     * 检查元素是否已渲染且有实际尺寸
     *
     * @param {HTMLElement} el
     * @returns {boolean}
     */
    function isElementReady(el) {
        if (!el) return false;
        var rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    /**
     * 执行引导显示（单步 highlight）
     *
     * @param {HTMLElement} el 目标元素
     */
    function runGuide(el) {
        // 引导真正执行时才设置 Cookie
        setCookie();

        var aiTitle = cfg.guideAiTitle || 'AI对话';
        var aiDesc  = cfg.guideAiDesc  || '点击此按钮，可与智能AI客服对话哦';

        var driverObj = window.driver.js.driver();
        driverObj.highlight({
            element: el,
            popover: {
                title: aiTitle,
                description: aiDesc,
                side: 'left'
            }
        });
    }

    /**
     * 轮询等待按钮就绪后启动引导
     *
     * 子比悬浮按钮可能延迟渲染，最多等待 10 次 * 500ms = 5 秒
     *
     * @param {number} attempt 当前尝试次数
     */
    function waitAndGuide(attempt) {
        var MAX_ATTEMPTS = 10;
        var INTERVAL = 500;

        var btnEl = findAiButton();

        // 按钮不存在，继续等待
        if (!btnEl && attempt < MAX_ATTEMPTS) {
            setTimeout(function () { waitAndGuide(attempt + 1); }, INTERVAL);
            return;
        }

        // 按钮存在但未完成布局，继续等待
        if (btnEl && !isElementReady(btnEl) && attempt < MAX_ATTEMPTS) {
            setTimeout(function () { waitAndGuide(attempt + 1); }, INTERVAL);
            return;
        }

        // 按钮就绪，执行引导
        if (btnEl && isElementReady(btnEl)) {
            runGuide(btnEl);
        }
    }

    /**
     * 入口：延迟后开始轮询
     */
    function init() {
        // driver.js 已通过 wp_enqueue_script 加载，延迟后开始寻找按钮
        setTimeout(function () { waitAndGuide(0); }, 1500);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
