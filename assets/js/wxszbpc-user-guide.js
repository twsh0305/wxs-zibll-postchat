/**
 * 用户引导功能
 * 
 * 首次访问时动态加载 driver.js 并显示操作引导
 * 首页引导：搜索框 + AI对话按钮
 * 其它页面：仅AI对话按钮
 * 移动端各步骤可通过后台开关独立控制
 * PostChat按钮由SDK异步创建，需轮询等待元素就绪
 * 读取全局变量: wxszbpc_config
 */
(function(){
    var cfg = window.wxszbpc_config || {};

    // 检查 Cookie 是否已存在
    if (document.cookie.indexOf('wxszbpc_visited=true') !== -1) return;

    // 设置 Cookie
    var cookieMs = cfg.guideCookieMs || 604800000;
    var now = new Date();
    now.setTime(now.getTime() + cookieMs);
    document.cookie = 'wxszbpc_visited=true;expires=' + now.toUTCString() + ';path=/';

    /**
     * 动态加载 driver.js 和 driver.css 资源
     */
    function wxszbpc_loadDriverResources() {
        return new Promise(function(resolve, reject) {
            if (window.driver && window.driver.js && window.driver.js.driver) {
                resolve();
                return;
            }

            var baseUrl = cfg.pluginUrl || '';
            var ver = cfg.pluginVer || '';

            var cssLink = document.createElement('link');
            cssLink.rel = 'stylesheet';
            cssLink.href = baseUrl + 'assets/driver.min.css' + ver;

            var jsScript = document.createElement('script');
            jsScript.src = baseUrl + 'assets/driver.min.js' + ver;

            var cssLoaded = false, jsLoaded = false;
            function checkAll() { if (cssLoaded && jsLoaded) resolve(); }

            cssLink.onload = function() { cssLoaded = true; checkAll(); };
            cssLink.onerror = function() { reject(new Error('driver.css加载失败')); };
            jsScript.onload = function() { jsLoaded = true; checkAll(); };
            jsScript.onerror = function() { reject(new Error('driver.js加载失败')); };

            document.head.appendChild(cssLink);
            document.head.appendChild(jsScript);
        });
    }

    /**
     * 根据按钮模式查找AI对话按钮元素
     * hijack模式：查找劫持目标按钮的父级悬浮条（.aitip）
     * postchat模式：查找PostChat原生按钮（#postChat_button）
     * 
     * @returns {HTMLElement|null} 找到的按钮元素或null
     */
    function wxszbpc_findAiButton() {
        var mode = cfg.guideBtnMode || '';

        if (mode === 'hijack') {
            var hijackSel = cfg.guideHijackSelector || '#ai-duihua';
            var hijackEl = document.querySelector('[href="' + hijackSel + '"]');
            var target = hijackEl ? hijackEl.closest('.aitip') : document.querySelector('.aitip');
            if (target) return target;
        } else if (mode === 'postchat') {
            var pcBtn = document.querySelector('#postChat_button');
            if (pcBtn) return pcBtn;
        }

        // 兜底：尝试任一按钮
        return document.querySelector('#postChat_button') || document.querySelector('.aitip') || null;
    }

    /**
     * 检查元素是否已渲染且有实际尺寸
     * PostChat按钮为position:fixed，需确认getBoundingClientRect有效
     * 
     * @param {HTMLElement} el 目标元素
     * @returns {boolean} 元素是否就绪
     */
    function wxszbpc_isElementReady(el) {
        if (!el) return false;
        var rect = el.getBoundingClientRect();
        return rect.width > 0 && rect.height > 0;
    }

    /**
     * 执行引导显示
     * 
     * @param {Array} steps 引导步骤数组
     */
    function wxszbpc_runGuide(steps) {
        if (steps.length === 0) return;

        if (steps.length === 1) {
            var driverObj = window.driver.js.driver();
            driverObj.highlight({
                element: steps[0].element,
                popover: steps[0].popover
            });
        } else {
            var driverObj = window.driver.js.driver({
                showProgress: true,
                steps: steps
            });
            driverObj.drive();
        }
    }

    /**
     * 轮询等待AI按钮就绪后启动引导
     * PostChat按钮由SDK异步注入DOM，需等待元素存在且有尺寸
     * 最多等待 MAX_ATTEMPTS * INTERVAL 毫秒，超时则仅用已有元素引导
     * 
     * @param {number} attempt 当前尝试次数
     */
    function wxszbpc_waitAndGuide(attempt) {
        var MAX_ATTEMPTS = 10;
        var INTERVAL = 500;

        var isMobile = !!cfg.isMobile;
        var isHome = !!cfg.isHomePage;

        // 读取自定义文案
        var searchTitle = cfg.guideSearchTitle || '小贴士';
        var searchDesc = cfg.guideSearchDesc || '在此处填写想搜索的内容，点击右侧AI搜索，结果更加精准哦';
        var aiTitle = cfg.guideAiTitle || 'AI对话';
        var aiDesc = cfg.guideAiDesc || '点击此按钮，可与智能AI客服对话哦';

        // 搜索框(.line-form)依赖子比主题设置：启用顶部多功能组件 + 显示规则，未启用时元素不存在
        var searchEl = document.querySelector('.line-form');
        var aiBtnEl = wxszbpc_findAiButton();

        // AI按钮存在但尚未渲染完成，继续等待
        var needAiBtn = !isMobile || cfg.guideMobileAiBtn;
        if (needAiBtn && !aiBtnEl && attempt < MAX_ATTEMPTS) {
            setTimeout(function() { wxszbpc_waitAndGuide(attempt + 1); }, INTERVAL);
            return;
        }
        // 按钮存在但尺寸为0（还没布局完），继续等待
        if (needAiBtn && aiBtnEl && !wxszbpc_isElementReady(aiBtnEl) && attempt < MAX_ATTEMPTS) {
            setTimeout(function() { wxszbpc_waitAndGuide(attempt + 1); }, INTERVAL);
            return;
        }

        // 构建引导步骤
        var steps = [];

        // 搜索框步骤：仅首页
        if (isHome && searchEl && wxszbpc_isElementReady(searchEl)) {
            if (!isMobile || cfg.guideMobileSearch) {
                steps.push({
                    element: searchEl,
                    popover: { title: searchTitle, description: searchDesc, side: 'left' }
                });
            }
        }

        // AI按钮步骤：所有页面
        if (aiBtnEl && wxszbpc_isElementReady(aiBtnEl)) {
            if (!isMobile || cfg.guideMobileAiBtn) {
                steps.push({
                    element: aiBtnEl,
                    popover: { title: aiTitle, description: aiDesc, side: 'left' }
                });
            }
        }

        wxszbpc_runGuide(steps);
    }

    /**
     * 初始化用户引导
     */
    function wxszbpc_initUserGuide() {
        wxszbpc_loadDriverResources().then(function() {
            // 延迟后开始轮询等待元素就绪
            setTimeout(function() { wxszbpc_waitAndGuide(0); }, 1500);
        }).catch(function(err) {
            console.error('加载用户引导资源失败:', err);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wxszbpc_initUserGuide);
    } else {
        wxszbpc_initUserGuide();
    }
})();
