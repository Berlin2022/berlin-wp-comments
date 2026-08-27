<?php
/**
 * Plugin Name:       Berlin WP Comments
 * Plugin URI:        https://github.com/Berlin2022/berlin-wp-comments
 * Description:       极简 WordPress 原生评论增强插件 —— Shortcode + 本地头像 + 原生评论。WordPress 负责数据与生命周期，本插件只负责呈现与头像。
 * Version:           0.1.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
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
 * V1_IN_PROGRESS（P1 本地头像已实现；P2 评论渲染器 + 模板已实现；P3 评论表单已实现）。
 *
 * 架构：WordPress 负责数据与生命周期，本插件负责呈现与头像（CP1 决策 D3/D4）。
 * 已落地：
 *   - P1 本地头像：get_avatar_data 挂钩 + 后台上传字段（user_meta attachment_id），
 *     零 Gravatar 请求（陷阱 A 处理）。
 *   - P2 评论渲染器 + 模板：WP_Comment_Query 取数 → wp_list_comments(callback) 走自有
 *     模板（templates/comment.php + comments.php）；模板覆盖顺序 子主题→父主题→插件
 *     （P9）；不使用 comments_template()（陷阱 C）。
 * 待实现：P4 短代码 / P5 分页 / P6 测试与 WP 实机。
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
 * 插件常量。
 */
define( 'BWPC_VERSION', '0.1.3' );
define( 'BWPC_PLUGIN_FILE', __FILE__ );
define( 'BWPC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BWPC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * 主 shortcode 标签。
 *
 * CP1 立项文件指定 `[wp_comments]`，此处按 CP1 原文实现，未擅自改名。
 *
 * ⚠️ 已知风险：`wp_` 前缀属通用命名空间，与其他插件/主题碰撞时
 * add_shortcode() 会静默覆盖。CP3 已提交改名建议（主标签
 * `[berlin_comments]` + `[wp_comments]` 别名），等待 USER 裁定。
 * 见记忆仓 05_KNOWLEDGE/KNOWN_ISSUES/OPEN_ITEMS.md ①。
 */
define( 'BWPC_SHORTCODE', 'wp_comments' );

/**
 * 载入插件类。
 *
 * 骨架期使用显式 require，不引入 Composer 自动加载——
 * 保持零依赖（P6 轻量原则）。
 */
require_once BWPC_PLUGIN_DIR . 'includes/class-plugin.php';
require_once BWPC_PLUGIN_DIR . 'includes/class-avatar.php';
require_once BWPC_PLUGIN_DIR . 'includes/class-comments-renderer.php';
require_once BWPC_PLUGIN_DIR . 'includes/class-comment-form.php';
require_once BWPC_PLUGIN_DIR . 'includes/class-comments-shortcode.php';

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
	static function () {
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
	static function () {
		// 骨架期：无操作。
	}
);
