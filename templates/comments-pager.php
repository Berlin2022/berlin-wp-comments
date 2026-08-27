<?php
/**
 * 模板：评论分页（原生 cpage）。
 *
 * 可被主题覆盖（P9）：
 *   {子主题}/berlin-wp-comments/comments-pager.php
 *   {父主题}/berlin-wp-comments/comments-pager.php
 *
 * 可用变量（由 Berlin_WP_Comments_Renderer::render_pagination 传入）：
 *
 * @var array $args      规范化后的 shortcode 参数。
 * @var array $links     分页链接数组，每项含 page / url / current。
 * @var int   $current   当前页码。
 * @var int   $max_pages 总页数。
 * @var int   $total     已批准评论总数。
 *
 * @package Berlin_WP_Comments
 *
 * P5 实现：消费骨架 TODO[D5]，方案 A = 原生 cpage（OPEN_ITEMS ③）。
 * ⚠️ O5 门禁：原生 cpage 行为须 P6 真实 WP 验证后关闭 O5。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $links ) ) {
	return;
}
?>
<ul class="bwpc-pager__list">
	<?php foreach ( $links as $link ) : ?>
		<li class="bwpc-pager__item<?php echo $link['current'] ? ' is-current' : ''; ?>">
			<?php if ( $link['current'] ) : ?>
				<span class="bwpc-pager__current" aria-current="page"><?php echo (int) $link['page']; ?></span>
			<?php else : ?>
				<a class="bwpc-pager__link" href="<?php echo esc_url( $link['url'] ); ?>"><?php echo (int) $link['page']; ?></a>
			<?php endif; ?>
		</li>
	<?php endforeach; ?>
</ul>
