# Changelog

本项目遵循语义化版本（SemVer）。所有条目按时间倒序排列。

## [0.1.5] — UNRELEASED

> 阶段：V1 实现期 P1–P5 已落地（P6 测试与 WP 实机 待做）。⚠️ O5 门禁：原生 cpage 分页须 P6 真实 WP 验证后关闭 O5。

- **P5 分页（OPEN_ITEMS ③，方案 A = 原生 cpage）**：`render_pagination()` 复用 `get_comments_pagenum_link()` 生成 `comment-page-N` / `?cpage=N` 链接，不依赖 `comments_template()` 上下文（陷阱 C）；分页在 `query_comments` 层以 `number`+`offset` 落地，`build_list_html` 不再向 `wp_list_comments` 传 `per_page/page` 避免双重切片；新增 `templates/comments-pager.php`（主题可覆盖，P9）。O5 在真实 WP 验证前仍 BLOCKED。结构自检新增 5 项 P5 原生 cpage 分页断言（现 83/83 全 PASS）。
- **范围守界**：P5 未注册重写规则（方案 A 重写规则须先真实 Page 实机确认，按 O5 门禁 deferred）；未越权 P6。

## [0.1.4] — UNRELEASED

> 阶段：V1 实现期 P1–P4 已落地（P5 分页见 0.1.5）。

- **P1 本地头像**：`get_avatar_data` 挂钩 + 后台上传字段（user_meta attachment_id），零 Gravatar 请求（陷阱 A）。
- **P2 评论渲染器 + 模板**：`WP_Comment_Query` 取数 → `wp_list_comments(callback)` 走自有模板；模板覆盖顺序 子主题→父主题→插件（P9）；不使用 `comments_template()`（陷阱 C）。
- **P3 评论表单**：复用核心 `comment_form()` 提交链路（不自造 `<form>`/nonce）；`comment-reply` 条件 enqueue（O4）；评论关闭提示仅对有权限登录用户（陷阱 D）。
- **P4 短代码 + 条件资源**：canonical `[berlin_comments]` + 别名 `[wp_comments]`（O1）；`handle()` 规范化参数（avatar_size 16–256 / comments_per_page 1–100 / show_avatar 布尔）并委托渲染器装配「列表 → 分页 → 表单」；资源仅当页面含 shortcode 时条件入队（O9 轻量，仅 CSS，JS 复用核心）。
- 静态架构自检：`structure-check.php` 75 项断言全 PASS（exit 0）。
- **P4 修正（AUDIT-007 `REJECT — REQUIRED CORRECTION`）**：`register()` 为两个 `add_shortcode()` 增加 `shortcode_exists()` 冲突保护，落实 O1「不得静默覆盖已有同名 shortcode handler」契约（WordPress 的 `add_shortcode()` 不会自动保护已有 handler）；结构自检新增第 76 项契约断言（75→76）。CHK-007 保留为不可变原始 P4 锚点，修正锚点建 CHK-008。

## [0.1.0] — UNRELEASED (INITIATED)

> 阶段：项目初始化 + 插件骨架。**功能未实现**，所有方法为占位 stub，均带 `TODO[D<n>]` 标记。

- 项目立项（Berlin WP Comments），确立「原生评论 + 本地头像 + 短代码」架构基线。
- 建立双仓库拓扑：代码仓库（`Berlin_wp_comments`）+ 记忆仓库（`Berlin_wp_comments_memory`）。
- 创建插件骨架：主文件 + 5 个类 + 3 个模板 + 资源占位 + 静态自检脚本。
- 确立 9 条 V1 目标（O1–O9）与 6 条治理原则（继承 BerlinOS）。
- 登记 6 个开放项（OPEN_ITEMS ①–⑥），待 CP1/USER 裁定。

### 待办（骨架期之后）

- 实现本地头像过滤器接线（先经 CP1 审计 D8 约束）。
- 实现短代码渲染与分页。
- 补齐 PHPUnit 测试基线（OPEN_ITEMS ②）。
