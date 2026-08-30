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
 *   ⚠️ 不給评论提交自造 nonce——会与核心端点冲突。
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
 * P3 实现：消费骨架 TODO[D3]。
 */
class Berlin_WP_Comments_Form {

	/**
	 * 插件主实例（用于模板渲染与依赖）。
	 *
	 * @var Berlin_WP_Comments_Plugin
	 */
	protected $plugin;

	/**
	 * 构造。
	 *
	 * @param Berlin_WP_Comments_Plugin $plugin 插件主实例。
	 */
	public function __construct( $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * 渲染评论表单。
	 *
	 * 不写任何提交逻辑：直接调用核心 comment_form()，由 WP 负责
	 * 审核/垃圾/通知/状态机。评论关闭时降级为管理员提示（陷阱 D）。
	 *
	 * @param array $args 已规范化的 shortcode 参数（P4 传入；P3 可空）。
	 * @return string 表单 HTML。
	 */
	public function render( array $args = array() ) {
		// 评论关闭：对有编辑权限的登录用户给出明确提示，访客静默（陷阱 D）。
		if ( ! comments_open() ) {
			return $this->render_closed_notice();
		}

		// O4：线程回复复用核心 comment-reply 脚本（不写自有线程逻辑）。
		$this->enqueue_reply_script();

		$form_args = $this->get_form_args( $args );

		// comment_form() 直接 echo，故 ob 捕获为字符串交由 shortcode 装配（P4）。
		ob_start();
		comment_form( $form_args );
		$form_html = (string) ob_get_clean();

		// 经模板输出（支持主题覆盖，P9）；模板只包裹、不自造 <form>。
		return $this->plugin->render_template(
			'form',
			array(
				'args'      => $form_args,
				'form_html' => $form_html,
			)
		);
	}

	/**
	 * 组装 comment_form() 参数。
	 *
	 * 定制走**作用域内的参数**，不自造表单、不自造 nonce。
	 * V1 字段（姓名+邮箱+评论内容）直接复用核心默认字段，无需改过滤器，
	 * 故此处仅设置标题/容器 class/提交按钮文案，避免注册全站过滤器污染
	 * 主题原生评论表单（P9：插件不接管主题）。如需更深定制，可在此基础上
	 * 通过 comment_form_defaults / comment_form_fields 过滤器扩展。
	 *
	 * @param array $args 已规范化的 shortcode 参数。
	 * @return array
	 */
	protected function get_form_args( array $args = array() ) {
		$commenter = wp_get_current_commenter();

		$form_args = array(
			'title_reply'         => __( 'Leave a Comment', 'berlin-wp-comments' ),
			'title_reply_before'  => '<h3 id="reply-title" class="bwpc-comment-reply-title">',
			'title_reply_after'   => '</h3>',
			'label_submit'        => __( 'Post Comment', 'berlin-wp-comments' ),
			'cancel_reply_link'   => __( 'Cancel reply', 'berlin-wp-comments' ),
			'class_form'          => 'bwpc-comment-form',
			'id_form'             => 'bwpc-commentform',
			'cancel_reply_before' => ' <span class="bwpc-cancel-reply">',
			'cancel_reply_after'  => '</span>',
			// 接管字段标签，强制英文（否则 zh_CN 站点下核心翻成"名/电子邮件/网站"）。
			'fields'              => array(
				'author' => '<p class="comment-form-author"><label for="author">' . __( 'Name', 'berlin-wp-comments' ) . ' <span class="required">*</span></label> ' .
				            '<input id="author" name="author" type="text" value="' . esc_attr( $commenter['comment_author'] ) . '" size="30" maxlength="245" autocomplete="name" required="required" /></p>',
				'email'  => '<p class="comment-form-email"><label for="email">' . __( 'Email', 'berlin-wp-comments' ) . ' <span class="required">*</span></label> ' .
				            '<input id="email" name="email" type="text" value="' . esc_attr( $commenter['comment_author_email'] ) . '" size="30" maxlength="100" aria-describedby="email-notes" autocomplete="email" required="required" /></p>',
				'url'    => '<p class="comment-form-url"><label for="url">' . __( 'Website', 'berlin-wp-comments' ) . '</label> ' .
				            '<input id="url" name="url" type="text" value="' . esc_attr( $commenter['comment_author_url'] ) . '" size="30" maxlength="200" /></p>',
			),
		);


		return $form_args;
	}

	/**
	 * 评论关闭时的提示。
	 *
	 * ⚠️ 可用性陷阱（陷阱 D / Page 默认关评论）：多数站点 Page 默认关闭评论。
	 * 用户插入 shortcode 后只看到空白会误判插件损坏。故对**有编辑权限的
	 * 登录用户**输出明确提示；普通访客保持静默。
	 *
	 * @return string
	 */
	protected function render_closed_notice() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return '';
		}

		$message = __( 'Comments are closed. To enable comments on this page, check "Allow comments" in the Discussion panel of this page\'s editor.', 'berlin-wp-comments' );

		return '<p class="bwpc-notice bwpc-notice--closed">' . esc_html( $message ) . '</p>';
	}

	/**
	 * 加载核心评论回复脚本（O4 线程回复）。
	 *
	 * 始终入队 WP 核心 comment-reply 脚本：本插件**始终**输出 Reply 链接
	 * （无论站点 Settings → Discussion 的「启用嵌套/线程评论」开关状态），
	 * 该脚本负责把表单内联移动到被回复评论下方、并拦截点击避免整页跳转。
	 *
	 * 旧逻辑以 get_option('thread_comments') 为闸门，会导致该开关关闭时
	 * 脚本不入队 → 点击 Reply 退化为整页导航（?replytocom=N#respond）而非
	 * 原生内联回复。改为无条件入队（仅当评论区开放时由 render() 调用到此）。
	 *
	 * 配合 templates/comment.php 的 respond_id='respond'（与 comment_form()
	 * 实际包裹层 id 一致），实现零自有 JS 的内联回复（CP1 约束 C5）。
	 *
	 * @return void
	 */
	protected function enqueue_reply_script() {
		wp_enqueue_script( 'comment-reply' );
	}
}
