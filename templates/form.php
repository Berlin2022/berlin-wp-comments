<?php
/**
 * 模板：评论表单包裹层。
 *
 * 可被主题覆盖（P9）：
 *   {子主题}/berlin-wp-comments/form.php
 *
 * ⚠️ 重要：本模板**不自造 <form>**。
 *
 * 表单本体由 WordPress 核心 comment_form() 输出，其 action 指向
 * /wp-comments-post.php，从而保住原生的审核、垃圾过滤、Akismet、
 * 通知与状态机（CP1 决策 D4 / P3）。
 *
 * 本模板只负责外层包裹与标题区。字段级定制走核心过滤器：
 *   comment_form_defaults / comment_form_fields / comment_form_field_{$name}
 *
 * 可用变量（TODO[D3] 实现后传入）：
 *
 * @var array  $args      规范化后的 shortcode 参数。
 * @var string $form_html comment_form() 的输出。
 *
 * @package Berlin_WP_Comments
 *
 * 骨架状态：结构占位，无输出。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// TODO[D3]：实现包裹结构。CP1 立项文件给出的目标形态：
//
//   [发表评论]
//
//   姓名
//   邮箱
//   头像上传      ← V1 不做（访客上传为 Deferred 项，CP1 决策 D6）
//   评论内容
//
// V1 表单字段 = 姓名 + 邮箱 + 评论内容（走核心默认字段）。
// 注册用户头像在个人资料页设置，不在评论表单内上传。
