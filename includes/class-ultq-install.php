<?php
/**
 * 啟用／升級：業務角色、舊 B2B 角色遷移、詢價清單頁、暫存目錄、rewrite endpoint
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Install {

	const LEGACY_B2B_ROLE = 'ultq_b2b';

	const DB_VERSION_KEY = 'ultq_db_version';

	public static function activate() {
		self::add_role();
		self::migrate_b2b_role();
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
			self::migrate_b2b_role();
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

	/** 註冊業務角色，並保留既有商店管理者的詢價管理權限。 */
	private static function add_role() {
		if ( ! get_role( ULTQ_Sales::ROLE ) ) {
			add_role( ULTQ_Sales::ROLE, '業務', array(
				'read' => true,
				'view_admin_dashboard' => true,
				ULTQ_Sales::CAPABILITY => true,
			) );
		}
		// 升級也補齊既有業務角色的後台入口權限，避免舊帳號被 WooCommerce 導回前台。
		get_role( ULTQ_Sales::ROLE )->add_cap( 'read' );
		get_role( ULTQ_Sales::ROLE )->add_cap( 'view_admin_dashboard' );
		// 既有管理員／商店管理員保留詢價權限，業務則只有獨立的詢價權限。
		foreach ( wp_roles()->role_objects as $role ) {
			if ( $role->has_cap( 'manage_woocommerce' ) || ULTQ_Sales::ROLE === $role->name ) {
				$role->add_cap( ULTQ_Sales::CAPABILITY );
			}
		}
	}

	/**
	 * 只移除舊角色標記，不改動任何 B2B 狀態／申請資料，也不觸發審核通知。
	 * 只有舊 B2B 角色的帳號補上一般顧客角色，其餘角色與個別權限全部保留。
	 */
	public static function migrate_b2b_role() {
		$migrated = 0;
		do {
			$ids = get_users( array( 'role' => self::LEGACY_B2B_ROLE, 'number' => 200, 'fields' => 'ID', 'cache_results' => false ) );
			foreach ( $ids as $id ) {
				$user = get_userdata( $id );
				// remove_cap 也能清理角色定義已被刪除、但帳號上仍殘留的標記。
				$user->remove_cap( self::LEGACY_B2B_ROLE );
				if ( ! $user->roles ) {
					$user->add_role( get_role( 'customer' ) ? 'customer' : 'subscriber' );
				}
				clean_user_cache( $id );
				++$migrated;
			}
		} while ( $ids );
		remove_role( self::LEGACY_B2B_ROLE );
		return $migrated;
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
