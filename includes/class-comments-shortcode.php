<?php
/**
 * Shortcode 入口模块。
 *
 * 职责：注册 shortcode、规范化参数、条件加载资源、装配输出。
 *
 * CP1 指定调用形态：
 *
 *   [berlin_comments]
 *   [berlin_comments avatar_size="48"]
 *   [berlin_comments comments_per_page="10"]
 *   [berlin_comments show_avatar="yes"]
 *   [berlin_comments avatar_size="48" comments_per_page="10"]
 *
 * O1 裁定（OPEN_ITEMS ① CLOSED）：canonical `[berlin_comments]` + 别名
 * `[wp_comments]`（兼容既有内容，不静默覆盖他人标签）。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Berlin_WP_Comments_Shortcode
 *
 * P4 实现：落实骨架 D4 阶段桩（注册 canonical+alias、条件入队资源、装配真实输出）。
 */
class Berlin_WP_Comments_Shortcode {

	/**
	 * 评论渲染模块。
	 *
	 * @var Berlin_WP_Comments_Renderer
	 */
	private $renderer;

	/**
	 * 评论表单模块。
	 *
	 * @var Berlin_WP_Comments_Form
	 */
	private $form;

	/**
	 * 本请求内资源是否已入队。
	 *
	 * @var bool
	 */
	private $assets_done = false;

	/**
	 * 构造。
	 *
	 * @param Berlin_WP_Comments_Renderer $renderer 渲染模块。
	 * @param Berlin_WP_Comments_Form     $form     表单模块。
	 */
	public function __construct( Berlin_WP_Comments_Renderer $renderer, Berlin_WP_Comments_Form $form ) {
		$this->renderer = $renderer;
		$this->form     = $form;
	}

	/**
	 * 注册 shortcode 与资源条件入队钩子。
	 *
	 * O1：canonical [berlin_comments] + alias [wp_comments] 共用同一处理器。
	 * O9：仅当页面含本 shortcode 才入队资源（wp 预检测为主，handle 兜底）。
	 *
	 * @return void
	 */
	public function register() {
		// O1：canonical + alias 均不得静默覆盖已有同名 shortcode handler。
		// WordPress 的 add_shortcode() 不会自动保护已有注册，故以 shortcode_exists() 守卫：
		// 若外部（主题/其他插件）已先注册同名标签，保留其 handler，本插件不抢占。
		if ( ! shortcode_exists( BWPC_SHORTCODE ) ) {
			add_shortcode( BWPC_SHORTCODE, array( $this, 'handle' ) );
		}
		if ( ! shortcode_exists( BWPC_SHORTCODE_ALIAS ) ) {
			add_shortcode( BWPC_SHORTCODE_ALIAS, array( $this, 'handle' ) );
		}

		// O9 轻量：wp 钩子预检测页面内容是否含 shortcode（先于 wp_enqueue_scripts，
		// 资源可正常进入 <head>）；小工具/区块模板等盲区由 handle() 兜底。
		add_action( 'wp', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Shortcode 处理器。
	 *
	 * 目标结构（CP1 指定）：评论列表 → 分页 → 评论表单。
	 * 由 Berlin_WP_Comments_Renderer::render_list() 统一装配
	 * （其内部已包含分页占位与表单接入，避免重复输出）。
	 *
	 * @param array|string $atts Shortcode 属性。
	 * @return string
	 */
	public function handle( $atts ) {
		$args = $this->normalize_atts( $atts );

		// O9 兜底：确保资源入队（覆盖 wp 预检测盲区）。
		$this->ensure_assets();

		return $this->renderer->render_list( $args );
	}

	/**
	 * 规范化 shortcode 参数（含边界校验）。
	 *
	 * @param array|string $atts 原始属性。
	 * @return array 规范化后的参数。
	 */
	protected function normalize_atts( $atts ) {
		$a = shortcode_atts(
			array(
				// 头像边长（px）。
				'avatar_size'       => 48,
				// 每页评论数。空字符串表示沿用 WP 站点设置。
				'comments_per_page' => '',
				// 是否显示头像。
				'show_avatar'       => 'yes',
				// 目标对象 ID。默认当前对象；④ 仅当前对象，不接受聚合。
				'post_id'           => 0,
			),
			$atts,
			BWPC_SHORTCODE
		);

		// avatar_size 限幅 16–256（O9 轻量 / 防滥用）。
		$a['avatar_size'] = (int) $a['avatar_size'];
		$a['avatar_size'] = min( 256, max( 16, $a['avatar_size'] ) );

		// comments_per_page：整数 1–100，否则沿用 WP 站点设置（空串）。
		if ( '' !== $a['comments_per_page'] && is_numeric( $a['comments_per_page'] ) ) {
			$a['comments_per_page'] = min( 100, max( 1, (int) $a['comments_per_page'] ) );
		} else {
			$a['comments_per_page'] = '';
		}

		// show_avatar 布尔解析。
		$a['show_avatar'] = in_array( $a['show_avatar'], array( 'no', 'false', '0' ), true ) ? 'no' : 'yes';

		// post_id 仅接受数字（④ 聚合防御）。
		$a['post_id'] = is_numeric( $a['post_id'] ) ? (int) $a['post_id'] : 0;

		return $a;
	}

	/**
	 * wp 钩子预检测：页面内容含本 shortcode 时入队资源。
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets() {
		if ( $this->assets_done ) {
			return;
		}

		$post = get_post();
		if ( empty( $post ) || empty( $post->ID ) ) {
			return;
		}

		if ( has_shortcode( $post->post_content, BWPC_SHORTCODE )
			|| has_shortcode( $post->post_content, BWPC_SHORTCODE_ALIAS ) ) {
			$this->enqueue_assets();
		}
	}

	/**
	 * 兜底晚入队（handle 内调用，覆盖小工具/区块模板盲区）。
	 *
	 * @return void
	 */
	protected function ensure_assets() {
		if ( $this->assets_done ) {
			return;
		}
		$this->enqueue_assets();
	}

	/**
	 * 实际入队资源（O9 轻量：仅 CSS；JS 保持 0 KB 复用 WP 核心）。
	 *
	 * @return void
	 */
	protected function enqueue_assets() {
		if ( $this->assets_done ) {
			return;
		}
		$this->assets_done = true;

		wp_enqueue_style(
			'bwpc-comments',
			BWPC_PLUGIN_URL . 'assets/css/comments.css',
			array(),
			BWPC_VERSION,
			'all'
		);
	}
}
