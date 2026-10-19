<?php
/**
 * 前台 AJAX：加入／更新／移除詢價清單、送出詢價
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Ajax {

	public static function init() {
		foreach ( array( 'add', 'update', 'remove', 'submit' ) as $action ) {
			add_action( 'wp_ajax_ultq_' . $action, array( __CLASS__, $action ) );
			add_action( 'wp_ajax_nopriv_ultq_' . $action, array( __CLASS__, $action ) );
		}
	}

	private static function verify() {
		if ( ! check_ajax_referer( 'ultq_front', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => '頁面已過期，請重新整理後再試一次。' ), 403 );
		}
	}

	public static function add() {
		self::verify();
		$variation = array();
		foreach ( $_POST as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification
			if ( 0 === strpos( (string) $key, 'attribute_' ) ) {
				$variation[ sanitize_title( $key ) ] = wc_clean( wp_unslash( $value ) );
			}
		}
		$result = ULTQ_List::add(
			absint( $_POST['product_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification
			absint( $_POST['variation_id'] ?? 0 ), // phpcs:ignore WordPress.Security.NonceVerification
			$variation,
			max( 1, absint( $_POST['quantity'] ?? 1 ) ) // phpcs:ignore WordPress.Security.NonceVerification
		);
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		wp_send_json_success( array(
			'count'    => ULTQ_List::count(),
			'message'  => '已加入詢價清單',
			'adjusted' => ULTQ_List::$adjusted,
		) );
	}

	public static function update() {
		self::verify();
		$key  = sanitize_key( wp_unslash( $_POST['key'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$note = isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification
		ULTQ_List::update( $key, absint( $_POST['qty'] ?? 0 ), $note ); // phpcs:ignore WordPress.Security.NonceVerification
		self::send_list( ULTQ_List::$adjusted );
	}

	public static function remove() {
		self::verify();
		ULTQ_List::remove( sanitize_key( wp_unslash( $_POST['key'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		self::send_list();
	}

	private static function send_list( $adjusted = '' ) {
		wp_send_json_success( array(
			'count'    => ULTQ_List::count(),
			'html'     => ULTQ_List::render_list_html(),
			'adjusted' => $adjusted,
		) );
	}

	public static function submit() {
		self::verify();

		if ( ! ULTQ_Rules::can_request_quote() ) {
			wp_send_json_error( array( 'message' => '請先登入會員再送出詢價。' ) );
		}

		// honeypot：真人看不到這個欄位
		if ( ! empty( $_POST['ultq_website'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wp_send_json_error( array( 'message' => '送出失敗。' ) );
		}

		// 同一 IP 10 分鐘內最多 5 次
		$ip_key = 'ultq_rl_' . md5( (string) WC_Geolocation::get_ip_address() );
		$hits   = (int) get_transient( $ip_key );
		if ( $hits >= 5 ) {
			wp_send_json_error( array( 'message' => '送出次數過多，請稍後再試。' ) );
		}

		// 已下架／不再需要詢價的品項清單頁不會顯示，送出時也略過
		$items = ULTQ_List::get_available_items();
		if ( ! $items ) {
			wp_send_json_error( array( 'message' => '詢價清單是空的。' ) );
		}

		$f        = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		$parsed   = ULTQ_Form::validate( 'quote', $f );
		$customer = $parsed['data'];
		$errors   = $parsed['errors'];
		$files = ULTQ_Files::enabled() ? ULTQ_Files::from_request( 'ultq_files' ) : array();
		$valid = ULTQ_Files::validate( $files );
		if ( is_wp_error( $valid ) ) {
			$errors[] = $valid->get_error_message();
		}
		if ( $errors ) {
			wp_send_json_error( array( 'message' => implode( '<br>', array_map( 'esc_html', $errors ) ) ) );
		}

		set_transient( $ip_key, $hits + 1, 10 * MINUTE_IN_SECONDS );

		// 附件要在新詢價通知發出前存好（通知會列出附件數）：create() 在寫完 meta、發通知之前觸發 ultq_quote_inserted
		$store_files = function ( $post_id ) use ( $files ) {
			ULTQ_Files::store( $post_id, $files, 'customer' );
		};
		add_action( 'ultq_quote_inserted', $store_files );
		$post_id = ULTQ_Quote_Post::create( $customer, array_values( $items ), get_current_user_id() );
		remove_action( 'ultq_quote_inserted', $store_files );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( array( 'message' => '送出失敗，請稍後再試。' ) );
		}

		ULTQ_List::clear();

		wp_send_json_success( array(
			'count' => 0,
			'html'  => ULTQ_Helper::get_template_html( 'quote-submitted.php', array(
				'number'   => ULTQ_Quote_Post::get_number( $post_id ),
				'email'    => $customer['email'],
				'shop_url' => wc_get_page_permalink( 'shop' ),
				'my_url'   => is_user_logged_in() ? wc_get_account_endpoint_url( ULTQ_My_Account::ENDPOINT ) : '',
			) ),
		) );
	}
}
