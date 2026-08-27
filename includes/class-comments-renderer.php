<?php
/**
 * 评论列表渲染模块。
 *
 * 职责：取原生评论数据 → 走插件自己的模板输出。
 *
 * 设计要点（详见记忆仓 05_KNOWLEDGE/TECHNICAL/WP_COMMENTS_ARCHITECTURE.md §1）：
 *
 *   **不使用 comments_template()**。原因：它 echo 而非 return、会加载主题的
 *   comments.php（违背 P9 主题无关）、有 singular 上下文假设、
 *   主题已调用时会双重渲染。
 *
 *   改用：WP_Comment_Query 取数 → wp_list_comments( callback ) 走自有模板。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Berlin_WP_Comments_Renderer
 *
 * 骨架状态：全部方法为桩。
 */
class Berlin_WP_Comments_Renderer {

	/**
	 * 渲染评论列表（含分页）。
	 *
	 * TODO[D2]：实现。步骤：
	 *   1. 取当前 post ID（范围界定见 OPEN_ITEMS ④：V1 只渲染当前对象）
	 *   2. WP_Comment_Query 取本页评论（status=approve，含 threading）
	 *   3. bwpc()->avatar->prime_cache_for_comments() 预热，防 N+1
	 *   4. wp_list_comments( array( 'callback' => array( $this, 'render_comment' ) ) )
	 *   5. 追加分页链接（方案待 OPEN_ITEMS ③ 裁定）
	 *   6. 全程 ob_ 缓冲，返回字符串
	 *
	 * @param array $args 已规范化的 shortcode 参数。
	 * @return string 评论列表 HTML。
	 */
	public function render_list( array $args ) {
		unset( $args );

		// TODO[D2]：实现评论列表渲染。
		return '';
	}

	/**
	 * 单条评论渲染回调（供 wp_list_comments 使用）。
	 *
	 * 注意 wp_list_comments 回调协议：本方法负责输出**未闭合**的
	 * <li>/<div>，闭合标签由 end-callback 或 wp_list_comments 处理。
	 * 实现时需与 templates/comment.php 的结构约定一致。
	 *
	 * TODO[D2]：实现，输出走 templates/comment.php。
	 * 所有输出必须转义（P8）。
	 *
	 * @param WP_Comment $comment 评论对象。
	 * @param array      $args    wp_list_comments 参数。
	 * @param int        $depth   当前层级深度。
	 * @return void
	 */
	public function render_comment( $comment, $args, $depth ) {
		unset( $comment, $args, $depth );

		// TODO[D2]：单条评论模板输出。
	}

	/**
	 * 渲染分页链接。
	 *
	 * ⚠️ 实现路径待裁定（OPEN_ITEMS ③）：
	 *   A. 复用原生 cpage / comment-page-N（CP3 倾向，符合 P1 原生优先）
	 *   B. 自有 query arg wpc_page（简单但与原生分页脱节）
	 *
	 * 未裁定前不实现，避免形成难以回退的技术债。
	 *
	 * @param array $args 已规范化的 shortcode 参数。
	 * @return string 分页 HTML。
	 */
	public function render_pagination( array $args ) {
		unset( $args );

		// TODO[D4]：待 OPEN_ITEMS ③ 裁定后实现。
		return '';
	}

	/**
	 * 取本页应显示的评论。
	 *
	 * TODO[D2]：实现 WP_Comment_Query 封装，
	 * 尊重 page_comments / comments_per_page / default_comments_page 选项。
	 *
	 * @param int   $post_id 目标对象 ID。
	 * @param array $args    参数。
	 * @return WP_Comment[]
	 */
	protected function query_comments( $post_id, array $args ) {
		unset( $post_id, $args );

		// TODO[D2]：实现取数。
		return array();
	}
}
