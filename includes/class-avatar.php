<?php
/**
 * 本地头像模块。
 *
 * 职责：把 WordPress 的头像解析从 Gravatar 切换到本地。
 *
 * 设计要点（详见记忆仓 05_KNOWLEDGE/TECHNICAL/WP_COMMENTS_ARCHITECTURE.md）：
 *
 *   1. 挂 `get_avatar_data`（不是 `get_avatar`）——只改 url + found_avatar，
 *      其余尺寸/class/lazyload 交给核心处理，一处挂钩全站生效。
 *
 *   2. ⚠️ 必须处理 not-found 分支。WP 的所有内置默认头像
 *      （mystery / blank / identicon / wavatar / monsterid / retro）
 *      **全部解析为 gravatar.com 远程 URL**。只替换"有本地头像的用户"
 *      不足以达成零 Gravatar 请求——无头像用户与全部访客仍会外联。
 *
 *   3. ⚠️ N+1 防护。本钩子每个头像调用一次，必须做请求内 memo +
 *      批量预热 meta 缓存。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Berlin_WP_Comments_Avatar
 *
 * 骨架状态：钩子未接线（register() 为空），解析方法为桩。
 */
class Berlin_WP_Comments_Avatar {

	/**
	 * 存放本地头像 attachment ID 的 user_meta 键。
	 *
	 * 存储形态（ID vs URL）待裁定，见 OPEN_ITEMS ⑤。
	 * 骨架期先按 attachment ID 方案预留键名。
	 */
	const META_KEY = 'bwpc_avatar_id';

	/**
	 * 派生 URL 缓存的 user_meta 键（双写方案，见 OPEN_ITEMS ⑤ 建议）。
	 */
	const META_KEY_URL = 'bwpc_avatar_url';

	/**
	 * 请求内解析结果 memo，防 N+1。
	 *
	 * 键为 user_id 或 email 的 hash，值为头像 URL 或 false。
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * 注册钩子。
	 *
	 * TODO[D1]：接线 get_avatar_data。骨架期不接线——保证插件激活后
	 * 站点头像行为完全不变（零副作用）。
	 *
	 * @return void
	 */
	public function register() {
		// TODO[D1]: add_filter( 'get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );
	}

	/**
	 * 过滤头像数据，替换为本地 URL。
	 *
	 * TODO[D1]：实现。步骤：
	 *   1. resolve_user_id( $id_or_email ) 取用户
	 *   2. 有本地头像 → $args['url'] = 本地 URL; $args['found_avatar'] = true
	 *   3. 无本地头像 → $args['url'] = 本地默认图; $args['found_avatar'] = true
	 *      （第 3 步是达成 O8 零 Gravatar 请求的关键，不可省）
	 *
	 * @param array $args        头像参数（含 url / found_avatar / size 等）。
	 * @param mixed $id_or_email user ID | WP_User | WP_Post | WP_Comment | email 字符串。
	 * @return array
	 */
	public function filter_avatar_data( $args, $id_or_email ) {
		unset( $id_or_email ); // 骨架期未使用。

		// TODO[D1]：实现本地头像解析。
		return $args;
	}

	/**
	 * 把多态的 $id_or_email 解析为 WP user ID。
	 *
	 * TODO[D1]：实现全部分支：
	 *   - int / 数字字符串  → user ID
	 *   - WP_User           → ->ID
	 *   - WP_Post           → ->post_author
	 *   - WP_Comment        → ->user_id > 0 ? user_id : （访客，返回 0）
	 *   - email 字符串      → get_user_by( 'email' )，未命中返回 0
	 *
	 * @param mixed $id_or_email 见上。
	 * @return int 命中的 user ID，访客或未命中返回 0。
	 */
	protected function resolve_user_id( $id_or_email ) {
		unset( $id_or_email );

		// TODO[D1]：实现多态解析。
		return 0;
	}

	/**
	 * 取指定用户的本地头像 URL。
	 *
	 * TODO[D1]：实现，含 memo 命中检查。
	 *
	 * @param int $user_id 用户 ID。
	 * @return string|false 头像 URL，无则 false。
	 */
	protected function get_local_avatar_url( $user_id ) {
		unset( $user_id );

		// TODO[D1]：读 META_KEY / META_KEY_URL。
		return false;
	}

	/**
	 * 取插件自带的本地默认头像 URL。
	 *
	 * TODO[D1]：实现，并在 assets/img/ 放置默认头像资源。
	 * 这是 O8（零 Gravatar 请求）的兜底出口。
	 *
	 * @return string
	 */
	public function get_default_avatar_url() {
		// TODO[D1]: return BWPC_PLUGIN_URL . 'assets/img/default-avatar.svg';
		return '';
	}

	/**
	 * 批量预热一页评论涉及的用户 meta，防 N+1（P10）。
	 *
	 * TODO[D1]：实现。收集评论中的 user_id 后一次性
	 * update_meta_cache( 'user', $user_ids )；若采用 attachment ID
	 * 方案，另需预热附件缓存。
	 *
	 * @param WP_Comment[] $comments 本页评论。
	 * @return void
	 */
	public function prime_cache_for_comments( array $comments ) {
		unset( $comments );

		// TODO[D1]：批量预热。
	}

	/**
	 * 注册用户头像上传的后台字段。
	 *
	 * TODO[D1]：挂 show_user_profile / edit_user_profile + personal_options_update
	 * / edit_user_profile_update。必须校验 nonce 与 capability，
	 * 并用 wp_check_filetype_and_ext() 验证文件类型（P8）。
	 *
	 * 注意：V1 只做**注册用户**头像（CP1 决策 D6）。
	 * 访客头像上传为 Deferred 项，不在 V1 实现。
	 *
	 * @return void
	 */
	public function register_profile_field() {
		// TODO[D1]：后台头像字段。
	}
}
