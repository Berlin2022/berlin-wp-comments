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
		$pager = $this->render_pagination( $args ); // P5 待实现，当前返回 ''。
		$form  = '';                                // P3 待实现。

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

		// 分页方案待 OPEN_ITEMS ③（P5）；此处按每页条数切分（不接管分页链接）。
		$per_page = $this->per_page( $args );
		if ( $per_page > 0 ) {
			$list_args['per_page'] = $per_page;
			$list_args['page']     = 1;
		}

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
			$query_args['paged']  = 1;
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
	 * 渲染分页链接（P5 待实现，OPEN_ITEMS ③）。
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
}
