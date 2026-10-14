<?php
/**
 * 通知：WC_Email（WooCommerce → 設定 → 電子郵件 可個別開關／改文案）＋ LINE（軟相依終極登入）
 *
 * | 事件             | Email                         | LINE        |
 * |------------------|-------------------------------|-------------|
 * | 送出詢價         | 管理員：新詢價／顧客：已收到   | 管理員群組  |
 * | 寄出報價         | 顧客：報價單（附 PDF）         | 顧客        |
 * | 顧客接受／婉拒   | 管理員：顧客回覆報價           | 管理員群組  |
 * | 送出 B2B 申請    | 管理員：新 B2B 申請            | 管理員群組  |
 * | B2B 核准／未通過 | 顧客：審核結果                 | 顧客        |
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Notifications {

	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_classes' ) );
		add_action( 'ultq_quote_created', array( __CLASS__, 'on_quote_created' ) );
		add_action( 'ultq_quote_responded', array( __CLASS__, 'on_quote_responded' ), 10, 2 );
		add_action( 'ultq_b2b_applied', array( __CLASS__, 'on_b2b_applied' ) );
		add_action( 'ultq_b2b_status_changed', array( __CLASS__, 'on_b2b_status_changed' ), 10, 2 );
	}

	public static function register_email_classes( $emails ) {
		require_once ULTQ_PLUGIN_DIR . 'includes/emails/class-ultq-email-base.php';
		$classes = array(
			'ULTQ_Email_New_Quote'       => 'class-ultq-email-new-quote.php',
			'ULTQ_Email_Quote_Received'  => 'class-ultq-email-quote-received.php',
			'ULTQ_Email_Quote_Sent'      => 'class-ultq-email-quote-sent.php',
			'ULTQ_Email_Quote_Response'  => 'class-ultq-email-quote-response.php',
			'ULTQ_Email_B2B_Application' => 'class-ultq-email-b2b-application.php',
			'ULTQ_Email_B2B_Result'      => 'class-ultq-email-b2b-result.php',
			'ULTQ_Email_Quote_Reminder'  => 'class-ultq-email-quote-reminder.php',
			'ULTQ_Email_Followup'        => 'class-ultq-email-followup.php',
		);
		foreach ( $classes as $class => $file ) {
			require_once ULTQ_PLUGIN_DIR . 'includes/emails/' . $file;
			$emails[ $class ] = new $class();
		}
		return $emails;
	}

	private static function email( $class ) {
		$emails = WC()->mailer()->get_emails();
		return $emails[ $class ] ?? null;
	}

	// ─── 詢價 ──────────────────────────────────────────────────────────────

	public static function on_quote_created( $post_id ) {
		$admin = self::email( 'ULTQ_Email_New_Quote' );
		if ( $admin ) {
			$admin->trigger( $post_id );
		}
		$customer_email = self::email( 'ULTQ_Email_Quote_Received' );
		if ( $customer_email ) {
			$customer_email->trigger( $post_id );
		}

		$customer = ULTQ_Quote_Post::get_customer( $post_id );
		$items    = ULTQ_Quote_Post::get_items( $post_id );
		$names    = array();
		foreach ( array_slice( $items, 0, 5 ) as $item ) {
			$names[] = $item['name'] . ' ×' . $item['request_qty'];
		}
		if ( count( $items ) > 5 ) {
			$names[] = '…共 ' . count( $items ) . ' 項';
		}
		$files = count( ULTQ_Files::get( $post_id ) );
		$owner = ULTQ_Sales::get_owner( $post_id );
		$title = '新詢價 ' . ULTQ_Quote_Post::get_number( $post_id );
		$rows  = array(
			array( '聯絡人', $customer['name'] ),
			array( '公司', $customer['company'] ),
			array( '電話', $customer['phone'] ),
			array( 'Email', $customer['email'] ),
			array( '品項', implode( "\n", $names ) ),
			array( '附件', $files ? $files . ' 個檔案' : '' ),
			array( '說明', mb_substr( $customer['message'], 0, 200 ) ),
			array( '業務', $owner ? ( ULTQ_Sales::info( $owner )['name'] ?? '' ) : '' ),
		);
		$sent = ULTQ_Helper::push_line_to_admins( $title, $rows, '前往後台報價', ULTQ_Quote_Post::admin_edit_url( $post_id ) );
		if ( $sent ) {
			ULTQ_Quote_Post::add_log( $post_id, sprintf( '已推播 LINE 通知 %d 個管理員群組', $sent ) );
		}
		if ( ULTQ_Sales::push_line_to_owner( $post_id, $title, $rows, '前往後台報價', ULTQ_Quote_Post::admin_edit_url( $post_id ) ) ) {
			ULTQ_Quote_Post::add_log( $post_id, '已推播 LINE 通知負責業務' );
		}
	}

	/**
	 * 寄出報價：產生 PDF → 附件寄給顧客 → LINE 通知 → 狀態改為已報價
	 *
	 * @return true|WP_Error
	 */
	public static function send_quote( $post_id ) {
		$customer = ULTQ_Quote_Post::get_customer( $post_id );
		if ( ! is_email( $customer['email'] ) ) {
			return new WP_Error( 'ultq_email', '顧客 Email 不正確，無法寄出報價。' );
		}
		if ( ! ULTQ_Quote_Post::is_fully_priced( $post_id ) ) {
			return new WP_Error( 'ultq_price', '還有品項沒有填單價，請填完再寄出。（已先儲存目前內容）' );
		}
		$email = self::email( 'ULTQ_Email_Quote_Sent' );
		if ( ! $email || ! $email->is_enabled() ) {
			return new WP_Error( 'ultq_disabled', '「終極詢價：報價單」Email 已在 WooCommerce 電子郵件設定中停用。' );
		}

		ULTQ_Quote_Post::get_token( $post_id, true );
		$path = ULTQ_PDF::save_temp( $post_id );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$sent = $email->trigger( $post_id, $path );
		wp_delete_file( $path );

		if ( ! $sent ) {
			ULTQ_Quote_Post::add_log( $post_id, '報價單 Email 寄送失敗' );
			return new WP_Error( 'ultq_mail', '報價單 Email 寄送失敗，請確認網站寄信設定。' );
		}

		// 快照要在更新 _ultq_sent_at 之前存：revision() 對沒有快照但寄出過的舊詢價單會算成第 1 版
		ULTQ_Quote_Post::add_version( $post_id, $customer['email'] );
		update_post_meta( $post_id, '_ultq_sent_at', time() );
		// 重新報價：清掉顧客先前的接受／婉拒，讓顧客可以針對新報價再回覆一次
		if ( ULTQ_Quote_Post::get_response( $post_id )['action'] ) {
			delete_post_meta( $post_id, '_ultq_response' );
			ULTQ_Quote_Post::add_log( $post_id, '重新報價，已清除顧客先前的線上回覆' );
		}
		ULTQ_Quote_Post::set_status( $post_id, 'ultq-quoted' );

		$totals = ULTQ_Quote_Post::totals( $post_id );
		ULTQ_Quote_Post::add_log( $post_id, sprintf( '寄出第 %d 版報價單給 %s（總計 %s）', ULTQ_Quote_Post::revision( $post_id ), $customer['email'], ULTQ_Helper::money( $totals['total'] ) ) );

		$user_id = ULTQ_Quote_Post::get_user_id( $post_id );
		if ( $user_id ) {
			$quote  = ULTQ_Quote_Post::get_quote( $post_id );
			$pushed = ULTQ_Helper::push_line_to_user(
				$user_id,
				'報價單已送達',
				array(
					array( '單號', ULTQ_Quote_Post::get_number( $post_id ) ),
					array( '總計', ULTQ_Helper::money( $totals['total'] ) ),
					array( '有效期限', $quote['valid_until'] ),
				),
				ULTQ_Settings::get( 'allow_respond' ) ? '查看並確認報價' : '查看報價單',
				ULTQ_Quote_Response::view_url( $post_id )
			);
			if ( $pushed ) {
				ULTQ_Quote_Post::add_log( $post_id, '已推播 LINE 通知顧客' );
			}
		}

		do_action( 'ultq_quote_sent', $post_id );
		return true;
	}

	/**
	 * 顧客線上接受／婉拒報價 → 通知管理員
	 */
	public static function on_quote_responded( $post_id, $action ) {
		$email = self::email( 'ULTQ_Email_Quote_Response' );
		if ( $email ) {
			$email->trigger( $post_id );
		}
		$customer = ULTQ_Quote_Post::get_customer( $post_id );
		$response = ULTQ_Quote_Post::get_response( $post_id );
		$accepted = 'accept' === $action;
		$title    = ( $accepted ? '顧客已接受報價 ' : '顧客婉拒報價 ' ) . ULTQ_Quote_Post::get_number( $post_id );
		$rows     = array(
			array( '公司', $customer['company'] ),
			array( '聯絡人', $customer['name'] ),
			array( '電話', $customer['phone'] ),
			array( '總計', ULTQ_Helper::money( ULTQ_Quote_Post::sent( $post_id )['totals']['total'] ) ),
			array( $accepted ? '簽署人' : '', $accepted ? $response['name'] : '' ),
			array( $accepted ? '備註' : '原因', mb_substr( $response['note'], 0, 200 ) ),
		);
		$sent     = ULTQ_Helper::push_line_to_admins( $title, $rows, '前往後台', ULTQ_Quote_Post::admin_edit_url( $post_id ) );
		if ( $sent ) {
			ULTQ_Quote_Post::add_log( $post_id, sprintf( '已推播 LINE 通知 %d 個管理員群組', $sent ) );
		}
		if ( ULTQ_Sales::push_line_to_owner( $post_id, $title, $rows, '前往後台', ULTQ_Quote_Post::admin_edit_url( $post_id ) ) ) {
			ULTQ_Quote_Post::add_log( $post_id, '已推播 LINE 通知負責業務' );
		}
	}

	// ─── B2B ───────────────────────────────────────────────────────────────

	public static function on_b2b_applied( $user_id ) {
		$email = self::email( 'ULTQ_Email_B2B_Application' );
		if ( $email ) {
			$email->trigger( $user_id );
		}
		$data = ULTQ_B2B_Member::get_data( $user_id );
		ULTQ_Helper::push_line_to_admins(
			'新的 B2B 會員申請',
			array(
				array( '公司', $data['company'] ),
				array( '統編', $data['tax_id'] ),
				array( '聯絡人', $data['contact'] ),
				array( '電話', $data['phone'] ),
				array( '審核', ULTQ_Settings::get( 'b2b_auto_approve' ) ? '已自動核准' : '待審核' ),
			),
			'前往審核',
			admin_url( 'user-edit.php?user_id=' . (int) $user_id . '#ultq-b2b' )
		);
	}

	public static function on_b2b_status_changed( $user_id, $status ) {
		$email = self::email( 'ULTQ_Email_B2B_Result' );
		if ( $email ) {
			$email->trigger( $user_id, $status );
		}
		$approved = 'approved' === $status;
		ULTQ_Helper::push_line_to_user(
			$user_id,
			$approved ? '您已成為 B2B 會員' : 'B2B 會員申請結果',
			array(
				array( '結果', $approved ? '已核准' : '未通過' ),
				array( '說明', $approved ? '登入後即可查看 B2B 參考價並線上詢價。' : (string) get_user_meta( $user_id, ULTQ_B2B_Member::META_REASON, true ) ),
			),
			$approved ? '開始選購' : '查看申請',
			$approved ? wc_get_page_permalink( 'shop' ) : wc_get_account_endpoint_url( ULTQ_B2B_Member::ENDPOINT )
		);
	}
}
