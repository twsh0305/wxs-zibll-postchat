# 子比PostChat

> 基于子比主题的 PostChat AI 摘要与对话集成插件，支持私有摘要存储与前端注入。

[![Version](https://img.shields.io/badge/version-1.0.0-blue.svg)](https://wxsnote.cn/7673.html)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-green.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/license-GPL%20v2-orange.svg)](LICENSE)

---

## 📖 简介

子比PostChat 是基于洪墨AI（PostChat）平台，为 [子比主题](https://www.zibll.com/pay-zibll?ref=2056) 提供 AI 智能摘要、AI 对话、AI 搜索等功能的深度集成插件。

**插件主页：** [https://wxsnote.cn/7673.html](https://wxsnote.cn/7673.html)

---

## ✨ 功能特性

| 功能 | 说明 |
|------|------|
| 🤖 **AI 智能摘要** | 文章 / 论坛帖子自动生成 AI 摘要，支持私有摘要模式（存储到数据库，前端直接读取，速度更快） |
| 💬 **AI 对话** | 支持按钮劫持（接管主题悬浮按钮）和 PostChat 悬浮按钮两种模式 |
| 🔍 **AI 搜索** | 集成搜索表单，提供 AI 增强搜索体验 |
| 🎙️ **AI 播客** | 自动生成文章语音播客（调用豆包 API） |
| 📝 **批量生成** | 一键检测并批量生成缺失摘要，支持请求间隔调控 |
| 📰 **内容管理** | 后台可视化编辑、搜索、删除、批量删除摘要 |
| 🎨 **深度适配** | 子比主题 CSS 选择器深度适配，完美融合 |
| 👋 **用户引导** | 基于 driver.js 的新用户操作引导，Cookie 控制不重复显示 |
| 🚫 **黑名单** | 支持 URL 通配符黑名单，排除不想要的页面 |

---

## 📋 环境要求

| 依赖 | 版本要求 |
|------|----------|
| WordPress | 5.0+ |
| PHP | 7.4+ |
| 子比主题 (Zibll) | 6.0+（依赖 CSF 框架） |
| PostChat API Key | 在 [洪墨AI后台](https://ai.zhheo.com/console/) 获取 |

---

## 🚀 快速开始

### 1. 安装插件

- 下载插件 ZIP 包，在 WordPress 后台「插件 → 安装插件 → 上传插件」中上传并启用
- 或解压到 `/wp-content/plugins/wxs-zibll-postchat/` 目录，在后台启用

### 2. 注册洪墨AI

访问 [洪墨AI平台](https://ai.zhheo.com/console/login?InviteID=63772604) 注册账号：

- 被邀请用户开通 **1年**，双方各获得 `31天` 奖励
- 被邀请用户开通 **1个月**，双方各获得 `7天` 奖励

### 3. 获取 API Key

1. 登录 [洪墨AI后台](https://ai.zhheo.com/console/)
2. 进入「我的项目」创建或选择一个项目
3. 复制 API Key（格式：`3P-xxxxxx`）

### 4. 配置插件

进入 WordPress 后台「PostChat AI」，按需配置各模块：

- **摘要设置** — 配置 API Key、选择器、主题样式等
- **AI 对话设置** — 配置对话模式、按钮样式、预填问题等
- **用户引导** — 配置引导步骤与触发时机

---

## ⚙️ 配置详解

### 摘要设置

| 配置项 | 说明 |
|--------|------|
| 启用 AI 摘要 | 总开关 |
| 文章 / 论坛摘要 | 分别控制文章和论坛帖子的摘要开关 |
| 私有摘要模式 | 开启后摘要存储在本地数据库，前端直接读取（需配置 API Secret） |
| PostChat Key | AI 摘要和对话共用密钥 |
| API Secret | 私有摘要模式必填，在 [用户设置](https://ai.zhheo.com/console/settings) 获取 |
| 黑名单 URL | 每行一个 URL，支持 `*` 通配符 |

### AI 对话设置

| 配置项 | 说明 |
|--------|------|
| 按钮模式 | **主题按钮劫持** — 接管子比右侧悬浮按钮 / **PostChat 悬浮按钮** — 使用 PostChat 自带按钮 |
| 对话模式 | 桌面端/移动端分别选择 iframe 或 Magic 模式 |
| 默认输入 | 支持 `{title}` 变量替换当前文章标题 |
| 上传网页 | 浏览文章时自动上传内容到 AI 知识库 |

### 按钮劫持模式配置

1. 进入子比主题设置 → 全局&功能 → 右侧悬浮按钮 → 更多按钮
2. 添加按钮，简介填写「AI对话」
3. URL 填写 `#ai-duihua`（或自定义值，需与插件中「查找按钮URL」一致）

---

## 📁 目录结构

```
wxs-zibll-postchat/
├── wxs-zibll-postchat.php    # 插件主文件（入口、常量、升级机制、主题检测）
├── uninstall.php              # 卸载清理
├── inc/
│   ├── options.php           # CSF 选项配置（后台设置页面）
│   ├── output.php            # 前端 JS/CSS 输出 + 摘要注入
│   ├── listener.php          # 文章发布监听 + API 调用 + AJAX 接口
│   └── updater.php           # GitHub 更新检查器
├── assets/
│   ├── css/
│   │   ├── wxszbpc-ai-search.css       # AI搜索样式源文件
│   │   └── wxszbpc-ai-search.min.css   # AI搜索样式压缩版
│   ├── js/
│   │   ├── wxszbpc-postchat.js         # 主脚本源文件（AI搜索+按钮劫持）
│   │   ├── wxszbpc-postchat.min.js     # 主脚本压缩版
│   │   ├── wxszbpc-user-guide.js       # 用户引导源文件
│   │   └── wxszbpc-user-guide.min.js   # 用户引导压缩版
│   ├── driver.css                      # driver.js 样式
│   ├── driver.js.iife.js               # driver.js IIFE 源文件
│   ├── driver.min.css                  # driver.js 压缩样式
│   └── driver.min.js                   # driver.js 压缩版
├── LICENSE                    # GPL v2 开源协议
└── README.md
```

---

## 🔧 开发者

### 数据库

| 表 | 键名 | 说明 |
|----|------|------|
| wp_options | `wxs_postchat_settings` | 插件全部配置 |
| wp_options | `wxs_postchat_db_version` | 数据库版本号 |
| wp_postmeta | `_wxs_postchat_summary` | 文章 AI 摘要 |
| wp_postmeta | `_wxs_postchat_content_hash` | 文章内容哈希（判断是否需要重新生成） |

### Hooks

| Hook | 说明 |
|------|------|
| `wxs_postchat_generate_summary_event` | 异步摘要生成任务 |
| `wp_ajax_wxs_postchat_batch_get_missing` | 获取缺失摘要文章列表 |
| `wp_ajax_wxs_postchat_batch_generate_single` | 为单篇文章生成摘要 |
| `wp_ajax_wxs_postchat_edit_summary` | 编辑摘要 |
| `wp_ajax_wxs_postchat_delete_summary` | 删除摘要 |
| `wp_ajax_wxs_postchat_batch_delete_summary` | 批量删除摘要 |
| `wp_ajax_wxs_postchat_search_summary` | 搜索摘要 |

### 常量

| 常量 | 说明 |
|------|------|
| `WXS_POSTCHAT_VERSION` | 插件版本 |
| `WXS_POSTCHAT_DB_VERSION` | 数据库版本（用于升级迁移） |
| `WXS_POSTCHAT_DIR` | 插件目录绝对路径 |
| `WXS_POSTCHAT_URL` | 插件目录 URL |
| `WXS_POSTCHAT_PREFIX` | 配置项存储前缀 |

### 函数前缀

所有函数以 `wxs_postchat_` 为前缀，确保不与主题或其他插件冲突。

---

## 🔄 升级机制

插件内置数据库版本升级系统：

1. 修改 `WXS_POSTCHAT_DB_VERSION` 常量为目标版本
2. 在 `wxs_postchat_maybe_upgrade()` 函数中添加对应版本的 `if` 分支
3. 插件激活后自动检测并执行升级逻辑

---

## 🗑️ 卸载

删除插件时会自动清理：

- 插件配置（`wxs_postchat_settings`）
- 数据库版本（`wxs_postchat_db_version`）
- 所有文章的摘要和内容哈希元数据
- 残留的计划任务

---

## 🙏 致谢

本插件的开发建立在以下优秀开源项目基础之上：

- [Codestar Framework](https://github.com/Codestar/codestar-framework) — 轻量级 WordPress 选项设置框架
- [driver.js](https://github.com/nilbuild/driver.js) — 强大的新用户引导库
- [PostChat](https://cn.wordpress.org/plugins/postchat/) — 原版 WordPress PostChat 插件，本插件基于其改造增强

---

## 📞 联系与交流

- **作者：** 天无神话
- **作者 QQ：** 2031301686
- **QQ群：** [399019539](https://jq.qq.com/?_wv=1027&k=eiGEOg3i)
- **开源地址：** [github.com/twsh0305/wxs-zibll-postchat](https://github.com/twsh0305/wxs-zibll-postchat)
- **插件主页：** [wxsnote.cn/7673.html](https://wxsnote.cn/7673.html)

---

## 📄 开源协议

本项目采用 [GPL v2 or later](LICENSE) 协议开源。
