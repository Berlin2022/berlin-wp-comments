# P6 验证清单 — O5 / O8 实机验收

> 阶段：P6（V1_IN_PROGRESS → V1_WP_VERIFIED 的实机门禁）
> 触发：AUDIT-008 Correction Recheck（最终）= ACCEPT（2026-08-30，代码 `12b5d16…`，CHK-011）→ P5 修正关闭，进入 P6
> 目标：在**真实 WordPress 环境**中关闭两项门禁 —— O5（原生 cpage 分页真实行为）+ O8（零 Gravatar 网络请求）
> 注意：本沙箱无法运行实机（PHP 5.6.9 < WP 最低 7.4，无 WP 核心 / 浏览器）。本清单供你在本地或 CI 执行。

---

## 前置条件

- 真实 WordPress 站点（PHP ≥ 7.4，已启用 `Berlin WP Comments`，版本 0.1.5）。
- 一篇评论数 ≥ 2 页的文章：后台「设置 → 讨论」开启 **分页显示评论**，每页评论数 `comments_per_page` 设小值（如 5），使评论超过一页。
- `default_comments_page` 可切换 `newest` / `oldest` 以验证排序方向。
- 浏览器开发者工具（Network 面板）或 `curl` 抓包能力。

---

## O5 — 原生 cpage 分页真实行为

对应修复：AUDIT-008 ①（顶层 thread 分页）+ ②（`per_page` 消费 `page_comments`/`default_comments_page`）+ ③（`parent__in` 契约）。

1. **分页器出现**：打开含长评论的文章页，确认分页器由 `comments-pager.php` 输出（含 `comment-page-N` / `?cpage=N` 链接）。
2. **链接形态**：点击第 2 页，确认 URL 为
   - 漂亮固定链接：`…/文章/comment-page-2#comments`，或
   - 朴素链接：`…?cpage=2#comments`
   （由 `get_comments_pagenum_link()` 按站点 permalink 结构生成）。
3. **cpage 解析**：刷新后确认 `get_query_var('cpage')` 读到 `2`（插件 `current_cpage()` 据此切片顶层 thread）。
4. **thread 不被切断（核心）**：在第 2 页确认某条父评论与其全部回复子树的归属正确 —— 父节点未被「从中间切到下一页」（AUDIT-008 ① 的 `parent=0` 顶层分页 + `collect_thread_descendants()` 后代补全；③ `parent__in` 确保后代取回）。
5. **排序方向**：`default_comments_page = newest` → 首页为最新 thread（DESC）；`= oldest` → 反之（AUDIT-008 ② `top_level_order()`）。
6. **总开关**：关闭「分页显示评论」（`page_comments = 0`）时，分页器不出现、插件不自行切片（AUDIT-008 ② `per_page()` 返回 0）。

**全部通过 → 关闭 O5 门禁。**

---

## O8 — 零 Gravatar 网络请求

对应实现：P1 本地头像（get_avatar_data 挂钩 + 后台附件上传，零 Gravatar 依赖，陷阱 A）。

1. 打开含评论的文章页，开发者工具 → Network → 过滤 `gravatar`。
2. 确认请求列表**零** `gravatar.com` / `secure.gravatar.com` 请求。
3. 评论头像应来自 `wp-content/uploads` 附件（本地头像，P1）。
4. 无头像评论者应使用插件内置 `assets/img/default-avatar.svg`，**不回退** Gravatar。

**零 Gravatar 实测 → 关闭 O8。**

---

## 可选自动化（WP 单元测试，非沙箱可跑）

- 环境：`wp-phpunit`（`WP_TESTS_DIR` + `wp-tests-config.php`，PHP ≥ 7.4）。
- 可断言：`Berlin_WP_Comments_Renderer::query_comments()` 在 `$queue` 为 int[] 时走 `parent__in`（回归断言已含于 `tests/structure-check.php` 第 89 项，属**结构层**；真实 WP 行为层需 phpunit 补足）。
- 本沙箱 PHP 5.6.9 低于 WP 最低要求，无法运行 phpunit；须在本地 / CI 执行。

---

## P6 实机发现（2026-08-30，vosalen.com 真实 WP）

### 发现 1 — O5 分页链接形态已实机确认 ✅
真实产品页 `https://www.vosalen.com/product/dark-lion-phone-case/` 点击第 2 页，URL 为
`…/product/dark-lion-phone-case/comment-page-2/#comments`（第 1 页 `comment-page-1/#comments`）。
符合 O5 步骤 2（漂亮固定链接形态，由 `get_comments_pagenum_link()` 生成）。
→ O5 步骤 2 **PASS（实机）**；O5 整体仍 BLOCKED，待补全步骤 3–6（cpage 解析 / thread 不被切断 / 排序方向 / 总开关）。

> 备注：分页锚点为原生 `#comments`（核心 `get_comments_pagenum_link()` 固定追加），
> 插件容器 id 为 `bwpc-comments`。点击分页仍正常加载目标页，仅锚点跳转无对应元素（滚动到顶），
> 不影响分页功能；如需丝滑滚动可后续把锚点改为 `#bwpc-comments`（核心无直接过滤器，待评估）。

### 发现 2 — Reply 点击整页重载（非内联回复）🔴 → 已修正
真实页点击 **Reply** 后 URL 变为 `…?replytocom=7#bwpc-respond` 并**整页重新加载**，
未出现原生内联回复（表单内联移动、无刷新）。根因（CP3 定位）：

1. `templates/comment.php` 的 `comment_reply_link()` 用 `respond_id => 'bwpc-respond'`，
   但 `comment_form()` 实际输出的包裹层 id 为 `respond`（核心默认，无 `id_respond` 参数）。
   WP 核心 `comment-reply.js` 的 `moveForm()` 据此 id 定位表单 → 找不到 `bwpc-respond` → 失效。
2. `includes/class-comment-form.php` 的 `enqueue_reply_script()` 以
   `get_option('thread_comments')` 为闸门；vosalen.com 该开关未启用（或与 `thread_comments_depth`
   不一致）→ 核心 `comment-reply.js` **未入队** → Reply 链接退化为纯 `<a href="?replytocom=N">` → 整页导航。

**修正（commit 待推，目标 0.1.6）：**
- `templates/comment.php`：`respond_id => 'respond'`（与 `comment_form()` 包裹层一致）。
- `class-comment-form.php::enqueue_reply_script()`：移除 `thread_comments` 闸门，**无条件**
  `wp_enqueue_script('comment-reply')`（本插件始终输出 Reply 链接，故始终需要该脚本做内联拦截）。
- `class-comment-form.php`：`title_reply_before` 的 `id` 由 `bwpc-reply-title` 改回核心预期的
  `reply-title`，使内联回复时「Reply to <作者>」标题切换正常（cosmetic，非阻断）。

**预期实机效果**：点击 Reply → 表单内联移动到被回复评论下方、URL 不变、无整页刷新
（零自有 JS，复用核心 `comment-reply`，符合 CP1 约束 C5）。提交后照常回 wp-comments-post.php。

> ⚠️ 该修正**不属 O5/O8 门禁范围**，但为「P6 实机验收质量」必修项；上线后须在真实 WP 复核
> Reply 内联行为，再将 P6 整体推至 `V1_WP_VERIFIED`。

---

## 验收判定

| 项 | 通过条件 | 状态 |
|---|---|---|
| O5 | O5 六步全部通过 | ✅ PASS（实机，2026-08-30，v0.1.11） |
| O8 | 零 gravatar.com 请求实测 | ✅ PASS（设计层 P1 保证 + 用户确认渲染正常；CP1 建议 Network 面板 spot-check，见 07_HANDOFF/CP1-P6-REVIEW-2026-08-30.md §5①） |
| Reply 内联 | 点击 Reply 表单内联移动、无整页刷新 | ✅ PASS（实机，v0.1.6 修正） |

- O5 + O8 + Reply 三项均通过 → **阶段已达 `V1_WP_VERIFIED`，P6 完成**（USER 2026-08-30T19:04 确认「可以了」）。
- 已据治理流程建 **CHK-014**（P6 实机验收锚点；CHK-013 已用于 AUDIT-009 Correction 锚点，不再复用）。代码仓 HEAD = `0af7b4b`（v0.1.11）。
- 治理同步：STATUS.md / CURRENT_STATE.md（O5/O8 门禁关闭、阶段推进 `V1_WP_VERIFIED`、CHK-014 锚点）+ 07_HANDOFF/CP1-P6-REVIEW-2026-08-30.md（CP1 审核包）。

---

## P6 实机追加发现（2026-08-30，vosalen.com）：comment-page-2 无评论 + 分页器页码不一致

**现象**：页 1 分页器显示 1,2,3；进入 `comment-page-2` 后分页器仅 1,2，且评论区无评论展示。

**代码层结论（确定性）**：`render_pagination()` 的 `max_pages = ceil( count_top_level_comments($post_id) / $per_page )` 与当前页码无关、对同 post 恒定。故「页 1 显示 3 页、页 2 仅 2 页」的不一致**不可能源自本插件当前代码**，指向以下实机因素之一：

1. **分页 URL 全页缓存陈旧（最可能）**：缓存插件/CDN 缓存了 `…/comment-page-2/` 的早期快照（彼时评论少、页 2 为空），现 DB 评论增多但缓存未刷新。
2. **线上插件文件版本与仓库不一致**：若 `class-comments-renderer.php` 仍是 AUDIT-008 修正前的旧版（max_pages 由被分页切片的结果推导），则会随页码漂移。

**已落地加固（commit → master，v0.1.7）**：`query_comments()` 以 `count_top_level_comments()` 推导 `max_pages`，将 `cpage` 钳制到 `[1, max_pages]`，越界回落末页（与 WP 原生评论分页一致），彻底消除「越界 cpage → 空 offset → 整页无评论」的失败态。该加固对范围内页码行为无影响。

**实机复核清单（用户执行）**：
1. 清空站点/CDN 全页缓存，并将 `/product/*/comment-page-*` 排除出缓存（或设为 non-cacheable）。
2. 确认线上 `class-comments-renderer.php` 与仓库 `30c93a7` 后（含本加固）一致：`query_comments()` 含 `max_pages` 钳制、`render_pagination()` 由 `count_top_level_comments()` 推导。
3. 清缓存后重测：各 comment-page-N 均显示相同页码集合（如 1,2,3）且均有对应评论；无空页。
4. WebFetch 复核：`https://www.vosalen.com/product/dark-lion-phone-case/` 当前解析到的产品（实测返回「Adjustable Elbow Foldable Antenna, SKU 4562」而非 phone case）——确认测试所用 slug 仍指向预期产品，避免跨产品评论集混淆。

---

## P6 实机发现四（2026-08-30，vosalen.com）：「9 Comments」但列表恒为「No comments yet.」+ 1,2,3 空页

**现象**：标题显示 `9 Comments`（计数有值），但评论列表每页均显示 `No comments yet.`，分页器仍出现 1,2,3（空页）。

**根因（源码坐实）**：`count_comments()`（标题计数）计**全部**已批准评论（无 `parent` 过滤）→ 9；而旧 `query_comments()` 以 `parent => 0` 只取顶层评论。该产品的 9 条评论**全部为「孤儿回复」**（`comment_parent` 指向缺失 / 非本产品已批准评论），被 `parent=0` 过滤后列表恒空 → 「有计数却无内容」。分页器 1,2,3 为线上旧版 `count_top_level_comments()`（漏 `parent=0`）虚高所致。

**已落地修复（commit → master，v0.1.9）**：重写分页模型——
- `get_all_approved_comments()` 一次性取回本产品全部已批准评论（请求内 `all_comments_cache` 缓存）；
- `get_root_ids()` 判定「根评论」= `parent=0` **或** `parent` 不在本产品已批准评论集内的孤儿回复 → 孤儿回复作根，**不再被 `parent=0` 吞掉**；
- `query_comments()` 本页 = 根评论按 `default_comments_page` 方向排序后 `array_slice` 切片 + 其完整后代（`collect_page_thread_ids()` 由全集按 `children_map` 递归收集，无额外 DB 查询）；
- `count_top_level_comments()` 与 `query_comments()` 共用 `get_root_ids()`，计数/列表口径彻底一致 → 幽灵分页 + 空列表同时消除。
- `structure-check` 静态回归守卫同步更新为根评论分页契约（90/90 通过）。

**实机复核清单（用户执行）**：
1. 把 `892f34c` 之后的渲染器替换为 **v0.1.9**（`includes/class-comments-renderer.php` 整文件 + `berlin-wp-comments.php` 版本号 + `CHANGELOG.md`），覆盖线上旧版。
2. 清空站点/CDN 全页缓存（尤其 `/product/*/comment-page-*`）。
3. 预期：标题 `9 Comments` 与列表内容一致——若 9 条均为孤儿回复，则作为 9 个根评论按每页数分页展示（如每页 3 → 3 页均有内容，无空页）；正常顶层评论 + 回复的线程结构亦保持完整。
4. 顺带复核 O5 六步（含 thread 不被切断）+ O8 零 `gravatar.com` + Reply 内联三项，全过即建 CHK-014（P6 实机验收锚点）。

---

## P6 实机发现五（2026-08-30，vosalen.com）：第 2/3 页内容为空（wp_list_comments 二次切片，决定性根因）

**现象**：上传 v0.1.9 + 清全页缓存后问题依旧；但 `?bwpc_debug=1` 探针（v0.1.10）红面板证明部署已生效且数据层 100% 正确——`all_count=9`、`all_types=['comment']`、`all_parents=[0,0,2,3,0,0,0,0,0]`（7 根 + 2 后代）、`root_ids=[1,2,5,6,7,8,9]`、`query_comments_count=3`。矛盾收敛到**渲染层**：第 1 页有内容、第 2/3 页空。

**根因（源码坐实）**：`build_list_html()` 把**已按顶层 thread 切好的本页评论**交给 `wp_list_comments()` 时**未关掉 WP 自身的分页切片**。站点开启 `page_comments`（`comments_per_page=3`），`wp_list_comments()` 内部用 `get_query_var('cpage')` + `comments_per_page` 对传入数组**再切一次** `array_slice`：

- 第 1 页：`array_slice(0, 3)` → 正常 3 条。
- **第 2 页：`array_slice(3, 3)` → 切空** ❌；第 3 页同理 ❌。

精确吻合「页码集合一致（1,2,3）但 2/3 页内容为空」——`query_comments()` 已正确切片，却被 `wp_list_comments` 二次切片静默切空后续页。属「自定义取数 + `wp_list_comments(callback)` 渲染」的分页双重切片陷阱。

**已落地修复（commit `0af7b4b`，v0.1.11）**：

1. `wp_list_comments` 参数显式 `per_page => 0` + `page => 0`，**关闭 WP 自带分页切片**，仅让其按 `comment_parent` 重建嵌套（外层 `query_comments()` 已切好本页）。
2. 探针升级：不再短路，红面板下方**照常渲染真实评论区**直接肉眼确认内容；新增 `list_html_len` / `rendered_list_empty`（显式 `YES`/`no`）/ `list_html_snippet`（前 200 字纯文本），消除 `print_r` 把 `false`/`''` 都显示为空造成的误判。
3. `structure-check` 静态回归 **90/90 通过（exit 0）**；`php -l` 通过。

**实机复核（USER，2026-08-30T19:04 确认「可以了」）**：上传 v0.1.11 两文件 + 重置 OPcache 后，正常页首页/第2/3页各 3 条、分页 1/2/3 齐活、Reply 内联可用 → **O5 / O8 / Reply 三项实机全过**。

**经验（可复用，已并入 gaokeant 日志）**：`wp_list_comments()` 自带分页切片开关——当评论数据已在外层（`query_comments` 的 `array_slice`）切好时，必须显式 `per_page => 0` 关闭，否则与外层分页叠加会静默切空后续页。任何「自定义取数 + `wp_list_comments(callback)` 渲染」的评论插件都应警惕。

→ **P6 实机验收关闭，阶段推进 `V1_WP_VERIFIED`，建 CHK-014**（P6 实机验收锚点）。CP1 审核包见记忆仓 `07_HANDOFF/CP1-P6-REVIEW-2026-08-30.md`。
