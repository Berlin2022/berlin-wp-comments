<?php
/**
 * 评论附件模块（v0.1.13+）。
 *
 * 职责：评论表单上传附件的"接 → 存 → 读 → 清理"全链路。
 *
 * 起源（SI-001 视觉主题 v0.1.12 落地后）：
 *   表单 UI 已预备 `<input type="file" name="bwpc_comment_attachment">` 与
 *   `<form enctype="multipart/form-data">`，但后端保存与前端展示均为零。
 *   v0.1.13 起接管 `$_FILES['bwpc_comment_attachment']`，以 comment_meta 关联评论。
 *   v0.1.18 起物理持久化委托给 Storage Provider（includes/class-bwpc-attachment-storage.php）：
 *   本适配层只依赖 `Bwpc_Attachment_Storage` 接口，默认 `Bwpc_Attachment_Storage_WP`
 *   封装 WP 核心 `wp_handle_upload()` + `wp_insert_attachment()`（与媒体库同款，零自造管道）。
 *   未来可注入 R2 等对象存储提供方，评论核心与适配层代码不变（ATT-P002 Storage Agnostic）。
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

	/** @var string 评论 meta：附件 ID（已批准评论正式关联）。 */
	const META_ATTACHMENT_ID = '_bwpc_attachment_id';

	/** @var string 评论 meta：附件 URL（冗余存，加快读取；权威以 attachment id + wp_get_attachment_url() 为准）。 */
	const META_ATTACHMENT_URL = '_bwpc_attachment_url';

	/** @var string 评论 meta：待审附件 ID（提交时即上传入库，评论批准后由 on_approve() 转正）。 */
	const META_ATTACHMENT_PENDING = '_bwpc_attachment_pending';

	/**
	 * 存储提供方（ATTACHMENT-001 §8 Storage Adapter Boundary）。
	 *
	 * null = 使用默认 WP 媒体库提供方（惰性构建，确保过滤器时序）。
	 * 未来可注入 Bwpc_Attachment_Storage_R2 等实现，附件适配层代码不变。
	 *
	 * @var Bwpc_Attachment_Storage|null
	 */
	private $storage;

	/**
	 * 注册全部钩子。
	 *
	 * 由 Berlin_WP_Comments_Plugin::boot() 在 plugins_loaded 之后调用。
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'comment_post',            array( $this, 'handle_upload' ), 10, 3 );
		add_action( 'transition_comment_status', array( $this, 'on_approve' ), 10, 3 );
		add_action( 'deleted_comment',         array( $this, 'cleanup' ),       10, 2 );
		add_action( 'trash_comment',           array( $this, 'cleanup' ),       10, 2 );
		add_action( 'spam_comment',            array( $this, 'cleanup' ),       10, 2 );
	}

	/**
	 * 构造（允许注入存储提供方，默认使用 WP 媒体库）。
	 *
	 * @param Bwpc_Attachment_Storage|null $storage 可选自定义存储提供方（如 R2）。
	 * @return void
	 */
	public function __construct( $storage = null ) {
		$this->storage = $storage;
	}

	/**
	 * 取存储提供方（惰性构建默认 WP 实现）。
	 *
	 * 默认提供方在首次使用时构建，确保 allowed_mimes / max_bytes 过滤器
	 * 在评论提交时刻（comment_post，晚于 init）才求值，而非激活期冻结。
	 *
	 * @return Bwpc_Attachment_Storage
	 */
	private function storage() {
		if ( null === $this->storage ) {
			$this->storage = new Bwpc_Attachment_Storage_WP( $this->allowed_mimes(), $this->max_bytes() );
		}
		return $this->storage;
	}

	/**
	 * 允许的 MIME 白名单（站点可经 bwpc_attachment_allowed_mimes 过滤器扩展）。
	 *
	 * 默认仅允许图片（jpeg/png/webp/gif）与 PDF；其它类型（如 zip/doc/xls）默拒。
	 *
	 * @return string[]
	 */
	protected function allowed_mimes() {
		// 注意：wp_handle_upload() 的 `mimes` 参数期望 'ext' => 'mime' 映射
		// （与 get_allowed_mime_types() 同格式），不能传纯 MIME 数组，
		// 否则旧 WP 版本下 wp_check_filetype_and_ext() 按扩展名正则匹配失败 → 上传被拒。
		$default = array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
			'webp'         => 'image/webp',
			'gif'          => 'image/gif',
			'pdf'          => 'application/pdf',
		);
		/**
		 * Filter the allowed attachment MIME types for comment uploads.
		 *
		 * @param array $mimes Default allow-list ('ext' => 'mime').
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

		// PHPCS: $_FILES 是超全局；此处的比较来自 WP 文档（$_FILES['x']['error']）。
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput
		$f = $_FILES['bwpc_comment_attachment'];
		if ( ! is_array( $f ) || empty( $f['name'] ) ) {
			return;
		}

		// 上传错误码检查。
		if ( ! empty( $f['error'] ) && UPLOAD_ERR_OK !== (int) $f['error'] ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[BWPC] attachment upload PHP error code %d (comment %d)', (int) $f['error'], (int) $comment_id ) );
			}
			return;
		}
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput

		// 持久化委托给 Storage Provider（ATTACHMENT-001 §8）：
		// 适配层不直接调用 wp_handle_upload / wp_insert_attachment，仅依赖接口。
		// 注意：无论评论是否已批准都先入库——后台批准时 $_FILES 早已不可用，
		// 必须在提交这一刻完成 store（待审评论的附件用 _bwpc_attachment_pending 暂存，
		// 由 on_approve() 在评论批准时转正）。
		$attach_id = $this->storage()->store( $f );
		if ( ! $attach_id ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( sprintf( '[BWPC] attachment store() failed for comment %d (file=%s) — check uploads dir perms / MIME / size', (int) $comment_id, $f['name'] ) );
			}
			// 上传 / 插入失败均静默返回——评论本身已成功提交（#16 不破坏评论核心）；
			// 插入失败产生的孤儿物理文件由 Provider::store() 内部 @unlink 兜底（#12 修正）。
			return;
		}

		$is_approved = ( 1 === (int) $approved || '1' === $approved || 'approve' === $approved );
		if ( $is_approved ) {
			update_comment_meta( (int) $comment_id, self::META_ATTACHMENT_ID, (int) $attach_id );
			update_comment_meta( (int) $comment_id, self::META_ATTACHMENT_URL, esc_url_raw( $this->storage()->get_url( $attach_id ) ) );
		} else {
			// 待审评论：文件已落媒体库，暂存 pending；批准后由 on_approve() 转正为正式关联。
			update_comment_meta( (int) $comment_id, self::META_ATTACHMENT_PENDING, (int) $attach_id );
		}
	}

	/**
	 * 评论状态变更钩子：待审评论被批准时，把暂存附件转正为正式关联。
	 *
	 * 提交时刻 $_FILES 已不可用，故 handle_upload() 对 pending 评论先把文件入库到
	 * 媒体库并写入 _bwpc_attachment_pending；此处（后台批准）再把 pending 移到正式
	 * _bwpc_attachment_id，render_media() 即可读取显示。
	 *
	 * @param string     $new_status 新状态（approved / unapproved / spam / trash）。
	 * @param string     $old_status 旧状态。
	 * @param WP_Comment $comment    评论对象。
	 * @return void
	 */
	public function on_approve( $new_status, $old_status, $comment ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( 'approved' !== $new_status || 'approved' === $old_status ) {
			return;
		}
		if ( ! $comment instanceof WP_Comment ) {
			return;
		}
		$comment_id = (int) $comment->comment_ID;
		$pid        = (int) get_comment_meta( $comment_id, self::META_ATTACHMENT_PENDING, true );
		if ( $pid > 0 ) {
			update_comment_meta( $comment_id, self::META_ATTACHMENT_ID, $pid );
			update_comment_meta( $comment_id, self::META_ATTACHMENT_URL, esc_url_raw( $this->storage()->get_url( $pid ) ) );
			delete_comment_meta( $comment_id, self::META_ATTACHMENT_PENDING );
		}
	}

	/**
	 * 清理钩子：评论被删/回收/标垃圾时同步删附件注册（物理删）+ 清 meta。
	 *
	 * 同时清理正式关联与待审暂存两路 meta（待审评论被删时其 pending 附件也需清）。
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
		$pid = (int) get_comment_meta( $comment_id, self::META_ATTACHMENT_PENDING, true );
		if ( $aid > 0 ) {
			// 经 Storage Provider 删除（含物理文件），不直接调用 wp_delete_attachment（#15 解耦）。
			$this->storage()->delete( $aid );
		}
		if ( $pid > 0 && $pid !== $aid ) {
			$this->storage()->delete( $pid );
		}

		delete_comment_meta( $comment_id, self::META_ATTACHMENT_ID );
		delete_comment_meta( $comment_id, self::META_ATTACHMENT_URL );
		delete_comment_meta( $comment_id, self::META_ATTACHMENT_PENDING );
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

		// 经 Storage Provider 读取（#15 解耦：不在适配层裸调 WP 媒体 API）。
		// 读路径仅需 get_url / exists，使用默认 WP 提供方即可（无策略注入需求）。
		$storage = new Bwpc_Attachment_Storage_WP( array(), 0 );
		if ( ! $storage->exists( $aid ) ) {
			// attachment 已被删除（评论在前台展示时刚好附件被回收） → 静默返回。
			return '';
		}

		$post = get_post( $aid );
		$mime = $post ? (string) $post->post_mime_type : '';
		$url  = $storage->get_url( $aid );
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
