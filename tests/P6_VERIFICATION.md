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
| O5 | O5 六步全部通过 | BLOCKED（待实机） |
| O8 | 零 gravatar.com 请求实测 | BLOCKED（待实机） |

- O5 + O8 均通过 → 阶段可达 `V1_WP_VERIFIED`，P6 完成。
- 通过后更新：`berlin-wp-comments.php` 注记 + `01_PROJECT/STATUS.md` + `00_STATE/CURRENT_STATE.md`（O5/O8 门禁关闭），并据治理流程建 CHK-014（P6 实机验收锚点；CHK-013 已用于 AUDIT-009 Correction 锚点，不再复用）。

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
