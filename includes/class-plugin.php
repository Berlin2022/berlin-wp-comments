<?php
/**
 * 插件引导类。
 *
 * 职责：单例、模块装配、i18n、模板定位。
 * 不含任何评论或头像业务逻辑——那些属于各自模块。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Berlin_WP_Comments_Plugin
 *
 * 骨架状态：装配已接线，业务方法为桩。
 */
final class Berlin_WP_Comments_Plugin {

	/**
	 * 单例。
	 *
	 * @var Berlin_WP_Comments_Plugin|null
	 */
	private static $instance = null;

	/**
	 * 头像模块。
	 *
	 * @var Berlin_WP_Comments_Avatar
	 */
	public $avatar;

	/**
	 * 评论渲染模块。
	 *
	 * @var Berlin_WP_Comments_Renderer
	 */
	public $renderer;

	/**
	 * 评论表单模块。
	 *
	 * @var Berlin_WP_Comments_Form
	 */
	public $form;

	/**
	 * Shortcode 模块。
	 *
	 * @var Berlin_WP_Comments_Shortcode
	 */
	public $shortcode;

	/**
	 * 取单例。
	 *
	 * @return Berlin_WP_Comments_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->boot();
		}
		return self::$instance;
	}

	/**
	 * 私有构造，强制走 instance()。
	 */
	private function __construct() {}

	/**
	 * 装配模块并注册钩子。
	 *
	 * @return void
	 */
	private function boot() {
		$this->avatar    = new Berlin_WP_Comments_Avatar();
		$this->renderer  = new Berlin_WP_Comments_Renderer( $this );
		$this->form      = new Berlin_WP_Comments_Form( $this );
		$this->shortcode = new Berlin_WP_Comments_Shortcode( $this->renderer, $this->form );

		// 各模块自行注册钩子。
		$this->avatar->register();
		$this->shortcode->register();

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * 载入翻译（P11：从第一天做 i18n）。
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain(
			'berlin-wp-comments',
			false,
			dirname( plugin_basename( BWPC_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * 定位模板文件，支持主题覆盖（P9）。
	 *
	 * 查找顺序（用户定制不被插件更新覆盖）：
	 *   1. {子主题}/berlin-wp-comments/{$name}.php
	 *   2. {父主题}/berlin-wp-comments/{$name}.php
	 *   3. {插件}/templates/{$name}.php
	 *
	 * P2 实现：消费骨架 TODO[D2]。
	 *
	 * @param string $name 模板名，不含扩展名。
	 * @return string 模板绝对路径；未找到返回空串。
	 */
	public function locate_template( $name ) {
		$name = sanitize_file_name( $name );
		$base = 'berlin-wp-comments/' . $name . '.php';

		// 1. 子主题（最高优先，用户定制不被插件更新覆盖）。
		$child = get_stylesheet_directory() . '/' . $base;
		if ( is_readable( $child ) ) {
			return $child;
		}

		// 2. 父主题。
		$parent = get_template_directory() . '/' . $base;
		if ( is_readable( $parent ) ) {
			return $parent;
		}

		// 3. 插件自带模板。
		$plugin = BWPC_PLUGIN_DIR . 'templates/' . $name . '.php';
		if ( is_readable( $plugin ) ) {
			return $plugin;
		}

		return '';
	}

	/**
	 * 渲染模板并返回字符串。
	 *
	 * P2 实现：消费骨架 TODO[D2]。输出缓冲 + 变量作用域隔离（extract EXTR_SKIP）。
	 *
	 * @param string $name 模板名。
	 * @param array  $vars 传入模板的变量。
	 * @return string
	 */
	public function render_template( $name, array $vars = array() ) {
		$path = $this->locate_template( $name );
		if ( ! $path ) {
			return '';
		}

		if ( $vars ) {
			extract( $vars, EXTR_SKIP );
		}

		ob_start();
		include $path;
		return (string) ob_get_clean();
	}

	/**
	 * 禁止克隆。
	 */
	private function __clone() {}

	/**
	 * 禁止反序列化。
	 *
	 * @throws Exception 总是抛出。
	 */
	public function __wakeup() {
		throw new Exception( 'Berlin_WP_Comments_Plugin is a singleton and cannot be unserialized.' );
	}
}
