<?php
/**
 * 模板：评论区外层容器。
 *
 * 可被主题覆盖（P9）：
 *   {子主题}/berlin-wp-comments/comments.php
 *   {父主题}/berlin-wp-comments/comments.php
 *
 * 可用变量（TODO[D2] 实现后传入）：
 *
 * @var array  $args     规范化后的 shortcode 参数。
 * @var string $list     评论列表 HTML。
 * @var string $pager    分页 HTML。
 * @var string $form     评论表单 HTML。
 * @var int    $count    已批准评论总数。
 *
 * @package Berlin_WP_Comments
 *
 * 骨架状态：结构占位，无输出。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// TODO[D2]：实现容器结构。目标（CP1 指定顺序）：
//
// <div class="bwpc">
//     <h2 class="bwpc__title">N 条评论</h2>
//     <ol class="bwpc__list"> ...$list... </ol>
//     <nav class="bwpc__pager"> ...$pager... </nav>
//     <div class="bwpc__form"> ...$form... </div>
// </div>
//
// 注意：$list / $pager / $form 由各模块生成时已完成转义，
// 此处按已转义 HTML 输出；所有**原始数据**的转义责任在生成端（P8）。
