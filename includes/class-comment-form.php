<?php
/**
 * 评论表单模块。
 *
 * 职责：输出评论表单，**完全复用 WordPress 原生提交链路**。
 *
 * 关键架构杠杆（CP1 决策 D4 / P3）：
 *
 *   使用核心的 comment_form()，其 action 指向 /wp-comments-post.php：
 *
 *       POST → wp-comments-post.php
 *              → wp_handle_comment_submission()
 *                  → wp_new_comment()
 *                      → 审核 / 垃圾过滤 / Akismet / 通知 / 状态机
 *
 *   **只要用 comment_form()，插件一行提交代码都不用写**，P3 自动成立。
 *   这是本插件能保持极简的核心原因。
 *
 *   ⚠️ 不要给评论提交自造 nonce——会与核心端点冲突。
 *      nonce 只用于插件自己的写操作（如头像上传）。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Berlin_WP_Comments_Form
 *
 * 骨架状态：全部方法为桩。
 */
class Berlin_WP_Comments_Form {

	/**
	 * 渲染评论表单。
	 *
	 * TODO[D3]：实现。步骤：
	 *   1. ! comments_open() 时走 render_closed_notice()
	 *   2. 组装 comment_form() 参数（title_reply / class_form / 字段模板等）
	 *   3. ob_start() → comment_form( $form_args ) → ob_get_clean()
	 *
	 * @param array $args 已规范化的 shortcode 参数。
	 * @return string 表单 HTML。
	 */
	public function render( array $args ) {
		unset( $args );

		// TODO[D3]：实现表单渲染。
		return '';
	}

	/**
	 * 组装 comment_form() 参数。
	 *
	 * TODO[D3]：实现。定制走核心过滤器，不自造表单：
	 *   - comment_form_defaults
	 *   - comment_form_fields
	 *   - comment_form_field_{$name}
	 *
	 * @return array
	 */
	protected function get_form_args() {
		// TODO[D3]：实现参数组装。
		return array();
	}

	/**
	 * 评论关闭时的提示。
	 *
	 * ⚠️ 可用性陷阱（见 WP_COMMENTS_ARCHITECTURE.md §7）：
	 * 多数站点的 Page 默认关闭评论。用户插入 shortcode 后只看到空白，
	 * 会误判插件损坏。
	 *
	 * TODO[D3]：对**有编辑权限的登录用户**输出明确提示
	 * （说明需在页面「讨论」面板勾选允许评论）；对普通访客保持静默。
	 *
	 * @return string
	 */
	protected function render_closed_notice() {
		// TODO[D3]：实现管理员可见提示。
		return '';
	}
}
