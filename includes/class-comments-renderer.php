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
	 * 请求内评论全集缓存（post_id => WP_Comment[]），避免重复 get_comments。
	 *
	 * @var array
	 */
	private $all_comments_cache = array();

	/**
	 * 请求内根评论 ID 缓存（post_id => int[]）。
	 *
	 * @var array
	 */
	private $root_ids_cache = array();

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

		$html = $this->plugin->render_template(
			'comments',
			array(
				'args'  => $args,
				'list'  => $list,
				'pager' => $pager,
				'form'  => $form,
				'count' => $count,
			)
		);

		// 调试探针（?bwpc_debug=1，仅管理员）：**不短路**——红面板下方照常渲染真实
		// 评论区，既能定位「有计数却无内容 / 空分页」类实机问题，也能直接肉眼确认
		// 内容是否渲染。同时作为部署生效试纸：看到红面板=含 get_root_ids 的新码已生效。
		if ( ! empty( $_GET['bwpc_debug'] ) && current_user_can( 'manage_options' ) ) {
			$html = $this->debug_panel( $post_id, $args, $comments, $list ) . $html;
		}

		return $html;
	}

	/**
	 * 调试探针面板（?bwpc_debug=1，仅管理员）。
	 *
	 * 输出真实运行期数据，用于定位「有计数却无内容 / 空分页」类实机问题：
	 * 实际取回的评论数、comment_type 分布、comment_parent 分布、根评论判定、
	 * 分页参数与 query_comments 实际返回条数、最终列表 HTML 长度与片段。
	 *
	 * @param int          $post_id  解析到的对象 ID。
	 * @param array        $args     参数。
	 * @param WP_Comment[] $comments query_comments 实际返回的本页评论。
	 * @param string       $list     已生成的列表 HTML（真实渲染产物）。
	 * @return string 调试 HTML。
	 */
	protected function debug_panel( $post_id, array $args, array $comments, $list ) {
		$out = array(
			'BWPC_VERSION'       => defined( 'BWPC_VERSION' ) ? BWPC_VERSION : '(undefined)',
			'has_get_root_ids'   => method_exists( $this, 'get_root_ids' ),
			'post_id'            => $post_id,
			'post_type'          => $post_id ? get_post_type( $post_id ) : null,
		);

		$all = $this->get_all_approved_comments( $post_id );
		$out['all_count'] = count( $all );
		$out['all_types'] = array_values( array_unique( array_map( function ( $c ) { return $c->comment_type; }, $all ) ) );
		$out['all_parents'] = array_map( function ( $c ) { return (int) $c->comment_parent; }, $all );

		$out['root_ids']               = $this->get_root_ids( $post_id );
		$out['count_comments']         = $this->count_comments( $post_id );
		$out['per_page']               = $this->per_page( $args );
		$out['top_level_order']        = $this->top_level_order( $args );
		$out['cpage']                  = $this->current_cpage();
		$out['opt_page_comments']      = get_option( 'page_comments' );
		$out['opt_comments_per_page']  = get_option( 'comments_per_page' );
		$out['opt_default_comments_page'] = get_option( 'default_comments_page' );

		$out['query_comments_count'] = count( $comments );
		// 显式输出布尔/长度，避免 print_r 把 false/'' 都显示为空造成误判。
		$out['rendered_list_empty']  = ( '' === $list ) ? 'YES' : 'no';
		$out['list_html_len']        = is_string( $list ) ? strlen( $list ) : -1;
		$out['list_html_snippet']    = is_string( $list ) ? mb_substr( wp_strip_all_tags( $list ), 0, 200 ) : '';

		if ( $all ) {
			$sample        = (array) $all[0];
			unset( $sample['comment_content'] );
			$out['sample_comment_fields'] = array_keys( $sample );
			$out['sample_comment']        = $sample;
		}

		// 附件上传诊断（最近一次评论提交时由 handle_upload 写入 transient）。
		$up = function_exists( 'get_transient' ) ? get_transient( 'bwpc_last_upload_debug' ) : null;
		if ( $up ) {
			$out['last_upload_debug'] = $up;
		}

		$html  = '<div style="border:2px solid #c00;background:#fff;color:#111;padding:12px;margin:12px 0;font:12px/1.4 monospace;white-space:pre-wrap;word-break:break-all;">';
		$html .= '<strong>bwpc_debug</strong> — Berlin WP Comments 运行期探针<br><br>';
		$html .= esc_html( print_r( $out, true ) );
		$html .= '</div>';

		return $html;
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
			// ⚠️ P6 实机关键修复：站点开启 page_comments 时，wp_list_comments 会用
			// get_query_var('cpage') + comments_per_page 对传入的评论数组「再切一次片」。
			// 但本页评论已由 query_comments 按顶层 thread 切好（含完整后代），若放任 WP
			// 二次切片，第 2/3 页会被 array_slice 切空（实机症状：页码一致但内容为空）。
			// 显式 per_page=0 关闭 WP 自身的分页切片，仅让其按 comment_parent 重建嵌套。
			'per_page'    => 0,
			'page'        => 0,
		);

		// 分页已在 query_comments 层完成（AUDIT-008 ①：以顶层 thread 为单位的
		// number+offset + 后代补全）；当前页评论已是「完整 thread 集合」，此处仅交给
		// wp_list_comments 依 comment_parent 重建线程（per_page=0 已禁止其重复切片）。
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
	 * 取本页应显示的评论。
	 *
	 * P6 修正（实机：产品 9 条评论全部为「孤儿回复」——comment_parent 指向缺失 /
	 * 非本产品评论，被旧版 parent=0 过滤后列表恒空，致「有计数却无内容」）：
	 *
	 *   ① 一次性取回本产品全部已批准评论（含回复），在 PHP 层判定「根评论」——
	 *      parent=0，或 parent 指向不存在 / 非本产品已批准评论的孤儿回复。
	 *      根评论既作为分页单位，也能让孤儿回复正常展示（不再被 parent=0 过滤吞掉）。
	 *   ② 本页 = 根评论按日期方向切片（顶层 thread 顺序）+ 其完整后代，
	 *      从已取回的全集内筛选（无额外 DB 查询），wp_list_comments 依 comment_parent 重建嵌套。
	 *   ③ 实际消费 page_comments（分页总开关）与 default_comments_page（顶层排序方向）。
	 *   ④ cpage 越界（缓存陈旧 / rewrite 误解析）回落末页，与 WP 原生一致。
	 *
	 * @param int   $post_id 目标对象 ID。
	 * @param array $args    参数。
	 * @return WP_Comment[]
	 */
	protected function query_comments( $post_id, array $args ) {
		$all = $this->get_all_approved_comments( $post_id );
		if ( empty( $all ) ) {
			return array();
		}

		$root_ids = $this->get_root_ids( $post_id );
		if ( empty( $root_ids ) ) {
			return array();
		}

		// 根评论按日期方向排序（顶层 thread 顺序：newest=DESC / oldest=ASC）。
		$order = $this->top_level_order( $args );
		$all_by_id = array();
		foreach ( $all as $c ) {
			$all_by_id[ (int) $c->comment_ID ] = $c;
		}
		$root_comments = array_intersect_key( $all_by_id, array_flip( $root_ids ) );
		uasort(
			$root_comments,
			function ( $a, $b ) use ( $order ) {
				$cmp = strtotime( $a->comment_date ) - strtotime( $b->comment_date );
				return 'DESC' === $order ? -$cmp : $cmp;
			}
		);

		$per_page = $this->per_page( $args );
		if ( $per_page > 0 ) {
			// ④ cpage 越界回落末页，避免空列表（吻合「comment-page-2 无评论」实机症状）。
			$max_pages = max( 1, (int) ceil( count( $root_ids ) / $per_page ) );
			$cpage     = $this->current_cpage();
			if ( $cpage > $max_pages ) {
				$cpage = $max_pages;
			}
			$page_root_ids = array_slice( array_keys( $root_comments ), ( $cpage - 1 ) * $per_page, $per_page );
		} else {
			$page_root_ids = array_keys( $root_comments );
		}

		// ② 本页根评论 + 完整后代，按顶层 $order 排列各组（组内按 $all 的 ASC 顺序
		//    => 父评论先于子评论，wp_list_comments 才能依 comment_parent 正确重建嵌套）。
		//    顶层组顺序跟随 $order：DESC = 最新根评论在前（用户要求，2026-08-31）。
		$page_comments = array();
		foreach ( $page_root_ids as $rid ) {
			$rid = (int) $rid;
			$thread_ids = $this->collect_page_thread_ids( $all_by_id, array( $rid ) );
			foreach ( $all as $c ) { // $all 为 ASC（get_all_approved_comments order=ASC），保证父先于子。
				if ( in_array( (int) $c->comment_ID, $thread_ids, true ) ) {
					$page_comments[] = $c;
				}
			}
		}

		return $page_comments;
	}

	/**
	 * 已批准评论总数（用于标题计数：含全部回复）。
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
	 * 取本产品全部已批准评论（含回复），供线程重建与分页。
	 *
	 * 一次性取回（产品评论量通常有限，V1 可接受），避免逐页 DB 查询；
	 * 后续根判定 / 后代补全均在本数据集内完成。结果按请求内 post_id 缓存。
	 *
	 * @param int $post_id 对象 ID。
	 * @return WP_Comment[]
	 */
	protected function get_all_approved_comments( $post_id ) {
		if ( ! isset( $this->all_comments_cache[ $post_id ] ) ) {
			$comments = get_comments(
				array(
					'post_id' => $post_id,
					'status'  => 'approve',
					'type'    => 'comment',
					'number'  => 0,
					'order'   => 'ASC',
				)
			);
			$this->all_comments_cache[ $post_id ] = is_array( $comments ) ? $comments : array();
		}

		return $this->all_comments_cache[ $post_id ];
	}

	/**
	 * 计算「根评论」ID 集合。
	 *
	 * 根 = comment_parent=0，或 parent 指向不存在 / 非本产品已批准评论的「孤儿回复」。
	 * 这样即便评论数据全是回复（父评论缺失 / 被删），也能作为根正常展示，
	 * 避免「有计数却无内容」（P6 实机：产品 9 条评论全部为孤儿回复 → 旧 parent=0 过滤致列表为空）。
	 *
	 * @param int $post_id 对象 ID。
	 * @return int[] 根评论 ID。
	 */
	protected function get_root_ids( $post_id ) {
		if ( ! isset( $this->root_ids_cache[ $post_id ] ) ) {
			$all   = $this->get_all_approved_comments( $post_id );
			$id_set = array_flip( array_map( 'intval', wp_list_pluck( $all, 'comment_ID' ) ) );
			$roots = array();
			foreach ( $all as $c ) {
				$pid = (int) $c->comment_parent;
				if ( 0 === $pid || ! isset( $id_set[ $pid ] ) ) {
					$roots[] = (int) $c->comment_ID;
				}
			}
			$this->root_ids_cache[ $post_id ] = $roots;
		}

		return $this->root_ids_cache[ $post_id ];
	}

	/**
	 * 顶层（根）评论总数，用于分页分母。
	 *
	 * P6 修正：分页单位 = 根评论（含孤儿回复根），而非机械按 parent=0 过滤。
	 * 与 query_comments() 共用 get_root_ids()，杜绝计数 / 列表口径分叉。
	 *
	 * @param int $post_id 对象 ID。
	 * @return int
	 */
	protected function count_top_level_comments( $post_id ) {
		return count( $this->get_root_ids( $post_id ) );
	}

	/**
	 * 从全集（all_by_id）中收集给定根评论的完整后代 ID（含根本身）。
	 *
	 * 基于预建的 children_map 递归，无额外 DB 查询（AUDIT-008 ① 线程不被切断）。
	 *
	 * @param WP_Comment[] $all_by_id 按 comment_ID 索引的评论全集。
	 * @param int[]        $root_ids  本页根评论 ID。
	 * @return int[] 根 + 后代 ID 集合。
	 */
	protected function collect_page_thread_ids( array $all_by_id, array $root_ids ) {
		$children_map = array();
		foreach ( $all_by_id as $c ) {
			$children_map[ (int) $c->comment_parent ][] = (int) $c->comment_ID;
		}

		$result = array();
		foreach ( $root_ids as $rid ) {
			$rid   = (int) $rid;
			$result[] = $rid;
			$queue = array( $rid );
			while ( ! empty( $queue ) ) {
				$pid = array_pop( $queue );
				if ( empty( $children_map[ $pid ] ) ) {
					continue;
				}
				foreach ( $children_map[ $pid ] as $child_id ) {
					if ( ! in_array( $child_id, $result, true ) ) {
						$result[] = $child_id;
						$queue[]  = $child_id;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * 计算每页评论数（= 每页顶层 thread 数）。
	 *
	 * AUDIT-008 REQUIRED CORRECTION ②：实际消费 page_comments 作为分页总开关，
	 * 而非仅读 comments_per_page。显式 shortcode 覆盖优先（不受总开关限制）。
	 *
	 * @param array $args 参数。
	 * @return int 0 表示不分页。
	 */
	protected function per_page( array $args ) {
		// 显式 shortcode 覆盖优先：用户明确指定每页数时不受 page_comments 总开关限制。
		if ( isset( $args['comments_per_page'] ) && is_numeric( $args['comments_per_page'] ) && (int) $args['comments_per_page'] > 0 ) {
			return (int) $args['comments_per_page'];
		}

		// 实际消费 page_comments：站点关闭评论分页时，插件不自行分页。
		if ( ! get_option( 'page_comments', 0 ) ) {
			return 0;
		}

		$opt = (int) get_option( 'comments_per_page', 0 );

		return $opt > 0 ? $opt : 0;
	}

	/**
	 * 顶层 thread 排序方向（AUDIT-008 REQUIRED CORRECTION ②）。
	 *
	 * 实际消费 default_comments_page：'newest' → DESC（首页为最新 thread），
	 * 否则 ASC（首页为最早 thread）。cpage 仍从 1 递增，与 offset 切片一致。
	 *
	 * @param array $args 参数。
	 * @return string 'ASC' | 'DESC'
	 */
	protected function top_level_order( array $args ) {
		// 产品站评论列表固定「最新评论在前」（用户明确要求，2026-08-31）；
		// 若需跟随 WP 后台「default_comments_page」设置，改回下方判断即可。
		return 'DESC';
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
	 *   自己的「顶层 thread 计数」与每页数推导（AUDIT-008 ①），避免依赖 $wp_query->max_num_comment_pages。
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

		$total = $this->count_top_level_comments( $post_id ); // AUDIT-008 ①：分页分母 = 顶层 thread 数
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
