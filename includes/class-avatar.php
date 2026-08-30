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
 *   2. ⚠️ 处理 not-found 分支（陷阱 A）。WP 的所有内置默认头像
 *      （mystery / blank / identicon / watar / monsterid / retro）
 *      **全部解析为 gravatar.com 远程 URL**。只替换"有本地头像的用户"
 *      不足以达成零 Gravatar 请求——无本地头像用户与全部访客仍会外联。
 *      故本模块对「无本地头像」也强制置 found_avatar=true + 本地默认图。
 *
 *   3. ⚠️ N+1 防护（陷阱 B）。本钩子每个头像调用一次，做请求内 memo
 *      + 批量预热 user_meta 缓存（prime_cache_for_comments）。
 *
 *   4. 存储形态（AUDIT-001 ⑤ 修正）：仅存 attachment_id（canonical），
 *      URL 由 wp_get_attachment_image_url() 派生，**禁双写 URL meta**。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Berlin_WP_Comments_Avatar
 *
 * P1 实现：钩子已接线，解析方法已实现。
 */
class Berlin_WP_Comments_Avatar {

	/**
	 * 存放本地头像 attachment ID 的 user_meta 键（canonical 存储，AUDIT-001 ⑤）。
	 */
	const META_KEY = 'bwpc_avatar_id';

	/**
	 * 请求内解析结果 memo，防 N+1。
	 *
	 * 键为 'u{user_id}'（注册用户）或 'guest'（访客/无头像），值为头像 URL。
	 *
	 * @var array
	 */
	private $memo = array();

	/**
	 * 注册钩子。
	 *
	 * 接线 get_avatar_data（O8 零 Gravatar 的基础），并注册后台头像上传字段
	 * （仅注册用户，CP1 决策 D6；访客上传为 Deferred）。
	 *
	 * @return void
	 */
	public function register() {
		add_filter( 'get_avatar_data', array( $this, 'filter_avatar_data' ), 10, 2 );

		add_action( 'show_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'edit_user_profile', array( $this, 'render_profile_field' ) );
		add_action( 'personal_options_update', array( $this, 'save_profile_field' ) );
		add_action( 'edit_user_profile_update', array( $this, 'save_profile_field' ) );
	}

	/**
	 * 过滤头像数据，替换为本地 URL（零 Gravatar）。
	 *
	 * @param array $args        头像参数（含 url / found_avatar / size 等）。
	 * @param mixed $id_or_email user ID | WP_User | WP_Post | WP_Comment | email 字符串。
	 * @return array
	 */
	public function filter_avatar_data( $args, $id_or_email ) {
		$size    = isset( $args['size'] ) ? (int) $args['size'] : 96;
		$user_id = $this->resolve_user_id( $id_or_email );

		$memo_key = $user_id > 0 ? 'u' . $user_id : 'guest';

		if ( isset( $this->memo[ $memo_key ] ) ) {
			$args['url']          = $this->memo[ $memo_key ];
			$args['found_avatar'] = true;
			return $args;
		}

		$url = '';
		if ( $user_id > 0 ) {
			$url = $this->get_local_avatar_url( $user_id, $size );
		}

		// 陷阱 A：无本地头像也强制本地默认图 + found_avatar=true，杜绝 gravatar.com 外联。
		if ( empty( $url ) ) {
			$url = $this->get_default_avatar_url();
		}

		$this->memo[ $memo_key ] = $url;

		if ( ! empty( $url ) ) {
			$args['url']          = $url;
			$args['found_avatar'] = true;
		}

		return $args;
	}

	/**
	 * 把多态的 $id_or_email 解析为 WP user ID。
	 *
	 * @param mixed $id_or_email 见上。
	 * @return int 命中的 user ID，访客或未命中返回 0。
	 */
	protected function resolve_user_id( $id_or_email ) {
		if ( is_numeric( $id_or_email ) ) {
			return (int) $id_or_email;
		}
		if ( $id_or_email instanceof WP_User ) {
			return (int) $id_or_email->ID;
		}
		if ( $id_or_email instanceof WP_Post ) {
			return (int) $id_or_email->post_author;
		}
		if ( $id_or_email instanceof WP_Comment ) {
			return (int) $id_or_email->user_id;
		}
		if ( is_string( $id_or_email ) && is_email( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			return $user ? (int) $user->ID : 0;
		}
		return 0;
	}

	/**
	 * 取指定用户的本地头像 URL（派生自 attachment_id）。
	 *
	 * @param int $user_id 用户 ID。
	 * @param int $size    像素尺寸（传数组给 wp_get_attachment_image_url 生成缩略）。
	 * @return string|false 头像 URL，无则 false。
	 */
	protected function get_local_avatar_url( $user_id, $size = 96 ) {
		$attachment_id = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( empty( $attachment_id ) ) {
			return false;
		}
		$url = wp_get_attachment_image_url( $attachment_id, array( $size, $size ) );
		return $url ? $url : false;
	}

	/**
	 * 取插件自带的本地默认头像 URL（O8 兜底出口）。
	 *
	 * @return string
	 */
	public function get_default_avatar_url() {
		return BWPC_PLUGIN_URL . 'assets/img/default-avatar.svg';
	}

	/**
	 * 批量预热一页评论涉及的用户 meta，防 N+1（陷阱 B）。
	 *
	 * @param WP_Comment[] $comments 本页评论。
	 * @return void
	 */
	public function prime_cache_for_comments( array $comments ) {
		$user_ids = array();
		foreach ( $comments as $comment ) {
			if ( $comment instanceof WP_Comment && (int) $comment->user_id > 0 ) {
				$user_ids[] = (int) $comment->user_id;
			}
		}
		if ( ! empty( $user_ids ) ) {
			update_meta_cache( 'user', $user_ids );
		}
	}

	/**
	 * 注册用户头像上传的后台字段（仅注册用户，D6）。
	 *
	 * @param WP_User $user 被编辑用户。
	 * @return void
	 */
	public function render_profile_field( $user ) {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}

		$attachment_id = (int) get_user_meta( $user->ID, self::META_KEY, true );

		wp_nonce_field( 'bwpc_avatar_' . $user->ID, 'bwpc_avatar_nonce' );
		?>
		<h2><?php esc_html_e( 'Berlin WP Comments Avatar', 'berlin-wp-comments' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="bwpc_avatar_file"><?php esc_html_e( 'Local Avatar', 'berlin-wp-comments' ); ?></label></th>
				<td>
					<?php if ( $attachment_id ) : ?>
						<?php echo wp_kses_post( wp_get_attachment_image( $attachment_id, 'thumbnail' ) ); ?><br />
					<?php endif; ?>
					<input type="file" name="bwpc_avatar_file" id="bwpc_avatar_file" accept="image/jpeg,image/png,image/gif,image/webp" />
					<?php if ( $attachment_id ) : ?>
						<br /><label><input type="checkbox" name="bwpc_avatar_remove" value="1" /> <?php esc_html_e( 'Remove current avatar', 'berlin-wp-comments' ); ?></label>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'Upload a JPG/PNG/GIF/WebP image as your comment avatar. V1 supports registered users only; guest uploads coming later.', 'berlin-wp-comments' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * 保存后台头像上传（落 user_meta attachment_id；替换时删除旧附件）。
	 *
	 * 安全：nonce 校验 + capability 校验 + wp_check_filetype_and_ext 类型校验。
	 *
	 * @param int $user_id 被保存用户 ID。
	 * @return void
	 */
	public function save_profile_field( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		if ( empty( $_POST['bwpc_avatar_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['bwpc_avatar_nonce'] ) ), 'bwpc_avatar_' . $user_id ) ) {
			return;
		}

		// 移除逻辑。
		if ( ! empty( $_POST['bwpc_avatar_remove'] ) ) {
			$old = (int) get_user_meta( $user_id, self::META_KEY, true );
			if ( $old ) {
				wp_delete_attachment( $old, true );
			}
			delete_user_meta( $user_id, self::META_KEY );
			return;
		}

		if ( empty( $_FILES['bwpc_avatar_file'] ) || empty( $_FILES['bwpc_avatar_file']['name'] ) ) {
			return;
		}

		$file = $_FILES['bwpc_avatar_file'];

		// 类型校验（D6 坑点：file type / MIME）。
		$allowed = array(
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
		);
		$check   = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed );
		if ( empty( $check['type'] ) || empty( $check['ext'] ) ) {
			return; // 类型不允许，静默忽略。
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_handle_upload( $file, array( 'test_form' => false ) );
		if ( empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
			return;
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $upload['type'],
				'post_title'     => sprintf( 'Avatar %d', $user_id ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		if ( ! $attachment_id ) {
			return;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		// 替换旧头像（删除旧附件，避免孤立文件）。
		$old = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( $old && $old !== $attachment_id ) {
			wp_delete_attachment( $old, true );
		}
		update_user_meta( $user_id, self::META_KEY, $attachment_id );
	}
}
