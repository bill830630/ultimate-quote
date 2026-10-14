<?php
/**
 * 前台：隱藏價格／顯示 B2B 參考價、按鈕改「加入詢價清單」、阻擋真實加入購物車、
 * B2B 階梯價表、商品列表徽章、浮動詢價清單按鈕
 *
 * 單一商品頁保留 WooCommerce 原生加入購物車表單（規格選擇才能照常運作），只換按鈕文字、
 * 插一個隱藏欄位 ultq_quote=1 當標記，JS 在 capture 階段攔下送出改走 AJAX（Blocksy 的
 * AJAX 加入購物車也是聽 submit，capture 階段先攔就不會被它送去購物車）。沒有 JS 時表單照常
 * POST，由 handle_no_js_submit() 在 WooCommerce 處理加入購物車之前接手。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Frontend {

	private static $enqueued = false;

	public static function init() {
		add_filter( 'woocommerce_get_price_html', array( __CLASS__, 'price_html' ), 99, 2 );
		add_filter( 'woocommerce_is_purchasable', array( __CLASS__, 'is_purchasable' ), 99, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( __CLASS__, 'is_purchasable' ), 99, 2 );
		add_filter( 'woocommerce_variation_is_visible', array( __CLASS__, 'variation_is_visible' ), 99, 4 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'available_variation' ), 99, 3 );
		add_filter( 'woocommerce_product_single_add_to_cart_text', array( __CLASS__, 'button_text' ), 99, 2 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( __CLASS__, 'loop_button_text' ), 99, 2 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'loop_button' ), 99, 3 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_add_to_cart' ), 99, 4 );
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'form_marker' ) );
		// 非 B2B 會員看詢價商品：把加入表單換成「B2B 會員專屬」說明（Blocksy 直接呼叫 woocommerce_template_single_add_to_cart()，所以掛在各類型的 add_to_cart action）
		add_action( 'woocommerce_simple_add_to_cart', array( __CLASS__, 'maybe_gate_form' ), 1 );
		add_action( 'woocommerce_variable_add_to_cart', array( __CLASS__, 'maybe_gate_form' ), 1 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_tier_table' ), 11 );
		add_action( 'woocommerce_after_add_to_cart_button', array( __CLASS__, 'render_line_button' ) );
		add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'purge_cart' ) );
		add_action( 'wp_loaded', array( __CLASS__, 'handle_no_js_submit' ), 15 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_inline_style' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'maybe_enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'take_over_line_float' ), 1 );
		add_action( 'wp_footer', array( __CLASS__, 'render_float_button' ) );

		if ( 'blocksy' === get_template() ) {
			add_filter( 'blocksy:woocommerce:product-card:badges', array( __CLASS__, 'blocksy_badge' ) );
		} else {
			add_action( 'woocommerce_before_shop_loop_item_title', array( __CLASS__, 'loop_badge' ), 15 );
		}
	}

	// ─── 價格 ──────────────────────────────────────────────────────────────

	public static function hidden_price_html() {
		return '<span class="ultq-price ultq-price--hidden">' . esc_html( ULTQ_Settings::get( 'hidden_price_text', '價格請詢價' ) ) . '</span>';
	}

	public static function price_html( $html, $product ) {
		if ( ! ULTQ_Rules::is_quote_only( $product ) ) {
			return $html;
		}
		if ( ! ULTQ_Rules::is_b2b() ) {
			return self::hidden_price_html();
		}

		$range = $product->is_type( 'variable' )
			? ULTQ_B2B_Pricing::get_b2b_price_range( $product )
			: self::single_range( $product );

		if ( ! $range ) {
			return 'hidden' === ULTQ_Settings::get( 'b2b_price_fallback', 'regular' ) ? self::hidden_price_html() : $html;
		}

		list( $min, $max ) = $range;
		$amount = wc_price( $min ) . ( $max > $min || self::has_tiers( $product ) ? ' 起' : '' );
		return '<span class="ultq-price ultq-price--b2b"><span class="ultq-price__label">B2B 參考價</span> ' . $amount . '</span>';
	}

	/** 簡單商品／規格：有階梯價時以最低階梯價當下限 */
	private static function single_range( $product ) {
		$price = ULTQ_B2B_Pricing::get_b2b_price( $product );
		$tiers = ULTQ_B2B_Pricing::get_tiers( $product );
		foreach ( $tiers as $tier ) {
			$price = null === $price ? (float) $tier['price'] : min( $price, (float) $tier['price'] );
		}
		return null === $price ? null : array( $price, $price );
	}

	private static function has_tiers( $product ) {
		return ! $product->is_type( 'variable' ) && (bool) ULTQ_B2B_Pricing::get_tiers( $product );
	}

	public static function is_purchasable( $purchasable, $product ) {
		// B2B 會員：沒有價格的詢價商品 WooCommerce 會判定不可購買而不輸出表單，這裡放行，真正加入購物車由 block_add_to_cart() 擋。
		// 其他人：詢價商品一律不可購買
		if ( ULTQ_Rules::is_quote_only( $product ) ) {
			return ULTQ_Rules::can_request_quote() && 'publish' === get_post_status( $product->get_id() );
		}
		return $purchasable;
	}

	public static function variation_is_visible( $visible, $variation_id, $parent_id, $variation ) {
		if ( ! $visible && ULTQ_Rules::is_quote_only( $variation ) ) {
			return 'publish' === $variation->get_status();
		}
		return $visible;
	}

	public static function available_variation( $data, $product, $variation ) {
		if ( ! ULTQ_Rules::is_quote_only( $variation ) ) {
			return $data;
		}
		$data['is_purchasable'] = true;
		$data['price_html']     = '<span class="price">' . $variation->get_price_html() . '</span>';
		if ( ULTQ_Rules::is_b2b() ) {
			$data['price_html'] .= self::tier_table_html( $variation );
		}
		return $data;
	}

	// ─── 按鈕 ──────────────────────────────────────────────────────────────

	public static function button_label() {
		return (string) ULTQ_Settings::get( 'button_text', '加入詢價清單' ) ?: '加入詢價清單';
	}

	public static function button_text( $text, $product ) {
		return ULTQ_Rules::is_quote_only( $product ) ? self::button_label() : $text;
	}

	public static function loop_button_text( $text, $product ) {
		if ( ! ULTQ_Rules::is_quote_only( $product ) ) {
			return $text;
		}
		if ( ! ULTQ_Rules::can_request_quote() ) {
			return 'B2B 會員專屬';
		}
		return $product->is_type( 'variable' ) ? '選擇規格詢價' : self::button_label();
	}

	public static function loop_button( $html, $product, $args = array() ) {
		if ( ! ULTQ_Rules::is_quote_only( $product ) ) {
			return $html;
		}
		// 非 B2B 會員：連到商品頁（頁面上有說明與申請按鈕）
		if ( ! ULTQ_Rules::can_request_quote() ) {
			$classes = array_diff( preg_split( '/\s+/', (string) ( $args['class'] ?? 'button' ) ), array( 'add_to_cart_button', 'ajax_add_to_cart', 'product_type_simple', 'product_type_variable' ) );
			return sprintf( '<a href="%s" class="%s">%s</a>', esc_url( $product->get_permalink() ), esc_attr( implode( ' ', array_filter( $classes ) ) ), esc_html( 'B2B 會員專屬' ) );
		}
		if ( ! $product->is_type( 'simple' ) ) {
			return $html;
		}
		self::enqueue_assets();
		$classes = preg_split( '/\s+/', (string) ( $args['class'] ?? 'button' ) );
		$classes = array_diff( $classes, array( 'add_to_cart_button', 'ajax_add_to_cart', 'product_type_simple' ) );
		$classes[] = 'ultq-add-to-quote';
		return sprintf(
			'<a href="%s" data-product_id="%d" data-quantity="%d" class="%s" rel="nofollow">%s</a>',
			esc_url( $product->get_permalink() ),
			$product->get_id(),
			ULTQ_Quantity::rules( $product )['min'],
			esc_attr( implode( ' ', array_filter( $classes ) ) ),
			esc_html( self::button_label() )
		);
	}

	// ─── 用 LINE 詢問 ───────────────────────────────────────────────────

	/** 設定頁填的官方帳號 ID 正規化成 @xxxx；格式不對回空字串（不顯示按鈕） */
	public static function line_oa_id() {
		$id = trim( (string) ULTQ_Settings::get( 'line_oa_id', '' ) );
		if ( '' === $id || ! preg_match( '/^@?[A-Za-z0-9._-]{3,40}$/', $id ) ) {
			return '';
		}
		return '@' . ltrim( $id, '@' );
	}

	/**
	 * LINE 的 oaMessage 連結：開啟與官方帳號的聊天室並預填訊息（顧客按送出才會真的傳）
	 * https://line.me/R/oaMessage/{@ID 需編碼}/?{訊息}
	 */
	public static function line_message_url( $text ) {
		return 'https://line.me/R/oaMessage/' . rawurlencode( self::line_oa_id() ) . '/?' . rawurlencode( $text );
	}

	public static function line_message_text( $product_name, $qty, $url ) {
		return strtr( (string) ULTQ_Settings::get( 'line_inquiry_msg', '' ), array(
			'{product}' => $product_name,
			'{qty}'     => (string) $qty,
			'{url}'     => $url,
		) );
	}

	public static function show_line_button( $product ) {
		$mode = (string) ULTQ_Settings::get( 'line_inquiry', 'quote' );
		if ( 'off' === $mode || ! $product || ! self::line_oa_id() ) {
			return false;
		}
		// 詢價商品只有 B2B 會員能詢價（LINE 詢問也算）；非 B2B 會員看詢價商品時表單已換成說明，按鈕也不會出現
		if ( ULTQ_Rules::is_quote_only( $product ) ) {
			return ULTQ_Rules::can_request_quote();
		}
		return 'all' === $mode;
	}

	/**
	 * 商品頁「用 LINE 詢問」，緊接在加入購物車／詢價按鈕後面（Blocksy 會一起包在 .ct-cart-actions 裡並排）。
	 * 是 <a> 不是 submit，不會觸發表單送出。href 是沒有 JS 時的預設訊息；
	 * 有 JS 時點擊當下再依已選規格與數量重組訊息（ultq-frontend.js）
	 */
	public static function render_line_button() {
		global $product;
		if ( ! self::show_line_button( $product ) ) {
			return;
		}
		self::enqueue_assets();
		$name = wp_strip_all_tags( $product->get_name() );
		$url  = $product->get_permalink();
		printf(
			'<a class="button ultq-line__btn" href="%1$s" target="_blank" rel="noopener" data-oa="%2$s" data-template="%3$s" data-name="%4$s" data-url="%5$s">%6$s<span>%7$s</span></a>',
			esc_url( self::line_message_url( self::line_message_text( $name, 1, $url ) ) ),
			esc_attr( self::line_oa_id() ),
			esc_attr( (string) ULTQ_Settings::get( 'line_inquiry_msg', '' ) ),
			esc_attr( $name ),
			esc_url( $url ),
			'<svg class="ultq-line__icon" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12 2.5C6.48 2.5 2 6.12 2 10.58c0 4 3.55 7.35 8.35 7.98.33.07.77.22.88.5.1.25.07.65.03.9l-.14.86c-.04.25-.2.99.87.54 1.07-.45 5.75-3.39 7.85-5.8C21.29 13.97 22 12.36 22 10.58 22 6.12 17.52 2.5 12 2.5Zm-3.97 10.7H6.04a.53.53 0 0 1-.53-.53V8.7a.53.53 0 0 1 1.06 0v3.44h1.46a.53.53 0 0 1 0 1.06Zm2.06-.53a.53.53 0 0 1-1.06 0V8.7a.53.53 0 0 1 1.06 0v3.97Zm4.79 0a.53.53 0 0 1-.95.32l-2.04-2.78v2.46a.53.53 0 0 1-1.06 0V8.7a.53.53 0 0 1 .95-.32l2.04 2.78V8.7a.53.53 0 0 1 1.06 0v3.97Zm3.22-2.51a.53.53 0 0 1 0 1.06h-1.46v.92h1.46a.53.53 0 0 1 0 1.06h-1.99a.53.53 0 0 1-.53-.53V8.7c0-.29.24-.53.53-.53h1.99a.53.53 0 0 1 0 1.06h-1.46v.93h1.46Z"/></svg>',
			esc_html( (string) ULTQ_Settings::get( 'line_inquiry_text', '用 LINE 詢問' ) )
		);
	}

	public static function form_marker() {
		global $product;
		if ( $product && ULTQ_Rules::is_quote_only( $product ) ) {
			self::enqueue_assets();
			echo '<input type="hidden" name="ultq_quote" value="1">';
		}
	}

	public static function block_add_to_cart( $passed, $product_id, $quantity, $variation_id = 0 ) {
		if ( $passed && ULTQ_Rules::is_quote_only( $variation_id ?: $product_id ) ) {
			wc_add_notice(
				ULTQ_Rules::can_request_quote()
					? sprintf( '此商品需透過詢價購買，請改用「%s」。', self::button_label() )
					: '此商品為 B2B 會員專屬，申請成為 B2B 會員後即可詢價。',
				'error'
			);
			return false;
		}
		return $passed;
	}

	/**
	 * 非 B2B 會員看詢價商品：拿掉這次的加入表單（WooCommerce 在 priority 30 輸出），改印說明；
	 * 印完在 priority 31 把表單掛回去，同一頁其他商品（例如快速檢視）不受影響
	 */
	public static function maybe_gate_form() {
		global $product;
		if ( ! $product || ! ULTQ_Rules::is_quote_only( $product ) || ULTQ_Rules::can_request_quote() ) {
			return;
		}
		$hook     = current_action();
		$callback = $hook; // WooCommerce 的輸出函式和 action 同名（woocommerce_simple_add_to_cart／woocommerce_variable_add_to_cart）
		if ( ! remove_action( $hook, $callback, 30 ) ) {
			return;
		}
		self::enqueue_assets();
		echo self::b2b_gate_html( get_permalink( $product->get_id() ) ); // phpcs:ignore WordPress.Security.EscapeOutput
		add_action( $hook, function () use ( $hook, $callback ) {
			add_action( $hook, $callback, 30 );
		}, 31 );
	}

	/**
	 * 「B2B 會員專屬」說明＋依身分的按鈕：訪客 → 登入／註冊；一般會員 → 申請 B2B；審核中 → 只顯示狀態
	 *
	 * @param string      $redirect 登入後回到哪裡
	 * @param string|null $text     說明文字；null＝設定頁「非 B2B 會員看到的說明」（商品頁用）
	 */
	public static function b2b_gate_html( $redirect = '', $text = null ) {
		$user_id = get_current_user_id();
		$status  = ULTQ_B2B_Member::get_status( $user_id );
		$enabled = (bool) ULTQ_Settings::get( 'b2b_enabled', 1 );
		$button  = null;
		$note    = '';
		if ( ! $user_id ) {
			$url    = wc_get_page_permalink( 'myaccount' );
			$button = array( '登入或註冊會員', $redirect ? add_query_arg( 'redirect_to', rawurlencode( $redirect ), $url ) : $url );
		} elseif ( 'pending' === $status ) {
			$note = '您的 B2B 會員申請審核中，核准後會以 Email 通知您。';
		} elseif ( $enabled ) {
			$button = array( 'rejected' === $status ? '重新申請 B2B 會員' : '申請成為 B2B 會員', wc_get_account_endpoint_url( ULTQ_B2B_Member::ENDPOINT ) );
		}
		return ULTQ_Helper::get_template_html( 'single-product/b2b-only.php', array(
			'text'   => null === $text ? (string) ULTQ_Settings::get( 'b2b_only_text', '' ) : $text,
			'note'   => $note,
			'button' => $button,
		) );
	}

	/**
	 * 購物車裡如果有詢價商品（例如顧客先加入購物車，之後才被核准成 B2B，或店家後來把商品改成詢價），
	 * 移出購物車並提示
	 */
	public static function purge_cart( $cart ) {
		$removed = array();
		foreach ( $cart->get_cart() as $key => $item ) {
			if ( ! empty( $item['data'] ) && ULTQ_Rules::is_quote_only( $item['data'] ) ) {
				$removed[] = $item['data']->get_name();
				unset( $cart->cart_contents[ $key ] );
			}
		}
		if ( $removed && ! wp_doing_ajax() ) {
			wc_add_notice(
				sprintf( '以下商品需透過詢價購買，已從購物車移除：%s', implode( '、', array_map( 'wp_strip_all_tags', $removed ) ) ),
				'notice'
			);
		}
	}

	/**
	 * 沒有 JS 時表單照常 POST（帶 ultq_quote=1）：在 WooCommerce 的加入購物車處理（wp_loaded:20）
	 * 之前接手，加進詢價清單後導回商品頁
	 */
	public static function handle_no_js_submit() {
		if ( empty( $_REQUEST['ultq_quote'] ) || empty( $_REQUEST['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$product_id   = absint( wp_unslash( $_REQUEST['add-to-cart'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$variation_id = absint( wp_unslash( $_REQUEST['variation_id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$qty          = absint( wp_unslash( $_REQUEST['quantity'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$variation    = array();
		foreach ( $_REQUEST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( 0 === strpos( (string) $key, 'attribute_' ) ) {
				$variation[ sanitize_title( $key ) ] = wc_clean( wp_unslash( $value ) );
			}
		}

		unset( $_REQUEST['add-to-cart'], $_POST['add-to-cart'], $_GET['add-to-cart'] );

		$result = ULTQ_List::add( $product_id, $variation_id, $variation, $qty ?: 1 );
		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return;
		}
		wc_add_notice( sprintf( '已加入詢價清單。<a href="%s" class="button wc-forward">查看詢價清單</a>', esc_url( ULTQ_List::page_url() ) ) );
		wp_safe_redirect( get_permalink( $product_id ) ?: ULTQ_List::page_url() );
		exit;
	}

	// ─── B2B 階梯價表 ───────────────────────────────────────────────────────

	public static function render_tier_table() {
		global $product;
		if ( ! $product || ! $product->is_type( 'simple' ) || ! ULTQ_Rules::is_b2b() || ! ULTQ_Rules::is_quote_only( $product ) ) {
			return;
		}
		echo self::tier_table_html( $product ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function tier_table_html( $product ) {
		$rows = ULTQ_B2B_Pricing::get_tier_rows( $product );
		if ( ! $rows ) {
			return '';
		}
		return ULTQ_Helper::get_template_html( 'single-product/b2b-tiers.php', array( 'rows' => $rows ) );
	}

	// ─── 徽章 ──────────────────────────────────────────────────────────────

	private static function show_badge( $product ) {
		return $product && ULTQ_Settings::get( 'show_loop_badge', 1 ) && ULTQ_Rules::is_flagged( $product );
	}

	public static function loop_badge() {
		global $product;
		if ( self::show_badge( $product ) ) {
			echo '<span class="ultq-badge">詢價</span>';
		}
	}

	/** Blocksy：走主題自己的縮圖徽章 filter，形狀跟隨客製化工具的特價徽章設定 */
	public static function blocksy_badge( $badges ) {
		global $product;
		if ( ! self::show_badge( $product ) ) {
			return $badges;
		}
		$shape    = function_exists( 'blocksy_get_theme_mod' ) ? blocksy_get_theme_mod( 'sale_badge_shape', 'type-2' ) : 'type-2';
		$badges[] = sprintf( '<span class="ct-woo-badge-ultq" data-shape="%s">詢價</span>', esc_attr( $shape ) );
		return $badges;
	}

	// ─── 資源 ──────────────────────────────────────────────────────────────

	/**
	 * 「價格請詢價」文字、徽章、浮動按鈕可能出現在任何頁面，比照競標外掛用沒有實體檔案的
	 * placeholder style 站台範圍輸出（不額外發 HTTP 請求）。顏色只吃 Blocksy 調色盤變數＋保底值
	 */
	public static function enqueue_inline_style() {
		wp_register_style( 'ultq-inline', false ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters
		wp_enqueue_style( 'ultq-inline' );
		wp_add_inline_style( 'ultq-inline', '
			:root {
				--ultq-accent: var( --theme-palette-color-1, #2872fa );
				--ultq-box-bg: #f4f8ff;
				--ultq-box-bg: color-mix( in srgb, var( --ultq-accent ) 8%, #fff );
				--ultq-box-border: #bcd3fd;
				--ultq-box-border: color-mix( in srgb, var( --ultq-accent ) 32%, #fff );
				--ultq-text: var( --theme-text-color, var( --theme-palette-color-3, #3a4f66 ) );
				--ultq-border: var( --theme-border-color, var( --theme-palette-color-5, #e1e8ed ) );
			}
			.ultq-price--hidden { color: var( --ultq-text ); }
			.ultq-price__label { display: inline-block; margin-right: 0.3em; padding: 0 0.45em; font-size: 0.7em; font-weight: 600; line-height: 1.7; vertical-align: middle; border-radius: 3px; color: var( --ultq-accent ); background: var( --ultq-box-bg ); }
			.ultq-badge { display: inline-block; margin: 0.4em 0; padding: 0.15em 0.6em; font-size: 0.75em; font-weight: 600; border-radius: 3px; color: #fff; background: var( --ultq-accent ); }
			.ct-woo-badge-ultq { background-color: var( --ultq-accent ); color: #fff; }
			.ultq-float { position: fixed; bottom: 20px; z-index: 99990; display: none; align-items: center; justify-content: center; width: 56px; height: 56px; border-radius: 50%; color: #fff; background: var( --ultq-accent ); box-shadow: 0 4px 14px rgba( 0, 0, 0, 0.18 ); text-decoration: none; transition: transform 0.15s ease; }
			.ultq-float:hover, .ultq-float:focus-visible { color: #fff; transform: scale( 1.06 ); }
			.ultq-float.is-visible { display: flex; }
			.ultq-float--left { left: 20px; }
			.ultq-float--right { right: 20px; }
			.ultq-float__count { position: absolute; top: -4px; right: -4px; min-width: 22px; height: 22px; padding: 0 6px; box-sizing: border-box; font-size: 12px; font-weight: 700; line-height: 20px; text-align: center; border-radius: 11px; border: 1px solid var( --ultq-accent ); color: var( --ultq-accent ); background: #fff; }
			.ultq-dock { position: fixed; bottom: 20px; z-index: 99990; display: flex; flex-direction: column; align-items: center; gap: 12px; }
			.ultq-dock--right { right: 20px; }
			.ultq-dock--left { left: 20px; }
			.ultq-dock .ultq-float { position: relative; left: auto; right: auto; bottom: auto; }
			.ultq-dock .wclon-line-float { position: static; }
			@media ( max-width: 600px ) { .ultq-float, .ultq-dock { bottom: 14px; } .ultq-float--right, .ultq-dock--right { right: 14px; } .ultq-float--left, .ultq-dock--left { left: 14px; } .ultq-dock .ultq-float { bottom: auto; left: auto; right: auto; } }
		' );
	}

	/** 詢價清單頁、商品頁、商品列表頁一定載入；其他頁面由 loop_button()/form_marker() 按需載入 */
	public static function maybe_enqueue() {
		$list_page = (int) ULTQ_Settings::get( 'list_page_id' );
		if ( ( $list_page && is_page( $list_page ) ) || is_product() || is_shop() || is_product_taxonomy() ) {
			self::enqueue_assets();
		}
	}

	public static function enqueue_assets() {
		if ( self::$enqueued ) {
			return;
		}
		self::$enqueued = true;
		wp_enqueue_style( 'ultq-frontend', ULTQ_PLUGIN_URL . 'assets/css/ultq-frontend.css', array(), ULTQ_VERSION );
		wp_enqueue_script( 'ultq-frontend', ULTQ_PLUGIN_URL . 'assets/js/ultq-frontend.js', array( 'jquery' ), ULTQ_VERSION, true );
		wp_localize_script( 'ultq-frontend', 'ultqData', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'ultq_front' ),
			'listUrl' => ULTQ_List::page_url(),
			'cookie'  => ULTQ_List::COOKIE,
			'taxLookup' => ULTQ_Tax_Lookup::enabled(),
			'i18n'    => array(
				'added'      => '已加入詢價清單',
				'viewList'   => '查看詢價清單',
				'error'      => '操作失敗，請重新整理後再試一次。',
				'submitting' => '送出中…',
				'confirmDel' => '確定要從詢價清單移除這個商品嗎？',
				'tooMany'    => '附件最多 %d 個檔案。',
				'tooLarge'   => '「%s」超過檔案大小上限。',
				'taxFound'   => '已帶入登記資料：',
			),
		) );
	}

	/** 終極登入的懸浮 LINE 按鈕有開時，由這裡接手輸出，和詢價清單按鈕合併在同一角 */
	private static $line_float_merged = false;

	public static function take_over_line_float() {
		if ( 'off' === ULTQ_Settings::get( 'float_position', 'left' ) || ! class_exists( 'WCLON_Line_Float' ) || ! WCLON_Line_Float::active() ) {
			return;
		}
		self::$line_float_merged = (bool) remove_action( 'wp_footer', array( 'WCLON_Line_Float', 'render' ) );
	}

	public static function render_float_button() {
		$position = ULTQ_Settings::get( 'float_position', 'left' );
		if ( 'off' === $position || is_admin() ) {
			return;
		}
		$list_page = (int) ULTQ_Settings::get( 'list_page_id' );
		// 詢價清單頁本身、或不能詢價的人（非 B2B 會員）不顯示詢價清單按鈕，但接手過的 LINE 按鈕仍要輸出
		if ( ( $list_page && is_page( $list_page ) ) || ! ULTQ_Rules::can_request_quote() ) {
			if ( self::$line_float_merged ) {
				WCLON_Line_Float::render();
			}
			return;
		}
		// 數量由 JS 讀 cookie 填入（頁面快取時伺服器輸出的數字可能是舊的）；沒有 cookie 時維持隱藏。
		// 用一小段 inline script，不必為了浮動按鈕在每一頁載入完整的 ultq-frontend.js
		$button = sprintf(
			'<a href="%s" class="ultq-float ultq-float--%s" aria-label="詢價清單" title="詢價清單"><svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true" focusable="false"><path fill="currentColor" d="M9 2h6a1 1 0 0 1 1 1v1h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2V3a1 1 0 0 1 1-1Zm1 2v2h4V4h-4ZM8 6H6v14h12V6h-2v1a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V6Zm0 5h8v2H8v-2Zm0 4h5v2H8v-2Z"/></svg><span class="ultq-float__count">0</span></a>',
			esc_url( ULTQ_List::page_url() ),
			esc_attr( 'right' === $position ? 'right' : 'left' )
		);
		if ( self::$line_float_merged ) {
			// 合併：跟著 LINE 按鈕的位置，上下疊放，詢價清單在 LINE 上方
			ob_start();
			WCLON_Line_Float::render();
			$line = ob_get_clean();
			printf( '<div class="ultq-dock ultq-dock--%s">%s%s</div>', esc_attr( WCLON_Line_Float::position() ), $button, $line ); // phpcs:ignore WordPress.Security.EscapeOutput
		} else {
			echo $button; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		?>
		<script>
		window.ultqUpdateFloat = function () {
			var m = document.cookie.match( /(?:^|;\s*)<?php echo esc_js( ULTQ_List::COOKIE ); ?>=(\d+)/ ), n = m ? parseInt( m[1], 10 ) : 0;
			document.querySelectorAll( '.ultq-float' ).forEach( function ( el ) {
				el.querySelector( '.ultq-float__count' ).textContent = n;
				el.classList.toggle( 'is-visible', n > 0 );
			} );
		};
		window.ultqUpdateFloat();
		</script>
		<?php
	}
}
