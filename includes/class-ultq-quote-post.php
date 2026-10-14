<?php
/**
 * 詢價單資料模型：CPT ultq_quote＋自訂狀態
 *
 * 一張詢價單 = 一個 ultq_quote post。post_title 存報價單號，post_author 存顧客 user ID（訪客為 0），
 * 其餘資料都在 post meta（量不大，不另建資料表）：
 * - _ultq_number      報價單號
 * - _ultq_user_id     顧客 user ID（給「我的詢價」查詢用）
 * - _ultq_customer    顧客資料陣列（name/company/tax_id/email/phone/address/message）
 * - _ultq_items       品項陣列（見 normalize_item()）
 * - _ultq_quote       報價欄位陣列（shipping/tax_mode/tax_rate/valid_until/note/admin_note/style/payment_terms/fields/discounts）
 *                     fields = 自訂欄位的值，key 是 ULTQ_Settings::custom_fields() 的 id
 *                     discounts = 整單折讓 array( array( label, type=fixed|percent, value ) )，在稅前扣
 * - _ultq_owner       負責業務 user ID（見 ULTQ_Sales）
 * - _ultq_files       附件（見 ULTQ_Files）
 * - _ultq_reminded    已寄過到期提醒的版本號；_ultq_followed 已通知業務追蹤的版本號（見 ULTQ_Cron）
 * - _ultq_response    顧客線上回覆（action=accept|reject、name、note、time、ip），重新寄出報價時清除
 * - _ultq_token       顧客免登入下載 PDF 的 token（第一次寄出報價時產生）
 * - _ultq_sent_at     最近一次寄出報價的時間（timestamp）
 * - _ultq_versions    每次寄出報價的快照（rev/time/user/email/items/quote/totals），最多保留 20 版
 * - _ultq_log         操作紀錄
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Quote_Post {

	const POST_TYPE = 'ultq_quote';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_filter( 'wp_untrash_post_status', array( __CLASS__, 'untrash_status' ), 10, 3 );
	}

	/** WP 5.6 起從回收桶還原一律變草稿；詢價單要回到丟進回收桶前的狀態（否則顧客看不到、排程也不處理） */
	public static function untrash_status( $new_status, $post_id, $previous_status ) {
		if ( self::POST_TYPE === get_post_type( $post_id ) ) {
			return isset( self::statuses()[ $previous_status ] ) ? $previous_status : 'ultq-processing';
		}
		return $new_status;
	}

	public static function statuses() {
		return array(
			'ultq-new'        => '新詢價',
			'ultq-processing' => '處理中',
			'ultq-quoted'     => '已報價',
			'ultq-accepted'   => '顧客已接受',
			'ultq-rejected'   => '顧客已婉拒',
			'ultq-expired'    => '已過期',
			'ultq-closed'     => '已結案',
			'ultq-cancelled'  => '已取消',
		);
	}

	public static function status_label( $status ) {
		$statuses = self::statuses();
		return $statuses[ $status ] ?? $status;
	}

	public static function tax_modes() {
		return array(
			'excl' => '未稅（另加營業稅）',
			'incl' => '含稅',
			'none' => '免稅',
		);
	}

	public static function register() {
		$cap = 'manage_woocommerce';
		register_post_type( self::POST_TYPE, array(
			'labels'          => array(
				'name'               => '詢價單',
				'singular_name'      => '詢價單',
				'edit_item'          => '編輯詢價單',
				'search_items'       => '搜尋詢價單',
				'not_found'          => '目前沒有詢價單',
				'not_found_in_trash' => '回收桶沒有詢價單',
				'add_new'            => '新增報價單',
				'add_new_item'       => '新增報價單',
				'all_items'          => '所有詢價單',
			),
			'public'          => false,
			'show_ui'         => true,
			'show_in_menu'    => false,
			'show_in_rest'    => false,
			'supports'        => array( '' ),
			'rewrite'         => false,
			'query_var'       => false,
			'map_meta_cap'    => false,
			'capabilities'    => array(
				'edit_post'          => $cap,
				'read_post'          => $cap,
				'delete_post'        => $cap,
				'edit_posts'         => $cap,
				'edit_others_posts'  => $cap,
				'delete_posts'       => $cap,
				'publish_posts'      => $cap,
				'read_private_posts' => $cap,
				// 電話、LINE、Email 來的詢價由業務在後台直接開單（ULTQ_Admin 處理新單的單號與預設值）
				'create_posts'       => $cap,
			),
		) );

		foreach ( self::statuses() as $status => $label ) {
			register_post_status( $status, array(
				'label'                     => $label,
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => 'ultq-cancelled' !== $status,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>' ),
			) );
		}
	}

	// ─── 建立 ──────────────────────────────────────────────────────────────

	/**
	 * @param array $customer 已驗證過的顧客資料
	 * @param array $items    ULTQ_List 的清單品項
	 * @return int|WP_Error
	 */
	public static function create( array $customer, array $items, $user_id = 0 ) {
		$number = self::next_number();

		$post_id = wp_insert_post( array(
			'post_type'   => self::POST_TYPE,
			'post_status' => 'ultq-new',
			'post_title'  => $number,
			'post_author' => (int) $user_id,
		), true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$is_b2b = $user_id && ULTQ_B2B_Member::is_approved( $user_id );
		$rows   = array();
		foreach ( $items as $item ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$qty       = max( 1, (int) $item['qty'] );
			$suggested = $is_b2b ? ULTQ_B2B_Pricing::get_b2b_price( $product, $qty ) : null;
			if ( null === $suggested && '' !== $product->get_price() ) {
				$suggested = (float) $product->get_price();
			}
			$rows[] = self::normalize_item( array(
				'product_id'    => (int) $item['product_id'],
				'variation_id'  => (int) $item['variation_id'],
				'name'          => $product->get_name(),
				'sku'           => $product->get_sku(),
				'attributes'    => ULTQ_List::attribute_labels( $product, $item['variation'] ?? array() ),
				'variation'     => is_array( $item['variation'] ?? null ) ? $item['variation'] : array(),
				'request_qty'   => $qty,
				'qty'           => $qty,
				'customer_note' => $item['note'] ?? '',
				'unit_price'    => $suggested,
				'unit'          => (string) ULTQ_Settings::get( 'default_unit', '' ),
			) );
		}

		update_post_meta( $post_id, '_ultq_number', $number );
		update_post_meta( $post_id, '_ultq_user_id', (int) $user_id );
		update_post_meta( $post_id, '_ultq_customer', $customer );
		update_post_meta( $post_id, '_ultq_items', $rows );
		update_post_meta( $post_id, '_ultq_quote', self::default_quote() );

		self::add_log( $post_id, $user_id ? '顧客送出詢價（會員）' : '顧客送出詢價（訪客）' );
		ULTQ_Sales::auto_assign( $post_id, $user_id );

		/** 資料寫好、通知發出前（ULTQ_Ajax 在這裡存附件） */
		do_action( 'ultq_quote_inserted', $post_id );

		do_action( 'ultq_quote_created', $post_id );

		return $post_id;
	}

	/** 新詢價單的報價欄位預設值（前台送出與後台開單共用） */
	public static function default_quote() {
		$valid_days = max( 1, (int) ULTQ_Settings::get( 'valid_days', 30 ) );
		return array(
			'shipping'      => 0,
			'tax_mode'      => ULTQ_Settings::get( 'tax_mode', 'excl' ),
			'tax_rate'      => (float) ULTQ_Settings::get( 'tax_rate', 5 ),
			'valid_until'   => wp_date( 'Y-m-d', time() + $valid_days * DAY_IN_SECONDS ),
			'note'          => ULTQ_Settings::get( 'default_terms', '' ),
			'admin_note'    => '',
			'payment_terms' => (string) ULTQ_Settings::get( 'default_payment_terms', '' ),
			'fields'        => wp_list_pluck( ULTQ_Settings::custom_fields(), 'default', 'id' ),
			'discounts'     => array(),
		);
	}

	/**
	 * 單號：前綴-Ymd-當日流水號（4 碼）。流水號存在 option，以當日日期為 key，只保留今天的。
	 * 兩張單同時送出時「讀→加 1→寫」會拿到同一號，所以用 MySQL GET_LOCK 包起來，鎖內直接讀 DB（不走 option 快取）
	 */
	public static function next_number() {
		global $wpdb;
		$prefix = preg_replace( '/[^A-Za-z0-9]/', '', (string) ULTQ_Settings::get( 'number_prefix', 'Q' ) );
		$prefix = $prefix ?: 'Q';
		$today  = wp_date( 'Ymd' );
		$lock   = 'ultq_number_' . $wpdb->prefix;
		$locked = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, 10 )', $lock ) );

		$raw  = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", 'ultq_number_seq' ) );
		$seq  = null === $raw ? array() : maybe_unserialize( $raw );
		$next = ( is_array( $seq ) && isset( $seq[ $today ] ) ? (int) $seq[ $today ] : 0 ) + 1;
		wp_cache_delete( 'ultq_number_seq', 'options' );
		update_option( 'ultq_number_seq', array( $today => $next ), false );

		if ( $locked ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', $lock ) );
		}
		return sprintf( '%s-%s-%04d', $prefix, $today, $next );
	}

	public static function normalize_item( array $item ) {
		$price = $item['unit_price'] ?? null;
		return array(
			'product_id'    => (int) ( $item['product_id'] ?? 0 ),
			'variation_id'  => (int) ( $item['variation_id'] ?? 0 ),
			'name'          => (string) ( $item['name'] ?? '' ),
			'sku'           => (string) ( $item['sku'] ?? '' ),
			'attributes'    => is_array( $item['attributes'] ?? null ) ? $item['attributes'] : array(),
			// 原始規格（attribute_pa_xxx => slug），「再次詢價」放回清單用；1.2.0 以前的詢價單沒有這欄
			'variation'     => is_array( $item['variation'] ?? null ) ? $item['variation'] : array(),
			'request_qty'   => (int) ( $item['request_qty'] ?? 0 ),
			'qty'           => max( 0, (float) ( $item['qty'] ?? 1 ) ),
			'customer_note' => (string) ( $item['customer_note'] ?? '' ),
			'unit_price'    => ( null === $price || '' === $price ) ? null : (float) $price,
			'line_note'     => (string) ( $item['line_note'] ?? '' ),
			'unit'          => (string) ( $item['unit'] ?? '' ),
			// 單項折扣（%），0 表示沒有折扣；金額欄是折後金額
			'discount'      => max( 0, min( 100, (float) ( $item['discount'] ?? 0 ) ) ),
		);
	}

	/** 品項金額（折後），沒填單價回傳 null */
	public static function line_total( array $item ) {
		if ( null === $item['unit_price'] ) {
			return null;
		}
		$discount = (float) ( $item['discount'] ?? 0 );
		return round( $item['unit_price'] * $item['qty'] * ( 100 - $discount ) / 100, wc_get_price_decimals() );
	}

	/**
	 * 折扣％ → 台灣習慣的「幾折」：10 → 9 折、15 → 85 折、12.5 → 87.5 折
	 */
	public static function discount_label( $discount ) {
		$rate = 100 - (float) $discount;
		if ( $rate <= 0 ) {
			return '免費';
		}
		$text = 0.0 === fmod( $rate, 10 ) ? (string) ( $rate / 10 ) : rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' );
		return $text . ' 折';
	}

	/** 整單折讓（清掉空白列，數值轉成正數） */
	public static function normalize_discounts( $rows ) {
		$out = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$value = abs( (float) ( $row['value'] ?? 0 ) );
			if ( $value <= 0 ) {
				continue;
			}
			$type  = 'percent' === ( $row['type'] ?? '' ) ? 'percent' : 'fixed';
			$out[] = array(
				'label' => (string) ( $row['label'] ?? '' ) ?: '折讓',
				'type'  => $type,
				'value' => 'percent' === $type ? min( 100, $value ) : $value,
			);
		}
		return $out;
	}

	// ─── 讀取 ──────────────────────────────────────────────────────────────

	public static function get_customer( $post_id ) {
		$customer = get_post_meta( $post_id, '_ultq_customer', true );
		return array_merge(
			array( 'name' => '', 'company' => '', 'tax_id' => '', 'email' => '', 'phone' => '', 'address' => '', 'message' => '' ),
			is_array( $customer ) ? $customer : array()
		);
	}

	public static function get_items( $post_id ) {
		$items = get_post_meta( $post_id, '_ultq_items', true );
		return is_array( $items ) ? array_map( array( __CLASS__, 'normalize_item' ), $items ) : array();
	}

	public static function get_quote( $post_id ) {
		$quote = get_post_meta( $post_id, '_ultq_quote', true );
		return array_merge(
			array( 'shipping' => 0, 'tax_mode' => 'excl', 'tax_rate' => 5, 'valid_until' => '', 'note' => '', 'admin_note' => '', 'payment_terms' => '', 'fields' => array(), 'discounts' => array() ),
			// 後台剛開的新單還沒有 meta，用設定頁的預設值
			is_array( $quote ) ? $quote : ( $post_id ? self::default_quote() : array() )
		);
	}

	public static function get_number( $post_id ) {
		return (string) get_post_meta( $post_id, '_ultq_number', true ) ?: get_the_title( $post_id );
	}

	public static function get_user_id( $post_id ) {
		return (int) get_post_meta( $post_id, '_ultq_user_id', true );
	}

	/**
	 * 金額試算：品項（折後）小計 → 扣整單折讓 → 加運費 → 稅。
	 * 含稅模式下單價本身已含稅，稅額是從總額反推出來的（只顯示用）。
	 * discounts 是每一列折讓算出來的金額（正數，顯示時加負號）
	 */
	public static function totals( $post_id, $items = null, $quote = null ) {
		$items    = $items ?? self::get_items( $post_id );
		$quote    = $quote ?? self::get_quote( $post_id );
		$decimals = wc_get_price_decimals();

		$subtotal = 0;
		foreach ( $items as $item ) {
			$line = self::line_total( $item );
			if ( null !== $line ) {
				$subtotal += $line;
			}
		}

		$discounts = array();
		$discount  = 0;
		foreach ( self::normalize_discounts( $quote['discounts'] ?? array() ) as $row ) {
			$amount = 'percent' === $row['type'] ? round( $subtotal * $row['value'] / 100, $decimals ) : round( $row['value'], $decimals );
			$amount = min( $amount, max( 0, $subtotal - $discount ) );
			$discount   += $amount;
			$discounts[] = array(
				'label'  => $row['label'] . ( 'percent' === $row['type'] ? '（' . wc_format_localized_decimal( $row['value'] ) . '%）' : '' ),
				'amount' => $amount,
			);
		}

		$shipping = (float) $quote['shipping'];
		$rate     = (float) $quote['tax_rate'];
		$base     = $subtotal - $discount + $shipping;

		switch ( $quote['tax_mode'] ) {
			case 'incl':
				$total = $base;
				$tax   = round( $base - $base / ( 1 + $rate / 100 ), $decimals );
				break;
			case 'none':
				$total = $base;
				$tax   = 0;
				break;
			default:
				$tax   = round( $base * $rate / 100, $decimals );
				$total = $base + $tax;
		}

		return array(
			'subtotal'  => $subtotal,
			'discounts' => $discounts,
			'discount'  => $discount,
			'shipping'  => $shipping,
			'tax'       => $tax,
			'total'     => $total,
		);
	}

	/** 是否每個品項都已填單價（寄出報價前檢查） */
	public static function is_fully_priced( $post_id ) {
		$items = self::get_items( $post_id );
		if ( ! $items ) {
			return false;
		}
		foreach ( $items as $item ) {
			if ( null === $item['unit_price'] ) {
				return false;
			}
		}
		return true;
	}

	// ─── 顧客可見／線上回覆 ────────────────────────────────────────────────

	/** 這些狀態下顧客看得到報價金額、能下載 PDF（前提是寄出過報價） */
	public static function customer_visible_statuses() {
		return array( 'ultq-quoted', 'ultq-accepted', 'ultq-rejected', 'ultq-expired', 'ultq-closed' );
	}

	public static function is_customer_visible( $post_id ) {
		return self::POST_TYPE === get_post_type( $post_id )
			&& get_post_meta( $post_id, '_ultq_sent_at', true )
			&& in_array( get_post_status( $post_id ), self::customer_visible_statuses(), true );
	}

	/**
	 * 顧客看到的報價內容＝最後寄出的那一版快照（PDF、Excel、檢視頁、我的帳戶、提醒信、線上回覆都看這份），
	 * 後台存檔但還沒寄出的修改不會被顧客看到或接受。1.2.0 以前寄出、沒有快照的舊單才用目前內容。
	 *
	 * @return array rev／items／quote／totals
	 */
	public static function sent( $post_id ) {
		$versions = self::get_versions( $post_id );
		if ( ! $versions ) {
			return array(
				'rev'    => self::revision( $post_id ),
				'items'  => self::get_items( $post_id ),
				'quote'  => self::get_quote( $post_id ),
				'totals' => self::totals( $post_id ),
			);
		}
		$version = end( $versions );
		return array(
			'rev'    => (int) $version['rev'],
			'items'  => array_map( array( __CLASS__, 'normalize_item' ), is_array( $version['items'] ) ? $version['items'] : array() ),
			'quote'  => array_merge( self::get_quote( $post_id ), is_array( $version['quote'] ) ? $version['quote'] : array() ),
			// 1.3.0 以前的快照沒有折讓欄位
			'totals' => array_merge( array( 'discounts' => array(), 'discount' => 0 ), is_array( $version['totals'] ) ? $version['totals'] : array() ),
		);
	}

	/** 已寄出過，但目前內容跟最後寄出的版本不一樣（後台提示「有未寄出的修改」） */
	public static function has_unsent_changes( $post_id ) {
		$versions = self::get_versions( $post_id );
		if ( ! $versions ) {
			return false;
		}
		$version = end( $versions );
		$quote   = self::get_quote( $post_id );
		unset( $quote['admin_note'] );
		$saved = array_merge( self::get_quote( $post_id ), is_array( $version['quote'] ) ? $version['quote'] : array() );
		unset( $saved['admin_note'] );
		$items = array_map( array( __CLASS__, 'normalize_item' ), is_array( $version['items'] ) ? $version['items'] : array() );
		return wp_json_encode( self::get_items( $post_id ) ) !== wp_json_encode( $items )
			|| wp_json_encode( self::comparable( $quote ) ) !== wp_json_encode( self::comparable( $saved ) );
	}

	/** 比對用：數字欄位統一型別、陣列 key 排序 */
	private static function comparable( array $quote ) {
		$quote['shipping']  = (float) $quote['shipping'];
		$quote['tax_rate']  = (float) $quote['tax_rate'];
		$quote['discounts'] = self::normalize_discounts( $quote['discounts'] ?? array() );
		$quote['fields']    = is_array( $quote['fields'] ?? null ) ? array_map( 'strval', $quote['fields'] ) : array();
		ksort( $quote['fields'] );
		ksort( $quote );
		return $quote;
	}

	/** 有效期限已過（當天仍有效）；看的是顧客收到的那一版 */
	public static function is_expired( $post_id ) {
		$valid = self::sent( $post_id )['quote']['valid_until'];
		return $valid && $valid < wp_date( 'Y-m-d' );
	}

	/** 顧客現在能不能線上接受／婉拒：設定有開、已寄出報價、狀態是已報價、還沒過期 */
	public static function can_respond( $post_id ) {
		return ULTQ_Settings::get( 'allow_respond' )
			&& self::is_customer_visible( $post_id )
			&& 'ultq-quoted' === get_post_status( $post_id )
			&& ! self::is_expired( $post_id );
	}

	public static function get_response( $post_id ) {
		$response = get_post_meta( $post_id, '_ultq_response', true );
		return array_merge(
			array( 'action' => '', 'name' => '', 'note' => '', 'time' => 0, 'ip' => '' ),
			is_array( $response ) ? $response : array()
		);
	}

	// ─── 報價版本 ──────────────────────────────────────────────────────────

	public static function get_versions( $post_id ) {
		$versions = get_post_meta( $post_id, '_ultq_versions', true );
		return is_array( $versions ) ? $versions : array();
	}

	/**
	 * 目前已寄出的版本號。1.2.0 以前寄出的詢價單沒有快照，寄出過就算第 1 版
	 */
	public static function revision( $post_id ) {
		$versions = self::get_versions( $post_id );
		if ( $versions ) {
			return (int) end( $versions )['rev'];
		}
		return get_post_meta( $post_id, '_ultq_sent_at', true ) ? 1 : 0;
	}

	public static function get_version( $post_id, $rev ) {
		foreach ( self::get_versions( $post_id ) as $version ) {
			if ( (int) $version['rev'] === (int) $rev ) {
				return $version;
			}
		}
		return null;
	}

	/** 寄出報價成功後存一份快照（內部備註不存） */
	public static function add_version( $post_id, $email ) {
		$versions = self::get_versions( $post_id );
		$quote    = self::get_quote( $post_id );
		unset( $quote['admin_note'] );
		$user       = wp_get_current_user();
		$versions[] = array(
			'rev'    => self::revision( $post_id ) + 1,
			'time'   => time(),
			'user'   => $user && $user->exists() ? $user->display_name : '',
			'email'  => $email,
			'items'  => self::get_items( $post_id ),
			'quote'  => $quote,
			'totals' => self::totals( $post_id ),
		);
		update_post_meta( $post_id, '_ultq_versions', array_slice( $versions, -20 ) );
	}

	/** 報價單號後面的版本標示：第 1 版不標 */
	public static function revision_label( $rev ) {
		if ( $rev < 2 ) {
			return '';
		}
		return '（第 ' . $rev . ' 版）';
	}

	public static function get_token( $post_id, $create = false ) {
		$token = (string) get_post_meta( $post_id, '_ultq_token', true );
		if ( ! $token && $create ) {
			$token = wp_generate_password( 32, false );
			update_post_meta( $post_id, '_ultq_token', $token );
		}
		return $token;
	}

	/** 作廢目前的 token（換收件人時），下次寄出報價會產生新的 */
	public static function reset_token( $post_id ) {
		delete_post_meta( $post_id, '_ultq_token' );
	}

	/** 顧客免登入下載報價單 PDF 的網址（Email 內附） */
	public static function public_pdf_url( $post_id ) {
		return add_query_arg( array(
			'ultq_pdf' => $post_id,
			'token'    => self::get_token( $post_id, true ),
		), home_url( '/' ) );
	}

	public static function admin_edit_url( $post_id ) {
		return admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' );
	}

	// ─── 紀錄 ──────────────────────────────────────────────────────────────

	public static function add_log( $post_id, $text ) {
		$log = get_post_meta( $post_id, '_ultq_log', true );
		$log = is_array( $log ) ? $log : array();
		$user = wp_get_current_user();
		$log[] = array(
			'time' => time(),
			'user' => $user && $user->exists() ? $user->display_name : '',
			'text' => $text,
		);
		update_post_meta( $post_id, '_ultq_log', array_slice( $log, -100 ) );
	}

	public static function get_log( $post_id ) {
		$log = get_post_meta( $post_id, '_ultq_log', true );
		return is_array( $log ) ? $log : array();
	}

	public static function set_status( $post_id, $status ) {
		if ( ! isset( self::statuses()[ $status ] ) || get_post_status( $post_id ) === $status ) {
			return;
		}
		wp_update_post( array( 'ID' => $post_id, 'post_status' => $status ) );
	}

	/**
	 * 某位顧客的詢價單（我的詢價），依 user ID
	 */
	public static function query_by_user( $user_id, $paged = 1, $per_page = 10 ) {
		return new WP_Query( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array_keys( self::statuses() ),
			'meta_key'       => '_ultq_user_id', // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => (int) $user_id, // phpcs:ignore WordPress.DB.SlowDBQuery
			'posts_per_page' => $per_page,
			'paged'          => max( 1, (int) $paged ),
			'orderby'        => 'date',
			'order'          => 'DESC',
		) );
	}
}
