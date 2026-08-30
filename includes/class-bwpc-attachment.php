<?php
/**
 * 评论附件模块（v0.1.13+）。
 *
 * 职责：评论表单上传附件的"接 → 存 → 读 → 清理"全链路。
 *
 * 起源（SI-001 视觉主题 v0.1.12 落地后）：
 *   表单 UI 已预备 `<input type="file" name="bwpc_comment_attachment">` 与
 *   `<form enctype="multipart/form-data">`，但后端保存与前端展示均为零。
 *   v0.1.13 起接管 `$_FILES['bwpc_comment_attachment']`，复用 WP 核心
 *   `wp_handle_upload()` + `wp_insert_attachment()`（与媒体库同款，零自造管道），
 *   将附件注册到 Media Library，再以 comment_meta 关联评论。
 *
 * 生命周期钩子：
 *   - comment_post        保存（仅评论批准后挂附件；待审/垃圾不挂，避免清理复杂度）
 *   - deleted_comment     删除评论时同步清理附件
 *   - trash_comment       回收评论时同步清理附件
 *   - spam_comment        标垃圾评论时同步清理附件
 *
 * 安全策略：
 *   - MIME 白名单（默认 image/jpeg, image/png, image/webp, image/gif, application/pdf）
 *   - 大小上限（默认 5 MB）
 *   - 全部由过滤器可被站点覆盖：
 *       bwpc_attachment_allowed_mimes (string[])
 *       bwpc_attachment_max_bytes    (int)
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Bwpc_Comment_Attachment
 */
class Bwpc_Comment_Attachment {

	/** @var string 评论 meta：附件 ID。 */
	const META_ATTACHMENT_ID = '_bwpc_attachment_id';

	/** @var string 评论 meta：附件 URL（冗余存，加快读取；权威以 attachment id + wp_get_attachment_url() 为准）。 */
	const META_ATTACHMENT_URL = '_bwpc_attachment_url';

	/**
	 * 注册全部钩子。
	 *
	 * 由 Berlin_WP_Comments_Plugin::boot() 在 plugins_loaded 之后调用。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'comment_post',       array( $this, 'handle_upload' ), 10, 3 );
		add_action( 'deleted_comment',    array( $this, 'cleanup' ),       10, 2 );
		add_action( 'trash_comment',      array( $this, 'cleanup' ),       10, 2 );
		add_action( 'spam_comment',       array( $this, 'cleanup' ),       10, 2 );
	}

	/**
	 * 允许的 MIME 白名单（站点可经 bwpc_attachment_allowed_mimes 过滤器扩展）。
	 *
	 * 默认仅允许图片（jpeg/png/webp/gif）与 PDF；其它类型（如 zip/doc/xls）默拒。
	 *
	 * @return string[]
	 */
	protected function allowed_mimes() {
		$default = array(
			'image/jpeg',
			'image/png',
			'image/webp',
			'image/gif',
			'application/pdf',
		);
		/**
		 * Filter the allowed attachment MIME types for comment uploads.
		 *
		 * @param string[] $mimes Default allow-list.
		 */
		return (array) apply_filters( 'bwpc_attachment_allowed_mimes', $default );
	}

	/**
	 * 单文件大小上限（bytes，站点可经 bwpc_attachment_max_bytes 过滤器覆盖）。
	 *
	 * @return int
	 */
	protected function max_bytes() {
		$default = 5 * 1024 * 1024; // 5 MB
		return (int) apply_filters( 'bwpc_attachment_max_bytes', $default );
	}

	/**
	 * 评论提交钩子：把 $_FILES['bwpc_comment_attachment'] 入库到媒体库 + 关联 meta。
	 *
	 * 仅当评论状态为 approved 时挂附件（避免对 spam/trash 留附件导致清理复杂度）。
	 * 任何失败均静默返回——评论本身已成功提交，附件是附属，不应让其失败阻塞提交体验。
	 *
	 * @param int        $comment_id  WP 新评论 ID。
	 * @param int|string $approved    1 / 0 / 'spam' / 'trash' / 'approve' 等，与 WP 核心 comment_approved 标识同口径。
	 * @param array      $commentdata 提交时的评论数据（与 wp_new_comment 入参一致）。
	 * @return void
	 */
	public function handle_upload( $comment_id, $approved, $commentdata ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		// 防御：非本插件表单的提交（含他人共用 wp-comments-post.php、或核心默认表单），
		// $_FILES['bwpc_comment_attachment'] 通常为空字符串 / 不存在 → 直接返回。
		if ( empty( $_FILES['bwpc_comment_attachment']['name'] ) ) {
			return;
		}

		// 仅 approved 评论挂附件（spam/trash/pending 一律不挂）。
		$is_approved = ( 1 === (int) $approved || '1' === $approved || 'approve' === $approved );
		if ( ! $is_approved ) {
			return;
		}

		// PHPCS: $_FILES 是超全局；此处的比较来自 WP 文档（$_FILES['x']['error']）。
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$f = $_FILES['bwpc_comment_attachment'];
		if ( ! is_array( $f ) || empty( $f['name'] ) ) {
			return;
		}

		// 上传错误码检查。
		if ( ! empty( $f['error'] ) && UPLOAD_ERR_OK !== (int) $f['error'] ) {
			return;
		}

		// 大小校验（$f['size'] 在前端已被浏览器可知，但服务端仍需自验防绕过）。
		if ( ! empty( $f['size'] ) && (int) $f['size'] > $this->max_bytes() ) {
			return;
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		// 复用 WP 媒体库上传管道。
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$upload = wp_handle_upload(
			$f,
			array(
				'test_form' => false,                           // 不走 is_user_logged_in 测试，前台匿名用户可上传
				'mimes'     => $this->allowed_mimes(),
				'action'    => 'bwpc_comment_attachment',
			)
		);

		if ( ! is_array( $upload ) || empty( $upload['file'] ) ) {
			return;
		}
		if ( ! empty( $upload['error'] ) ) {
			return;
		}

		// 注册到 Media Library（得到 attachment ID）。
		$wp_filetype = wp_check_filetype( $upload['file'] );
		$attachment  = array(
			'guid'           => $upload['url'],
			'post_mime_type' => $wp_filetype['type'] ? $wp_filetype['type'] : 'application/octet-stream',
			'post_title'     => sanitize_file_name( basename( $upload['file'] ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);
		$attach_id = wp_insert_attachment( $attachment, $upload['file'] );
		if ( is_wp_error( $attach_id ) || ! $attach_id ) {
			return;
		}

		// 生成附件元数据（图片会生成 thumbnails；PDF 走默认元数据即可）。
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
		if ( is_array( $attach_data ) ) {
			wp_update_attachment_metadata( $attach_id, $attach_data );
		}

		// 关联到评论 meta。
		update_comment_meta( (int) $comment_id, self::META_ATTACHMENT_ID, (int) $attach_id );
		update_comment_meta( (int) $comment_id, self::META_ATTACHMENT_URL, esc_url_raw( $upload['url'] ) );
	}

	/**
	 * 清理钩子：评论被删/回收/标垃圾时同步删附件注册（物理删）+ 清 meta。
	 *
	 * @param int $comment_id 评论 ID。
	 * @return void
	 */
	public function cleanup( $comment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$comment_id = (int) $comment_id;
		if ( $comment_id <= 0 ) {
			return;
		}

		$aid = (int) get_comment_meta( $comment_id, self::META_ATTACHMENT_ID, true );
		if ( $aid > 0 ) {
			// 第二个参数 true = 真删（物理删除文件 + 数据库行），与 WP 媒体库交互保持一致。
			wp_delete_attachment( $aid, true );
		}

		delete_comment_meta( $comment_id, self::META_ATTACHMENT_ID );
		delete_comment_meta( $comment_id, self::META_ATTACHMENT_URL );
	}

	/**
	 * 渲染附件 HTML（评论模板调用：templates/comment.php）。
	 *
	 * 输出形态：
	 *   - 图片：缩略图包 `<a>`，点击看大图（target="_blank"，含 rel=noopener）。
	 *   - PDF/其它：📎 + 文件名链接。
	 *   - 无附件 / 附件已被清理 → 返回空串（调用方按需决定是否输出 <div> 容器）。
	 *
	 * @param int $comment_id 评论 ID。
	 * @return string 渲染好的 HTML；可能为空。
	 */
	public static function render_media( $comment_id ) {
		$comment_id = (int) $comment_id;
		if ( $comment_id <= 0 ) {
			return '';
		}

		$aid = (int) get_comment_meta( $comment_id, Bwpc_Comment_Attachment::META_ATTACHMENT_ID, true );
		if ( $aid <= 0 ) {
			return '';
		}

		$post = get_post( $aid );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			// attachment 已被 wp_delete_attachment 清掉（评论在前台展示时刚好附件被回收） → 静默返回。
			return '';
		}

		$mime = (string) $post->post_mime_type;
		$url  = (string) wp_get_attachment_url( $aid );
		if ( '' === $url ) {
			return '';
		}

		// 图片：thumbnail 包链接。
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$img = wp_get_attachment_image(
				$aid,
				'thumbnail',
				false,
				array(
					'loading' => 'lazy',
					'alt'     => '',
					'class'   => 'bwpc-comment__media-img',
				)
			);
			if ( $img ) {
				return '<a class="bwpc-comment__media-img-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . $img . '</a>';
			}
		}

		// PDF / 其它：文档链接卡（含 emoji icon + 文件名）。
		$label = $post->post_title ? $post->post_title : basename( $url );

		return '<a class="bwpc-comment__media-file" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">'
			. '<span class="bwpc-comment__media-file-icon" aria-hidden="true">📎</span>'
			. '<span class="bwpc-comment__media-file-name">' . esc_html( $label ) . '</span>'
			. '</a>';
	}
}
