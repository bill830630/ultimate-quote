<?php
/**
 * 詢價附件：顧客在詢價清單上傳的圖面／規格書，業務在後台補上的檔案
 *
 * - 存放：uploads/ultq-files/{詢價單 ID}/{隨機名}.bin（目錄有 .htaccess／index.php；檔名隨機且不保留副檔名，
 *   即使主機是 nginx 不吃 .htaccess，也猜不到網址、不會被當成程式執行）
 * - 下載一律走 admin-post.php?action=ultq_file（manage_woocommerce＋nonce），用原始檔名送出
 * - meta _ultq_files：array( array( id, name, file, size, by=customer|admin, time ) )
 * - 詢價單永久刪除時一併刪檔
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Files {

	const META = '_ultq_files';

	/** 不管設定怎麼填都不收的副檔名 */
	const BLOCKED = array( 'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pl', 'py', 'cgi', 'sh', 'bash', 'exe', 'com', 'bat', 'cmd', 'msi', 'js', 'mjs', 'html', 'htm', 'shtml', 'xhtml', 'svg', 'svgz', 'htaccess', 'asp', 'aspx', 'jsp' );

	public static function init() {
		add_action( 'admin_post_ultq_file', array( __CLASS__, 'handle_download' ) );
		add_action( 'admin_post_ultq_file_delete', array( __CLASS__, 'handle_delete' ) );
		add_action( 'before_delete_post', array( __CLASS__, 'delete_all' ) );
	}

	public static function enabled() {
		return (bool) ULTQ_Settings::get( 'upload_enabled', 1 );
	}

	public static function allowed_types() {
		$types = preg_split( '/[\s,，、]+/', strtolower( (string) ULTQ_Settings::get( 'upload_types', '' ) ) );
		$types = array_map( function ( $ext ) {
			return ltrim( trim( $ext ), '.' );
		}, $types );
		return array_values( array_diff( array_unique( array_filter( $types ) ), self::BLOCKED ) );
	}

	/** 單檔上限（bytes），不超過主機的 upload_max_filesize／post_max_size */
	public static function max_bytes() {
		$mb = max( 1, (float) ULTQ_Settings::get( 'upload_max_mb', 10 ) );
		return (int) min( $mb * MB_IN_BYTES, wp_max_upload_size() );
	}

	public static function max_files() {
		return max( 1, (int) ULTQ_Settings::get( 'upload_max_files', 5 ) );
	}

	private static function base_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'ultq-files';
	}

	private static function dir( $post_id ) {
		return self::base_dir() . '/' . (int) $post_id;
	}

	public static function get( $post_id ) {
		$files = get_post_meta( $post_id, self::META, true );
		return is_array( $files ) ? $files : array();
	}

	/**
	 * $_FILES 多檔欄位 → 每個檔一列；沒選檔的空欄位略過
	 */
	public static function from_request( $field ) {
		if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ]['name'] ?? null ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return array();
		}
		$raw   = $_FILES[ $field ]; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$files = array();
		foreach ( array_keys( $raw['name'] ) as $i ) {
			if ( UPLOAD_ERR_NO_FILE === (int) $raw['error'][ $i ] ) {
				continue;
			}
			$files[] = array(
				'name'     => (string) $raw['name'][ $i ],
				'tmp_name' => (string) $raw['tmp_name'][ $i ],
				'error'    => (int) $raw['error'][ $i ],
				'size'     => (int) $raw['size'][ $i ],
			);
		}
		return $files;
	}

	/**
	 * 檢查張數、大小、副檔名
	 *
	 * @param int $existing 這張詢價單已有的附件數（後台補檔時算進上限）
	 * @return true|WP_Error
	 */
	public static function validate( array $files, $existing = 0 ) {
		if ( ! $files ) {
			return true;
		}
		$max = self::max_files();
		if ( count( $files ) + $existing > $max ) {
			return new WP_Error( 'ultq_files', sprintf( '附件最多 %d 個檔案。', $max ) );
		}
		$types = self::allowed_types();
		foreach ( $files as $file ) {
			$name = sanitize_file_name( $file['name'] );
			if ( UPLOAD_ERR_OK !== $file['error'] ) {
				return new WP_Error( 'ultq_files', in_array( $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true )
					? sprintf( '「%s」超過檔案大小上限 %s。', $name, size_format( self::max_bytes() ) )
					: sprintf( '「%s」上傳失敗，請再試一次。', $name ) );
			}
			if ( $file['size'] > self::max_bytes() ) {
				return new WP_Error( 'ultq_files', sprintf( '「%s」超過檔案大小上限 %s。', $name, size_format( self::max_bytes() ) ) );
			}
			$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( '' === $ext || ! in_array( $ext, $types, true ) ) {
				return new WP_Error( 'ultq_files', sprintf( '「%s」的檔案類型不接受，可以上傳：%s。', $name, implode( '、', $types ) ) );
			}
		}
		return true;
	}

	/**
	 * 搬進詢價單目錄並記錄（先 validate()）
	 *
	 * @param string $by customer|admin
	 * @return int 成功存下的數量
	 */
	public static function store( $post_id, array $files, $by = 'customer' ) {
		if ( ! $files ) {
			return 0;
		}
		$dir = self::dir( $post_id );
		ULTQ_Install::ensure_private_dir( self::base_dir() );
		ULTQ_Install::ensure_private_dir( $dir );

		$list  = self::get( $post_id );
		$saved = 0;
		foreach ( $files as $file ) {
			$stored = strtolower( wp_generate_password( 24, false ) ) . '.bin';
			if ( ! is_uploaded_file( $file['tmp_name'] ) || ! @move_uploaded_file( $file['tmp_name'], $dir . '/' . $stored ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				continue;
			}
			$list[] = array(
				'id'   => strtolower( wp_generate_password( 12, false ) ),
				'name' => sanitize_file_name( $file['name'] ),
				'file' => $stored,
				'size' => (int) $file['size'],
				'by'   => 'admin' === $by ? 'admin' : 'customer',
				'time' => time(),
			);
			$saved++;
		}
		if ( $saved ) {
			update_post_meta( $post_id, self::META, $list );
			ULTQ_Quote_Post::add_log( $post_id, sprintf( '%s上傳 %d 個附件', 'admin' === $by ? '' : '顧客', $saved ) );
		}
		return $saved;
	}

	public static function download_url( $post_id, $file_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ultq_file&post=' . (int) $post_id . '&file=' . rawurlencode( $file_id ) ), 'ultq_file_' . (int) $post_id );
	}

	public static function delete_url( $post_id, $file_id ) {
		return wp_nonce_url( admin_url( 'admin-post.php?action=ultq_file_delete&post=' . (int) $post_id . '&file=' . rawurlencode( $file_id ) ), 'ultq_file_delete_' . (int) $post_id );
	}

	private static function find( $post_id, $file_id ) {
		foreach ( self::get( $post_id ) as $file ) {
			if ( hash_equals( (string) $file['id'], (string) $file_id ) ) {
				return $file;
			}
		}
		return null;
	}

	private static function request_target( $nonce_action ) {
		$post_id = absint( $_GET['post'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( $nonce_action . $post_id );
		if ( ! current_user_can( 'manage_woocommerce' ) || ULTQ_Quote_Post::POST_TYPE !== get_post_type( $post_id ) ) {
			wp_die( '權限不足。' );
		}
		$file = self::find( $post_id, sanitize_key( wp_unslash( $_GET['file'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $file ) {
			wp_die( '找不到這個附件。', '', array( 'response' => 404 ) );
		}
		return array( $post_id, $file );
	}

	public static function handle_download() {
		list( $post_id, $file ) = self::request_target( 'ultq_file_' );
		$path = self::dir( $post_id ) . '/' . basename( $file['file'] );
		if ( ! is_readable( $path ) ) {
			wp_die( '附件檔案已遺失。', '', array( 'response' => 404 ) );
		}
		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Length: ' . filesize( $path ) );
		header( "Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode( $file['name'] ) );
		header( 'X-Content-Type-Options: nosniff' );
		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function handle_delete() {
		list( $post_id, $file ) = self::request_target( 'ultq_file_delete_' );
		wp_delete_file( self::dir( $post_id ) . '/' . basename( $file['file'] ) );
		$list = array_values( array_filter( self::get( $post_id ), function ( $row ) use ( $file ) {
			return $row['id'] !== $file['id'];
		} ) );
		update_post_meta( $post_id, self::META, $list );
		ULTQ_Quote_Post::add_log( $post_id, '刪除附件：' . $file['name'] );
		wp_safe_redirect( ULTQ_Quote_Post::admin_edit_url( $post_id ) . '#ultq_files' );
		exit;
	}

	public static function delete_all( $post_id ) {
		if ( ULTQ_Quote_Post::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		$dir = self::dir( $post_id );
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) scandir( $dir ) as $name ) {
			if ( is_file( $dir . '/' . $name ) ) {
				wp_delete_file( $dir . '/' . $name );
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/** 後台詢價單編輯頁「附件」方塊 */
	public static function render_box( $post ) {
		$files = self::get( $post->ID );
		if ( $files ) {
			echo '<ul class="ultq-files">';
			foreach ( $files as $file ) {
				printf(
					'<li><a href="%1$s">%2$s</a> <span class="ultq-files__meta">%3$s · %4$s · %5$s</span> <a href="%6$s" class="ultq-files__delete" onclick="return confirm(\'確定要刪除這個附件嗎？\');">刪除</a></li>',
					esc_url( self::download_url( $post->ID, $file['id'] ) ),
					esc_html( $file['name'] ),
					esc_html( size_format( (int) $file['size'] ) ),
					'admin' === $file['by'] ? '後台上傳' : '顧客上傳',
					esc_html( wp_date( 'Y-m-d H:i', (int) $file['time'] ) ),
					esc_url( self::delete_url( $post->ID, $file['id'] ) )
				);
			}
			echo '</ul>';
		} else {
			echo '<p class="description">目前沒有附件。</p>';
		}
		printf(
			'<p><label for="ultq-admin-files">上傳附件</label><br><input type="file" id="ultq-admin-files" name="ultq_admin_files[]" multiple accept="%s"></p><p class="description">儲存時一併上傳，只有後台看得到。可以上傳：%s，單檔上限 %s。</p>',
			esc_attr( implode( ',', array_map( function ( $ext ) {
				return '.' . $ext;
			}, self::allowed_types() ) ) ),
			esc_html( implode( '、', self::allowed_types() ) ),
			esc_html( size_format( self::max_bytes() ) )
		);
	}
}
