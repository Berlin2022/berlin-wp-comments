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
 * P2 实现：消费骨架 TODO[D2]。
 */
class Berlin_WP_Comments_Renderer {

	/**
	 * 插件主实例（用于模板定位与头像预热）。
	 *
	 * @var Berlin_WP_Comments_Plugin
	 */
	private $plugin;

	/**
	 * 构造。
	 *
	 * @param Berlin_WP_Comments_Plugin $plugin 插件主实例。
	 */
	public function __construct( Berlin_WP_Comments_Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/**
	 * 渲染评论区（外层容器 + 列表 + 分页占位 + 表单占位）。
	 *
	 * 消费骨架 TODO[D2]。步骤对应 CP2-001 P2：
	 *   1. 取当前对象 post ID（OPEN_ITEMS ④：V1 只渲染当前对象）。
	 *   2. WP_Comment_Query 取本页评论。
	 *   3. 头像缓存预热（防 N+1，陷阱 B）。
	 *   4. wp_list_comments(callback) 驱动 render_comment。
	 *   5. 组装外层容器（templates/comments.php）。
	 *
	 * @param array $args 已规范化的 shortcode 参数。
	 * @return string 评论区 HTML。
	 */
	public function render_list( array $args ) {
		$post_id = $this->resolve_post_id( $args );

		// ④ 仅当前对象；无有效对象则不渲染。
		if ( ! $post_id ) {
			return '';
		}

		$comments = $this->query_comments( $post_id, $args );

		// 陷阱 B：头像缓存预热，避免每条评论单独查 user_meta。
		if ( $this->plugin->avatar && method_exists( $this->plugin->avatar, 'prime_cache_for_comments' ) ) {
			$this->plugin->avatar->prime_cache_for_comments( $comments );
		}

		$list  = $this->build_list_html( $comments, $args );
		$pager = $this->render_pagination( $args ); // P5：原生 cpage 分页（OPEN_ITEMS ③ 方案 A）。
		$form  = $this->plugin->form->render( $args ); // P3 实现；P4 接入评论区（列表 → 分页 → 表单）。

		$count = $this->count_comments( $post_id );

		return $this->plugin->render_template(
			'comments',
			array(
				'args'  => $args,
				'list'  => $list,
				'pager' => $pager,
				'form'  => $form,
				'count' => $count,
			)
		);
	}

	/**
	 * 解析目标对象 ID。
	 *
	 * ④ V1 只渲染当前 singular object；允许 shortcode atts 覆盖（未来扩展），
	 * 但默认绝不聚合 / 不接受任意 post_id= 聚合（见 OPEN_ITEMS ④ 裁定）。
	 *
	 * @param array $args 参数。
	 * @return int
	 */
	protected function resolve_post_id( array $args ) {
		if ( ! empty( $args['post_id'] ) && is_numeric( $args['post_id'] ) ) {
			return (int) $args['post_id'];
		}
		$post = get_post();
		return $post ? (int) $post->ID : 0;
	}

	/**
	 * 用 wp_list_comments + callback 生成列表 HTML。
	 *
	 * @param WP_Comment[] $comments 评论数组。
	 * @param array        $args     参数。
	 * @return string
	 */
	protected function build_list_html( array $comments, array $args ) {
		if ( empty( $comments ) ) {
			return '';
		}

		$avatar_size = isset( $args['avatar_size'] ) ? (int) $args['avatar_size'] : 48;

		$list_args = array(
			'style'       => 'ol',
			'type'        => 'all',
			'avatar_size' => $avatar_size,
			'callback'    => array( $this, 'render_comment' ),
			'echo'        => false,
		);

		// 分页已在 query_comments 层（DB 级 number+offset）完成；此处仅把
		// 当前页评论交给 wp_list_comments 渲染并依 comment_parent 构建评论树，
		// 不再传 per_page/page，避免与 DB 级分页重复切片（OPEN_ITEMS ③ 方案 A）。
		// wp_list_comments 第二参接受评论数组，无需污染全局 $wp_query。
		$html = wp_list_comments( $list_args, $comments );

		return is_string( $html ) ? $html : '';
	}

	/**
	 * 单条评论渲染回调（供 wp_list_comments 使用）。
	 *
	 * 协议：本方法输出**未闭合**的 <li>（闭合由 wp_list_comments walker 处理），
	 * 内部 <article class="bwpc-comment"> 需自行闭合。
	 *
	 * 所有输出经 WP 核心转义函数（comment_author / comment_text / get_avatar 等），
	 * 原始数据不裸输出（P8）。
	 *
	 * @param WP_Comment $comment 评论对象。
	 * @param array      $args    wp_list_comments 参数。
	 * @param int        $depth   当前层级深度。
	 * @return void
	 */
	public function render_comment( $comment, $args, $depth ) {
		$avatar_size = isset( $args['avatar_size'] ) ? (int) $args['avatar_size'] : 48;
		$show_avatar = ! isset( $args['show_avatar'] ) || 'yes' === $args['show_avatar'];

		// 头像经 P1 的 get_avatar_data 挂钩解析：本地头像或默认 SVG，零 Gravatar（O8）。
		$avatar_html = $show_avatar ? get_avatar( $comment, $avatar_size ) : '';

		// ⚠️ 输出（非返回）：本方法处于 wp_list_comments 的 callback 协议中，
		// Walker_Comment::start_el() 以 ob_start() 捕获 callback 输出、不读取返回值。
		// 故必须 echo，否则评论 HTML 在运行时被丢弃、列表为空（AUDIT-006 修正）。
		echo $this->plugin->render_template(
			'comment',
			array(
				'comment'     => $comment,
				'args'        => $args,
				'depth'       => $depth,
				'avatar_html' => $avatar_html,
				'show_avatar' => $show_avatar,
			)
		);
	}

	/**
	 * 取本页应显示的评论（WP_Comment_Query）。
	 *
	 * 尊重 page_comments / comments_per_page / default_comments_page 选项。
	 *
	 * @param int   $post_id 目标对象 ID。
	 * @param array $args    参数。
	 * @return WP_Comment[]
	 */
	protected function query_comments( $post_id, array $args ) {
		$per_page = $this->per_page( $args );

		$query_args = array(
			'post_id' => $post_id,
			'status'  => 'approve',
			'order'   => 'ASC',
			'type'    => 'comment',
		);

		if ( $per_page > 0 ) {
			$query_args['number'] = $per_page;
			// 分页在 query 层落地（DB 级 offset），不依赖 comments_template()
			// 建立的 $wp_query->comments 上下文（陷阱 C / OPEN_ITEMS ③ 方案 A）。
			$query_args['offset'] = ( $this->current_cpage() - 1 ) * $per_page;
		}

		$comments = get_comments( $query_args );

		return is_array( $comments ) ? $comments : array();
	}

	/**
	 * 已批准评论总数（用于标题计数）。
	 *
	 * @param int $post_id 对象 ID。
	 * @return int
	 */
	protected function count_comments( $post_id ) {
		$count = get_comments(
			array(
				'post_id' => $post_id,
				'status'  => 'approve',
				'count'   => true,
				'type'    => 'comment',
			)
		);

		return (int) $count;
	}

	/**
	 * 计算每页评论数。
	 *
	 * @param array $args 参数。
	 * @return int 0 表示沿用 WP 站点设置（不限制 number）。
	 */
	protected function per_page( array $args ) {
		if ( isset( $args['comments_per_page'] ) && is_numeric( $args['comments_per_page'] ) && (int) $args['comments_per_page'] > 0 ) {
			return (int) $args['comments_per_page'];
		}

		$opt = (int) get_option( 'comments_per_page', 0 );

		return $opt > 0 ? $opt : 0;
	}

	/**
	 * 读取当前评论分页页码（原生 cpage 查询变量）。
	 *
	 * 方案 A（OPEN_ITEMS ③，原生 cpage）：复用 WP 原生评论分页语境，
	 * 不依赖 comments_template() 建立的 $wp_query->comments 上下文。
	 *
	 * ⚠️ O5 门禁：原生 cpage 在真实 WordPress Page 语境下的行为（含
	 * comment-page-N 固定链接、?cpage=N 解析）须由 P6 实机验证后方能关闭 O5；
	 * 本方法仅负责读取，不在此断言验证结论。
	 *
	 * @return int 当前页码（最小 1）。
	 */
	protected function current_cpage() {
		$c = (int) get_query_var( 'cpage', 1 );
		return $c > 0 ? $c : 1;
	}

	/**
	 * 渲染分页链接（P5，OPEN_ITEMS ③ 方案 A = 原生 cpage）。
	 *
	 * 设计：
	 * - 复用 WP 原生评论分页链接生成 get_comments_pagenum_link()，基于当前
	 *   singular object 固定链接输出 comment-page-N / ?cpage=N，不依赖
	 *   comments_template() 建立的 $wp_query->comments 上下文。
	 * - 当前页码来自 current_cpage()（get_query_var('cpage')）；总页数由本插件
	 *   自己的评论计数与每页数推导，避免依赖 $wp_query->max_num_comment_pages。
	 *
	 * ⚠️ O5 门禁：原生 cpage 在真实 WP Page 语境下的行为须 P6 实机验证，
	 * 验证前 O5 仍 BLOCKED（见 CHK-009 / 立项文件 ③ 裁定）。若实机 FAIL，
	 * 按 CP2-001 P5 降级方案 B（自有 query arg wpc_page）并记 Deviation。
	 *
	 * @param array $args 已规范化的 shortcode 参数。
	 * @return string 分页 HTML（单页或不可分页时返回空串）。
	 */
	public function render_pagination( array $args ) {
		$post_id = $this->resolve_post_id( $args );
		if ( ! $post_id ) {
			return '';
		}

		$per_page = $this->per_page( $args );
		if ( $per_page <= 0 ) {
			return ''; // 未限制每页数 → 不分页。
		}

		$total = $this->count_comments( $post_id );
		if ( $total <= $per_page ) {
			return ''; // 单页，无需分页。
		}

		$max_pages = (int) ceil( $total / $per_page );
		$current   = $this->current_cpage();
		if ( $current > $max_pages ) {
			$current = $max_pages;
		}

		$links = array();
		for ( $i = 1; $i <= $max_pages; $i++ ) {
			$links[] = array(
				'page'    => $i,
				'url'     => get_comments_pagenum_link( $i ),
				'current' => ( $i === $current ),
			);
		}

		return $this->plugin->render_template(
			'comments-pager',
			array(
				'args'      => $args,
				'links'     => $links,
				'current'   => $current,
				'max_pages' => $max_pages,
				'total'     => $total,
			)
		);
	}
}
