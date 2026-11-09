/**
 * WXS AI 对话交互脚本
 * 功能：劫持子比悬浮按钮 + 对话发送 + 打字动画 + Markdown渲染
 *
 * 架构：DOMContentLoaded 后统一初始化（按钮劫持 + 对话面板）
 *
 * @package WXS_Zibll_PostChat
 * @since 1.2.1
 */
(function () {
    'use strict';

    // 对话面板 DOM 引用（在 initChatPanel 中赋值）
    var overlay, messages, input, sendBtn, closeBtn;

    // 全局配置（延迟读取，确保内联脚本已执行）
    var cfg;

    // 状态
    var isGenerating = false;
    var hasNewUserMsg = false; // 标记当前会话是否有过新的用户发言
    var chatNonce = '';        // 安全校验 nonce，首次发送时异步获取（兼容 Nginx 页面缓存）

    // 未登录时暂存「选中内容提问」的预填文本，登录刷新后自动恢复
    var PENDING_INPUT_KEY = 'wxs_ai_pending_input';

    // ==================== 统一入口：DOMContentLoaded 后执行 ====================

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    function init() {
        cfg = window.wxsAiConfig || {};
        if (!cfg.ajaxUrl) return;

        initHijack();
        initChatPanel();
    }

    // ==================== 按钮劫持 ====================

    function initHijack() {
        if (!cfg.btnSelector) return;

        var selector = 'a[href="' + cfg.btnSelector + '"]';

        /**
         * 对匹配到的按钮元素绑定劫持事件
         */
        function hijackLink(link) {
            if (link.dataset.wxsHijacked) return;
            link.dataset.wxsHijacked = '1';

            // 移除 href 防止锚点跳转，移除 tooltip 防止 Bootstrap 干扰
            link.setAttribute('href', 'javascript:void(0)');
            link.removeAttribute('data-toggle');
            link.removeAttribute('data-original-title');
            link.setAttribute('title', cfg.chatTitle || 'AI对话');

            link.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                openChat();
            });

            // 可选：替换按钮图标
            if (cfg.btnSvgIcon) {
                var useEl = link.querySelector('use');
                if (useEl) {
                    useEl.setAttribute('xlink:href', cfg.btnSvgIcon);
                }
            }
        }

        // 策略1：查找已存在的按钮
        var existing = document.querySelectorAll(selector);
        if (existing.length) {
            existing.forEach(hijackLink);
        }

        // 策略2：MutationObserver + 轮询兜底
        if (!existing.length) {
            var observer = new MutationObserver(function (mutations) {
                for (var i = 0; i < mutations.length; i++) {
                    var added = mutations[i].addedNodes;
                    for (var j = 0; j < added.length; j++) {
                        var node = added[j];
                        if (node.nodeType !== 1) continue;
                        if (node.matches && node.matches(selector)) {
                            hijackLink(node);
                        }
                        if (node.querySelectorAll) {
                            var inner = node.querySelectorAll(selector);
                            inner.forEach(hijackLink);
                        }
                    }
                }
            });
            observer.observe(document.body, { childList: true, subtree: true });

            // 兜底轮询
            var attempts = 0;
            var poll = setInterval(function () {
                var el = document.querySelector(selector);
                if (el) {
                    hijackLink(el);
                    clearInterval(poll);
                    observer.disconnect();
                } else if (++attempts >= 20) {
                    clearInterval(poll);
                    observer.disconnect();
                }
            }, 500);
        }
    }

    // ==================== 对话面板初始化 ====================

    function initChatPanel() {
        overlay  = document.getElementById('wxs-ai-chat-overlay');
        messages = document.getElementById('wxs-ai-chat-messages');
        input    = document.getElementById('wxs-ai-chat-input');
        sendBtn  = document.getElementById('wxs-ai-chat-send');
        closeBtn = document.getElementById('wxs-ai-chat-close');

        if (!overlay || !messages || !input || !sendBtn) return;

        bindEvents();

        // 恢复登录前暂存的提问内容
        restorePendingInput();
    }

    // ==================== 事件绑定 ====================

    function bindEvents() {
        // 关闭按钮
        closeBtn.addEventListener('click', closeChat);

        // 结束对话按钮（清空历史并重置）
        var resetBtn = document.getElementById('wxs-ai-chat-reset');
        if (resetBtn) {
            resetBtn.addEventListener('click', resetChat);
        }

        // 历史记录按钮
        var historyBtn = document.getElementById('wxs-ai-chat-history-btn');
        if (historyBtn) {
            historyBtn.addEventListener('click', showHistoryPanel);
        }

        // 遮罩层点击关闭
        overlay.addEventListener('click', function (e) {
            if (e.target === overlay) closeChat();
        });

        // 发送按钮
        sendBtn.addEventListener('click', handleSend);

        // 回车发送（Shift+Enter换行）
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                handleSend();
            }
        });

        // 输入框自动增高
        input.addEventListener('input', function () {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 120) + 'px';
        });

        // ESC 关闭
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && overlay.classList.contains('wxs-ai-active')) {
                closeChat();
            }
        });
    }

    // ==================== 打开/关闭对话窗口 ====================

    /**
     * 恢复登录前暂存的提问内容（来自「选中内容提问AI」）
     * 只在登录成功后页面刷新时生效，避免未登录刷新反复弹窗
     */
    function restorePendingInput() {
        var pending = '';
        try { pending = sessionStorage.getItem(PENDING_INPUT_KEY) || ''; } catch (e) { return; }
        if (!pending || !input) return;
        // 仍未登录：保留暂存内容，等待登录后刷新再恢复
        if (!cfg.isLoggedIn) return;
        try { sessionStorage.removeItem(PENDING_INPUT_KEY); } catch (e) {}
        input.value = pending;
        // 自动增高适配多行内容
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    /**
     * 打开对话窗口
     *
     * @return {boolean|string} true=已打开；'login_required'=未登录且禁止游客；false=面板未就绪
     */
    function openChat() {
        // 防御：若对话面板DOM未就绪（极端情况），静默忽略
        if (!overlay) return false;

        // 游客权限校验：未登录且不允许游客使用时，调起登录框
        if (cfg.guestAllowed === false && !cfg.isLoggedIn) {
            if (typeof jQuery !== 'undefined' && jQuery('.signin-loader').length) {
                jQuery('.signin-loader').trigger('click');
            } else if (window._win && window._win.signin_url) {
                window.location.href = window._win.signin_url;
            }
            return 'login_required';
        }

        var isMobile = window.innerWidth <= 768;
        var mode = isMobile ? (cfg.mobileMode || 'fullscreen') : (cfg.desktopMode || 'sidebar');

        // 移除旧模式class
        overlay.classList.remove('wxs-ai-mode-sidebar', 'wxs-ai-mode-popup', 'wxs-ai-mode-fullscreen');
        overlay.classList.add('wxs-ai-mode-' + mode);
        overlay.classList.add('wxs-ai-active');

        // 自动填充默认输入（仅在输入框为空时）
        if (cfg.defaultInput && input && !input.value.trim()) {
            input.value = cfg.defaultInput;
        }

        if (input) {
            setTimeout(function () { input.focus(); }, 300);
        }

        return true;
    }

    function closeChat() {
        if (overlay) {
            overlay.classList.remove('wxs-ai-active');
        }
    }

    /**
     * 结束对话：保存当前会话到历史，然后重置
     */
    function resetChat() {
        if (isGenerating) return;

        // 保存当前会话（有用户消息才会保存/更新）
        saveCurrentSession();

        // 重置状态
        currentSessionId = null;
        hasNewUserMsg = false;

        // 重置消息区为欢迎语
        renderWelcome();

        // 恢复默认输入内容
        if (input) {
            input.value = cfg.defaultInput || '';
            input.style.height = 'auto';
            input.focus();
        }

        // 显示历史列表面板
        showHistoryPanel();
    }

    // ==================== 发送消息 ====================

    function handleSend() {
        var question = input.value.trim();
        if (!question || isGenerating) return;

        // 标记当前会话有过用户发言
        hasNewUserMsg = true;

        // 确保当前会话有唯一 ID（字符串），供服务端统计与本地历史复用
        if (!currentSessionId) {
            currentSessionId = generateSessionId();
        }

        // 收集发送前的历史（不含本次问题），用于多轮上下文
        var historyPayload = collectHistoryForSend();

        // 显示用户消息
        appendMessage(question, 'user');

        // 清空输入框
        input.value = '';
        input.style.height = 'auto';

        // 显示生成中动画
        var thinkingEl = appendThinking();
        isGenerating = true;
        sendBtn.disabled = true;

        // AJAX 请求
        var payload = {
            question: question,
            history: historyPayload,
            sessionId: currentSessionId,
            sessionRounds: Math.floor(collectCurrentMessages().length / 2),
            sessionTokens: estimateTokens()
        };

        sendChatRequest(payload, thinkingEl, false).finally(function () {
            isGenerating = false;
            sendBtn.disabled = false;
        });
    }

    /**
     * 获取 nonce（首次发送时异步拉取，避免 PHP 直接输出导致页面缓存后失效）
     */
    function ensureNonce() {
        if (chatNonce) return Promise.resolve(chatNonce);
        return fetch(cfg.ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=wxs_local_get_nonce'
        })
        .then(function (resp) { return resp.json(); })
        .then(function (json) {
            if (!json || !json.success || !json.data || !json.data.nonce) {
                throw new Error('页面校验获取失败，请刷新后重试');
            }
            chatNonce = json.data.nonce;
            return chatNonce;
        });
    }

    /**
     * 发送对话请求，nonce 失效时自动刷新并重试一次
     */
    function sendChatRequest(payload, thinkingEl, isRetry) {
        return ensureNonce().then(function (nonce) {
            var body = 'action=wxs_local_chat'
                + '&nonce=' + encodeURIComponent(nonce)
                + '&question=' + encodeURIComponent(payload.question)
                + '&history=' + encodeURIComponent(JSON.stringify(payload.history))
                + '&session_id=' + encodeURIComponent(payload.sessionId)
                + '&session_rounds=' + payload.sessionRounds
                + '&session_tokens=' + payload.sessionTokens;

            return fetch(cfg.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body
            });
        })
        .then(function (resp) { return resp.text(); })
        .then(function (text) {
            var json;
            try {
                json = JSON.parse(text);
            } catch (e) {
                throw new Error('服务器返回异常');
            }
            // nonce 过期：清空缓存后重试一次
            if (!isRetry && json.data && json.data.code === 'invalid_nonce') {
                chatNonce = '';
                return sendChatRequest(payload, thinkingEl, true);
            }
            removeThinking(thinkingEl);
            if (json.success && json.data && json.data.answer) {
                typewriterRender(json.data.answer);
                // 每次收到回复后保存会话到 localStorage 历史
                saveCurrentSession();
            } else {
                handleChatError(json);
            }
        })
        .catch(function (err) {
            removeThinking(thinkingEl);
            appendError('网络错误：' + err.message);
        });
    }

    /**
     * 统一处理对话接口的错误返回
     */
    function handleChatError(json) {
        var code = json.data && json.data.code;
        var msg = (json.data && json.data.message) ? json.data.message : '请求失败，请稍后重试';
        // 处理特殊错误码
        if (code === 'login_required') {
            appendError(msg);
            setTimeout(function() {
                if (typeof jQuery !== 'undefined' && jQuery('.signin-loader').length) {
                    jQuery('.signin-loader').trigger('click');
                }
            }, 1500);
        } else if (code === 'session_rounds_limit' || code === 'session_tokens_limit') {
            appendError(msg + '\n\n点击「结束对话」后可新建会话继续使用。');
        } else {
            appendError(msg);
        }
    }

    // ==================== 消息渲染 ====================

    // 消息头像：机器人（SVG）与用户（无头像时兜底 SVG）
    var BOT_AVATAR_SVG = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
        + '<rect x="4" y="8" width="16" height="12" rx="3"/>'
        + '<path d="M12 8V5"/><circle cx="12" cy="4" r="1.2"/>'
        + '<circle cx="9" cy="14" r="1"/><circle cx="15" cy="14" r="1"/>'
        + '<path d="M9.5 17.5h5"/></svg>';
    var USER_AVATAR_SVG = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
        + '<circle cx="12" cy="8" r="3.5"/>'
        + '<path d="M5 20c0-3.6 3.1-6 7-6s7 2.4 7 6"/></svg>';

    /**
     * 构建一条消息行（头像 + 气泡），结构对齐子比私信窗口
     */
    function buildMsgEl(role) {
        var wrap = document.createElement('div');
        wrap.className = 'wxs-ai-msg wxs-ai-msg-' + role;

        var avatar = document.createElement('div');
        avatar.className = 'wxs-ai-msg-avatar';
        if (role === 'user' && cfg.userAvatar) {
            var img = document.createElement('img');
            img.src = cfg.userAvatar;
            img.alt = '';
            avatar.appendChild(img);
        } else {
            avatar.innerHTML = (role === 'user') ? USER_AVATAR_SVG : BOT_AVATAR_SVG;
        }

        var bubble = document.createElement('div');
        bubble.className = 'wxs-ai-msg-bubble';

        wrap.appendChild(avatar);
        wrap.appendChild(bubble);
        return { wrap: wrap, bubble: bubble };
    }

    function appendMessage(text, role) {
        var el = buildMsgEl(role);
        el.bubble.textContent = text;
        messages.appendChild(el.wrap);
        scrollToBottom();
        return el.bubble;
    }

    /**
     * 渲染欢迎语（清空消息区后使用）
     */
    function renderWelcome() {
        if (!messages) return;
        var welcome = cfg.welcomeText || ('你好！我是' + (cfg.chatTitle || 'AI助理') + '，有什么可以帮你的？');
        messages.innerHTML = '';
        var el = buildMsgEl('bot');
        el.bubble.textContent = welcome;
        messages.appendChild(el.wrap);
    }

    function appendThinking() {
        var el = buildMsgEl('bot');
        el.bubble.classList.add('wxs-ai-thinking');
        el.bubble.innerHTML = '<span class="wxs-ai-dot"></span>' +
            '<span class="wxs-ai-dot"></span>' +
            '<span class="wxs-ai-dot"></span>' +
            '<span style="margin-left:4px">正在思考...</span>';
        messages.appendChild(el.wrap);
        scrollToBottom();
        return el.wrap;
    }

    function removeThinking(el) {
        if (el && el.parentNode) {
            el.parentNode.removeChild(el);
        }
    }

    function appendError(msg) {
        var el = buildMsgEl('bot');
        el.wrap.classList.add('wxs-ai-msg-error');
        el.bubble.textContent = msg;
        messages.appendChild(el.wrap);
        scrollToBottom();
    }

    // ==================== 打字动画 + Markdown 渲染 ====================

    function typewriterRender(fullText) {
        var el = buildMsgEl('bot');
        var bubble = el.bubble;
        messages.appendChild(el.wrap);

        var index = 0;
        var speed = cfg.typingSpeed || 15;
        var len = fullText.length;

        function tick() {
            if (index >= len) {
                // 完成：最终全量渲染确保Markdown完整
                bubble.innerHTML = renderMarkdown(fullText);
                scrollToBottom();
                return;
            }
            // 快速推进：每tick 2-4字符
            index += Math.ceil(Math.random() * 2 + 2);
            if (index > len) index = len;

            var partial = fullText.slice(0, index);
            bubble.innerHTML = renderMarkdown(partial);
            scrollToBottom();
            setTimeout(tick, speed);
        }
        tick();
    }

    function renderMarkdown(text) {
        if (window.marked && window.marked.parse) {
            return window.marked.parse(text);
        }
        // Markdown库未加载时，简单换行处理
        return text.replace(/\n/g, '<br>');
    }

    // ==================== 工具函数 ====================

    function scrollToBottom() {
        if (messages) {
            messages.scrollTop = messages.scrollHeight;
        }
    }

    // ==================== 对话历史（localStorage，最多3个槽位）====================

    var HISTORY_LIST_KEY = 'wxs_ai_chat_history_list';
    var MAX_SESSIONS = 3;
    var currentSessionId = null; // 当前正在对话的会话 ID（null=新对话）

    /**
     * 收集当前对话历史用于发送给后端（多轮上下文）
     * 返回纯文本数组：[{role:'user'|'bot', content:'...'}, ...]
     */
    function collectHistoryForSend() {
        var items = [];
        if (!messages) return items;
        var msgEls = messages.querySelectorAll('.wxs-ai-msg');
        msgEls.forEach(function (el) {
            if (el.querySelector('.wxs-ai-thinking') || el.classList.contains('wxs-ai-msg-error')) {
                return;
            }
            var bubble = el.querySelector('.wxs-ai-msg-bubble');
            if (!bubble) return;
            var role = el.classList.contains('wxs-ai-msg-user') ? 'user' : 'bot';
            items.push({ role: role, content: bubble.textContent });
        });
        return items;
    }

    /**
     * 获取历史列表
     */
    function getHistoryList() {
        try {
            return JSON.parse(localStorage.getItem(HISTORY_LIST_KEY) || '[]');
        } catch (e) { return []; }
    }

    /**
     * 保存历史列表
     */
    function setHistoryList(list) {
        try {
            localStorage.setItem(HISTORY_LIST_KEY, JSON.stringify(list));
        } catch (e) {}
    }

    /**
     * 收集当前消息区的内容
     */
    function collectCurrentMessages() {
        if (!messages) return [];
        var items = [];
        var msgEls = messages.querySelectorAll('.wxs-ai-msg');
        msgEls.forEach(function (el) {
            if (el.querySelector('.wxs-ai-thinking') || el.classList.contains('wxs-ai-msg-error')) {
                return;
            }
            var bubble = el.querySelector('.wxs-ai-msg-bubble');
            if (!bubble) return;
            var role = el.classList.contains('wxs-ai-msg-user') ? 'user' : 'bot';
            var content = (role === 'bot') ? bubble.innerHTML : bubble.textContent;
            items.push({ role: role, content: content });
        });
        return items;
    }

    /**
     * 粗估当前会话的 token 消耗（1中文≈2token，1英文≈1token）
     */
    function estimateTokens() {
        var items = collectCurrentMessages();
        var total = 0;
        items.forEach(function (item) {
            var text = item.content || '';
            // 粗估：中文字符数*2 + 英文字符数
            var cnLen = (text.match(/[\u4e00-\u9fff]/g) || []).length;
            var otherLen = text.length - cnLen;
            total += cnLen * 2 + otherLen;
        });
        return total;
    }

    /**
     * 生成唯一会话ID（纯函数，不修改当前会话状态）
     */
    function generateSessionId() {
        return 'wxs_' + Date.now() + '_' + Math.random().toString(36).substr(2, 8);
    }

    /**
     * 保存/更新当前会话到历史
     * 会话 ID 统一为字符串；命中已有记录则更新，否则新增（超过上限淘汰最旧的）
     */
    function saveCurrentSession() {
        if (!hasNewUserMsg) return; // 没发过消息不保存

        var items = collectCurrentMessages();
        var hasUser = items.some(function (i) { return i.role === 'user'; });
        if (!hasUser) return;

        // 提取第一条用户消息作为标题
        var title = '';
        for (var i = 0; i < items.length; i++) {
            if (items[i].role === 'user') {
                title = items[i].content.substring(0, 40);
                break;
            }
        }

        if (!currentSessionId) {
            currentSessionId = generateSessionId();
        }

        var list = getHistoryList();
        var updated = false;

        // 更新已有会话（统一转字符串比较，兼容旧数据中的数字 ID）
        for (var j = 0; j < list.length; j++) {
            if (String(list[j].id) === String(currentSessionId)) {
                list[j].id = currentSessionId;
                list[j].title = title;
                list[j].time = new Date().toLocaleString();
                list[j].messages = items;
                updated = true;
                break;
            }
        }

        // 新会话：插入到最前面，超过上限则淘汰最旧的
        if (!updated) {
            list.unshift({
                id: currentSessionId,
                title: title,
                time: new Date().toLocaleString(),
                messages: items
            });
            if (list.length > MAX_SESSIONS) {
                list = list.slice(0, MAX_SESSIONS);
            }
        }

        setHistoryList(list);
    }

    /**
     * 显示历史对话列表面板（覆盖消息区域）
     */
    function showHistoryPanel() {
        if (!messages) return;
        var list = getHistoryList();

        if (!list.length) return; // 无历史则不显示

        var html = '<div class="wxs-ai-history-panel">';
        html += '<div class="wxs-ai-history-header">历史对话</div>';
        html += '<ul class="wxs-ai-history-list">';
        list.forEach(function (item) {
            var activeClass = (String(item.id) === String(currentSessionId)) ? ' wxs-ai-history-item-active' : '';
            html += '<li class="wxs-ai-history-item' + activeClass + '" data-id="' + escHtml(String(item.id)) + '">';
            html += '<span class="wxs-ai-history-title">' + escHtml(item.title) + '</span>';
            html += '<span class="wxs-ai-history-time">' + escHtml(item.time) + '</span>';
            html += '</li>';
        });
        html += '</ul>';
        html += '<button class="wxs-ai-history-back">新对话</button>';
        html += '</div>';

        messages.innerHTML = html;

        // 绑定点击事件：恢复某条历史
        messages.querySelectorAll('.wxs-ai-history-item').forEach(function (el) {
            el.addEventListener('click', function () {
                restoreFromHistory(el.dataset.id);
            });
        });

        // 新对话按钮
        var backBtn = messages.querySelector('.wxs-ai-history-back');
        if (backBtn) {
            backBtn.addEventListener('click', function () {
                currentSessionId = null;
                hasNewUserMsg = false;
                renderWelcome();
                if (input) {
                    input.value = cfg.defaultInput || '';
                }
            });
        }
    }

    /**
     * 从历史列表恢复某条对话（可继续对话）
     */
    function restoreFromHistory(id) {
        if (!messages) return;
        var list = getHistoryList();

        var record = null;
        for (var i = 0; i < list.length; i++) {
            if (String(list[i].id) === String(id)) { record = list[i]; break; }
        }
        if (!record || !record.messages) return;

        // 设置当前会话 ID（后续发消息会更新这条记录）
        currentSessionId = record.id;
        hasNewUserMsg = false;

        messages.innerHTML = '';
        record.messages.forEach(function (item) {
            var role = item.role === 'user' ? 'user' : 'bot';
            var el = buildMsgEl(role);
            if (role === 'bot') {
                el.bubble.innerHTML = item.content;
            } else {
                el.bubble.textContent = item.content;
            }
            messages.appendChild(el.wrap);
        });
        scrollToBottom();
    }

    function escHtml(str) {
        var d = document.createElement('div');
        d.textContent = str || '';
        return d.innerHTML;
    }
    if (window.marked && window.marked.setOptions) {
        window.marked.setOptions({
            breaks: true,
            gfm: true
        });
    }

    // 暴露全局接口：供右键菜单等外部代码调用
    // 返回 true=已打开；'login_required'=未登录且禁止游客（已弹登录框）；false=窗口未输出
    window.wxsAiChatOpen = function (prefillText) {
        if (!overlay) return false;
        var state = openChat();
        if (state === 'login_required') {
            // 暂存预填内容，登录成功后刷新页面自动恢复到输入框
            if (prefillText) {
                try { sessionStorage.setItem(PENDING_INPUT_KEY, prefillText); } catch (e) {}
            }
            return 'login_required';
        }
        if (prefillText && input) {
            input.value = prefillText;
            input.focus();
        }
        return true;
    };

})();
