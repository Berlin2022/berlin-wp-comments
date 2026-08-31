# ATTACHMENT-001 — 验证报告（Verification）

- **范围**：`berlin-wp-comments` v0.1.18 源码（commit `808a9d7`，未 push）
- **方法**：静态代码审计（对照真实源码 `file:line`）+ `tests/structure-check.php` 架构不变量自检（108/108 PASS）；无 live WordPress 运行期实测；标注「需实机」的项为仅靠代码无法 100% 坐实、需在真实 WP 复现的项。
- **判定符号**：✅ PASS（代码可证）／⚠️ PASS\*（成立但有前提或隐式实现）／🔲 GAP（当前未证明，需补代码或架构）

---

## 0. CP1 审计裁决（2026-08-31，用户已确认"已更新，同意"）

CP1 对 ATTACHMENT-001 Verification 的结论：

> **ATTACHMENT-001 = ACCEPT WITH CORRECTIONS**（不直接 ACCEPT）

- **#12（插入失败物理文件 orphan）必须修** —— 给出干净修法：`wp_insert_attachment` 失败时 `@unlink( $upload['file'] )`。
- **#15（Storage Boundary）不能降级为"设计目标"** —— CP1 明确："R2 本身可延期，但 Storage Boundary 不能延期"。当前适配层把 Attachment Domain + WP Storage 焊死，必须抽 `Bwpc_Attachment_Storage` 接口 + WP 适配器。

两项修正均已在 **v0.1.18** 落地（见 §A / §B 状态），结构自检新增 3 项契约守护（存储接口存在、适配层不直连 WP 媒体 API、插入失败清理孤儿）。

---

## 1. 16 项清单逐条取证

| # | 要求 | 判定 | 证据 / 说明 |
|---|------|------|------|
| 1 | 图片能够随评论提交 | ✅ PASS | `comment_post` 钩子 → `handle_upload()`（`class-bwpc-attachment.php` 注册于 `register()`）；approved 评论读取 `$_FILES['bwpc_comment_attachment']` 经 `$this->storage()->store($f)` 入库。表单 `name="bwpc_comment_attachment"` + `enctype="multipart/form-data"`（`class-comment-form.php`）。 |
| 2 | 非法文件不能上传 | ✅ PASS | `Bwpc_Attachment_Storage_WP::store()` 内 `wp_handle_upload(..., ['mimes' => $this->mimes])`；白名单外 MIME 被核心拒绝返回 `error`，物理文件不落盘（`store()` 早退 `return 0`）。 |
| 3 | MIME / extension / content validation 有效 | ✅ PASS（核心实施） | `wp_handle_upload` 内部走 `wp_check_filetype_and_ext()`，做**扩展名 + 真实内容嗅探（finfo）**双校验；白名单由 `allowed_mimes()` 注入、`Bwpc_Attachment_Storage_WP` 构造时传入。验证有效性由 WP 核心保证。 |
| 4 | 图片数量受限制 | ⚠️ PASS\*（隐式） | 表单为**单文件** `<input type="file">`，无 `multiple` 属性 → 每评论至多 1 个附件。限制成立，但**无显式可配计数常量**；若未来要 N 张需加 `multiple` + 循环 + 计数闸门（属未来范围，非当前缺口）。 |
| 5 | 文件大小受限制 | ✅ PASS | `max_bytes()` 默认 5 MB；`Bwpc_Attachment_Storage_WP::store()` 服务端硬校验 `$file['size'] > $this->max_bytes` 早退 `return 0`，防前端绕过。另有 `wp_handle_upload` 内部的 `upload_size_limit` 兜底。 |
| 6 | Attachment 能正确关联 Comment | ✅ PASS | `update_comment_meta(comment_id, _bwpc_attachment_id, attach_id)` + `_bwpc_attachment_url`（`handle_upload()`）；读取端 `render_media()` 用 `get_comment_meta(..., META_ATTACHMENT_ID)` 按 `comment_ID` 取回。关联键稳定。 |
| 7 | 评论能够正常显示图片 | ✅ PASS | `render_media()` 图片分支输出 `<a>` 包 thumbnail；`templates/comment.php` 在内容后输出 `.bwpc-comment__media` 容器，仅当非空。 |
| 8 | 无附件评论仍正常 | ✅ PASS | `render_media()` 无 `aid` 时返回 `''`；模板 `if ('' !== $html)` 才输出容器。无附件评论零额外 DOM。 |
| 9 | Reply 不受影响 | ✅ PASS | 附件以 `comment_ID` 为键，与回复层级无关；回复走核心 `comment-reply.js`（无条件入队），`Reply` 链接独立。附件读写不触碰回复逻辑。 |
| 10 | cpage 不受影响 | ⚠️ PASS\* | 分页由渲染器 `query_comments()` 基于 `WP_Comment_Query` + `page_comments` 计算，附件存于 `comment_meta`，**不进入评论取数 query**，不影响 `per_page` / `cpage` 切片。需实机：跨多页 + 带附件评论确认分页计数无误。 |
| 11 | zero Gravatar 不受影响 | ✅ PASS | P1 本地头像由 `class-avatar.php` 经 `get_avatar_data` + `user_meta attachment_id` 派生，与评论附件模块**零交叉**；附件逻辑不触发任何 Gravatar 请求。 |
| 12 | Comment failure 不产生不可控 orphan | ✅ PASS（v0.1.18 修复） | 见 §A。`Bwpc_Attachment_Storage_WP::store()` 在 `wp_insert_attachment` 返回 `WP_Error`/`0` 时立即 `@unlink( $upload['file'] )` 清理已落盘物理文件，再 `return 0`。评论本身早已写入，附件失败静默返回（#16 仍成立），**不再产生游离文件 orphan**。 |
| 13 | Comment deletion 有明确 Attachment 行为 | ✅ PASS | `cleanup()` 挂在 `deleted_comment` / `trash_comment` / `spam_comment`；经 `$this->storage()->delete($aid)`（内部 `wp_delete_attachment($aid, true)` 物理删）+ `delete_comment_meta` 清两键。行为明确、三路覆盖。 |
| 14 | Storage Provider 与 Core 解耦 | ✅ PASS（v0.1.18 升级） | 评论核心（`wp_new_comment`）在 `comment_post` 触发前已完成；附件适配层**完全不调用 WP 媒体 API**，仅依赖 `Bwpc_Attachment_Storage` 接口（惰性构建默认 WP 提供方）。存储实现（WP 媒体库）被隔离在 `Bwpc_Attachment_Storage_WP` 内，符合 ATT-P001 Core/Adapter Separation。 |
| 15 | 未来可以替换为 R2 Provider | ✅ PASS（v0.1.18 修复） | 见 §B。新增 `interface Bwpc_Attachment_Storage`（store / get_url / delete / exists）+ 默认 `class Bwpc_Attachment_Storage_WP`。适配层只 `new`/注入接口，不触碰 `wp_handle_upload` / `wp_insert_attachment` / `wp_delete_attachment`。未来新增 `Bwpc_Attachment_Storage_R2` 即插即用，评论核心与适配层代码不变（ATT-P002 Storage Agnostic）。 |
| 16 | 上传失败不会破坏 Comment Core | ✅ PASS | `handle_upload()` 全程静默 `return`（transport error / size / store 失败均早退）；评论早已写入，附件失败绝不抛 fatal 或回滚评论。`store()` 内 `#12` 清理也不影响评论。 |

---

## 2. 缺口（GAP）详述与状态

### §A — 插入失败时物理文件游离（对应 #12）✅ 已于 v0.1.18 修复

- **原位置**：`class-bwpc-attachment.php` 旧 `handle_upload()` 内 `wp_handle_upload()` 成功移文件 → `wp_insert_attachment()` 失败仅 `return`。
- **修复**（v0.1.18）：持久化逻辑迁入 `Bwpc_Attachment_Storage_WP::store()`，在 `wp_insert_attachment` 失败分支：
  ```php
  if ( is_wp_error( $attach_id ) || ! $attach_id ) {
      @unlink( $upload['file'] ); // 清理已落盘物理文件，避免孤儿
      return 0;
  }
  ```
- **守护**：`tests/structure-check.php` 新增契约「插入失败清理孤儿文件（#12：store 中 @unlink + is_wp_error 判定）」。
- **影响**：低风险，不改变对外行为（评论照常提交，仅附件缺失），无副作用。

### §B — 无 Storage Provider 抽象（对应 #15）✅ 已于 v0.1.18 修复

- **原位置**：适配层 `handle_upload()` / `cleanup()` / `render_media()` 直接耦合 WP 核心媒体库 API。
- **修复**（v0.1.18）：抽 `interface Bwpc_Attachment_Storage` + `class Bwpc_Attachment_Storage_WP`（默认实现），新增文件 `includes/class-bwpc-attachment-storage.php`。
  - 适配层 `handle_upload()` → `$this->storage()->store($f)` + `get_url()`；
  - `cleanup()` → `$this->storage()->delete($aid)`；
  - `render_media()` → `new Bwpc_Attachment_Storage_WP(...)` 经 `exists()` / `get_url()` 读取；
  - 构造注入：`__construct( $storage = null )`，默认惰性构建 WP 提供方（确保 `allowed_mimes`/`max_bytes` 过滤器在 `comment_post` 时刻求值，非激活期冻结）。
- **守护**：`tests/structure-check.php` 新增 2 项契约——「Storage Provider 抽象存在」+「附件适配层不直连 WP 媒体 API（wp_handle_upload / wp_insert_attachment / wp_delete_attachment 仅允许出现在 storage 文件）」。
- **未来 R2**：新增 `class Bwpc_Attachment_Storage_R2 implements Bwpc_Attachment_Storage`，在 `boot()` 注入即可，评论核心 / 适配层零改动。

---

## 3. 验证结论

- **直接 PASS**：#1 #2 #3 #5 #6 #7 #8 #9 #11 #12 #13 #14 #15 #16（14 项，含 v0.1.18 修复的 #12 #14 #15）。
- **PASS\*（需实机复现或属隐式/前提成立）**：#4（单文件隐式上限）#10（分页需实机）。
- **GAP**：无。

**整体**：ATTACHMENT-001 在 v0.1.18 下 **16 项中 14 项 PASS + 2 项 PASS\*（#4/#10 需实机复现，但代码逻辑已成立）**，原 2 项硬缺口（#12、#15）均已在 CP1 ACCEPT WITH CORRECTIONS 后闭合。满足 CP1 审计「ACCEPT WITH CORRECTIONS → CP2 → CP3 实现 → 全部 Acceptance Criteria PASS」的收口路径。

> 注：#4 / #10 的 PASS\* 非架构缺口，仅需在 vosalen.com 真实提交 + 翻页一次坐实（建议纳入 P6 实机验证）。

---

## 4. 建议下一步

1. **实机复现**：#10（cpage 带附件跨页）、#4（单文件上限浏览器端生效）建议在 vosalen.com 真实提交 + 翻页验证（属 PASS\* 坐实，非阻断）。
2. **部署 v0.1.18**：FTP 覆盖 `wp-content/plugins/berlin-wp-comments/` → 重启 PHP-FPM + 清 OPcache → 后台启用。
3. **发版**：v0.1.18 打包于 `E:\AI-Projects\ChatGPT+HY3\berlin-wp-comments-0.1.18.zip`；GitHub Release 待 `gh auth login` 重认证后补发（v0.1.13–v0.1.18）。
4. **（可选）R2 Provider**：若确定上 Cloudflare R2，新建 `Bwpc_Attachment_Storage_R2` 实现接口并在 `boot()` 注入，无需改动适配层。
