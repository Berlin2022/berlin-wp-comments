<?php
/**
 * Plugin Name:       Berlin WP Comments
 * Plugin URI:        https://github.com/Berlin2022/berlin-wp-comments
 * Description:       极简 WordPress 原生评论增强插件 —— Shortcode + 本地头像 + 原生评论。WordPress 负责数据与生命周期，本插件只负责呈现与头像。
 * Version:           0.1.0-skeleton
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
 * 骨架声明（CP1 指令 D8）
 * ---------------------------------------------------------------------------
 * 本版本为 **骨架**：结构、类、钩子接线点、模板占位均已就位，但
 * **功能全部未实现**。这是 CP1 立项裁定的第一阶段交付物：
 *
 *   「初始化项目 + 建立插件骨架 + 输出实现计划 + 不实现功能。」
 *
 * 所有待实现点以 `TODO[D<n>]` 标记，n 对应实现计划阶段
 * （见记忆仓 03_PLAN/CP2/CP2-001_v1_implementation_plan.md）。
 *
 * 本插件此刻可安全激活/停用，不产生任何前端输出、不写任何数据。
 * ---------------------------------------------------------------------------
 *
 * License 说明：上方 GPL-2.0-or-later 为 WordPress 生态惯例占位声明。
 * 最终 License 由 USER 裁定（见记忆仓 OPEN_ITEMS ⑥），裁定后补 LICENSE 全文。
 */

// 阻止直接访问。
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 插件常量。
 */
define( 'BWPC_VERSION', '0.1.0-skeleton' );
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
