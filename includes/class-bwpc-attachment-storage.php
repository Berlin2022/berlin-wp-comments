<?php
/**
 * 评论附件存储抽象层（ATTACHMENT-001 §8 Storage Adapter Boundary）。
 *
 * 目的：把"附件物理持久化"与评论附件适配层解耦。
 * Attachment Adapter（class-bwpc-attachment.php）只依赖 Bwpc_Attachment_Storage 接口，
 * 不直接调用 wp_handle_upload / wp_insert_attachment / wp_delete_attachment。
 *
 * 默认提供 WordPress 媒体库实现（Bwpc_Attachment_Storage_WP）。
 * 未来可新增 Bwpc_Attachment_Storage_R2（Cloudflare R2 / S3 兼容对象存储），
 * 评论核心 / Attachment Adapter 均无需改动（ATT-P002 Storage Agnostic）。
 *
 * 关键边界（CP1 审计 2026-08-31）：
 *   - R2 实现本身可延期，但 Storage Boundary（本文件）不能延期；
 *   - Attachment Domain 不得与具体存储后端焊死。
 *
 * @package Berlin_WP_Comments
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * 附件存储提供方接口。
 *
 * 方法对应 ATTACHMENT-001 §8 要求的能力：
 *   store()    → 持久化一个已上传的文件，返回 attachment ID（0 = 失败）
 *   get_url()  → 取回可公开访问的 URL
 *   delete()   → 删除（含物理文件）
 *   exists()   → 是否存在
 * （§8 的 retrieve 语义由 get_url + exists 覆盖）
 */
interface Bwpc_Attachment_Storage {

	/**
	 * 持久化一个 PHP $_FILES 条目。
	 *
	 * @param array $file 单文件上传数组（name / size / type / tmp_name / error）。
	 * @return int attachment ID；0 表示失败（调用方应静默返回，不阻断评论提交）。
	 */
	public function store( array $file );

	/**
	 * 取回附件公开 URL。
	 *
	 * @param int $attach_id attachment ID。
	 * @return string
	 */
	public function get_url( $attach_id );

	/**
	 * 删除附件（含物理文件）。
	 *
	 * @param int $attach_id attachment ID。
	 * @return void
	 */
	public function delete( $attach_id );

	/**
	 * 附件是否存在。
	 *
	 * @param int $attach_id attachment ID。
	 * @return bool
	 */
	public function exists( $attach_id );
}

/**
 * WordPress 媒体库存储提供方（默认实现）。
 *
 * 封装 wp_handle_upload + wp_insert_attachment + wp_delete_attachment，
 * 并应用注入的 MIME 白名单与大小上限策略。
 *
 * 安全边界（ATTACHMENT-001 §6）：
 *   - MIME 白名单由调用方注入（allowed_mimes），wp_handle_upload 内部再做真实内容嗅探；
 *   - 大小上限在服务端硬校验（防前端绕过）；
 *   - 插入失败时立即 @unlink 物理文件，避免不可控 orphan（ATTACHMENT-001 #12 修正）。
 */
class Bwpc_Attachment_Storage_WP implements Bwpc_Attachment_Storage {

	/** @var string[] MIME 白名单。 */
	private $mimes;

	/** @var int 单文件大小上限（bytes）。 */
	private $max_bytes;

	/** @var string 最近一次 store() 失败原因（供 ?bwpc_debug 探针读取，仅管理员可见）。 */
	private static $last_error = '';

	/**
	 * @param string[] $mimes     允许的 MIME 类型。
	 * @param int      $max_bytes 单文件大小上限（bytes）。
	 */
	public function __construct( array $mimes, $max_bytes ) {
		$this->mimes     = $mimes;
		$this->max_bytes = (int) $max_bytes;
	}

	/**
	 * 取最近一次 store() 失败原因（调试用，仅 ?bwpc_debug 探针读取）。
	 *
	 * @return string
	 */
	public static function last_error() {
		return self::$last_error;
	}

	/**
	 * 持久化一个已上传的文件。
	 *
	 * @param array $file 单文件上传数组。
	 * @return int attachment ID；0 表示失败。
	 */
	public function store( array $file ) {
		self::$last_error = '';
		// 大小校验（服务端硬校验，防前端绕过）。
		if ( empty( $file['size'] ) || (int) $file['size'] > $this->max_bytes ) {
			self::$last_error = 'size_exceeded:' . (int) ( isset( $file['size'] ) ? $file['size'] : 0 ) . '>' . $this->max_bytes;
			return 0;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$upload = wp_handle_upload(
			$file,
			array(
				'test_form' => false,
				'mimes'     => $this->mimes,
				'action'    => 'bwpc_comment_attachment',
			)
		);

		if ( ! is_array( $upload ) || empty( $upload['file'] ) || ! empty( $upload['error'] ) ) {
			self::$last_error = 'wp_handle_upload:' . ( ! empty( $upload['error'] ) ? $upload['error'] : 'no_file_returned' );
			return 0;
		}

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
			// ATTACHMENT-001 #12 修正：插入失败立即清理已落盘物理文件，避免孤立文件。
			self::$last_error = 'wp_insert_attachment_failed';
			@unlink( $upload['file'] ); // phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged
			return 0;
		}

		$attach_data = wp_generate_attachment_metadata( (int) $attach_id, $upload['file'] );
		if ( is_array( $attach_data ) ) {
			wp_update_attachment_metadata( (int) $attach_id, $attach_data );
		}

		return (int) $attach_id;
	}

	/**
	 * 取回附件公开 URL。
	 *
	 * @param int $attach_id attachment ID。
	 * @return string
	 */
	public function get_url( $attach_id ) {
		return (string) wp_get_attachment_url( (int) $attach_id );
	}

	/**
	 * 删除附件（含物理文件）。
	 *
	 * @param int $attach_id attachment ID。
	 * @return void
	 */
	public function delete( $attach_id ) {
		if ( $attach_id > 0 ) {
			wp_delete_attachment( (int) $attach_id, true );
		}
	}

	/**
	 * 附件是否存在。
	 *
	 * @param int $attach_id attachment ID。
	 * @return bool
	 */
	public function exists( $attach_id ) {
		$post = $attach_id > 0 ? get_post( (int) $attach_id ) : null;
		return $post && 'attachment' === $post->post_type;
	}
}
