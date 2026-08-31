# ATTACHMENT-001 — 验证报告（Verification）

- **范围**：`berlin-wp-comments` v0.1.17 源码（commit `1e08854`，未 push）
- **方法**：静态代码审计（对照真实源码 `file:line`），无 live WordPress 运行期实测；标注「需实机」的项为仅靠代码无法 100% 坐实、需在真实 WP 复现的项。
- **判定符号**：✅ PASS（代码可证）／⚠️ PASS\*（成立但有前提或隐式实现）／🔲 GAP（当前未证明，需补代码或架构）

---

## 1. 16 项清单逐条取证

| # | 要求 | 判定 | 证据 / 说明 |
|---|------|------|------|
| 1 | 图片能够随评论提交 | ✅ PASS | `comment_post` 钩子 → `handle_upload()`（`class-bwpc-attachment.php:53` 注册、`103` 实现）；approved 评论读取 `$_FILES['bwpc_comment_attachment']` 入库（`137` `wp_handle_upload`）。表单 `name="bwpc_comment_attachment"` + `enctype="multipart/form-data"`（`class-comment-form.php:106,124`）。 |
| 2 | 非法文件不能上传 | ✅ PASS | `wp_handle_upload(..., ['mimes' => allowed_mimes()])`（`141`）；白名单外 MIME 被核心拒绝返回 `error`，物理文件不落盘（`146-151` 早退）。 |
| 3 | MIME / extension / content validation 有效 | ✅ PASS（核心实施） | `wp_handle_upload` 内部走 `wp_check_filetype_and_ext()`，做**扩展名 + 真实内容嗅探（finfo）**双校验，非仅看扩展名；白名单由 `allowed_mimes()`（`66-80`）注入。验证有效性由 WP 核心保证，本模块未自造校验器。 |
| 4 | 图片数量受限制 | ⚠️ PASS\*（隐式） | 表单为**单文件** `<input type="file">`，无 `multiple` 属性（`class-comment-form.php:124`）→ 每评论至多 1 个附件。限制成立，但**无显式可配计数常量**；若未来要 N 张需加 `multiple` + 循环 + 计数闸门。 |
| 5 | 文件大小受限制 | ✅ PASS | `max_bytes()` 默认 5 MB（`87-90`）；服务端硬校验 `$f['size'] > max_bytes()` 早退（`129`），防前端绕过。另有 `wp_handle_upload` 内部的 `upload_size_limit` 兜底。 |
| 6 | Attachment 能正确关联 Comment | ✅ PASS | `update_comment_meta(comment_id, _bwpc_attachment_id, attach_id)` + `_bwpc_attachment_url`（`175-176`）；读取端 `render_media()` 用 `get_comment_meta(..., META_ATTACHMENT_ID)` 按 `comment_ID` 取回（`218`）。关联键稳定。 |
| 7 | 评论能够正常显示图片 | ✅ PASS | `render_media()` 图片分支输出 `<a>` 包 thumbnail（`236-249`）；`templates/comment.php:60-65` 在内容后输出 `.bwpc-comment__media` 容器，仅当非空。 |
| 8 | 无附件评论仍正常 | ✅ PASS | `render_media()` 无 `aid` 时返回 `''`（`219-221`）；模板 `if ('' !== $html)` 才输出容器（`61`）。无附件评论零额外 DOM。 |
| 9 | Reply 不受影响 | ✅ PASS | 附件以 `comment_ID` 为键，与回复层级无关；回复走核心 `comment-reply.js`（`class-comment-form.php:212` 无条件入队），`Reply` 链接独立（`comment.php:75`）。附件读写不触碰回复逻辑。 |
| 10 | cpage 不受影响 | ⚠️ PASS\* | 分页由渲染器 `query_comments()` 基于 `WP_Comment_Query` + `page_comments` 计算（`class-comments-renderer.php`），附件存于 `comment_meta`，**不进入评论取数 query**，不影响 `per_page` / `cpage` 切片。需实机：跨多页 + 带附件评论确认分页计数无误。 |
| 11 | zero Gravatar 不受影响 | ✅ PASS | P1 本地头像由 `class-avatar.php` 经 `get_avatar_data` + `user_meta attachment_id` 派生（`class-avatar.php:135-286`），与评论附件模块**零交叉**；附件逻辑不触发任何 Gravatar 请求。 |
| 12 | Comment failure 不产生不可控 orphan | 🔲 GAP | 见「缺口」§A。`wp_handle_upload()` 成功后文件已移入 `uploads/`，若随后 `wp_insert_attachment()` 失败（`162-165` 早退），**物理文件成为游离 orphan**（未注册进媒体库、无 comment 关联、无清理）。评论本身已存，故无「评论 orphan」，但存在「文件 orphan」。 |
| 13 | Comment deletion 有明确 Attachment 行为 | ✅ PASS | `cleanup()` 挂在 `deleted_comment` / `trash_comment` / `spam_comment`（`54-56`）；`wp_delete_attachment($aid, true)` 物理删 + `delete_comment_meta` 清两键（`191-198`）。行为明确、三路覆盖。 |
| 14 | Storage Provider 与 Core 解耦 | ⚠️ PASS\* | 「与**评论核心**解耦」成立：评论持久化（`wp_new_comment`）在 `comment_post` 触发前已完成；附件在独立钩子内、任何失败均静默早退（`106-176` 多处 `return`），不阻断评论提交。但存储本身**耦合 WP 核心媒体库**（见 #15）。 |
| 15 | 未来可以替换为 R2 Provider | 🔲 GAP | 见「缺口」§B。当前直接调用 `wp_handle_upload` / `wp_insert_attachment` / `wp_delete_attachment`（`137,162,169,194`），**无 Storage_Provider 抽象层 / 接口**。要换 R2 须改 `handle_upload()` 与 `cleanup()` 内部实现，非「插拔式」。 |
| 16 | 上传失败不会破坏 Comment Core | ✅ PASS | `handle_upload()` 全程 `return` 静默退出（错误码 `124-126`、超限 `129-131`、upload 失败 `146-151`、insert 失败 `163-165`）；评论早已写入，附件失败绝不抛 fatal 或回滚评论。 |

---

## 2. 缺口（GAP）详述

### §A — 插入失败时物理文件游离（对应 #12）
- **位置**：`class-bwpc-attachment.php:137`（`wp_handle_upload` 成功移文件）→ `162-165`（`wp_insert_attachment` 返回 `WP_Error`/`0` 时仅 `return`）。
- **后果**：`uploads/<year>/<month>/` 下多出无媒体库记录、无 comment 关联的孤立文件。频率低（insert 极少失败），但属「不可控 orphan」。
- **修复方向**：`wp_insert_attachment` 失败时调用 `@unlink( $upload['file'] )` 清理物理文件（此时文件尚未生成元数据，安全删除）。约 3 行。

### §B — 无 Storage Provider 抽象（对应 #15）
- **位置**：`handle_upload()` / `cleanup()` 直接耦合 WP 核心媒体库 API。
- **后果**：要切换到 R2 / S3 / 对象存储，必须改模块内部代码，无法「实现接口即替换」。
- **修复方向**：抽出 `interface Bwpc_Attachment_Storage`（`upload(array $file): array` / `register(int $comment_id, array $upload): int` / `delete(int $attach_id): void`），默认提供 `Bwpc_Attachment_Storage_WP` 适配器（封装现有 `wp_handle_upload` 等），未来加 `Bwpc_Attachment_Storage_R2` 即插即用。属架构级改动（约 1 个新文件 + 2 处调用替换），需 CP1 审计。

---

## 3. 验证结论

- **可直接判 PASS**：#1 #2 #3 #5 #6 #7 #8 #9 #11 #13 #16（11 项，代码可证）。
- **PASS\*（需实机复现或属隐式/前提成立）**：#4（单文件隐式上限）#10（分页需实机）#14（与评论核心解耦成立，但与 WP 媒体库耦合）。
- **GAP（未证明，需补）**：#12（插入失败文件 orphan）#15（R2 抽象缺失）。

**整体**：ATTACHMENT-001 当前未完全闭合——2 项硬缺口（#12、#15）。#12 为低风险确定性 bug，建议立即补；#15 为架构可扩展性缺口，是否落地取决于是否真要换 R2。其余 14 项已满足或条件成立。

---

## 4. 建议下一步

1. **立即补 #12**：`handle_upload()` 在 `wp_insert_attachment` 失败分支加物理文件清理（低风险，不改变对外行为）。
2. **确认 #15 范围**：是否真要「可插拔 R2」？若要，引入 `Bwpc_Attachment_Storage` 接口 + WP 适配器（默认实现保持现状）；否则把 #15 标注为「设计目标 / 非当前要求」，ATTACHMENT-001 以 #14 的「与评论核心解耦」口径视为通过。
3. **实机复现**：#10（cpage 带附件跨页）、#4（确认单文件上限在浏览器端生效）建议在 vosalen.com 真实提交 + 翻页验证。
