<?php
/**
 * 模板：单条评论。
 *
 * 可被主题覆盖（P9）：
 *   {子主题}/berlin-wp-comments/comment.php
 *
 * 可用变量（TODO[D2] 实现后传入）：
 *
 * @var WP_Comment $comment     评论对象。
 * @var array      $args        wp_list_comments 参数。
 * @var int        $depth       层级深度。
 * @var string     $avatar_html 头像 HTML（由 get_avatar() 产出）。
 *
 * @package Berlin_WP_Comments
 *
 * 骨架状态：结构占位，无输出。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// TODO[D2]：实现单条评论结构。CP1 立项文件给出的目标形态：
//
//   ┌──────────────────────────────┐
//   │  👤 用户头像   用户名         │
//   │  评论内容                    │
//   │  时间 · 回复                 │
//   └──────────────────────────────┘
//
// 实现要点：
//   - 转义（P8）：作者名 esc_html、URL esc_url、内容走 comment_text()
//     或 wp_kses_post()，绝不裸输出。
//   - 回复链接用 get_comment_reply_link()，其 add_below / respond_id
//     参数必须与容器 id 一致，否则 WP 核心 comment-reply 脚本失效。
//   - 时间用 get_comment_date() / human_time_diff()，尊重站点时区设置。
//   - wp_list_comments 回调协议：本模板输出**未闭合**的列表项，
//     闭合由 end-callback 处理。
