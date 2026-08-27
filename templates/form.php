<?php
/**
 * 模板：评论表单包裹层。
 *
 * 可被主题覆盖（P9）：
 *   {子主题}/berlin-wp-comments/form.php
 *
 * ⚠️ 重要：本模板**不自造 <form>**。
 *
 * 表单本体由 WordPress 核心 comment_form() 输出（见 class-comment-form.php），
 * 其 action 指向 /wp-comments-post.php，从而保住原生的审核、垃圾过滤、Akismet、
 * 通知与状态机（CP1 决策 D4 / P3）。
 *
 * 本模板只负责外层包裹，字段级定制由核心 comment_form() 参数控制。
 *
 * 可用变量（由 class-comment-form.php 的 render() 传入）：
 *
 * @var array  $args      规范化后的 shortcode 参数。
 * @var string $form_html comment_form() 的输出（已含完整 <form> 与提交按钮）。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="bwpc-comment-form-wrap">
	<?php echo $form_html; ?>
</div>
