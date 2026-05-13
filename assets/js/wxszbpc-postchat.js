/**
 * WXS Zibll PostChat 插件主脚本
 * 
 * 包含：AI搜索功能 + AI对话按钮劫持
 * 依赖外部JS: postChatUser (PostChat脚本提供)
 * 依赖主题JS: notyf (子比主题通知函数，已做运行时检查)
 * 读取全局变量: wxszbpc_config
 */
(function(){
    var cfg = window.wxszbpc_config || {};

    // ============================================
    // AI搜索功能
    // ============================================

    if (cfg.enableAiSearch) {

        /**
         * 在搜索表单后插入AI搜索按钮
         */
        function wxszbpc_addAiSearchButton(form) {
            if (!form || document.getElementById('search-heoGPT-tips')) return;

            var aiSearchLink = document.createElement('a');
            aiSearchLink.id = 'search-heoGPT-tips';
            aiSearchLink.className = 'search-ai-btn';
            aiSearchLink.href = 'javascript:;';
            aiSearchLink.rel = 'external nofollow';
            aiSearchLink.title = '数字人助理';
            aiSearchLink.innerHTML = '<i class="fa fa-smile-o"></i>' +
                '<span class="search-heoGPT-text">试试使用AI进行搜索，搜索结果更加精准</span>' +
                '<span class="search-heoGPT-text-right">推荐</span>';

            form.parentNode.insertBefore(aiSearchLink, form.nextSibling);

            aiSearchLink.addEventListener('click', function(event) {
                event.preventDefault();
                var searchInput = form.querySelector('input[name="s"]');
                if (!searchInput) {
                    if (typeof notyf !== 'undefined') notyf('搜索输入框加载失败，请刷新页面重试～', 'warning');
                    return;
                }

                var searchContent = searchInput.value.trim();
                if (!searchContent) {
                    if (typeof notyf !== 'undefined') notyf('请输入搜索内容后再使用AI搜索～', 'warning');
                    searchInput.focus();
                    return;
                }

                if (typeof postChatUser === 'undefined' || typeof postChatUser.sendSearchMsg !== 'function') {
                    if (typeof notyf !== 'undefined') notyf('AI搜索功能暂不可用，请稍后再试～', 'warning');
                    return;
                }

                try {
                    postChatUser.sendSearchMsg(searchContent);
                } catch (e) {
                    if (typeof notyf !== 'undefined') notyf('AI搜索请求失败，请稍后再试～', 'warning');
                }
            });
        }

        /**
         * 初始化AI搜索按钮：模态框搜索 + 页面搜索 + 首页搜索框
         */
        function wxszbpc_initAISearch() {
            // 模态框搜索
            var modalSearchContainer = document.querySelector('.main-search.fixed-body.main-bg.box-body.navbar-search.nopw-sm');
            if (modalSearchContainer) {
                var modalObserver = new MutationObserver(function(mutationsList) {
                    mutationsList.forEach(function(mutation) {
                        if (mutation.type === 'attributes' && mutation.attributeName === 'class' &&
                            modalSearchContainer.classList.contains('show')) {
                            var modalForm = modalSearchContainer.querySelector('form.padding-10.search-form[method="get"]');
                            if (modalForm) {
                                wxszbpc_addAiSearchButton(modalForm);
                                modalObserver.disconnect();
                            }
                        }
                    });
                });
                modalObserver.observe(modalSearchContainer, { attributes: true });
            } else {
                // 页面搜索表单
                document.querySelectorAll('form.padding-10.search-form[method="get"]').forEach(function(form) {
                    wxszbpc_addAiSearchButton(form);
                });
            }

            // 首页搜索框修改
            if (cfg.isHomePage && !cfg.isMobile) {
                var targetDiv = document.querySelector('.abs-right.muted-color');
                if (targetDiv) {
                    var buttonToRemove = targetDiv.querySelector('button');
                    if (buttonToRemove) targetDiv.removeChild(buttonToRemove);

                    var newLink = document.createElement('a');
                    newLink.id = 'aisearch';
                    newLink.className = 'btn wxs-search-button-red';
                    newLink.innerHTML = 'AI搜索';

                    var newButton = document.createElement('button');
                    newButton.type = 'submit';
                    newButton.tabIndex = 2;
                    newButton.className = 'btn wxs-search-button-blue';
                    newButton.textContent = '站内搜索';

                    targetDiv.appendChild(newLink);
                    targetDiv.appendChild(newButton);

                    // 监听AI搜索按钮点击
                    newLink.addEventListener('click', function(event) {
                        event.preventDefault();
                        var searchInput = document.getElementsByName('s')[0];
                        if (searchInput && typeof postChatUser !== 'undefined' && postChatUser.sendSearchMsg) {
                            postChatUser.sendSearchMsg(searchInput.value);
                        }
                    });
                }
            }
        }
    }

    // ============================================
    // AI对话按钮劫持
    // ============================================

    if (cfg.aiBtnSelector) {

        /**
         * 劫持主题悬浮按钮为AI对话触发器
         */
        function wxszbpc_initAIButton() {
            var el = document.querySelector('a[href="' + cfg.aiBtnSelector + '"]');
            if (!el) return;

            el.setAttribute('href', 'javascript:;');
            el.classList.add('aitip');

            el.addEventListener('click', function() {
                var questionTitle;
                if (cfg.defaultInput) {
                    // 使用后台配置的值（已由PHP替换变量）
                    questionTitle = cfg.defaultInput;
                } else {
                    // JS后备值：使用document.title，并根据配置清理站点名
                    var title = document.title;
                    if (cfg.excludeSiteTitle && cfg.siteName) {
                        // 移除常见的标题格式：标题 - 站点名、标题 | 站点名
                        title = title.replace(new RegExp('\\s*[-|–|—]\\s*' + cfg.siteName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*$', 'g'), '');
                        title = title.replace(new RegExp('\\s*\\|\\s*' + cfg.siteName.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\s*$', 'g'), '');
                        title = title.trim();
                    }
                    questionTitle = '请问在《' + title + '》中';
                }
                
                if (typeof postChatUser !== 'undefined' && postChatUser.setPostChatInput) {
                    postChatUser.setPostChatInput(questionTitle);

                    // 同步主题色到PostChat
                    var themeCookie = document.cookie.split('; ').find(function(row) { return row.startsWith('theme_mode='); });
                    if (themeCookie) {
                        var mode = themeCookie.split('=')[1];
                        if (mode === 'white-theme') {
                            postChatUser.setPostChatTheme('light');
                        } else if (mode === 'dark-theme') {
                            postChatUser.setPostChatTheme('dark');
                        }
                    } else {
                        var isDark = document.body.classList.contains('dark-theme');
                        postChatUser.setPostChatTheme(isDark ? 'dark' : 'light');
                    }
                }
            });

            // 替换SVG图标（若配置了）
            if (cfg.aiBtnSvgIcon) {
                var svgUse = el.querySelector('svg use');
                if (svgUse) svgUse.setAttribute('xlink:href', cfg.aiBtnSvgIcon);
            }
        }
    }

    // ============================================
    // DOM加载后统一执行
    // ============================================

    /**
     * 初始化所有已启用的功能
     */
    function wxszbpc_init() {
        if (cfg.enableAiSearch && typeof wxszbpc_initAISearch === 'function') {
            wxszbpc_initAISearch();
        }
        if (cfg.aiBtnSelector && typeof wxszbpc_initAIButton === 'function') {
            wxszbpc_initAIButton();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', wxszbpc_init);
    } else {
        wxszbpc_init();
    }
})();
