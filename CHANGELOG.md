# Changelog

本项目遵循语义化版本（SemVer）。所有条目按时间倒序排列。

## [0.1.14] — UNRELEASED

> 评论列表框视觉对齐 `.bwpc-pager__current`（CP2 视觉标准：框体元素统一用主色边框 + 圆角语言；用户指定"不需要背景"）。

- **评论列表框（`.bwpc` 容器）边框化**：`.bwpc` 外层容器新增 `border: 1px solid var(--bwpc-accent)` + `border-radius: var(--vosalen-radius)` + `background: transparent`（无填充）+ `padding: 22px 24px`（`box-sizing: border-box` 保持 `max-width` 含边框，不撑破版面）。移动端（≤600px）padding 收窄为 `16px 14px`。评论标题 / 卡片 / 分页 / 表单整体被统一收进主色描边卡片，与分页当前页按钮视觉语言一致。作用域锁定 `.bwpc`，不污染主题；站点 Additional CSS 仍可同级覆盖。

## [0.1.13] — 2026-08-30 (RELEASED)

> 落地上一版「为将来预留」的评论附件完整链路 + 分页非当前页可见性微调 + P3 表单契约细化（v0.1.12 自渲染后旧契约已不适用）。

- **评论附件完整链路**：v0.1.12 已预备表单 UI（`<input type="file">` + `enctype="multipart/form-data"`），CHANGELOG 明确标注「为将来预留」。本版本落地「接 → 存 → 读 → 清理」全链路。新模块 `class Bwpc_Comment_Attachment`（`includes/class-bwpc-attachment.php`）接管 `comment_post` 钩子 → 复用核心 `wp_handle_upload()` + `wp_insert_attachment()` + `wp_generate_attachment_metadata()` 入媒体库 → `update_comment_meta()` 关联评论（`_bwpc_attachment_id` + `_bwpc_attachment_url`）。同时注册 `deleted_comment` / `trash_comment` / `spam_comment` 三路清理钩子同步 `wp_delete_attachment()` 物理删。MIME 白名单默认 `image/jpeg, image/png, image/webp, image/gif, application/pdf`，大小上限默认 5 MB，均可由过滤器 `bwpc_attachment_allowed_mimes` / `bwpc_attachment_max_bytes` 扩展。**仅 approved 评论挂附件**，避免待审/垃圾评论留下需清理的附件。`templates/comment.php` 调用静态助手 `Bwpc_Comment_Attachment::render_media( $comment_id )` 输出 `<div class="bwpc-comment__media">…</div>`：图片渲染 thumbnail 包链接（点击看大图，`loading="lazy"`），其它（PDF）输出 📎 + 文件名文档卡；attachment 已被清理 / 无权限 → 静默返回空。
- **评论分页非当前页可见性修复**（实机截图 vosalen / 2026-08-30）：旧版 `.bwpc-pager__link` 边框色 `var(--vosalen-border): #E5E7EB` 在白底上对比度过弱，肉眼几乎不可见；当前页 `.bwpc-pager__current` 用主色实心填充 + 白字 → 截图中「1」显眼、「2/3」看起来无样式。v0.1.13 拆分两组选择器（非当前页不再与当前页共用组合选择器），非当前页改用 `#D1D5DB` 边框 + 显式白底 + 字重 500 + `cursor: pointer`；hover 主色软底 + 主色边框 + 主色文字；active 下沉 1px 按下反馈；当前页加 `cursor: default` + `user-select: none` 防误点；`:focus-visible` 键盘可达描边。
- **静态自检契约细化**（102/102 PASS，旧基线 90 → 新基线 102）：
  - 新增 5 项 v0.1.13 契约：`attachment 模块注册 4 个生命周期钩子` / `attachment 模块已装配` / `templates/comment.php 调用 render_media + 输出 .bwpc-comment__media` / `.bwpc-pager__link 含 #D1D5DB 分页可见性守门` / `LICENSE` 等已存在文件补 required_files 守门。
  - **P3 表单契约重写**（v0.1.12 自渲染后旧契约必然 FAIL，必须细化才能继续守门）：
    - 原「调用核心 `comment_form`」 → 新「不再调用核心 `comment_form()`」（脱离 WP 内部排序漂移）
    - 原「不自造 `<form>`」 → 新「`action` 仍指 `/wp-comments-post.php` + `id="respond"` + 保留 `do_action('comment_form', $post_id)` 扩展位 + 不自造 nonce」（核心端点不破、第三方扩展不丢、nonce 不双写）

## [0.1.12] — UNRELEASED

> 内置 B2B 视觉主题（SI-001 成果并入核心，撤销原「站点层 override」红线）。

- **内置评论区视觉主题 `berlin-wp-comments-vosalen.css`**：将原 SI-001「站点层 override」成果转为插件内置默认样式（设计基线参考 Made-in-China / Alibaba 主流 B2B 评论板块：圆形头像、5 星、国家/Verified/Repeat buyer 元数据、产品订单条、Helpful）。`enqueue_assets()` 在加载 `comments.css` 后自动加载本主题（依赖 `bwpc-comments`，保证输出顺序）。站点层仍可用主题 Additional CSS 覆盖 `.bwpc*` 命名空间，不锁定视觉。品牌色等以 `:root` CSS 变量抽离，便于站点定制。
- **评论表单完全自渲染**（结构性变更，脱离 `comment_form()` 内部排序）：P6 + SI-001 实机发现——作者先看到的"Wp 核心 `comment_form()` 在不同 WP 版本下输出顺序不可控"问题，在不同 WP 版本下 cookies-consent 与 textarea / email / url 之间顺序漂移。改为 `render_form_html()` 直接 echo `<form id="respond">`，字段顺序固定为 ① Name+Email 同行（`.bwpc-form-row` flex）→ ② Attachment → ③ Comment textarea → ④ cookies-consent → ⑤ submit + post id + parent id。提交 action 仍指向核心 `/wp-comments-post.php`，审核/垃圾/Akismet/通知由核心在提交端处理；`id="respond"` 保证核心 `comment-reply.js` 仍能识别并移动表单。占位符即标签（`Name *` / `Email *` / `Your comment *`）+ `enctype="multipart/form-data"` 为将来 `$_FILES['bwpc_comment_attachment']` 上传处理预留。

## [0.1.11] — 2026-08-30 (V1 首发)

> P6 实机根因修复（2026-08-30，vosalen.com 真实 WP，探针数据驱动定位）。本版本对应 V1_WP_VERIFIED 实机验收通过，作为首个正式发布 tag `v0.1.11`。

- **空分页根因修复（P6 实机发现五，决定性）**：`?bwpc_debug=1` 探针（v0.1.10）实机数据暴露——`query_comments_count=3` 但第 2/3 页内容为空。根因：`build_list_html()` 把已按顶层 thread 切好的本页评论交给 `wp_list_comments()` 时**未关闭 WP 自身的分页切片**；站点开启 `page_comments`（`comments_per_page=3`）时，`wp_list_comments()` 用 `get_query_var('cpage')` 对传入数组**二次切片**——第 1 页 `array_slice(0,3)` 正常，第 2 页 `array_slice(3,3)`、第 3 页同理**切空**（精确吻合「页码一致但 2/3 页为空」实机症状）。修正：在 `wp_list_comments` 参数中显式 `per_page => 0` + `page => 0`，禁止 WP 重复切片，仅让其按 `comment_parent` 重建嵌套。
- **探针升级（v0.1.10 → 延续）**：`?bwpc_debug=1` 不再短路，红面板下方照常渲染真实评论区，直接肉眼确认内容；新增 `list_html_len` / `rendered_list_empty`（`YES`/`no` 显式）/ `list_html_snippet`（前 200 字纯文本），消除 `print_r` 把 `false`/`''` 都显示为空造成的误判。`rendered_list_empty` 字段语义由布尔改为可读字符串。
- 静态回归 `structure-check` 90/90 通过；PHP `-l` 语法检查通过。

## [0.1.10] — UNRELEASED

> P6 实机诊断探针（2026-08-30，vosalen.com 真实 WP）。

- **运行期调试探针 `?bwpc_debug=1`（仅管理员）**：`render_list()` 在管理员访问且带 `?bwpc_debug=1` 时输出红色诊断面板，暴露真实运行期数据——`BWPC_VERSION`、`has_get_root_ids`、`all_count`、`all_types`（comment_type 分布）、`all_parents`（comment_parent 分布）、`root_ids`、`count_comments`、`per_page`、`cpage`、`opt_page_comments/comments_per_page/default_comments_page`、`query_comments_count`、`rendered_list_empty`、样本评论字段。双重用途：①定位「有计数却无内容」真实根因（尤指评论 `comment_type` 非 `'comment'` 或 `comment_parent` 数据异常）；②作为部署生效试纸——若线上显示该红色面板，证明含 `get_root_ids` 的新代码已真正加载，否则仍跑旧版（OPcache 未失效 / 路径错 / 重复插件目录）。本次仅新增诊断，**未改动任何渲染逻辑**，可安全上线。

## [0.1.9] — UNRELEASED

> P6 实机发现修正（2026-08-30，vosalen.com 真实 WP）。

- **「有计数却无内容」根因修复（P6 实机发现四）**：产品页显示「9 Comments」但列表恒为「No comments yet.」、且分页器出现 1,2,3 空页。根因：列表查询 `query_comments()` 以 `parent => 0` 只取顶层评论，而该产品的 9 条评论**全部为「孤儿回复」**（comment_parent 指向缺失 / 非本产品评论），被 `parent=0` 过滤后列表恒空；标题计数 `count_comments()` 不计 parent 故照常显示 9。修正：重写分页模型——一次性取回本产品全部已批准评论（请求内缓存），以「根评论」= `parent=0` **或** `parent` 不在本产品已批准评论集内的孤儿回复作为分页与展示单位；本页 = 根评论按日期方向切片（`array_slice`）+ 其完整后代（由已取回全集按 `children_map` 递归收集，无额外 DB 查询）；`count_top_level_comments()` 与 `query_comments()` 共用 `get_root_ids()`，计数/列表口径彻底一致，幽灵分页与空列表同时消除。`structure-check` 静态回归守卫同步更新为根评论分页契约（90/90 通过）。**注意**：本改法依赖「评论数据真实存在但 parent 指向缺失」的实机假设；若 vosalen.com 评论确为顶层（parent=0）评论，本版本同样正确展示，无需额外操作。

## [0.1.8] — UNRELEASED

> P6 实机发现修正（2026-08-30，vosalen.com 真实 WP）。

- **幽灵分页页根因修复（P6 实机发现）**：`comment-page-2/3` 无评论内容、但分页器显示 1,2,3（页码一致）——实机根因为线上 `class-comments-renderer.php` 为旧版，`count_top_level_comments()` 计数时漏 `parent=0` 限制，把「顶层评论 + 全部回复」一起计入，致 `max_pages` 虚高、后续页 offset 落到空处。当前仓库计数已限 `parent=0`（与列表查询同口径），本提交进一步将两处查询参数抽取为共用 `top_level_base_args()`，彻底杜绝口径分叉（防漂移）。线上须重新上传 `includes/class-comments-renderer.php` 至本版本方生效；若产品顶层 thread 数 ≤ 每页数，分页器将正确消失（单页线程展示），此为预期正确行为。

## [0.1.7] — UNRELEASED

> P6 实机发现修正（2026-08-30，vosalen.com 真实 WP）。

- **评论分页越界保护（P6 实机发现）**：`query_comments()` 在 `cpage` 越界（缓存陈旧 / rewrite 误解析导致超大页码）时请求空 `offset`，致使 `comment-page-2` 等页「无评论展示」。修正：以 `count_top_level_comments()` 推导 `max_pages`，将 `cpage` 钳制到 `[1, max_pages]`，越界回落末页（与 WP 原生评论分页一致）。本插件分页计数（max_pages 由顶层 thread 总数推导）本身确定性、与页码无关，故「页 1 显示 1,2,3、页 2 仅 1,2」的不一致现象不源自本代码，实机多为分页 URL 的全页缓存陈旧或线上插件文件版本与仓库不一致所致；本提交为防御性加固，消除空列表失败态。

## [0.1.6] — UNRELEASED

> P6 实机发现修正（2026-08-30，vosalen.com 真实 WP）。

- **Reply 内联回复修正（P6 实机发现）**：真实页点击 Reply 触发整页重载（`?replytocom=N#bwpc-respond`）而非原生内联回复。根因：① `templates/comment.php` 的 `comment_reply_link()` 用 `respond_id => 'bwpc-respond'`，但 `comment_form()` 实际包裹层 id 为 `respond`（核心默认，`moveForm()` 据此定位）；② `enqueue_reply_script()` 以 `get_option('thread_comments')` 为闸门，站点未启用该开关时核心 `comment-reply.js` 未入队。修正：`respond_id => 'respond'`；`enqueue_reply_script()` 移除闸门、**无条件** `wp_enqueue_script('comment-reply')`（本插件始终输出 Reply 链接，故始终需要该脚本拦截整页导航）；`title_reply_before` 的 `id` 改回核心预期的 `reply-title` 使内联回复标题切换正常。零自有 JS，复用核心 `comment-reply`（CP1 约束 C5）。O5 步骤 2 分页链接形态已实机确认（`comment-page-2/#comments`）。

## [0.1.5] — UNRELEASED

> 阶段：V1 实现期 P1–P5 已落地（P6 测试与 WP 实机 待做）。⚠️ O5 门禁：原生 cpage 分页须 P6 真实 WP 验证后关闭 O5。

- **P5 分页（OPEN_ITEMS ③，方案 A = 原生 cpage）**：`render_pagination()` 复用 `get_comments_pagenum_link()` 生成 `comment-page-N` / `?cpage=N` 链接，不依赖 `comments_template()` 上下文（陷阱 C）；分页在 `query_comments` 层以 `number`+`offset` 落地，`build_list_html` 不再向 `wp_list_comments` 传 `per_page/page` 避免双重切片；新增 `templates/comments-pager.php`（主题可覆盖，P9）。O5 在真实 WP 验证前仍 BLOCKED。结构自检新增 5 项 P5 原生 cpage 分页断言（现 83/83 全 PASS）。
- **范围守界**：P5 未注册重写规则（方案 A 重写规则须先真实 Page 实机确认，按 O5 门禁 deferred）；未越权 P6。
- **AUDIT-008 局部修正（REJECT — REQUIRED CORRECTION，P5 不回滚、不推翻方案）**：① thread 安全分页——`query_comments` 改以「顶层评论（parent=0）」为分页单位（number+offset 落在顶层），新增 `collect_thread_descendants()` 递归补齐每个顶层 thread 的完整后代子树，杜绝把一条 thread 从父节点切到下一页（WP_Comment_Query 默认 hierarchical=false 不自动补全后代）；分页分母改由 `count_top_level_comments()`（顶层 thread 数）推导。② `per_page()` 实际消费 `page_comments`（分页总开关）与 `default_comments_page`（顶层排序方向，'newest'→DESC）；显式 shortcode 覆盖优先。结构自检新增 5 项 AUDIT-008 契约断言（现 88/88 全 PASS）。版本仍 0.1.5（修正不升版，与 AUDIT-007 惯例一致）。
- **AUDIT-008 Correction Recheck（二次 `REJECT — REQUIRED CORRECTION`，P5 不回滚、不推翻方案）**：`collect_thread_descendants()` 批量取后代误用 `'parent' => $queue`（数组值），违背 WP_Comment_Query 契约（`parent` 仅接受单个 int，数组会被 `$wpdb->prepare('... = %d', ...)` 忽略/报错，导致后代取不回）。修正为 `'parent__in' => $queue`（数组参数）。新增 1 项结构契约断言（88→89）锁住该参数：使用 `parent__in` 且不得出现数组值 `parent`。版本仍 0.1.5；修正锚点建 CHK-011（CHK-009/CHK-010 保留不可变）。

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
