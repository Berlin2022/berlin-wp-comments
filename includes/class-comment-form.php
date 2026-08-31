<?php
/**
 * 评论表单模块。
 *
 * 职责：输出评论表单，**完全复用 WordPress 原生提交链路**。
 *
 * v0.1.12 — 完全自渲染（脱离 comment_form() 内部排序）：
 *
 *   提交 action 仍指向 /wp-comments-post.php，由核心处理
 *   审核/垃圾过滤/Akismet/通知/状态机。HTML 完全由本模块 echo，
 *   字段顺序固定为：
 *
 *     [Author *      ] [Email *       ]    ← .bwpc-form-row 同行
 *     Attachment: [选择文件]
 *     [ Your comment *                           ]
 *     [☑] Save my name, email ...
 *     [ Post Comment ]
 *
 *   id="respond" 让核心 comment-reply 脚本能识别并移动表单到被回复评论下方；
 *   enctype="multipart/form-data" 为将来接管 $_FILES['bwpc_comment_attachment']
 *   备用（暂不处理后端）。
 *
 *   ⚠️ 不给评论提交自造 nonce——与核心端点冲突，nonce 只用于插件自己的写操作。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Berlin_WP_Comments_Form
 */
class Berlin_WP_Comments_Form {

	/**
	 * 插件主实例。
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
	 * 渲染评论表单（v0.1.12 完全自渲染版本）。
	 *
	 * 不写任何提交逻辑：表单 action 仍指向核心 /wp-comments-post.php，
	 * 审核/垃圾/通知由核心在提交端处理。
	 *
	 * @param array $args 已规范化的 shortcode 参数（P4 传入；保留兼容）。
	 * @return string 表单 HTML。
	 */
	public function render( array $args = array() ) {
		// 评论关闭：对有编辑权限的登录用户给出明确提示，访客静默（陷阱 D）。
		if ( ! comments_open() ) {
			return $this->render_closed_notice();
		}

		// O4：线程回复复用核心 comment-reply 脚本（不写自有线程逻辑）。
		$this->enqueue_reply_script();

		$form_html = $this->render_form_html();

		// 经模板输出（支持主题覆盖，P9）；模板只包裹、不自造 <form>。
		return $this->plugin->render_template(
			'form',
			array(
				'args'      => $args,
				'form_html' => $form_html,
			)
		);
	}

	/**
	 * 组装完整表单 HTML（v0.1.12 自渲染）。
	 *
	 * 字段顺序固定：
	 *   1) Name + Email（同行，flex .bwpc-form-row）
	 *   2) Attachment（文件上传预留）
	 *   3) Comment（textarea，带 placeholder "Your comment *"）
	 *   4) cookies-consent（保留核心字段名 wp-comment-cookies-consent）
	 *   5) submit + post id + parent id（隐藏）
	 *
	 * 占位符即标签（USER 视觉要求"文字放到 input 框里"），原 <label> 由 CSS sr-only。
	 *
	 * @return string
	 */
	protected function render_form_html() {
		$commenter = wp_get_current_commenter();
		$post_id   = (int) get_the_ID();
		$req       = (string) get_option( 'require_name_email' );

		$required_attr = ( '1' === $req || 1 === $req || true === $req ) ? ' required="required"' : '';

		ob_start();
		?>
<form action="<?php echo esc_url( site_url( '/wp-comments-post.php' ) ); ?>" method="post" id="respond" class="bwpc-comment-form" enctype="multipart/form-data">
	<h3 id="reply-title" class="bwpc-comment-reply-title"><?php esc_html_e( 'Leave a Comment', 'berlin-wp-comments' ); ?></h3>

	<?php // 1) Name + Email — 同行（v0.1.12 视觉要求） ?>
	<div class="bwpc-form-row">
		<p class="comment-form-author">
			<label for="author"><?php esc_html_e( 'Name', 'berlin-wp-comments' ); ?> <span class="required">*</span></label>
			<input id="author" name="author" type="text" placeholder="<?php echo esc_attr__( 'Name *', 'berlin-wp-comments' ); ?>" value="<?php echo esc_attr( $commenter['comment_author'] ); ?>" size="30" maxlength="245" autocomplete="name"<?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
		</p>
		<p class="comment-form-email">
			<label for="email"><?php esc_html_e( 'Email', 'berlin-wp-comments' ); ?> <span class="required">*</span></label>
			<input id="email" name="email" type="email" placeholder="<?php echo esc_attr__( 'Email *', 'berlin-wp-comments' ); ?>" value="<?php echo esc_attr( $commenter['comment_author_email'] ); ?>" size="30" maxlength="100" aria-describedby="email-notes" autocomplete="email"<?php echo $required_attr; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> />
		</p>
	</div>

	<?php // 2) Attachment — 文件上传（自定义英文控件，覆盖浏览器原生 file 的中文按钮） ?>
	<p class="comment-form-attachment">
		<label for="bwpc-comment-attachment"><?php esc_html_e( 'Attachment', 'berlin-wp-comments' ); ?></label>
		<span class="bwpc-attachment-field">
			<label for="bwpc-comment-attachment" class="bwpc-file-btn"><?php esc_html_e( 'Choose File', 'berlin-wp-comments' ); ?></label>
			<span class="bwpc-file-name" id="bwpc-file-name"><?php esc_html_e( 'No file chosen', 'berlin-wp-comments' ); ?></span>
		</span>
		<input id="bwpc-comment-attachment" name="bwpc_comment_attachment" type="file" accept="image/*,.pdf" class="bwpc-file-input" />
	</p>
	<?php // 选中文件后回显文件名（极简内联脚本，避免在中文浏览器下显示原生「未选择任何文件」） ?>
	<script>
	(function () {
		var input = document.getElementById('bwpc-comment-attachment');
		var name = document.getElementById('bwpc-file-name');
		if (input && name) {
			input.addEventListener('change', function () {
				name.textContent = input.files && input.files.length ? input.files[0].name : <?php echo wp_json_encode( __( 'No file chosen', 'berlin-wp-comments' ) ); ?>;
			});
		}
	})();
	</script>

	<?php // 3) Comment textarea — placeholder="Your comment *"（占位符即标签） ?>
	<p class="comment-form-comment">
		<label for="comment"><?php echo esc_html_x( 'Comment', 'noun', 'berlin-wp-comments' ); ?></label>
		<textarea id="comment" name="comment" placeholder="<?php echo esc_attr__( 'Your comment *', 'berlin-wp-comments' ); ?>" cols="45" rows="8" maxlength="65525" required="required" aria-required="true"></textarea>
	</p>

	<?php // 4) cookies-consent — 保留核心字段名 wp-comment-cookies-consent ?>
	<p class="comment-form-cookies-consent">
		<input id="wp-comment-cookies-consent" name="wp-comment-cookies-consent" type="checkbox" value="yes" />
		<label for="wp-comment-cookies-consent"><?php esc_html_e( 'Save my name, email, and website in this browser for the next time I comment.', 'berlin-wp-comments' ); ?></label>
	</p>

	<?php // 5) submit + 隐藏字段（comment_post_ID / comment_parent） ?>
	<p class="form-submit">
		<button type="submit" name="submit" id="submit" class="submit" value="<?php echo esc_attr__( 'Post Comment', 'berlin-wp-comments' ); ?>"><?php esc_html_e( 'Post Comment', 'berlin-wp-comments' ); ?></button>
		<input type="hidden" name="comment_post_ID" value="<?php echo (int) $post_id; ?>" id="comment_post_ID" />
		<input type="hidden" name="comment_parent" id="comment_parent" value="0" />
	</p>

	<?php
	// 第三方插件 hook 点（保留扩展位：comment-reply 行为 / 反垃圾令牌 / 自定义逻辑可挂靠）
	/**
	 * Fires at the end of the comment form, after all fields are rendered.
	 *
	 * @since 2.7.0
	 * @param int $post_id The post ID.
	 */
	do_action( 'comment_form', $post_id );
	?>
</form>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * 保留供模板兼容的旧入口（旧模板仍可读 $args → 无副作用）。
	 *
	 * 不再被 render() 调用；保留仅为不让任何外部测试脚本意外 fatal。
	 *
	 * @param array $args 已规范化的 shortcode 参数。
	 * @return array
	 */
	protected function get_form_args( array $args = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return array(
			'title_reply'         => __( 'Leave a Comment', 'berlin-wp-comments' ),
			'label_submit'        => __( 'Post Comment', 'berlin-wp-comments' ),
			'class_form'          => 'bwpc-comment-form',
			'id_form'             => 'bwpc-commentform',
		);
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
	 * 自渲染表单 id="respond" 与核心 comment-reply.js 期望一致，脚本正常工作。
	 *
	 * @return void
	 */
	protected function enqueue_reply_script() {
		wp_enqueue_script( 'comment-reply' );
	}
}
