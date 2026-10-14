<?php
/**
 * 顧客線上回覆報價（接受／婉拒）
 *
 * 入口有兩個，共用同一份回覆表單（templates/quote-respond.php）：
 * - 我的帳戶 → 我的詢價 → 明細（登入且是本人）
 * - 免登入檢視頁：詢價清單頁加上 ?ultq_view=ID&token=xxx（Email、LINE 的按鈕都連這裡，訪客詢價也能用）
 *
 * 能回覆的條件見 ULTQ_Quote_Post::can_respond()（設定有開、已寄出、狀態已報價、未過期）。
 * 回覆後狀態改成「顧客已接受／顧客已婉拒」、寫紀錄、通知管理員（Email＋LINE）；
 * 管理員重新寄出報價時清除回覆，顧客可以再回覆一次（見 ULTQ_Notifications::send_quote()）。
 *
 * 「再次詢價」也放在這裡（同樣的存取權判斷）：把舊詢價單的商品放回詢價清單，再導到清單頁。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Quote_Response {

	const QUERY_VAR = 'ultq_view';

	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'handle_submit' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'handle_requote' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'no_cache_view' ), 1 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ) );
	}

	/** 免登入檢視頁（Email、LINE 按鈕用） */
	public static function view_url( $post_id ) {
		return add_query_arg( array(
			self::QUERY_VAR => (int) $post_id,
			'token'         => ULTQ_Quote_Post::get_token( $post_id, true ),
		), ULTQ_List::page_url() );
	}

	/** 是否可以檢視這張報價：token 正確，或登入且是本人 */
	public static function can_access( $post_id, $token = '' ) {
		if ( ULTQ_Quote_Post::POST_TYPE !== get_post_type( $post_id ) ) {
			return false;
		}
		$saved = ULTQ_Quote_Post::get_token( $post_id );
		if ( $token && $saved && hash_equals( $saved, (string) $token ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		return $user_id && ULTQ_Quote_Post::get_user_id( $post_id ) === $user_id;
	}

	private static function requested_view() {
		return absint( $_GET[ self::QUERY_VAR ] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	private static function requested_token() {
		return sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/** 帶 token 的頁面不能被頁面快取存起來，也不要被搜尋引擎收錄 */
	public static function no_cache_view() {
		if ( self::requested_view() ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	public static function robots( $robots ) {
		if ( self::requested_view() ) {
			$robots['noindex']  = true;
			$robots['nofollow'] = true;
		}
		return $robots;
	}

	/**
	 * 詢價清單頁的 shortcode 遇到 ?ultq_view= 時改顯示報價內容
	 *
	 * @return string|null null 表示不是檢視請求，照常顯示詢價清單
	 */
	public static function maybe_render_view() {
		$post_id = self::requested_view();
		if ( ! $post_id ) {
			return null;
		}
		$token = self::requested_token();
		if ( ! self::can_access( $post_id, $token ) || ! ULTQ_Quote_Post::is_customer_visible( $post_id ) ) {
			return '<div class="woocommerce-error">找不到這份報價單，或連結已失效。</div>';
		}
		return ULTQ_Helper::get_template_html( 'myaccount/quote-view.php', self::view_args( $post_id, $token, '' ) );
	}

	/** 報價檢視樣板（我的帳戶明細與免登入檢視頁共用）的參數 */
	public static function view_args( $post_id, $token, $back_url ) {
		$visible = ULTQ_Quote_Post::is_customer_visible( $post_id );
		// 已報價：顯示最後寄出的那一版；還沒報價：顯示顧客當初詢問的內容
		$sent    = $visible ? ULTQ_Quote_Post::sent( $post_id ) : null;
		$pdf_url = '';
		if ( $visible ) {
			$pdf_url = $token
				? add_query_arg( array( 'ultq_pdf' => $post_id, 'token' => $token ), home_url( '/' ) )
				: ULTQ_PDF::customer_pdf_url( $post_id );
		}
		return array(
			'post_id'  => $post_id,
			'number'   => ULTQ_Quote_Post::get_number( $post_id ),
			'status'   => ULTQ_Quote_Post::status_label( get_post_status( $post_id ) ),
			'date'     => get_the_date( 'Y-m-d H:i', $post_id ),
			'customer' => ULTQ_Quote_Post::get_customer( $post_id ),
			'items'    => $sent ? $sent['items'] : ULTQ_Quote_Post::get_items( $post_id ),
			'quote'    => $sent ? $sent['quote'] : ULTQ_Quote_Post::get_quote( $post_id ),
			'totals'   => $sent ? $sent['totals'] : ULTQ_Quote_Post::totals( $post_id ),
			'visible'  => $visible,
			'pdf_url'  => $pdf_url,
			'back_url' => $back_url,
			'respond'  => $visible ? self::render_form( $post_id, $token ) : '',
			'revision' => $sent ? $sent['rev'] : 0,
			'requote'  => self::requote_url( $post_id, $token ),
			'xlsx_url' => $visible && ULTQ_Excel::customer_enabled() ? ULTQ_Excel::customer_url( $post_id, $token ) : '',
			'sales'    => $visible ? ULTQ_Sales::info( ULTQ_Sales::get_owner( $post_id ) ) : array(),
		);
	}

	// ─── 再次詢價 ──────────────────────────────────────────────────────────

	public static function requote_url( $post_id, $token = '' ) {
		$args = array( 'ultq_requote' => (int) $post_id );
		if ( $token ) {
			$args['token'] = $token;
		}
		return wp_nonce_url( add_query_arg( $args, ULTQ_List::page_url() ), 'ultq_requote_' . (int) $post_id );
	}

	/**
	 * 把舊詢價單的商品放回詢價清單（數量：已報價用報價數量，否則用當初詢問的數量）。
	 * 已刪除、已下架、現在可以直接購買的商品會略過並提示；自訂品項（後台手動新增）不放回。
	 */
	public static function handle_requote() {
		$post_id = absint( $_GET['ultq_requote'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $post_id ) {
			return;
		}
		$list_url = ULTQ_List::page_url();
		// 訪客可能還沒有 WC session，先建立，提示訊息才帶得到下一頁。
		// 驗 nonce 前先記下沒有 session 時的 nonce 結果：建立 session 會改變訪客 nonce 的 uid
		$nonce = sanitize_key( $_GET['_wpnonce'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$valid = wp_verify_nonce( $nonce, 'ultq_requote_' . $post_id );
		if ( WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
		if ( ! $valid || ! self::can_access( $post_id, self::requested_token() ) ) {
			wc_add_notice( '連結已失效，請回到詢價單重新操作。', 'error' );
			wp_safe_redirect( $list_url );
			exit;
		}

		$visible = ULTQ_Quote_Post::is_customer_visible( $post_id );
		$added   = 0;
		$skipped = array();
		foreach ( ( $visible ? ULTQ_Quote_Post::sent( $post_id )['items'] : ULTQ_Quote_Post::get_items( $post_id ) ) as $item ) {
			if ( ! $item['product_id'] ) {
				continue;
			}
			$qty       = max( 1, (int) ceil( $visible && $item['qty'] ? $item['qty'] : $item['request_qty'] ) );
			$variation = $item['variation'];
			if ( ! $variation && $item['variation_id'] ) {
				$child     = wc_get_product( $item['variation_id'] );
				$variation = $child ? $child->get_variation_attributes() : array();
			}
			$result = ULTQ_List::add( $item['product_id'], $item['variation_id'], $variation, $qty );
			if ( is_wp_error( $result ) ) {
				$skipped[] = $item['name'];
			} else {
				$added++;
			}
		}

		if ( $added ) {
			wc_add_notice( sprintf( '已把 %d 項商品放回詢價清單，可以調整數量後再送出。', $added ) );
		}
		if ( $skipped ) {
			wc_add_notice( '以下商品目前無法詢價（已下架、規格已變更或可以直接購買），沒有放回清單：' . implode( '、', $skipped ), $added ? 'notice' : 'error' );
		}
		wp_safe_redirect( $list_url );
		exit;
	}

	/** 回覆表單／回覆結果 */
	public static function render_form( $post_id, $token = '' ) {
		$notice = sanitize_key( $_GET['ultq_responded'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$error  = sanitize_key( $_GET['ultq_respond_error'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		return ULTQ_Helper::get_template_html( 'quote-respond.php', array(
			'post_id'     => $post_id,
			'token'       => $token,
			'can_respond' => ULTQ_Quote_Post::can_respond( $post_id ),
			'enabled'     => (bool) ULTQ_Settings::get( 'allow_respond' ),
			'expired'     => ULTQ_Quote_Post::is_expired( $post_id ),
			'status'      => get_post_status( $post_id ),
			'response'    => ULTQ_Quote_Post::get_response( $post_id ),
			'notice'      => in_array( $notice, array( 'accept', 'reject' ), true ) ? $notice : '',
			'error'       => $error,
			'prefill'     => ULTQ_Quote_Post::get_customer( $post_id )['name'],
		) );
	}

	public static function handle_submit() {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['ultq_respond'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$post_id  = absint( $_POST['ultq_quote_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$token    = sanitize_text_field( wp_unslash( $_POST['ultq_token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$action   = sanitize_key( $_POST['ultq_respond'] ); // phpcs:ignore WordPress.Security.NonceVerification
		// 表單送回同一頁，wp_get_referer() 遇到「來源＝目前網址」會回 false，所以直接用目前網址
		// （wp_validate_redirect 只放行本站網域，wp_safe_redirect 會再清理一次網址）
		$current  = set_url_scheme( 'http://' . wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) . wp_unslash( $_SERVER['REQUEST_URI'] ?? '/' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$redirect = wp_validate_redirect( esc_url_raw( $current ), home_url( '/' ) );
		$redirect = remove_query_arg( array( 'ultq_responded', 'ultq_respond_error' ), $redirect );

		if ( ! isset( $_POST['_ultq_respond_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_ultq_respond_nonce'] ), 'ultq_respond_' . $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'ultq_respond_error', 'expired', $redirect ) );
			exit;
		}
		if ( ! in_array( $action, array( 'accept', 'reject' ), true ) || ! self::can_access( $post_id, $token ) ) {
			wp_die( '找不到這份報價單，或連結已失效。', '報價單', array( 'response' => 403 ) );
		}
		if ( ! ULTQ_Quote_Post::can_respond( $post_id ) ) {
			wp_safe_redirect( add_query_arg( 'ultq_respond_error', 'closed', $redirect ) );
			exit;
		}

		$name = sanitize_text_field( wp_unslash( $_POST['ultq_sign_name'] ?? '' ) );
		$note = sanitize_textarea_field( wp_unslash( $_POST['ultq_respond_note'] ?? '' ) );
		if ( 'accept' === $action && ( '' === $name || empty( $_POST['ultq_agree'] ) ) ) {
			wp_safe_redirect( add_query_arg( 'ultq_respond_error', 'required', $redirect ) );
			exit;
		}

		self::record( $post_id, $action, $name, $note );
		wp_safe_redirect( add_query_arg( 'ultq_responded', $action, $redirect ) );
		exit;
	}

	/**
	 * 寫入回覆：meta、狀態、紀錄，再觸發 ultq_quote_responded（通知管理員）
	 */
	public static function record( $post_id, $action, $name, $note ) {
		update_post_meta( $post_id, '_ultq_response', array(
			'action' => $action,
			'name'   => $name,
			'note'   => $note,
			'time'   => time(),
			'ip'     => WC_Geolocation::get_ip_address(),
		) );
		ULTQ_Quote_Post::set_status( $post_id, 'accept' === $action ? 'ultq-accepted' : 'ultq-rejected' );
		ULTQ_Quote_Post::add_log(
			$post_id,
			'accept' === $action
				? sprintf( '顧客線上接受報價（簽署人：%s）', $name )
				: '顧客婉拒報價' . ( $note ? '：' . $note : '' )
		);
		do_action( 'ultq_quote_responded', $post_id, $action );
	}
}
