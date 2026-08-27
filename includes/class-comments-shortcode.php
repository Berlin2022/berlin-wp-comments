<?php
/**
 * Shortcode 入口模块。
 *
 * 职责：注册 shortcode、规范化参数、条件加载资源、装配输出。
 *
 * CP1 指定调用形态：
 *
 *   [wp_comments]
 *   [wp_comments avatar_size="48"]
 *   [wp_comments comments_per_page="10"]
 *   [wp_comments show_avatar="yes"]
 *   [wp_comments avatar_size="48" comments_per_page="10"]
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Berlin_WP_Comments_Shortcode
 *
 * 骨架状态：shortcode 已注册（可验证接线），但返回占位注释，不产生实际输出。
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
	 * 注册 shortcode 与资源检测钩子。
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( BWPC_SHORTCODE, array( $this, 'handle' ) );

		// TODO[D4]：预检测 + 条件入队（见 WP_COMMENTS_ARCHITECTURE.md §9）。
		// add_action( 'wp', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Shortcode 处理器。
	 *
	 * TODO[D4]：装配实际输出。目标结构（CP1 指定）：
	 *
	 *     评论列表 → 分页 → 评论表单
	 *
	 * 骨架期返回 HTML 注释占位，使接线可在真实站点验证而不产生可见输出。
	 *
	 * @param array|string $atts Shortcode 属性。
	 * @return string
	 */
	public function handle( $atts ) {
		$args = $this->normalize_atts( $atts );
		unset( $args );

		// TODO[D4]：
		//   $this->ensure_assets();
		//   $out  = $this->renderer->render_list( $args );
		//   $out .= $this->renderer->render_pagination( $args );
		//   $out .= $this->form->render( $args );
		//   return $out;

		return '<!-- Berlin WP Comments ' . esc_html( BWPC_VERSION ) . ': 骨架版，功能未实现（CP1 指令 D8） -->';
	}

	/**
	 * 规范化 shortcode 参数。
	 *
	 * TODO[D4]：补齐类型与边界校验（avatar_size 上下限、
	 * comments_per_page 上限防滥用、show_avatar 布尔解析）。
	 *
	 * @param array|string $atts 原始属性。
	 * @return array 规范化后的参数。
	 */
	protected function normalize_atts( $atts ) {
		return shortcode_atts(
			array(
				// 头像边长（px）。
				'avatar_size'       => 48,
				// 每页评论数。空字符串表示沿用 WP 站点设置。
				'comments_per_page' => '',
				// 是否显示头像。
				'show_avatar'       => 'yes',
			),
			$atts,
			BWPC_SHORTCODE
		);
	}

	/**
	 * 确保资源已入队（兜底晚入队路径）。
	 *
	 * TODO[D4]：实现双策略——`wp` 钩子预检测为主，本方法为兜底，
	 * 覆盖 shortcode 出现在小工具/区块模板/页面构建器中的检测盲区。
	 *
	 * @return void
	 */
	protected function ensure_assets() {
		if ( $this->assets_done ) {
			return;
		}
		$this->assets_done = true;

		// TODO[D4]：wp_enqueue_style( 'bwpc-comments', ... )。
		//
		// 线程回复复用 WP 核心脚本，不计入插件自有 JS 体积：
		// if ( comments_open() && get_option( 'thread_comments' ) ) {
		//     wp_enqueue_script( 'comment-reply' );
		// }
	}
}
