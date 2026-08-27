<?php
/**
 * 模板：评论区外层容器。
 *
 * 可被主题覆盖（P9）：
 *   {子主题}/berlin-wp-comments/comments.php
 *   {父主题}/berlin-wp-comments/comments.php
 *
 * 可用变量（由 Berlin_WP_Comments_Renderer::render_list 传入）：
 *
 * @var array  $args     规范化后的 shortcode 参数。
 * @var string $list     评论列表 HTML（已由 wp_list_comments 包裹 <ol class="comment-list">）。
 * @var string $pager    分页 HTML（P5 前为空）。
 * @var string $form     评论表单 HTML（P3 前为空）。
 * @var int    $count    已批准评论总数。
 *
 * @package Berlin_WP_Comments
 *
 * P2 实现：消费骨架 TODO[D2]。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="bwpc" id="bwpc-comments">
	<h2 class="bwpc__title">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %d = 评论数 */
				_n( '%d 条评论', '%d 条评论', $count, 'berlin-wp-comments' ),
				$count
			)
		);
		?>
	</h2>

	<?php if ( $list ) : ?>
		<?php
		// $list 已由 wp_list_comments 包裹 <ol class="comment-list">，此处按已转义 HTML 输出。
		echo $list;
		?>
	<?php else : ?>
		<p class="bwpc__empty"><?php echo esc_html__( '暂无评论。', 'berlin-wp-comments' ); ?></p>
	<?php endif; ?>

	<?php if ( $pager ) : ?>
		<nav class="bwpc__pager" aria-label="<?php esc_attr_e( '评论分页', 'berlin-wp-comments' ); ?>">
			<?php echo $pager; ?>
		</nav>
	<?php endif; ?>

	<?php if ( $form ) : ?>
		<div class="bwpc__form">
			<?php echo $form; ?>
		</div>
	<?php endif; ?>
</div>
