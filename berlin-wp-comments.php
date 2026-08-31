<?php
/**
 * Plugin Name:       Berlin WP Comments
 * Plugin URI:        https://github.com/Berlin2022/berlin-wp-comments
 * Description:       Minimal WordPress native comments enhancer — Shortcode + local avatars + native comments. WordPress owns the data and lifecycle; this plugin only handles presentation and avatars.
 * Version:           0.1.19
 * Requires at least: 6.0
 * Requires PHP:      7.0
 * Author:            Berlin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       berlin-wp-comments
 * Domain Path:       /languages
 *
 * @package Berlin_WP_Comments
 *
 * ---------------------------------------------------------------------------
 * 版本声明
 * ---------------------------------------------------------------------------
 * V1_RELEASED（v0.1.11 已发布；v0.1.12 内置 B2B 视觉主题 SI-001 并入核心；v0.1.13 落地预留的评论附件完整链路 + 分页可见性修复 + P3 表单契约细化）。P1 本地头像已实现；P2 评论渲染器 + 模板已实现；P3 评论表单已实现；P4 短代码 + 条件资源已实现；P5 原生 cpage 分页已实现并经 AUDIT-008 局部修正（thread 安全分页 + per_page 消费 page_comments/default_comments_page）+ AUDIT-008 Correction Recheck（最终）= ACCEPT（P5 修正关闭，2026-08-30）；P6 实机验证 O5/O8/Reply 已 PASS（vosalen.com 真实 WP，CHK-014 = STABLE，AUDIT-010 = ACCEPT，2026-08-30）。0.1.6：修正 Reply 点击整页重载 → 原生内联回复（respond_id 对齐核心包裹层 + 无条件入队 comment-reply 脚本；P6 实机发现 2026-08-30）。0.1.12：SI-001 视觉主题（Made-in-China/Alibaba B2B 风格）并入核心，由 enqueue_assets() 在 comments.css 后自动加载。0.1.13：评论附件完整链路落地（v0.1.12 CHANGELOG 明确标注「为将来预留」）—— `class Bwpc_Comment_Attachment`（`comment_post` 接管 + 媒体库入库 + 3 路生命周期清理）+ `render_media()` 静态助手 + 模板输出 `.bwpc-comment__media`；分页非当前页边框色 `#D1D5DB` 强化视觉对比；P3 表单契约细化（自渲染后，旧 P3 契约「调用 comment_form / 不自造 form」不再适用，必须明确为「自渲染 + action=/wp-comments-post.php + id=respond + 保留 do_action + 不自造 nonce」）；静态自检 90 → 102。
 *
 * 架构：WordPress 负责数据与生命周期，本插件负责呈现与头像（CP1 决策 D3/D4）。
 * 已落地：
 *   - P1 本地头像：get_avatar_data 挂钩 + 后台上传字段（user_meta attachment_id），
 *     零 Gravatar 请求（陷阱 A 处理）。
 *   - P2 评论渲染器 + 模板：WP_Comment_Query 取数 → wp_list_comments(callback) 走自有
 *     模板（templates/comment.php + comments.php）；模板覆盖顺序 子主题→父主题→插件
 *     （P9）；不使用 comments_template()（陷阱 C）。
 * 待实现：P6 实机验证 O5/O8（⚠️ O5 门禁：原生 cpage 分页（comment-page-N 固定链接 + ?cpage=N 解析）须真实 WP 验证后关闭 O5；O8 零 Gravatar 网络请求须真实浏览器实测）。AUDIT-008 局部修正已落实（CHK-010，①②）；经 AUDIT-008 Correction Recheck `REJECT — REQUIRED CORRECTION` 发现 collect_thread_descendants 误用数组 parent（违背 WP_Comment_Query 契约），已由 CP3-015 修正（CHK-011：parent__in 修正 + 结构自检 89/89 断言）；AUDIT-008 Correction Recheck（最终）= ACCEPT（P5 修正关闭，2026-08-30），进入 P6（实机验证 O5/O8）。P6 验证清单见 tests/P6_VERIFICATION.md。
 * 各阶段进度见记忆仓 03_PLAN/CP2/CP2-001.md。
 *
 * ⚠️ 激活即生效：P1 改变全站头像来源（指向本地）。如需回退，停用本插件即可。
 * ---------------------------------------------------------------------------
 *
 * License：GPL-2.0-or-later（已由 USER 于 2026-08-27T17:45 最终裁定，见 OPEN_ITEMS ⑥）。
 * LICENSE 文件作为发布物料在适当阶段落地，不与 WP.org 发布目标绑定（AUDIT-005 C1）。
 */

// 阻止直接访问。
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 最低 PHP 版本守卫。
 *
 * 低于 PHP 7.0 时（例如主文件早先用到的 static 闭包在旧版本是解析错误），
 * 给出可读的后台提示并安全退出，避免白屏致命（fatal error）。
 */
if ( PHP_VERSION_ID < 70000 ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p><strong>Berlin WP Comments</strong> 需要 PHP 7.0 或更高版本，当前服务器运行的是 PHP ' . esc_html( PHP_VERSION ) . '。请升级服务器 PHP 版本后再启用本插件。</p></div>';
		}
	);
	return;
}

/**
 * 插件常量。
 */
define( 'BWPC_VERSION', '0.1.19' );
define( 'BWPC_PLUGIN_FILE', __FILE__ );
define( 'BWPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BWPC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * 主 shortcode 标签（canonical）。
 *
 * O1 裁定（OPEN_ITEMS ① CLOSED）：主标签 `[berlin_comments]`，
 * 别名 `[wp_comments]` 保留以兼容既有内容（向后兼容，不静默覆盖他人）。
 * 改名于 P4 落地（AUDIT-006 recheck 后 P4 放行）。
 */
define( 'BWPC_SHORTCODE', 'berlin_comments' );

/**
 * Shortcode 别名（向后兼容）。
 *
 * 兼容 CP1 早期指定的 `[wp_comments]` 用法；与 canonical 共用同一处理器。
 */
define( 'BWPC_SHORTCODE_ALIAS', 'wp_comments' );

/**
 * 载入插件类。
 *
 * 骨架期使用显式 require，不引入 Composer 自动加载——
 * 保持零依赖（P6 轻量原则）。
 */
if ( ! class_exists( 'Berlin_WP_Comments_Plugin' ) ) {
	require_once BWPC_PLUGIN_DIR . 'includes/class-plugin.php';
}
if ( ! class_exists( 'Berlin_WP_Comments_Avatar' ) ) {
	require_once BWPC_PLUGIN_DIR . 'includes/class-avatar.php';
}
if ( ! interface_exists( 'Bwpc_Attachment_Storage' ) ) {
	require_once BWPC_PLUGIN_DIR . 'includes/class-bwpc-attachment-storage.php';
}
if ( ! class_exists( 'Bwpc_Comment_Attachment' ) ) {
	require_once BWPC_PLUGIN_DIR . 'includes/class-bwpc-attachment.php';
}
if ( ! class_exists( 'Berlin_WP_Comments_Renderer' ) ) {
	require_once BWPC_PLUGIN_DIR . 'includes/class-comments-renderer.php';
}
if ( ! class_exists( 'Berlin_WP_Comments_Form' ) ) {
	require_once BWPC_PLUGIN_DIR . 'includes/class-comment-form.php';
}
if ( ! class_exists( 'Berlin_WP_Comments_Shortcode' ) ) {
	require_once BWPC_PLUGIN_DIR . 'includes/class-comments-shortcode.php';
}

/**
 * 取插件主实例。
 *
 * @return Berlin_WP_Comments_Plugin
 */
function bwpc() {
	return Berlin_WP_Comments_Plugin::instance();
}

// 启动。挂在 plugins_loaded 以确保 WP 与其他插件均已就绪。
add_action( 'plugins_loaded', 'bwpc' );

/**
 * 激活钩子。
 *
 * TODO[D5]：如需注册重写规则（分页方案取决于 OPEN_ITEMS ③ 的裁定），
 * 在此 flush_rewrite_rules()。骨架期不做任何事。
 */
register_activation_hook(
	__FILE__,
	function () {
		// 骨架期：无操作。不建表、不写选项、不改重写规则。
	}
);

/**
 * 停用钩子。
 *
 * 重要（P1/P2）：停用绝不触碰评论数据——评论属于 WordPress，不属于本插件。
 */
register_deactivation_hook(
	__FILE__,
	function () {
		// 骨架期：无操作。
	}
);
