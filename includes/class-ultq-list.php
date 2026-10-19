<?php
/**
 * 詢價清單：存在 WooCommerce session（訪客也能用），登入會員另存 user meta 以便跨裝置
 *
 * 品項以 md5( product_id|variation_id|規格 ) 為 key：
 * array( 'product_id', 'variation_id', 'variation' => array( 'attribute_pa_color' => 'red' ), 'qty', 'note' )
 *
 * 清單數量另外寫一個 cookie（ultq_count），前台浮動按鈕用 JS 讀 cookie 顯示，
 * 頁面被快取時數量也不會錯。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_List {

	const SESSION_KEY = 'ultq_list';
	const META_KEY    = '_ultq_list';
	const COOKIE      = 'ultq_count';

	/** 最近一次 add()／update() 因最低訂購量／包裝倍數調整數量時的說明，沒調整是空字串 */
	public static $adjusted = '';

	public static function init() {
		add_shortcode( 'ultq_quote_list', array( __CLASS__, 'render_shortcode' ) );
	}

	private static function session() {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return null;
		}
		return WC()->session;
	}

	public static function get_items() {
		$session = self::session();
		$items   = $session ? $session->get( self::SESSION_KEY ) : null;
		$items   = is_array( $items ) ? $items : array();

		// 登入會員：第一次讀取時把其他裝置存的清單合併進目前 session
		$user_id = get_current_user_id();
		if ( $user_id && $session && ! $session->get( 'ultq_list_synced' ) ) {
			$saved = get_user_meta( $user_id, self::META_KEY, true );
			if ( is_array( $saved ) ) {
				foreach ( $saved as $key => $item ) {
					if ( ! isset( $items[ $key ] ) ) {
						$items[ $key ] = $item;
					}
				}
			}
			$session->set( 'ultq_list_synced', 1 );
			self::save( $items );
		}

		return $items;
	}

	public static function count() {
		return count( self::get_items() );
	}

	private static function save( array $items ) {
		$session = self::session();
		if ( $session ) {
			if ( ! is_user_logged_in() && ! $session->has_session() ) {
				$session->set_customer_session_cookie( true );
			}
			$session->set( self::SESSION_KEY, $items );
		}
		$user_id = get_current_user_id();
		if ( $user_id ) {
			if ( $items ) {
				update_user_meta( $user_id, self::META_KEY, $items );
			} else {
				delete_user_meta( $user_id, self::META_KEY );
			}
		}
		if ( ! headers_sent() ) {
			wc_setcookie( self::COOKIE, (string) count( $items ), 0, false, false );
		}
	}

	/**
	 * @param array $variation 前台送來的 attribute_* 欄位
	 * @return string|WP_Error 品項 key
	 */
	public static function add( $product_id, $variation_id = 0, array $variation = array(), $qty = 1, $note = '' ) {
		$product_id   = absint( $product_id );
		$variation_id = absint( $variation_id );
		$qty          = max( 1, absint( $qty ) );
		$product      = wc_get_product( $product_id );

		if ( ! $product || 'publish' !== $product->get_status() ) {
			return new WP_Error( 'ultq_invalid', '找不到這個商品。' );
		}
		if ( $product->is_type( 'variation' ) ) {
			$variation_id = $product->get_id();
			$product_id   = $product->get_parent_id();
			$product      = wc_get_product( $product_id );
			// 規格本身永遠是 publish，要再看主商品（草稿／私密的商品不能詢價）
			if ( ! $product || 'publish' !== $product->get_status() ) {
				return new WP_Error( 'ultq_invalid', '找不到這個商品。' );
			}
		}
		if ( ! ULTQ_Rules::is_quote_only( $product ) ) {
			return new WP_Error( 'ultq_not_quote', '這個商品可以直接購買，不需要詢價。' );
		}
		if ( ! ULTQ_Rules::can_request_quote() ) {
			return new WP_Error( 'ultq_b2b_only', '這個商品為 B2B 會員專屬，申請成為 B2B 會員後即可詢價。' );
		}

		$clean_variation = array();
		if ( $product->is_type( 'variable' ) ) {
			if ( ! $variation_id ) {
				$variation_id = ( new WC_Product_Data_Store_CPT() )->find_matching_product_variation( $product, $variation );
			}
			$child = $variation_id ? wc_get_product( $variation_id ) : null;
			// 停用的規格 status 是 private
			if ( ! $child || $child->get_parent_id() !== $product_id || 'publish' !== $child->get_status() ) {
				return new WP_Error( 'ultq_variation', '請先選擇商品規格。' );
			}
			// 規格設成「任何」的屬性要用顧客選的值，其餘以規格本身為準
			foreach ( $child->get_variation_attributes() as $key => $value ) {
				if ( '' === $value ) {
					$posted = isset( $variation[ $key ] ) ? wc_clean( wp_unslash( $variation[ $key ] ) ) : '';
					if ( '' === $posted ) {
						return new WP_Error( 'ultq_variation', '請先選擇商品規格。' );
					}
					$clean_variation[ $key ] = $posted;
				} else {
					$clean_variation[ $key ] = $value;
				}
			}
		} else {
			$variation_id = 0;
		}

		$items = self::get_items();
		$key   = md5( $product_id . '|' . $variation_id . '|' . wp_json_encode( $clean_variation ) );

		if ( isset( $items[ $key ] ) ) {
			$items[ $key ]['qty'] = self::fix_qty( $product, $items[ $key ]['qty'] + $qty );
			if ( '' !== $note ) {
				$items[ $key ]['note'] = $note;
			}
		} else {
			$items[ $key ] = array(
				'product_id'   => $product_id,
				'variation_id' => $variation_id,
				'variation'    => $clean_variation,
				'qty'          => self::fix_qty( $product, $qty ),
				'note'         => sanitize_text_field( $note ),
			);
		}

		self::save( $items );
		return $key;
	}

	/** 依最低訂購量／包裝倍數調整，有調整時把說明記在 $adjusted */
	private static function fix_qty( $product, $qty ) {
		$fixed = ULTQ_Quantity::fix( $product, $qty );
		self::$adjusted = $fixed !== (int) $qty
			? sprintf( '「%s」%s，數量已調整為 %d。', wp_strip_all_tags( $product->get_name() ), ULTQ_Quantity::describe( $product ), $fixed )
			: '';
		return $fixed;
	}

	public static function update( $key, $qty, $note = null ) {
		self::$adjusted = '';
		$items = self::get_items();
		if ( ! isset( $items[ $key ] ) ) {
			return;
		}
		$qty = absint( $qty );
		if ( $qty < 1 ) {
			unset( $items[ $key ] );
		} else {
			$product = wc_get_product( $items[ $key ]['product_id'] );
			$items[ $key ]['qty'] = $product ? self::fix_qty( $product, $qty ) : $qty;
			if ( null !== $note ) {
				$items[ $key ]['note'] = sanitize_text_field( $note );
			}
		}
		self::save( $items );
	}

	public static function remove( $key ) {
		$items = self::get_items();
		unset( $items[ $key ] );
		self::save( $items );
	}

	public static function clear() {
		self::save( array() );
	}

	/**
	 * 規格顯示用文字 array( '顏色' => '紅', '尺寸' => 'L' )
	 */
	public static function attribute_labels( $product, array $variation ) {
		$labels = array();
		if ( ! $product || ! $product->is_type( 'variation' ) ) {
			return $labels;
		}
		$attributes = $variation ?: $product->get_variation_attributes();
		foreach ( $attributes as $key => $value ) {
			$taxonomy = str_replace( 'attribute_', '', $key );
			$label    = wc_attribute_label( $taxonomy, $product );
			if ( taxonomy_exists( $taxonomy ) ) {
				$term  = get_term_by( 'slug', $value, $taxonomy );
				$value = $term && ! is_wp_error( $term ) ? $term->name : $value;
			}
			if ( '' !== (string) $value ) {
				$labels[ $label ] = (string) $value;
			}
		}
		return $labels;
	}

	public static function page_url() {
		$page_id = (int) ULTQ_Settings::get( 'list_page_id' );
		return $page_id ? get_permalink( $page_id ) : home_url( '/' );
	}

	/**
	 * 清單裡的品項現在還能不能詢價：商品（與規格）仍上架、仍是詢價商品。
	 * 加入清單之後商品可能被下架、停用規格或改回一般販售，顯示與送出時都要再檢查
	 */
	public static function is_available( array $item ) {
		$product = wc_get_product( (int) ( $item['product_id'] ?? 0 ) );
		if ( ! $product || 'publish' !== $product->get_status() || ! ULTQ_Rules::is_quote_only( $product ) ) {
			return false;
		}
		if ( ! empty( $item['variation_id'] ) ) {
			$child = wc_get_product( (int) $item['variation_id'] );
			return $child && $child->get_parent_id() === $product->get_id() && 'publish' === $child->get_status();
		}
		return ! $product->is_type( 'variable' );
	}

	/** 送出用：只留還能詢價的品項（跟清單頁顯示的一致） */
	public static function get_available_items() {
		return array_filter( self::get_items(), array( __CLASS__, 'is_available' ) );
	}

	/** 清單頁顯示用：補上商品物件，順便剔除已下架／不再需要詢價的品項 */
	public static function get_display_items() {
		$rows = array();
		foreach ( self::get_available_items() as $key => $item ) {
			$product = wc_get_product( $item['variation_id'] ?: $item['product_id'] );
			if ( ! $product ) {
				continue;
			}
			$rows[ $key ] = array_merge( $item, array(
				'key'        => $key,
				'product'    => $product,
				'attributes' => self::attribute_labels( $product, $item['variation'] ?? array() ),
				'qty_rules'  => ULTQ_Quantity::rules( $product ),
				'qty_note'   => ULTQ_Quantity::describe( $product ),
			) );
		}
		return $rows;
	}

	/** 送出表單預填（後台開單選會員時也用）：B2B 申請資料 → WooCommerce 帳單資料 */
	public static function prefill_customer( $user_id = null ) {
		$data = array( 'name' => '', 'company' => '', 'tax_id' => '', 'email' => '', 'phone' => '', 'address' => '', 'customer_type' => 'personal' );
		$user = null === $user_id ? wp_get_current_user() : get_userdata( (int) $user_id );
		if ( ! $user || ! $user->exists() ) {
			return $data;
		}
		$customer        = new WC_Customer( $user->ID );
		$b2b             = ULTQ_B2B_Member::get_data( $user->ID );
		$data['name']    = $b2b['contact'] ?: ( trim( $customer->get_billing_last_name() . $customer->get_billing_first_name() ) ?: $user->display_name );
		$data['company'] = $b2b['company'] ?: $customer->get_billing_company();
		$data['tax_id']  = $b2b['tax_id'];
		$data['customer_type'] = metadata_exists( 'user', $user->ID, ULTQ_B2B_Member::META_DATA ) ? $b2b['customer_type'] : ( $data['company'] || $data['tax_id'] ? 'company' : 'personal' );
		$data['email']   = $customer->get_billing_email() ?: $user->user_email;
		$data['phone']   = $b2b['phone'] ?: $customer->get_billing_phone();
		$data['address'] = $b2b['address'] ?: trim( $customer->get_billing_state() . $customer->get_billing_city() . $customer->get_billing_address_1() );
		return $data;
	}

	public static function render_shortcode() {
		if ( ! function_exists( 'WC' ) ) {
			return '';
		}
		ULTQ_Frontend::enqueue_assets();
		// Email／LINE 的「查看報價」連結（?ultq_view=ID&token=）借用這個頁面顯示報價內容
		$view = ULTQ_Quote_Response::maybe_render_view();
		if ( null !== $view ) {
			return '<div class="woocommerce ultq-quote-view-wrap">' . $view . '</div>';
		}
		// 「再次詢價」等導回這頁時帶的 WooCommerce 提示（一般頁面不會自動印）
		$notices = function_exists( 'wc_print_notices' ) && WC()->session ? wc_print_notices( true ) : '';
		return $notices . '<div class="ultq-quote-list-wrap">' . self::render_list_html() . '</div>';
	}

	public static function render_list_html() {
		return ULTQ_Helper::get_template_html( 'quote-list.php', array(
			'items'       => self::get_display_items(),
			'customer'    => self::prefill_customer(),
			'can_request' => ULTQ_Rules::can_request_quote(),
			'gate'        => ULTQ_Rules::can_request_quote() ? '' : ULTQ_Frontend::b2b_gate_html( self::page_url(), '詢價功能僅限 B2B 會員使用，成為 B2B 會員後即可查看批發價格並線上詢價。' ),
			'login_url'   => add_query_arg( 'redirect_to', rawurlencode( self::page_url() ), wc_get_page_permalink( 'myaccount' ) ),
			'shop_url'    => wc_get_page_permalink( 'shop' ),
		) );
	}
}
