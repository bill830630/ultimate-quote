<?php
/**
 * 每日排程（每天早上 9 點，WP-Cron）：到期提醒、自動過期、未回覆追蹤
 *
 * 只看「已報價」且顧客還沒線上回覆的詢價單：
 * - 到期提醒（remind_days）：有效期限在 N 天內 → Email＋LINE 提醒顧客，每一版只提醒一次（_ultq_reminded = 版本號）
 * - 自動過期（auto_expire）：過了有效期限 → 狀態改成「已過期」
 * - 未回覆追蹤（followup_days）：寄出 N 天顧客還沒回覆 → 整理成一封清單通知管理員（各業務另收自己負責的），
 *   LINE 推給管理員群組／負責業務，每一版只通知一次（_ultq_followed = 版本號）
 * 重新寄出報價會變成新版本，兩種通知都會重新計算。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Cron {

	const HOOK = 'ultq_daily';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'schedule' ) );
	}

	public static function schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}
		$next = new DateTime( 'today 09:00', wp_timezone() );
		if ( $next->getTimestamp() <= time() ) {
			$next->modify( '+1 day' );
		}
		wp_schedule_event( $next->getTimestamp(), 'daily', self::HOOK );
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** @return array 處理結果（驗證用）：reminded／expired／followed 各自的詢價單 ID */
	public static function run() {
		$result = array( 'reminded' => array(), 'expired' => array(), 'followed' => array() );
		$ids    = get_posts( array(
			'post_type'      => ULTQ_Quote_Post::POST_TYPE,
			'post_status'    => 'ultq-quoted',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$today         = wp_date( 'Y-m-d' );
		$remind_days   = max( 0, (int) ULTQ_Settings::get( 'remind_days', 3 ) );
		$followup_days = max( 0, (int) ULTQ_Settings::get( 'followup_days', 3 ) );
		$remind_until  = wp_date( 'Y-m-d', time() + $remind_days * DAY_IN_SECONDS );

		foreach ( $ids as $post_id ) {
			$sent_at = (int) get_post_meta( $post_id, '_ultq_sent_at', true );
			if ( ! $sent_at || ULTQ_Quote_Post::get_response( $post_id )['action'] ) {
				continue;
			}
			$rev   = ULTQ_Quote_Post::revision( $post_id );
			// 到期日看顧客收到的那一版（後台改了沒寄出不算）
			$valid = ULTQ_Quote_Post::sent( $post_id )['quote']['valid_until'];

			if ( $valid && $valid < $today ) {
				if ( ULTQ_Settings::get( 'auto_expire', 1 ) ) {
					ULTQ_Quote_Post::set_status( $post_id, 'ultq-expired' );
					ULTQ_Quote_Post::add_log( $post_id, '報價已過有效期限（' . $valid . '），狀態改為「已過期」' );
					$result['expired'][] = $post_id;
				}
				continue;
			}

			if ( $remind_days && $valid && $valid <= $remind_until && (int) get_post_meta( $post_id, '_ultq_reminded', true ) !== $rev ) {
				if ( self::remind( $post_id ) ) {
					$result['reminded'][] = $post_id;
				}
				update_post_meta( $post_id, '_ultq_reminded', $rev );
			}

			if ( $followup_days && $sent_at <= time() - $followup_days * DAY_IN_SECONDS && (int) get_post_meta( $post_id, '_ultq_followed', true ) !== $rev ) {
				$result['followed'][] = $post_id;
				update_post_meta( $post_id, '_ultq_followed', $rev );
				ULTQ_Quote_Post::add_log( $post_id, sprintf( '寄出 %d 天顧客還沒回覆，已通知追蹤', (int) floor( ( time() - $sent_at ) / DAY_IN_SECONDS ) ) );
			}
		}

		if ( $result['followed'] ) {
			self::notify_followup( $result['followed'] );
		}
		return $result;
	}

	/** 到期提醒：Email＋LINE 給顧客 */
	private static function remind( $post_id ) {
		$sent   = false;
		$emails = WC()->mailer()->get_emails();
		if ( isset( $emails['ULTQ_Email_Quote_Reminder'] ) ) {
			$sent = (bool) $emails['ULTQ_Email_Quote_Reminder']->trigger( $post_id );
		}
		$sent_q = ULTQ_Quote_Post::sent( $post_id );
		$quote  = $sent_q['quote'];
		$pushed = ULTQ_Helper::push_line_to_user(
			ULTQ_Quote_Post::get_user_id( $post_id ),
			'報價即將到期',
			array(
				array( '單號', ULTQ_Quote_Post::get_number( $post_id ) ),
				array( '總計', ULTQ_Helper::money( $sent_q['totals']['total'] ) ),
				array( '有效期限', $quote['valid_until'] ),
			),
			ULTQ_Settings::get( 'allow_respond' ) ? '查看並確認報價' : '查看報價單',
			ULTQ_Quote_Response::view_url( $post_id )
		);
		if ( $sent || $pushed ) {
			ULTQ_Quote_Post::add_log( $post_id, '已提醒顧客報價將於 ' . $quote['valid_until'] . ' 到期（' . implode( '＋', array_filter( array( $sent ? 'Email' : '', $pushed ? 'LINE' : '' ) ) ) . '）' );
		}
		return $sent || $pushed;
	}

	/** 未回覆追蹤：管理員收全部，有開「通知負責業務」時各業務另收自己負責的 */
	private static function notify_followup( array $ids ) {
		$emails = WC()->mailer()->get_emails();
		$email  = $emails['ULTQ_Email_Followup'] ?? null;
		if ( $email ) {
			$email->trigger( $ids );
		}

		$by_owner = array();
		foreach ( $ids as $post_id ) {
			if ( ULTQ_Sales::should_notify( $post_id ) ) {
				$by_owner[ ULTQ_Sales::get_owner( $post_id ) ][] = $post_id;
			}
		}
		foreach ( $by_owner as $owner => $owner_ids ) {
			if ( $email ) {
				$address = ULTQ_Sales::info( $owner )['email'] ?? '';
				if ( is_email( $address ) ) {
					$email->trigger( $owner_ids, $address );
				}
			}
			ULTQ_Helper::push_line_to_staff( $owner, '報價待追蹤（' . count( $owner_ids ) . ' 張）', self::line_rows( $owner_ids ), '前往後台', admin_url( 'edit.php?post_type=' . ULTQ_Quote_Post::POST_TYPE . '&post_status=ultq-quoted&ultq_owner=mine' ) );
		}

		ULTQ_Helper::push_line_to_admins( '報價待追蹤（' . count( $ids ) . ' 張）', self::line_rows( $ids ), '前往後台', admin_url( 'edit.php?post_type=' . ULTQ_Quote_Post::POST_TYPE . '&post_status=ultq-quoted' ) );
	}

	private static function line_rows( array $ids ) {
		$rows = array();
		foreach ( array_slice( $ids, 0, 10 ) as $post_id ) {
			$customer = ULTQ_Quote_Post::get_customer( $post_id );
			$rows[]   = array( ULTQ_Quote_Post::get_number( $post_id ), ( $customer['company'] ?: $customer['name'] ) . '　' . ULTQ_Helper::money( ULTQ_Quote_Post::sent( $post_id )['totals']['total'] ) );
		}
		if ( count( $ids ) > 10 ) {
			$rows[] = array( '', '…共 ' . count( $ids ) . ' 張' );
		}
		return $rows;
	}

	/** 已寄出幾天（後台列表與追蹤信用） */
	public static function days_since_sent( $post_id ) {
		$sent_at = (int) get_post_meta( $post_id, '_ultq_sent_at', true );
		return $sent_at ? (int) floor( ( time() - $sent_at ) / DAY_IN_SECONDS ) : 0;
	}
}
