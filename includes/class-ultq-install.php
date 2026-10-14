<?php
/**
 * 啟用／升級：B2B 角色、詢價清單頁、暫存目錄、rewrite endpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Install {

	const DB_VERSION_KEY = 'ultq_db_version';

	public static function activate() {
		self::add_role();
		self::maybe_create_list_page();
		self::ensure_private_dir( ULTQ_Helper::tmp_dir() );
		self::ensure_private_dir( ULTQ_Helper::font_dir() );
		update_option( self::DB_VERSION_KEY, ULTQ_VERSION );

		// My Account endpoint 需要 flush 才會生效；延到下一個請求的 init 之後再 flush（此時 endpoint 已註冊）
		update_option( 'ultq_flush_rewrite', 1 );
	}

	public static function maybe_upgrade() {
		if ( get_option( self::DB_VERSION_KEY ) !== ULTQ_VERSION ) {
			self::add_role();
			update_option( self::DB_VERSION_KEY, ULTQ_VERSION );
			update_option( 'ultq_flush_rewrite', 1 );
		}

		add_action( 'init', function () {
			if ( get_option( 'ultq_flush_rewrite' ) ) {
				delete_option( 'ultq_flush_rewrite' );
				flush_rewrite_rules( false );
			}
		}, 99 );
	}

	/**
	 * B2B 角色只當「標記」用，權限跟一般 read 一樣；真正的判斷依據是 user meta
	 * ultq_b2b_status（見 ULTQ_B2B_Member::is_approved()），角色是給後台使用者列表篩選用的
	 */
	private static function add_role() {
		if ( ! get_role( ULTQ_B2B_Member::ROLE ) ) {
			add_role( ULTQ_B2B_Member::ROLE, 'B2B 會員', array( 'read' => true ) );
		}
	}

	private static function maybe_create_list_page() {
		$page_id = (int) ULTQ_Settings::get( 'list_page_id' );
		if ( $page_id && 'page' === get_post_type( $page_id ) && 'trash' !== get_post_status( $page_id ) ) {
			return;
		}

		$page_id = wp_insert_post( array(
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_title'   => '詢價清單',
			'post_name'    => 'quote-list',
			'post_content' => '<!-- wp:shortcode -->[ultq_quote_list]<!-- /wp:shortcode -->',
		) );

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			ULTQ_Settings::update( 'list_page_id', (int) $page_id );
		}
	}

	/**
	 * 報價單 PDF 暫存與字型快取目錄放在 uploads 底下（外掛目錄在某些主機不可寫），
	 * 並放 index.php/.htaccess 阻擋直接瀏覽
	 */
	public static function ensure_private_dir( $dir ) {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			@file_put_contents( $dir . '/.htaccess', "Deny from all\n" ); // phpcs:ignore
		}
		return true;
	}
}
