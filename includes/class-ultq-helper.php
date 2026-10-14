<?php
/**
 * 共用小工具：目錄路徑、主題主色、金額格式、統編驗證、樣板載入、LINE 推播
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Helper {

	public static function tmp_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'ultq-tmp';
	}

	public static function font_dir() {
		$uploads = wp_upload_dir( null, false );
		return trailingslashit( $uploads['basedir'] ) . 'ultq-fonts';
	}

	/**
	 * 主題主色（Blocksy 調色盤 1 號色），給 PDF 與 LINE Flex 這些吃不到前台 CSS 變數的地方用。
	 * 讀法比照 wc-auction-bidding 的 WCAB_Settings::theme_palette_swatches()：走 Blocksy 官方 API，
	 * 非 Blocksy 或讀不到時退回 Blocksy 預設藍。
	 */
	public static function accent_hex() {
		static $hex = null;
		if ( null !== $hex ) {
			return $hex;
		}
		$hex = '#2872fa';
		if ( 'blocksy' === get_template() && function_exists( 'blocksy_manager' ) ) {
			$manager = blocksy_manager();
			if ( isset( $manager->colors ) && method_exists( $manager->colors, 'get_color_palette' ) ) {
				$palette = $manager->colors->get_color_palette();
				if ( is_array( $palette ) ) {
					foreach ( $palette as $entry ) {
						if ( ! empty( $entry['variable'] ) && 'theme-palette-color-1' === $entry['variable'] && ! empty( $entry['color'] ) ) {
							$color = sanitize_hex_color( $entry['color'] );
							if ( $color ) {
								$hex = $color;
							}
						}
					}
				}
			}
		}
		return $hex;
	}

	/** 純文字金額（Email 純文字版、LINE、PDF 用），例如 NT$1,200 */
	public static function money( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	// ─── 報價單用：大寫金額、日期 ──────────────────────────────────────────

	/** 常用幣別的中文名稱，大寫金額用；不在表上的幣別直接用代碼 */
	public static function currency_names( $code = '' ) {
		$code  = $code ?: get_woocommerce_currency();
		$names = array(
			'TWD' => '新台幣',
			'USD' => '美金',
			'CNY' => '人民幣',
			'HKD' => '港幣',
			'JPY' => '日圓',
			'EUR' => '歐元',
			'SGD' => '新加坡幣',
			'MYR' => '馬幣',
		);
		return $names[ $code ] ?? $code;
	}

	/**
	 * 中文大寫金額（財務寫法），例如 12300 → 新台幣壹萬貳仟參佰元整、100500 → 壹拾萬零伍佰元整
	 */
	public static function amount_in_chinese( $amount, $currency = '' ) {
		$amount   = round( abs( (float) $amount ), 2 );
		$integer  = (int) floor( $amount );
		$cents    = (int) round( ( $amount - $integer ) * 100 );
		$digits   = array( '零', '壹', '貳', '參', '肆', '伍', '陸', '柒', '捌', '玖' );
		$units    = array( '', '拾', '佰', '仟' );
		$sections = array( '', '萬', '億', '兆' );

		$section_text = function ( $part ) use ( $digits, $units ) {
			$text = '';
			$zero = false;
			for ( $i = 3; $i >= 0; $i-- ) {
				$digit = intdiv( $part, 10 ** $i ) % 10;
				if ( 0 === $digit ) {
					$zero = '' !== $text;
					continue;
				}
				if ( $zero ) {
					$text .= '零';
					$zero  = false;
				}
				$text .= $digits[ $digit ] . $units[ $i ];
			}
			return $text;
		};

		$parts = array();
		for ( $n = $integer; $n > 0; $n = intdiv( $n, 10000 ) ) {
			$parts[] = $n % 10000;
		}
		$text = '';
		$zero = false;
		for ( $i = count( $parts ) - 1; $i >= 0; $i-- ) {
			if ( 0 === $parts[ $i ] ) {
				$zero = '' !== $text;
				continue;
			}
			if ( '' !== $text && ( $zero || $parts[ $i ] < 1000 ) ) {
				$text .= '零';
			}
			$text .= $section_text( $parts[ $i ] ) . ( $sections[ $i ] ?? '' );
			$zero  = false;
		}
		$text = ( '' === $text ? '零' : $text ) . '元';

		$jiao = intdiv( $cents, 10 );
		$fen  = $cents % 10;
		if ( ! $cents ) {
			$text .= '整';
		} else {
			if ( $jiao ) {
				$text .= $digits[ $jiao ] . '角';
			} elseif ( $integer ) {
				$text .= '零';
			}
			if ( $fen ) {
				$text .= $digits[ $fen ] . '分';
			}
		}
		return self::currency_names( $currency ) . $text;
	}

	/** Y-m-d → 報價單日期；勾選民國年時輸出「115年10月12日」 */
	public static function format_date( $ymd, $roc = false ) {
		if ( ! $roc || ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', (string) $ymd, $m ) ) {
			return (string) $ymd;
		}
		return sprintf( '%d年%d月%d日', (int) $m[1] - 1911, (int) $m[2], (int) $m[3] );
	}

	/**
	 * 台灣統一編號檢查碼驗證（財政部 2023 年起改為「加總可被 5 整除」，向下相容舊的 10）
	 */
	public static function is_valid_tax_id( $tax_id ) {
		$tax_id = (string) $tax_id;
		if ( ! preg_match( '/^\d{8}$/', $tax_id ) ) {
			return false;
		}
		$weights = array( 1, 2, 1, 2, 1, 2, 4, 1 );
		$sum     = 0;
		for ( $i = 0; $i < 8; $i++ ) {
			$product = (int) $tax_id[ $i ] * $weights[ $i ];
			$sum    += intdiv( $product, 10 ) + ( $product % 10 );
		}
		if ( 0 === $sum % 5 ) {
			return true;
		}
		// 第 7 碼為 7 時，乘積 28 → 2+8=10 → 可取 1 或 0
		return '7' === $tax_id[6] && 0 === ( $sum + 1 ) % 5;
	}

	/**
	 * 載入樣板（主題可覆寫：yourtheme/ultimate-quote/{name}）
	 */
	public static function get_template_html( $name, array $args = array() ) {
		return wc_get_template_html( $name, $args, 'ultimate-quote/', ULTQ_PLUGIN_DIR . 'templates/' );
	}

	/** Email 樣板內引用共用片段（品項表、顧客資料） */
	public static function email_partial( $name, array $args = array() ) {
		echo self::get_template_html( $name, $args ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * 數量顯示：後台報價數量可以填小數（2.5 公斤），不能用 ULTQ_Helper::qty()（會轉成整數，金額卻是用小數算的）
	 */
	public static function qty( $qty ) {
		$qty = (float) $qty;
		return floor( $qty ) === $qty ? (string) (int) $qty : rtrim( rtrim( number_format( $qty, 4, '.', '' ), '0' ), '.' );
	}

	/**
	 * 純文字 Email 用的品項清單
	 *
	 * @param bool $sent 用最後寄出的版本（同 templates/emails/quote-items.php 的 $sent）
	 */
	public static function plain_items( $quote_id, $show_prices, $sent = false ) {
		$data  = $sent ? ULTQ_Quote_Post::sent( $quote_id ) : null;
		$items = $data ? $data['items'] : ULTQ_Quote_Post::get_items( $quote_id );
		$lines = array();
		foreach ( $items as $item ) {
			$line = '- ' . $item['name'];
			foreach ( $item['attributes'] as $label => $value ) {
				$line .= '／' . $label . '：' . $value;
			}
			if ( $show_prices ) {
				$line .= sprintf( ' × %s ＠ %s%s = %s', ULTQ_Helper::qty( $item['qty'] ), self::money( (float) $item['unit_price'] ), $item['discount'] > 0 ? '（' . ULTQ_Quote_Post::discount_label( $item['discount'] ) . '）' : '', self::money( (float) ULTQ_Quote_Post::line_total( $item ) ) );
				if ( $item['line_note'] ) {
					$line .= '（' . $item['line_note'] . '）';
				}
			} else {
				$line .= ' × ' . ULTQ_Helper::qty( $item['request_qty'] );
				if ( $item['customer_note'] ) {
					$line .= '（' . $item['customer_note'] . '）';
				}
			}
			$lines[] = $line;
		}
		if ( $show_prices ) {
			$quote  = $data ? $data['quote'] : ULTQ_Quote_Post::get_quote( $quote_id );
			$totals = $data ? $data['totals'] : ULTQ_Quote_Post::totals( $quote_id, $items, $quote );
			$lines[] = '';
			$lines[] = '小計：' . self::money( $totals['subtotal'] );
			foreach ( $totals['discounts'] as $row ) {
				$lines[] = $row['label'] . '：−' . self::money( $row['amount'] );
			}
			if ( $totals['shipping'] ) {
				$lines[] = '運費：' . self::money( $totals['shipping'] );
			}
			if ( 'none' !== $quote['tax_mode'] ) {
				$lines[] = ( 'incl' === $quote['tax_mode'] ? '內含營業稅：' : '營業稅：' ) . self::money( $totals['tax'] );
			}
			$lines[] = '總計：' . self::money( $totals['total'] );
		}
		return implode( "\n", $lines ) . "\n";
	}

	// ─── LINE 推播（軟相依終極登入，不修改對方任何檔案） ─────────────────────

	private static function line_token() {
		if ( ! class_exists( 'WCLON_Settings' ) ) {
			return '';
		}
		return (string) WCLON_Settings::get( 'channel_access_token' );
	}

	/**
	 * 推播給已綁定 LINE 的顧客：設定頁開關、對方外掛存在、顧客已綁定且未關閉通知時才送
	 */
	public static function push_line_to_user( $user_id, $title, array $rows, $btn_label, $btn_url ) {
		if ( ! $user_id || ! ULTQ_Settings::get( 'line_customer' ) ) {
			return false;
		}
		if ( ! class_exists( 'WCLON_Line_Login' ) ) {
			return false;
		}
		$line_user_id = get_user_meta( $user_id, WCLON_Line_Login::USER_META_KEY, true );
		if ( ! $line_user_id ) {
			return false;
		}
		if ( method_exists( 'WCLON_Line_Login', 'get_notify_enabled' ) && ! WCLON_Line_Login::get_notify_enabled( $user_id ) ) {
			return false;
		}
		return true === self::push_line( $line_user_id, self::make_flex( $title, $rows, $btn_label, $btn_url ) );
	}

	/**
	 * 推播給後台人員本人（負責業務）：不看「LINE 通知顧客」開關與顧客的通知偏好，只要求已綁定 LINE
	 */
	public static function push_line_to_staff( $user_id, $title, array $rows, $btn_label, $btn_url ) {
		if ( ! $user_id || ! class_exists( 'WCLON_Line_Login' ) ) {
			return false;
		}
		$line_user_id = get_user_meta( $user_id, WCLON_Line_Login::USER_META_KEY, true );
		if ( ! $line_user_id ) {
			return false;
		}
		$result = self::push_line( $line_user_id, self::make_flex( $title, $rows, $btn_label, $btn_url ) );
		if ( true !== $result ) {
			error_log( 'ULTQ LINE 業務推播失敗（user ' . (int) $user_id . '）：' . $result ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return true === $result;
	}

	/**
	 * 推播到終極登入「管理員通知」設定的群組／聊天室（WCAN_Settings group_ids）
	 */
	public static function push_line_to_admins( $title, array $rows, $btn_label, $btn_url ) {
		if ( ! ULTQ_Settings::get( 'line_admin' ) || ! class_exists( 'WCAN_Settings' ) ) {
			return 0;
		}
		$recipients = array_filter( (array) WCAN_Settings::get( 'group_ids', array() ) );
		if ( ! $recipients ) {
			return 0;
		}
		$message = self::make_flex( $title, $rows, $btn_label, $btn_url );
		$sent    = 0;
		foreach ( $recipients as $to ) {
			$result = self::push_line( $to, $message );
			if ( true === $result ) {
				$sent++;
			} else {
				error_log( 'ULTQ LINE 管理員推播失敗（' . $to . '）：' . $result ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		return $sent;
	}

	/**
	 * Flex bubble：主色抬頭（站名＋標題）、label/value 列、一顆按鈕
	 *
	 * @param array $rows array( array( label, value ), ... )
	 */
	public static function make_flex( $title, array $rows, $btn_label, $btn_url ) {
		$site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$accent = self::accent_hex();

		$body = array();
		foreach ( $rows as $row ) {
			$value = trim( (string) $row[1] );
			if ( '' === $value ) {
				continue;
			}
			$body[] = array(
				'type'     => 'box',
				'layout'   => 'baseline',
				'spacing'  => 'sm',
				'contents' => array(
					array( 'type' => 'text', 'text' => mb_substr( (string) $row[0], 0, 40 ), 'color' => '#aaaaaa', 'size' => 'sm', 'flex' => 2, 'wrap' => true ),
					array( 'type' => 'text', 'text' => mb_substr( $value, 0, 300 ), 'color' => '#111111', 'size' => 'sm', 'flex' => 4, 'wrap' => true ),
				),
			);
		}
		if ( ! $body ) {
			$body[] = array( 'type' => 'text', 'text' => $title, 'size' => 'sm', 'wrap' => true );
		}

		return array(
			'type'     => 'flex',
			'altText'  => mb_substr( "【{$site}】{$title}", 0, 400 ),
			'contents' => array(
				'type'   => 'bubble',
				'header' => array(
					'type'            => 'box',
					'layout'          => 'vertical',
					'backgroundColor' => $accent,
					'paddingAll'      => '16px',
					'contents'        => array(
						array( 'type' => 'text', 'text' => $site, 'color' => '#ffffff', 'size' => 'sm', 'weight' => 'bold' ),
						array( 'type' => 'text', 'text' => $title, 'color' => '#ffffff', 'size' => 'xl', 'weight' => 'bold', 'margin' => 'sm', 'wrap' => true ),
					),
				),
				'body'   => array(
					'type'     => 'box',
					'layout'   => 'vertical',
					'spacing'  => 'sm',
					'contents' => $body,
				),
				'footer' => array(
					'type'     => 'box',
					'layout'   => 'vertical',
					'contents' => array(
						array(
							'type'   => 'button',
							'action' => array( 'type' => 'uri', 'label' => mb_substr( $btn_label, 0, 20 ), 'uri' => $btn_url ),
							'style'  => 'primary',
							'color'  => $accent,
							'height' => 'sm',
						),
					),
				),
			),
		);
	}

	/**
	 * @return true|string true 成功，字串為錯誤訊息
	 */
	public static function push_line( $to, array $message ) {
		$token = self::line_token();
		if ( ! $token ) {
			return '尚未設定 Channel Access Token。';
		}

		$response = wp_remote_post( 'https://api.line.me/v2/bot/message/push', array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $token,
			),
			'body'    => wp_json_encode( array(
				'to'       => $to,
				'messages' => array( $message ),
			) ),
		) );

		if ( is_wp_error( $response ) ) {
			return $response->get_error_message();
		}
		if ( 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			return true;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return $body['message'] ?? ( 'HTTP ' . wp_remote_retrieve_response_code( $response ) );
	}
}
