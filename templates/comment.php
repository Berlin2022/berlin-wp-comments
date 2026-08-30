<?php
/**
 * 模板：单条评论（wp_list_comments 回调渲染）。
 *
 * 可被主题覆盖（P9）：
 *   {子主题}/berlin-wp-comments/comment.php
 *
 * 可用变量（由 Berlin_WP_Comments_Renderer::render_comment 传入）：
 *
 * @var WP_Comment $comment     评论对象。
 * @var array      $args        wp_list_comments 参数。
 * @var int        $depth       层级深度。
 * @var string     $avatar_html 头像 HTML（由 get_avatar() 产出，已转义）。
 * @var bool       $show_avatar 是否显示头像。
 *
 * @package Berlin_WP_Comments
 *
 * P2 实现：消费骨架 TODO[D2]。
 *
 * ⚠️ 回调协议：本模板输出**未闭合**的 <li>，闭合标签由 wp_list_comments
 * 的 walker 处理。内部 <article class="bwpc-comment"> 必须自行闭合。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<li id="comment-<?php comment_ID(); ?>" <?php comment_class( $args['has_children'] ? 'parent' : '' ); ?>>
	<article id="div-comment-<?php comment_ID(); ?>" class="bwpc-comment">
		<?php if ( $show_avatar && $avatar_html ) : ?>
			<div class="bwpc-comment__avatar">
				<?php echo $avatar_html; // 已由 get_avatar() 转义 ?>
			</div>
		<?php endif; ?>

		<div class="bwpc-comment__body">
			<header class="bwpc-comment__meta">
				<span class="bwpc-comment__author"><?php comment_author(); // esc_html 内置 ?></span>
				<time class="bwpc-comment__date" datetime="<?php comment_time( 'c' ); // esc_attr 内置 ?>">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %1$s = 日期, %2$s = 时间 */
							__( '%1$s %2$s', 'berlin-wp-comments' ),
							get_comment_date(),
							get_comment_time()
						)
					);
					?>
				</time>
			</header>

			<div class="bwpc-comment__content"><?php comment_text(); // WP 核心转义 + KSES ?></div>

			<footer class="bwpc-comment__actions">
				<?php
			// 回复链接：respond_id 必须与 comment_form() 实际输出的包裹层 id 一致。
			// 核心 comment_form() 默认输出 <div id="respond">，且 WP 核心
			// comment-reply.js 的 moveForm() 据此 id 定位并内联移动表单；
			// 若此处写成 'bwpc-respond'（实际不存在该 id），moveForm 找不到
			// 容器 → 点击 Reply 退化为整页跳转（?replytocom=N#...）而非内联回复。
			comment_reply_link(
				array(
					'add_below'  => 'div-comment',
					'respond_id' => 'respond',
						'reply_text' => esc_html__( 'Reply', 'berlin-wp-comments' ),
						'depth'      => $depth,
						'max_depth'  => isset( $args['max_depth'] ) ? (int) $args['max_depth'] : 0,
					),
					$comment
				);
				?>
			</footer>
		</div>
	</article>
