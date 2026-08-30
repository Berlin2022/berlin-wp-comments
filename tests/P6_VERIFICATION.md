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

## 验收判定

| 项 | 通过条件 | 状态 |
|---|---|---|
| O5 | O5 六步全部通过 | BLOCKED（待实机） |
| O8 | 零 gravatar.com 请求实测 | BLOCKED（待实机） |

- O5 + O8 均通过 → 阶段可达 `V1_WP_VERIFIED`，P6 完成。
- 通过后更新：`berlin-wp-comments.php` 注记 + `01_PROJECT/STATUS.md` + `00_STATE/CURRENT_STATE.md`（O5/O8 门禁关闭），并据治理流程建 CHK-014（P6 实机验收锚点；CHK-013 已用于 AUDIT-009 Correction 锚点，不再复用）。
