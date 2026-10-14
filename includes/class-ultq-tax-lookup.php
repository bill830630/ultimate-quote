<?php
/**
 * 輸入統一編號自動帶出公司名稱、地址：經濟部商業司「商工登記公示資料」開放資料 API（免申請）
 *
 * - 公司登記：5F64D864-61CB-4D0D-8AD9-492047CC1EA6，以 Business_Accounting_NO 查
 * - 商業登記（行號）：426D5542-5F05-43EB-83F9-F1300F14E1F1，以 President_No 查（同一統編可能有歇業的舊資料，優先取營業中）
 * 查無資料時 API 回傳空字串（HTTP 200）。結果快取 30 天、查無資料快取 1 天。
 *
 * 前台（詢價表單、B2B 申請）與後台詢價單共用 AJAX ultq_tax_lookup，同一 IP 10 分鐘最多 30 次。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Tax_Lookup {

	const API = 'https://data.gcis.nat.gov.tw/od/data/api/';

	public static function init() {
		add_action( 'wp_ajax_ultq_tax_lookup', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_nopriv_ultq_tax_lookup', array( __CLASS__, 'ajax' ) );
	}

	public static function enabled() {
		return (bool) ULTQ_Settings::get( 'tax_lookup', 1 );
	}

	/**
	 * @return array|null array( name, address, status )；查無資料或查詢失敗回傳 null
	 */
	public static function lookup( $tax_id ) {
		$tax_id = preg_replace( '/\D/', '', (string) $tax_id );
		if ( ! ULTQ_Helper::is_valid_tax_id( $tax_id ) ) {
			return null;
		}
		$cache_key = 'ultq_tax_' . $tax_id;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached['name'] ? $cached : null;
		}

		$result = self::query_company( $tax_id );
		if ( false !== $result && ! $result ) {
			$result = self::query_business( $tax_id );
		}
		if ( false === $result ) {
			return null; // 連線失敗不快取，下次再試
		}
		$data = $result ?: array( 'name' => '', 'address' => '', 'status' => '' );
		set_transient( $cache_key, $data, $data['name'] ? 30 * DAY_IN_SECONDS : DAY_IN_SECONDS );
		return $data['name'] ? $data : null;
	}

	/** @return array|false|null 找到／連線失敗／查無資料 */
	private static function request( $dataset, $field, $tax_id, $top = 1 ) {
		$url      = self::API . $dataset . '?' . http_build_query( array(
			'$format' => 'json',
			'$filter' => $field . ' eq ' . $tax_id,
			'$skip'   => 0,
			'$top'    => $top,
		), '', '&', PHP_QUERY_RFC3986 );
		$response = wp_remote_get( $url, array( 'timeout' => 8 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}
		$body = trim( (string) wp_remote_retrieve_body( $response ) );
		if ( '' === $body ) {
			return null;
		}
		$rows = json_decode( $body, true );
		return is_array( $rows ) && $rows ? $rows : null;
	}

	private static function query_company( $tax_id ) {
		$rows = self::request( '5F64D864-61CB-4D0D-8AD9-492047CC1EA6', 'Business_Accounting_NO', $tax_id );
		if ( ! $rows ) {
			return $rows;
		}
		return array(
			'name'    => trim( (string) ( $rows[0]['Company_Name'] ?? '' ) ),
			'address' => trim( (string) ( $rows[0]['Company_Location'] ?? '' ) ),
			'status'  => trim( (string) ( $rows[0]['Company_Status_Desc'] ?? '' ) ),
		);
	}

	private static function query_business( $tax_id ) {
		$rows = self::request( '426D5542-5F05-43EB-83F9-F1300F14E1F1', 'President_No', $tax_id, 5 );
		if ( ! $rows ) {
			return $rows;
		}
		$pick = $rows[0];
		foreach ( $rows as $row ) {
			if ( '01' === ( $row['Business_Current_Status'] ?? '' ) ) {
				$pick = $row;
				break;
			}
		}
		return array(
			'name'    => trim( (string) ( $pick['Business_Name'] ?? '' ) ),
			'address' => trim( (string) ( $pick['Business_Address'] ?? '' ) ),
			'status'  => trim( (string) ( $pick['Business_Current_Status_Desc'] ?? '' ) ),
		);
	}

	public static function ajax() {
		$nonce = sanitize_key( wp_unslash( $_POST['nonce'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! wp_verify_nonce( $nonce, 'ultq_front' ) && ! wp_verify_nonce( $nonce, 'ultq_admin' ) ) {
			wp_send_json_error( array( 'message' => '頁面已過期，請重新整理後再試一次。' ), 403 );
		}
		if ( ! self::enabled() ) {
			wp_send_json_error( array( 'message' => '' ) );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$key  = 'ultq_tax_rl_' . md5( (string) WC_Geolocation::get_ip_address() );
			$hits = (int) get_transient( $key );
			if ( $hits >= 30 ) {
				wp_send_json_error( array( 'message' => '查詢次數過多，請稍後再試。' ) );
			}
			set_transient( $key, $hits + 1, 10 * MINUTE_IN_SECONDS );
		}
		$tax_id = preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['tax_id'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! ULTQ_Helper::is_valid_tax_id( $tax_id ) ) {
			wp_send_json_error( array( 'message' => '統一編號格式不正確。' ) );
		}
		$data = self::lookup( $tax_id );
		if ( ! $data ) {
			wp_send_json_error( array( 'message' => '查不到這個統一編號的登記資料，請手動填寫公司名稱。' ) );
		}
		wp_send_json_success( $data );
	}
}
