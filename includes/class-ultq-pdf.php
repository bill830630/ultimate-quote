<?php
/**
 * 報價單 PDF（dompdf＋內建 Noto Sans TC）
 *
 * - dompdf 用官方 release 包（含相依套件）放在 vendor/dompdf，不需要 Composer；站上其他外掛已載入
 *   Dompdf 時直接沿用，不重複 require
 * - 字型：assets/fonts/NotoSansTC-{Regular,Bold}.ttf 是打包前用 fonttools 裁成 Big5 字集的靜態字重
 *   （Google Fonts 原檔是可變字型，dompdf 不支援）。dompdf 第一次註冊時會產生字型 metrics，
 *   快取在 uploads/ultq-fonts（外掛目錄不一定可寫）；開啟 font subsetting，產出的 PDF 只嵌入用到的字
 * - PDF 都是即時產生，不在主機上留檔；寄 Email 附件時暫存在 uploads/ultq-tmp，寄完即刪
 *
 * 下載入口：
 * - 後台預覽：admin-post.php?action=ultq_pdf&post=ID（nonce＋ultq_manage_quotes）
 * - 顧客：?ultq_pdf=ID&token=xxx（Email 內附連結，免登入）或我的帳戶（登入＋擁有者）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_PDF {

	const FONT_FAMILY = 'Noto Sans TC';

	/** 後台預覽／寄出時印今天日期；顧客事後下載則印當初寄出的日期（Excel 匯出共用） */
	public static $issue_today = false;

	public static function init() {
		add_action( 'admin_post_ultq_pdf', array( __CLASS__, 'handle_admin_preview' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_customer_download' ) );
	}

	private static function load_library() {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			require_once ULTQ_PLUGIN_DIR . 'vendor/dompdf/autoload.inc.php';
		}
	}

	/**
	 * @return string PDF 二進位內容
	 */
	public static function render( $post_id, $rev = 0 ) {
		self::load_library();
		wp_raise_memory_limit( 'admin' );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$font_dir = ULTQ_Helper::font_dir();
		ULTQ_Install::ensure_private_dir( $font_dir );
		$uploads = wp_upload_dir( null, false );

		$options = new \Dompdf\Options();
		$options->set( 'fontDir', $font_dir );
		$options->set( 'fontCache', $font_dir );
		$options->set( 'tempDir', $font_dir );
		$options->set( 'chroot', array( ULTQ_PLUGIN_DIR, $uploads['basedir'] ) );
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'isFontSubsettingEnabled', true );
		$options->set( 'defaultFont', self::FONT_FAMILY );
		$options->set( 'defaultPaperSize', 'a4' );

		$dompdf = new \Dompdf\Dompdf( $options );
		self::register_fonts( $dompdf );

		$dompdf->loadHtml( self::html( $post_id, $rev ), 'UTF-8' );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		return $dompdf->output();
	}

	private static function register_fonts( \Dompdf\Dompdf $dompdf ) {
		$metrics = $dompdf->getFontMetrics();
		$family  = $metrics->getFamily( strtolower( self::FONT_FAMILY ) );
		$files   = array(
			'normal' => ULTQ_PLUGIN_DIR . 'assets/fonts/NotoSansTC-Regular.ttf',
			'bold'   => ULTQ_PLUGIN_DIR . 'assets/fonts/NotoSansTC-Bold.ttf',
		);
		foreach ( $files as $weight => $file ) {
			if ( ! empty( $family[ $weight ] ) && file_exists( $family[ $weight ] . '.ttf' ) ) {
				continue;
			}
			$metrics->registerFont(
				array( 'family' => self::FONT_FAMILY, 'weight' => $weight, 'style' => 'normal' ),
				$file
			);
		}
	}

	// ─── 欄位 ──────────────────────────────────────────────────────────────

	public static function default_labels() {
		return array(
			'doc_title'     => '報價單',
			'quote_no'      => '報價單號',
			'quote_date'    => '報價日期',
			'valid_until'   => '有效期限',
			'customer'      => '客戶資料',
			'customer_name' => '客戶名稱',
			'tax_id'        => '統一編號',
			'contact'       => '聯絡人',
			'phone'         => '電話',
			'email'         => 'Email',
			'address'       => '地址',
			'col_no'        => '項次',
			'col_desc'      => '品名／規格',
			'col_sku'       => '料號',
			'col_qty'       => '數量',
			'col_unit'      => '單位',
			'col_price'     => '單價',
			'col_discount'  => '折扣',
			'col_amount'    => '金額',
			'subtotal'      => '小計',
			'discount'      => '折讓',
			'shipping'      => '運費',
			'tax'           => '營業稅',
			'tax_incl'      => '內含營業稅',
			'tax_none'      => '稅別',
			'total'         => '總計',
			'amount_words'  => '大寫金額',
			'payment_terms' => '付款條件',
			'bank'          => '匯款資訊',
			'note'          => '報價說明',
			'sign'          => '客戶確認簽章',
			'sales_name'    => '業務窗口',
			'sales_phone'   => '業務電話',
			'sales_email'   => '業務 Email',
		);
	}

	/**
	 * 報價單上可以開關、排序的欄位，依區塊分組。key 同 default_labels()；
	 * 自訂欄位依它設定的位置併進對應區塊（key 是 cf_xxxxxx）
	 */
	public static function layout_groups() {
		$item = function ( $label, $default = 1, $locked = false, $custom = false ) {
			return array( 'label' => $label, 'default' => $default, 'locked' => $locked, 'custom' => $custom );
		};
		$groups = array(
			'header'   => array(
				'label' => '報價資訊（右上角）',
				'items' => array(
					'quote_no'    => $item( '報價單號', 1, true ),
					'quote_date'  => $item( '報價日期' ),
					'valid_until' => $item( '有效期限' ),
					'sales_name'  => $item( '業務窗口' ),
					'sales_phone' => $item( '業務電話' ),
					'sales_email' => $item( '業務 Email', 0 ),
				),
			),
			'customer' => array(
				'label' => '客戶資料',
				'items' => array(
					'customer_name' => $item( '客戶名稱' ),
					'tax_id'        => $item( '統一編號' ),
					'contact'       => $item( '聯絡人' ),
					'phone'         => $item( '電話' ),
					'email'         => $item( 'Email' ),
					'address'       => $item( '地址' ),
				),
			),
			'items'    => array(
				'label' => '品項表',
				'items' => array(
					'col_no'     => $item( '項次' ),
					'col_sku'    => $item( '料號', 0 ),
					'col_desc'   => $item( '品名／規格', 1, true ),
					'col_qty'    => $item( '數量' ),
					'col_unit'   => $item( '單位', 0 ),
					'col_price'  => $item( '單價' ),
					'col_discount' => $item( '折扣（有品項打折時才印）' ),
					'col_amount' => $item( '金額' ),
				),
			),
			'footer'   => array(
				'label' => '總計左側',
				'items' => array(
					'payment_terms' => $item( '付款條件' ),
					'bank'          => $item( '匯款資訊' ),
				),
			),
		);
		foreach ( ULTQ_Settings::custom_fields() as $field ) {
			if ( isset( $groups[ $field['position'] ] ) ) {
				$groups[ $field['position'] ]['items'][ $field['id'] ] = $item( $field['label'], 1, false, true );
			}
		}
		return $groups;
	}

	/**
	 * 依設定排好的欄位：group => array( key => 0|1 )。設定裡沒有的欄位（新增的自訂欄位）用預設值排在最後，
	 * 已刪除的自訂欄位直接略過
	 */
	public static function layout() {
		$saved = ULTQ_Settings::get( 'pdf_layout', array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::layout_groups() as $group => $def ) {
			$rows = array();
			foreach ( is_array( $saved[ $group ] ?? null ) ? $saved[ $group ] : array() as $key => $on ) {
				if ( isset( $def['items'][ $key ] ) ) {
					$rows[ $key ] = $def['items'][ $key ]['locked'] ? 1 : ( $on ? 1 : 0 );
				}
			}
			foreach ( $def['items'] as $key => $item ) {
				if ( ! isset( $rows[ $key ] ) ) {
					$rows[ $key ] = $item['locked'] ? 1 : (int) $item['default'];
				}
			}
			$out[ $group ] = $rows;
		}
		return $out;
	}

	/** 某區塊要印的欄位 key（依排序） */
	public static function enabled( $group ) {
		return array_keys( array_filter( self::layout()[ $group ] ?? array() ) );
	}

	/**
	 * @param int $rev 指定歷史版本（後台「報價版本」的 PDF 連結）；0 表示目前內容
	 */
	public static function html( $post_id, $rev = 0 ) {
		return ULTQ_Helper::get_template_html( 'pdf/quote.php', self::data( $post_id, $rev ) );
	}

	/**
	 * 報價單內容（PDF 樣板與 Excel 匯出共用，欄位開關／排序也一致）
	 *
	 * @param int $rev 指定歷史版本；0 表示目前內容
	 */
	public static function data( $post_id, $rev = 0 ) {
		$settings = ULTQ_Settings::all();
		$version  = $rev ? ULTQ_Quote_Post::get_version( $post_id, $rev ) : null;
		if ( $version ) {
			$quote  = array_merge( ULTQ_Quote_Post::get_quote( $post_id ), $version['quote'] );
			$items  = array_map( array( 'ULTQ_Quote_Post', 'normalize_item' ), $version['items'] );
			// 1.3.0 以前的快照沒有折讓欄位
			$totals = array_merge( array( 'discounts' => array(), 'discount' => 0 ), $version['totals'] );
		} else {
			$rev    = self::$issue_today ? ULTQ_Quote_Post::revision( $post_id ) + 1 : ULTQ_Quote_Post::revision( $post_id );
			$quote  = ULTQ_Quote_Post::get_quote( $post_id );
			$items  = ULTQ_Quote_Post::get_items( $post_id );
			$totals = ULTQ_Quote_Post::totals( $post_id );
		}
		$roc      = ! empty( $settings['pdf_roc_date'] );

		$layout = array();
		foreach ( self::layout_groups() as $group => $unused ) {
			$layout[ $group ] = self::enabled( $group );
		}
		// 折扣欄只在有品項打折時才印
		$has_discount = false;
		foreach ( $items as $item ) {
			$has_discount = $has_discount || $item['discount'] > 0;
		}
		if ( ! $has_discount ) {
			$layout['items'] = array_values( array_diff( $layout['items'], array( 'col_discount' ) ) );
		}

		// 自訂欄位：id => array( 名稱, 值 )，留空的不印
		$custom = array();
		foreach ( ULTQ_Settings::custom_fields() as $field ) {
			$value = trim( (string) ( $quote['fields'][ $field['id'] ] ?? $field['default'] ) );
			if ( '' !== $value ) {
				$custom[ $field['id'] ] = array( $field['label'], $value );
			}
		}

		$bank = array();
		if ( in_array( 'bank', $layout['footer'], true ) && '' !== trim( (string) $settings['bank_account'] ) ) {
			$bank_name = trim( $settings['bank_name'] . ( $settings['bank_code'] ? '（' . $settings['bank_code'] . '）' : '' ) );
			$bank      = array_filter( array(
				array( '銀行', $bank_name ),
				array( '戶名', $settings['bank_account_name'] ),
				array( '帳號', $settings['bank_account'] ),
			), function ( $row ) {
				return '' !== trim( (string) $row[1] );
			} );
		}

		$amount_words = array();
		if ( ! empty( $settings['pdf_amount_words'] ) ) {
			$amount_words[] = ULTQ_Helper::amount_in_chinese( $totals['total'] );
		}

		// 顧客的接受章只蓋在最後寄出的那一版上（顧客回覆的就是那一版）
		$response = ( ! $version || (int) $version['rev'] === ULTQ_Quote_Post::revision( $post_id ) ) ? ULTQ_Quote_Post::get_response( $post_id ) : array( 'action' => '' );
		$sales    = ULTQ_Sales::info( ULTQ_Sales::get_owner( $post_id ) );

		return array(
			'post_id'      => $post_id,
			'labels'       => self::default_labels(),
			'layout'       => $layout,
			'number'       => ULTQ_Quote_Post::get_number( $post_id ) . ULTQ_Quote_Post::revision_label( $rev ),
			'date'         => ULTQ_Helper::format_date( $version ? wp_date( 'Y-m-d', (int) $version['time'] ) : self::issue_date( $post_id ), $roc ),
			'valid_until'  => ULTQ_Helper::format_date( $quote['valid_until'], $roc ),
			'customer'     => ULTQ_Quote_Post::get_customer( $post_id ),
			'items'        => $items,
			'quote'        => $quote,
			'totals'       => $totals,
			'company'      => array(
				'name'    => $settings['company_name'],
				'tax_id'  => $settings['company_tax_id'],
				'address' => $settings['company_address'],
				'phone'   => $settings['company_phone'],
				'email'   => $settings['company_email'],
				'logo'    => self::image_path( $settings['logo_id'] ),
				'stamp'   => ! empty( $settings['pdf_show_stamp'] ) ? self::image_path( $settings['stamp_id'] ) : '',
			),
			'options'      => array(
				'sku_inline' => ! empty( $settings['pdf_sku_inline'] ) && ! in_array( 'col_sku', $layout['items'], true ),
				'sign'       => ! empty( $settings['pdf_show_sign'] ),
			),
			'custom'       => $custom,
			'bank'         => $bank,
			'amount_words' => $amount_words,
			'footer'       => (string) $settings['pdf_footer'],
			'accepted'     => 'accept' === $response['action'] ? $response : null,
			'sales'        => array_merge( array( 'name' => '', 'phone' => '', 'email' => '' ), $sales ),
			'accent'       => ULTQ_Helper::accent_hex(),
			'font'         => self::FONT_FAMILY,
		);
	}

	public static function issue_date( $post_id ) {
		$sent_at = (int) get_post_meta( $post_id, '_ultq_sent_at', true );
		return wp_date( 'Y-m-d', ( self::$issue_today || ! $sent_at ) ? null : $sent_at );
	}

	/** 圖片給本機檔案路徑（dompdf 不開遠端存取），只接受 chroot 內的 jpg/png/gif */
	private static function image_path( $attachment_id ) {
		if ( ! $attachment_id ) {
			return '';
		}
		$file = get_attached_file( (int) $attachment_id );
		if ( ! $file || ! file_exists( $file ) || ! preg_match( '/\.(jpe?g|png|gif)$/i', $file ) ) {
			return '';
		}
		return $file;
	}

	public static function filename( $post_id, $rev = 0 ) {
		return '報價單-' . ULTQ_Quote_Post::get_number( $post_id ) . ( $rev > 1 ? '-v' . $rev : '' ) . '.pdf';
	}

	/**
	 * 寫到暫存目錄（Email 附件用），呼叫端寄完要自己刪
	 *
	 * @return string|WP_Error 檔案路徑
	 */
	public static function save_temp( $post_id ) {
		$dir = ULTQ_Helper::tmp_dir();
		ULTQ_Install::ensure_private_dir( $dir );
		self::$issue_today = true;
		try {
			$pdf = self::render( $post_id );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ultq_pdf', '報價單 PDF 產生失敗：' . $e->getMessage() );
		} finally {
			self::$issue_today = false;
		}
		$path = trailingslashit( $dir ) . sanitize_file_name( 'quote-' . ULTQ_Quote_Post::get_number( $post_id ) . '-' . wp_generate_password( 6, false ) . '.pdf' );
		if ( false === file_put_contents( $path, $pdf ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'ultq_pdf', '報價單 PDF 無法寫入暫存目錄。' );
		}
		return $path;
	}

	private static function stream( $post_id, $inline = true, $rev = 0 ) {
		try {
			$pdf = self::render( $post_id, $rev );
		} catch ( \Throwable $e ) {
			wp_die( esc_html( '報價單 PDF 產生失敗：' . $e->getMessage() ) );
		}
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( "Content-Disposition: " . ( $inline ? 'inline' : 'attachment' ) . "; filename*=UTF-8''" . rawurlencode( self::filename( $post_id, $rev ) ) );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public static function handle_admin_preview() {
		$post_id = absint( $_GET['post'] ?? 0 );
		check_admin_referer( 'ultq_pdf_' . $post_id );
		if ( ! ULTQ_Sales::can_manage_quote( $post_id ) ) {
			wp_die( '權限不足。', '', array( 'response' => 403 ) );
		}
		// &rev=N：後台「報價版本」看當初寄出的那一版；沒帶就是預覽下次要寄出的內容
		$rev = absint( $_GET['rev'] ?? 0 );
		if ( $rev && ULTQ_Quote_Post::get_version( $post_id, $rev ) ) {
			self::stream( $post_id, true, $rev );
		}
		self::$issue_today = true;
		self::stream( $post_id );
	}

	/** 顧客可以下載的條件：已寄出過報價且狀態在 ULTQ_Quote_Post::customer_visible_statuses() 內＋（token 正確 或 登入且是本人） */
	public static function customer_can_view( $post_id, $token = '' ) {
		if ( ULTQ_Sales::is_restricted() && ! ULTQ_Sales::can_manage_quote( $post_id ) ) {
			return false;
		}
		if ( ULTQ_Quote_Post::POST_TYPE !== get_post_type( $post_id ) ) {
			return false;
		}
		if ( ! ULTQ_Quote_Post::is_customer_visible( $post_id ) ) {
			return false;
		}
		$saved = ULTQ_Quote_Post::get_token( $post_id );
		if ( $token && $saved && hash_equals( $saved, (string) $token ) ) {
			return true;
		}
		$user_id = get_current_user_id();
		return $user_id && ULTQ_Quote_Post::get_user_id( $post_id ) === $user_id;
	}

	/** 顧客下載用的版本號：有快照就用最後一版，1.2.0 以前的舊單（沒有快照）回傳 0＝目前內容 */
	public static function customer_rev( $post_id ) {
		return ULTQ_Quote_Post::get_versions( $post_id ) ? ULTQ_Quote_Post::revision( $post_id ) : 0;
	}

	public static function customer_pdf_url( $post_id ) {
		return add_query_arg( 'ultq_pdf', $post_id, home_url( '/' ) );
	}

	public static function handle_customer_download() {
		if ( empty( $_GET['ultq_pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$post_id = absint( $_GET['ultq_pdf'] ); // phpcs:ignore WordPress.Security.NonceVerification
		$token   = sanitize_text_field( wp_unslash( $_GET['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! self::customer_can_view( $post_id, $token ) ) {
			wp_die( '找不到這份報價單，或連結已失效。', '報價單', array( 'response' => 404 ) );
		}
		// 顧客拿到的是最後寄出的那一版，不是後台存檔但還沒寄出的內容
		self::stream( $post_id, true, self::customer_rev( $post_id ) );
	}
}
