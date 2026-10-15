<?php
/**
 * 設定頁：快捷鍵 → 終極詢價 → 設定
 *
 * 所有設定存在單一 option ultq_settings（陣列）。欄位由 fields() 定義，render 與 sanitize
 * 都跑同一份定義；每次只送出目前頁籤的欄位，sanitize 時以既有值為底合併，不會洗掉其他頁籤。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Settings {

	const OPTION_KEY = 'ultq_settings';
	const PAGE_SLUG  = 'ultq-settings';

	private static $cache = null;

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		// 設定頁開放給 manage_woocommerce（商店管理員），options.php 存檔預設要 manage_options，要一起放寬
		add_filter( 'option_page_capability_ultq_settings_group', array( __CLASS__, 'capability' ) );
		add_action( 'update_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_cache' ) );
		add_action( 'add_option_' . self::OPTION_KEY, array( __CLASS__, 'flush_cache' ) );
	}

	public static function flush_cache() {
		self::$cache = null;
	}

	public static function tabs() {
		return array(
			'general' => '詢價設定',
			'b2b'     => 'B2B 會員',
			'quote'   => '公司與報價',
			'pdf'     => '報價單版面',
			'sales'   => '業務與追蹤',
			'notify'  => '通知',
		);
	}

	public static function fields() {
		return array(
			'general' => array(
				// 詢價範圍與分類合併成一列顯示，資料仍分存 quote_all／quote_categories 兩個 key；inline 的欄位由前一個欄位一起輸出
				'quote_all'         => array( 'type' => 'quote_scope', 'label' => '詢價商品範圍', 'desc' => '詢價商品會隱藏售價、只有 B2B 會員能加入詢價清單，其他人看得到商品但不能購買。', 'default' => 0 ),
				'quote_categories'  => array( 'type' => 'categories', 'label' => '僅限詢價的分類', 'inline' => true, 'desc' => '這些分類的商品變成詢價商品。個別商品也可以在商品編輯頁勾選「僅限詢價」。其他商品 B2B 會員和一般顧客一樣直接購買。', 'default' => array() ),
				'hidden_price_text' => array( 'type' => 'text', 'label' => '隱藏價格時顯示文字', 'default' => '價格請詢價' ),
				'b2b_only_text'     => array( 'type' => 'textarea', 'label' => '非 B2B 會員看到的說明', 'desc' => '訪客與一般會員在詢價商品頁、詢價清單頁看到的文字，下方會依身分顯示「登入」或「申請 B2B 會員」按鈕。', 'default' => '此商品為 B2B 會員專屬，成為 B2B 會員後即可查看批發價格並線上詢價。' ),
				'button_text'       => array( 'type' => 'text', 'label' => '詢價按鈕文字', 'default' => '加入詢價清單' ),
				'list_page_id'      => array( 'type' => 'page', 'label' => '詢價清單頁面', 'desc' => '頁面內容需包含短代碼 <code>[ultq_quote_list]</code>。', 'default' => 0 ),
				'float_position'    => array( 'type' => 'select', 'label' => '浮動詢價清單按鈕', 'options' => array( 'left' => '左下角', 'right' => '右下角', 'off' => '不顯示' ), 'desc' => '清單內有商品時才會出現。有開「終極登入」的懸浮 LINE 按鈕時，會疊在 LINE 按鈕上方（位置跟著 LINE 按鈕）。', 'default' => 'left' ),
				'show_loop_badge'   => array( 'type' => 'checkbox', 'label' => '商品列表顯示「詢價」徽章', 'desc' => '僅限詢價的商品在商品列表縮圖上顯示徽章。', 'default' => 1 ),
				'line_oa_id'        => array( 'type' => 'text', 'label' => 'LINE 官方帳號 ID', 'desc' => '填了才會在商品頁顯示「用 LINE 詢問」按鈕，例如 <code>@123abcde</code>（LINE Official Account Manager 帳號名稱下方的 ID，含 @）。', 'default' => '' ),
				'line_inquiry'      => array( 'type' => 'select', 'label' => '「用 LINE 詢問」按鈕', 'options' => array( 'quote' => '只在詢價商品顯示', 'all' => '所有商品都顯示', 'off' => '不顯示' ), 'default' => 'quote' ),
				'line_inquiry_text' => array( 'type' => 'text', 'label' => 'LINE 按鈕文字', 'default' => '用 LINE 詢問' ),
				'line_inquiry_msg'  => array( 'type' => 'textarea', 'label' => 'LINE 預填訊息', 'desc' => '顧客按下按鈕後，LINE 聊天室會預先填好這段文字（顧客可以修改後再送出）。可用：<code>{product}</code> 商品名稱（含已選規格）、<code>{qty}</code> 數量、<code>{url}</code> 商品網址。', 'default' => "您好，我想詢問這個商品：\n{product}\n數量：{qty}\n{url}" ),
				'upload_enabled'    => array( 'type' => 'checkbox', 'label' => '詢價附件', 'desc' => '詢價表單可以上傳附件（圖面、規格書、照片），只有後台看得到。', 'default' => 1 ),
				'upload_types'      => array( 'type' => 'text', 'label' => '可上傳的檔案類型', 'desc' => '副檔名用逗號分隔。為了安全，網頁與程式類檔案（php、html、js、svg 等）一律不收。', 'default' => 'jpg,jpeg,png,gif,webp,heic,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,dwg,dxf,step,stp,igs,iges,stl,zip,rar,7z' ),
				'upload_max_mb'     => array( 'type' => 'number', 'label' => '單檔大小上限（MB）', 'desc' => '不會超過主機的上傳上限。', 'default' => 10, 'min' => 1, 'max' => 512, 'step' => '1' ),
				'upload_max_files'  => array( 'type' => 'number', 'label' => '每張詢價單附件數上限', 'default' => 5, 'min' => 1, 'max' => 20, 'step' => '1' ),
				'tax_lookup'        => array( 'type' => 'checkbox', 'label' => '統編自動帶入公司名稱', 'desc' => '詢價表單、B2B 申請與後台詢價單輸入統一編號後，自動從經濟部商工登記公示資料帶出公司名稱與地址（公司、行號都查得到）。', 'default' => 1 ),
			),
			'b2b'     => array(
				'b2b_enabled'        => array( 'type' => 'checkbox', 'label' => '開放 B2B 會員申請', 'desc' => '在「我的帳戶」顯示「B2B 會員」申請頁籤。', 'default' => 1 ),
				'b2b_auto_approve'   => array( 'type' => 'checkbox', 'label' => '自動核准', 'desc' => '送出申請即成為 B2B 會員，不需人工審核。', 'default' => 0 ),
				'b2b_price_fallback' => array( 'type' => 'select', 'label' => '商品沒設 B2B 價時，B2B 會員看到', 'options' => array( 'regular' => '一般售價', 'hidden' => '隱藏價格文字' ), 'default' => 'regular' ),
				'b2b_discount'       => array( 'type' => 'number', 'label' => '全站預設 B2B 折扣（%）', 'desc' => '商品沒設 B2B 價時，以一般售價打這個折扣當 B2B 參考價；0 表示不套用。例如填 10 代表九折。', 'default' => 0, 'min' => 0, 'max' => 100, 'step' => '0.1' ),
				'b2b_intro'          => array( 'type' => 'textarea', 'label' => '申請頁說明文字', 'default' => '成為 B2B 會員後，可查看批發價格並線上詢價。送出申請後我們會盡快審核。' ),
			),
			'quote'   => array(
				'company_name'    => array( 'type' => 'text', 'label' => '公司名稱', 'default' => get_bloginfo( 'name' ) ),
				'company_tax_id'  => array( 'type' => 'text', 'label' => '統一編號', 'default' => '' ),
				'company_address' => array( 'type' => 'text', 'label' => '地址', 'default' => '' ),
				'company_phone'   => array( 'type' => 'text', 'label' => '電話', 'default' => '' ),
				'company_email'   => array( 'type' => 'text', 'label' => 'Email', 'desc' => '印在報價單上給顧客聯絡用，留空就不顯示。', 'default' => '' ),
				'logo_id'         => array( 'type' => 'media', 'label' => '報價單 Logo', 'default' => 0 ),
				'stamp_id'        => array( 'type' => 'media', 'label' => '公司印章圖', 'desc' => '建議使用去背 PNG，顯示在報價單右下角。', 'default' => 0 ),
				'number_prefix'   => array( 'type' => 'text', 'label' => '報價單號前綴', 'desc' => '單號格式：前綴-年月日-流水號，例如 Q-20261012-0001。', 'default' => 'Q' ),
				'valid_days'      => array( 'type' => 'number', 'label' => '報價有效天數', 'default' => 30, 'min' => 1, 'max' => 365, 'step' => '1' ),
				'tax_mode'        => array( 'type' => 'select', 'label' => '預設稅別', 'options' => ULTQ_Quote_Post::tax_modes(), 'default' => 'excl' ),
				'tax_rate'        => array( 'type' => 'number', 'label' => '營業稅率（%）', 'default' => 5, 'min' => 0, 'max' => 100, 'step' => '0.1' ),
				'default_terms'   => array( 'type' => 'textarea', 'label' => '預設報價條款', 'desc' => '每張新報價單預設帶入，可在報價單上個別修改。', 'default' => "1. 本報價單自報價日起依有效期限內有效。\n2. 如有任何問題，歡迎與我們聯繫。" ),
				'payment_terms_options' => array( 'type' => 'textarea', 'label' => '付款條件選項', 'desc' => '一行一個，填報價時可以直接挑選，也可以另外手打。', 'default' => "匯款\n月結 30 天\n月結 60 天\n貨到付款\n開立支票" ),
				'default_payment_terms' => array( 'type' => 'text', 'label' => '預設付款條件', 'default' => '匯款' ),
				'default_unit'    => array( 'type' => 'text', 'label' => '預設單位', 'desc' => '報價單版面開啟「單位」欄時，新品項預設帶入的單位，例如：個、組、箱、pcs。', 'default' => '個' ),
				'bank_name'       => array( 'type' => 'text', 'label' => '匯款銀行', 'desc' => '含分行，例如：台灣銀行 營業部。', 'default' => '' ),
				'bank_code'       => array( 'type' => 'text', 'label' => '銀行代碼', 'default' => '' ),
				'bank_account_name' => array( 'type' => 'text', 'label' => '戶名', 'default' => '' ),
				'bank_account'    => array( 'type' => 'text', 'label' => '帳號', 'desc' => '有填帳號時，報價單才會顯示匯款資訊。', 'default' => '' ),
				'allow_respond'   => array( 'type' => 'checkbox', 'label' => '顧客線上回覆報價', 'desc' => '顧客可以從 Email、LINE 或「我的詢價」直接按「接受報價」或「婉拒」，結果會通知管理員。報價過期後就不能再回覆。', 'default' => 1 ),
			),
			'pdf'     => array(
				'pdf_layout'        => array( 'type' => 'layout', 'label' => '欄位', 'desc' => '勾選要印在報價單上的欄位，拖曳左側 ☰ 調整順序。單位可以在報價時逐項修改；匯款資訊取自「公司與報價」頁籤；新增的自訂欄位存檔後才會出現在這裡。', 'default' => array() ),
				'pdf_sku_inline'    => array( 'type' => 'checkbox', 'label' => '品名下方附料號', 'desc' => '品項表沒有開「料號」欄時，把料號用小字印在品名下方。', 'default' => 1 ),
				'pdf_amount_words'  => array( 'type' => 'checkbox', 'label' => '大寫金額', 'desc' => '總計下方加上「新台幣壹萬貳仟元整」。', 'default' => 1 ),
				'pdf_show_sign'     => array( 'type' => 'checkbox', 'label' => '客戶回簽欄', 'desc' => '讓顧客簽章後回傳確認。', 'default' => 1 ),
				'pdf_show_stamp'    => array( 'type' => 'checkbox', 'label' => '公司印章', 'desc' => '有上傳印章圖時顯示在右下角。', 'default' => 1 ),
				'pdf_roc_date'      => array( 'type' => 'checkbox', 'label' => '民國年日期', 'desc' => '日期改用民國年，例如 115年10月12日。', 'default' => 0 ),
				'pdf_footer'        => array( 'type' => 'textarea', 'label' => '頁尾文字', 'desc' => '顯示在報價單最下方，例如：本報價單經雙方簽章後視同訂購單。', 'default' => '' ),
				'pdf_custom_fields' => array( 'type' => 'custom_fields', 'label' => '自訂欄位', 'desc' => '例如交貨期、交貨地點、保固。預設值會帶入每張新報價單，可以在報價時修改；留空的欄位不會印出。業務窗口請用「業務與追蹤」的指派功能，姓名電話會自動帶入。', 'default' => array() ),
				'customer_excel'    => array( 'type' => 'checkbox', 'label' => 'Excel 報價單', 'desc' => '顧客也可以下載 Excel 版報價單（內容和欄位設定同 PDF，金額可以直接計算）。不勾選時只有後台能下載。', 'default' => 1 ),
			),
			'sales'   => array(
				'sales_assign'  => array( 'type' => 'sales_assign', 'label' => '新詢價自動指派', 'desc' => '有設定「專屬業務」的會員（在會員的個人資料頁設定）會優先指派給他的專屬業務。後台開單預設指派給開單的人。', 'default' => '' ),
				'sales_team'    => array( 'type' => 'users', 'label' => '輪流分配名單', 'desc' => '「輪流分配」時依序指派給這些人。業務的姓名、電話會印在報價單上，可以在各自的個人資料頁設定。', 'default' => array() ),
				'notify_owner'  => array( 'type' => 'checkbox', 'label' => '通知負責業務', 'desc' => '新詢價、顧客回覆、待追蹤時，負責業務也會收到 Email；業務有用「終極登入」綁定 LINE 的話也會收到 LINE。', 'default' => 1 ),
				'remind_days'   => array( 'type' => 'number', 'label' => '到期前提醒顧客（天）', 'desc' => '報價快到有效期限、顧客還沒回覆時，提前幾天寄 Email（綁定 LINE 的顧客另推 LINE）提醒，每一版只提醒一次。填 0 不提醒。', 'default' => 3, 'min' => 0, 'max' => 60, 'step' => '1' ),
				'auto_expire'   => array( 'type' => 'checkbox', 'label' => '自動標成已過期', 'desc' => '過了有效期限、顧客還沒回覆的報價，狀態自動改成「已過期」。重新寄出報價會變回「已報價」。', 'default' => 1 ),
				'followup_days' => array( 'type' => 'number', 'label' => '未回覆追蹤（天）', 'desc' => '報價寄出幾天後顧客還沒回覆，就通知管理員與負責業務追蹤（每天早上 9 點整理成一封清單）。填 0 不通知。', 'default' => 3, 'min' => 0, 'max' => 60, 'step' => '1' ),
			),
			'notify'  => array(
				'line_admin'    => array( 'type' => 'checkbox', 'label' => 'LINE 通知管理員', 'desc' => '新詢價、新 B2B 申請推播到「終極登入 → 管理員通知」設定的群組／聊天室。', 'default' => 1 ),
				'line_customer' => array( 'type' => 'checkbox', 'label' => 'LINE 通知顧客', 'desc' => '報價寄出、B2B 審核結果推播給已綁定 LINE 的顧客。', 'default' => 1 ),
			),
		);
	}

	public static function defaults() {
		$defaults = array();
		foreach ( self::fields() as $fields ) {
			foreach ( $fields as $key => $field ) {
				$defaults[ $key ] = $field['default'];
			}
		}
		return $defaults;
	}

	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION_KEY, array() );
			self::$cache = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		}
		return self::$cache;
	}

	public static function get( $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function update( $key, $value ) {
		$saved         = get_option( self::OPTION_KEY, array() );
		$saved         = is_array( $saved ) ? $saved : array();
		$saved[ $key ] = $value;
		// 直接寫入，不經過 sanitize（sanitize 只處理設定頁送出的單一頁籤），寫完再掛回去
		$had = remove_filter( 'sanitize_option_' . self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
		update_option( self::OPTION_KEY, $saved );
		if ( $had ) {
			add_filter( 'sanitize_option_' . self::OPTION_KEY, array( __CLASS__, 'sanitize' ) );
		}
		self::flush_cache();
	}

	public static function capability() {
		return 'manage_woocommerce';
	}

	public static function register_setting() {
		register_setting( 'ultq_settings_group', self::OPTION_KEY, array(
			'type'              => 'array',
			'sanitize_callback' => array( __CLASS__, 'sanitize' ),
		) );
	}

	public static function sanitize( $input ) {
		$saved = get_option( self::OPTION_KEY, array() );
		$saved = is_array( $saved ) ? $saved : array();
		if ( ! is_array( $input ) ) {
			return $saved;
		}

		$tab    = isset( $input['_tab'] ) ? sanitize_key( $input['_tab'] ) : '';
		$fields = self::fields();
		if ( ! isset( $fields[ $tab ] ) ) {
			return $saved;
		}

		foreach ( $fields[ $tab ] as $key => $field ) {
			$raw = $input[ $key ] ?? null;
			switch ( $field['type'] ) {
				case 'checkbox':
					$saved[ $key ] = empty( $raw ) ? 0 : 1;
					break;
				case 'quote_scope':
					$saved[ $key ] = '1' === (string) $raw ? 1 : 0;
					break;
				case 'number':
					$value = is_numeric( $raw ) ? (float) $raw : (float) $field['default'];
					if ( isset( $field['min'] ) ) {
						$value = max( (float) $field['min'], $value );
					}
					if ( isset( $field['max'] ) ) {
						$value = min( (float) $field['max'], $value );
					}
					$saved[ $key ] = $value;
					break;
				case 'select':
					$saved[ $key ] = ( is_string( $raw ) && isset( $field['options'][ $raw ] ) ) ? $raw : $field['default'];
					break;
				case 'categories':
				case 'users':
					$saved[ $key ] = is_array( $raw ) ? array_values( array_filter( array_map( 'absint', $raw ) ) ) : array();
					break;
				case 'sales_assign':
					$raw           = is_string( $raw ) ? $raw : '';
					$saved[ $key ] = 'round' === $raw || ULTQ_Sales::is_sales( absint( $raw ) ) ? ( 'round' === $raw ? 'round' : (string) absint( $raw ) ) : '';
					break;
				case 'page':
				case 'media':
					$saved[ $key ] = absint( $raw );
					break;
				case 'textarea':
					$saved[ $key ] = sanitize_textarea_field( (string) $raw );
					break;
				case 'layout':
					// 送出順序就是排序；自訂欄位可能在同一次存檔才新增或刪除，這裡只收格式正確的 key，對不上的在 ULTQ_PDF::layout() 略過
					$layout = array();
					foreach ( ULTQ_PDF::layout_groups() as $group => $unused ) {
						$layout[ $group ] = array();
						foreach ( is_array( $raw[ $group ] ?? null ) ? $raw[ $group ] : array() as $item_key => $on ) {
							if ( preg_match( '/^[a-z0-9_]+$/', (string) $item_key ) ) {
								$layout[ $group ][ $item_key ] = empty( $on ) ? 0 : 1;
							}
						}
					}
					$saved[ $key ] = $layout;
					break;
				case 'custom_fields':
					$saved[ $key ] = self::sanitize_custom_fields( $raw );
					break;
				default:
					$saved[ $key ] = sanitize_text_field( (string) $raw );
			}
		}

		return $saved;
	}

	/** 自訂欄位出現的位置 */
	public static function custom_field_positions() {
		return array(
			'header'   => '報價資訊（右上角）',
			'customer' => '客戶資料',
			'footer'   => '總計左側',
		);
	}

	/**
	 * 自訂欄位：array( array( id, label, position, default ) )。id 一經產生就固定，
	 * 每張報價單填的值以 id 對應，改欄位名稱不會弄丟舊報價單的資料
	 */
	private static function sanitize_custom_fields( $raw ) {
		$rows = array();
		$seen = array();
		foreach ( is_array( $raw ) ? $raw : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
			if ( '' === $label ) {
				continue;
			}
			$id = sanitize_key( (string) ( $row['id'] ?? '' ) );
			if ( ! preg_match( '/^cf_[a-z0-9]{6}$/', $id ) || isset( $seen[ $id ] ) ) {
				$id = 'cf_' . strtolower( wp_generate_password( 6, false ) );
			}
			$seen[ $id ] = true;
			$position    = (string) ( $row['position'] ?? '' );
			$rows[]      = array(
				'id'       => $id,
				'label'    => $label,
				'position' => isset( self::custom_field_positions()[ $position ] ) ? $position : 'header',
				'default'  => sanitize_text_field( (string) ( $row['default'] ?? '' ) ),
			);
		}
		return $rows;
	}

	public static function custom_fields() {
		$fields = self::get( 'pdf_custom_fields', array() );
		return is_array( $fields ) ? $fields : array();
	}

	/** 付款條件選項（設定頁一行一個） */
	public static function payment_terms_options() {
		return array_values( array_filter( array_map( 'trim', preg_split( '/\R/', (string) self::get( 'payment_terms_options', '' ) ) ) ) );
	}

	public static function admin_url( $tab = 'general' ) {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'general';
		}
		$fields = self::fields()[ $tab ];
		?>
		<div class="wrap">
			<h1>終極詢價設定</h1>
			<?php settings_errors(); ?>

			<?php // 設定分類比照終極電商／WooCommerce 用 subsubsub 文字頁籤 ?>
			<nav class="ultq-subtabs" aria-label="設定分類"><ul class="subsubsub">
				<?php foreach ( $tabs as $key => $label ) : ?>
					<li><a href="<?php echo esc_url( self::admin_url( $key ) ); ?>"<?php echo $key === $tab ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a></li>
				<?php endforeach; ?>
			</ul></nav>

			<?php if ( 'notify' === $tab ) : ?>
				<p class="description">
					Email 通知的開關、收件人與文案請到 <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ); ?>">WooCommerce → 設定 → 電子郵件</a>，找「終極詢價」開頭的項目。
					<?php if ( ! class_exists( 'WCLON_Settings' ) ) : ?>
						<br><strong>目前沒有啟用「終極登入」外掛，LINE 通知不會發送。</strong>
					<?php endif; ?>
				</p>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'ultq_settings_group' ); ?>
				<input type="hidden" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>">
				<table class="form-table" role="presentation">
					<?php foreach ( $fields as $key => $field ) : ?>
						<?php
						if ( ! empty( $field['inline'] ) ) {
							continue;
						}
						?>
						<tr>
							<th scope="row"><label for="ultq-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td>
								<?php self::render_field( $key, $field ); ?>
								<?php if ( ! empty( $field['desc'] ) && 'checkbox' !== $field['type'] ) : ?>
									<p class="description"><?php echo wp_kses_post( $field['desc'] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_field( $key, $field ) {
		$name  = self::OPTION_KEY . '[' . $key . ']';
		$id    = 'ultq-' . $key;
		$value = self::get( $key );

		switch ( $field['type'] ) {
			case 'checkbox':
				printf(
					'<label><input type="checkbox" id="%s" name="%s" value="1" %s> %s</label>',
					esc_attr( $id ),
					esc_attr( $name ),
					checked( 1, (int) $value, false ),
					wp_kses_post( $field['desc'] ?? '' )
				);
				break;

			case 'quote_scope':
				$scopes = array(
					'1' => '全站商品',
					'0' => '指定分類與個別商品',
				);
				echo '<fieldset class="ultq-quote-scope"><legend class="screen-reader-text">' . esc_html( $field['label'] ) . '</legend>';
				foreach ( $scopes as $opt => $label ) {
					printf(
						'<label><input type="radio" name="%s" value="%s" %s> %s</label><br>',
						esc_attr( $name ),
						esc_attr( $opt ),
						checked( (string) (int) $value, $opt, false ),
						esc_html( $label )
					);
				}
				// 全站時分類用不到，JS 隱藏；已選的分類照樣送出保留，切回「指定」時不必重選
				$cats = self::fields()['general']['quote_categories'];
				echo '<div class="ultq-quote-scope__cats">';
				self::render_field( 'quote_categories', $cats );
				printf( '<p class="description">%s</p>', wp_kses_post( $cats['desc'] ) );
				echo '</div></fieldset>';
				break;

			case 'number':
				printf(
					'<input type="number" class="small-text" id="%s" name="%s" value="%s" min="%s" max="%s" step="%s">',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					esc_attr( $field['min'] ?? '' ),
					esc_attr( $field['max'] ?? '' ),
					esc_attr( $field['step'] ?? '1' )
				);
				break;

			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				foreach ( $field['options'] as $opt => $label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( $opt ), selected( $value, $opt, false ), esc_html( $label ) );
				}
				echo '</select>';
				break;

			case 'textarea':
				printf( '<textarea class="large-text" rows="5" id="%s" name="%s">%s</textarea>', esc_attr( $id ), esc_attr( $name ), esc_textarea( $value ) );
				break;

			case 'page':
				wp_dropdown_pages( array(
					'name'              => esc_attr( $name ),
					'id'                => esc_attr( $id ),
					'selected'          => (int) $value,
					'show_option_none'  => '— 請選擇 —',
					'option_none_value' => 0,
				) );
				break;

			case 'categories':
				$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
				if ( is_wp_error( $terms ) || ! $terms ) {
					echo '<em>尚無商品分類</em>';
					break;
				}
				// 比照終極電商 twshop_render_chip_field()：WooCommerce 的 selectWoo 多選，分類數量有限，選項直接全部渲染、本地過濾
				$selected = array_map( 'intval', (array) $value );
				// 全部移除時也要送出這個 key，否則 sanitize 拿不到值
				printf( '<input type="hidden" name="%s[]" value="0">', esc_attr( $name ) );
				printf( '<select id="%s" name="%s[]" multiple="multiple" class="wc-enhanced-select ultq-term-select" data-placeholder="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( '+ 點選加入分類…' ) );
				foreach ( $terms as $term ) {
					printf(
						'<option value="%d" %s>%s</option>',
						(int) $term->term_id,
						selected( in_array( (int) $term->term_id, $selected, true ), true, false ),
						esc_html( $term->name )
					);
				}
				echo '</select>';
				break;

			case 'sales_assign':
				printf( '<select id="%s" name="%s">', esc_attr( $id ), esc_attr( $name ) );
				printf( '<option value="" %s>%s</option>', selected( $value, '', false ), esc_html( '不自動指派' ) );
				printf( '<option value="round" %s>%s</option>', selected( $value, 'round', false ), esc_html( '輪流分配（依下方名單）' ) );
				foreach ( ULTQ_Sales::users() as $user_id => $user_name ) {
					printf( '<option value="%d" %s>%s</option>', (int) $user_id, selected( (string) $value, (string) $user_id, false ), esc_html( '全部指派給 ' . $user_name ) );
				}
				echo '</select>';
				break;

			case 'users':
				// 同分類多選：selectWoo，選項全部渲染、本地過濾
				$selected = array_map( 'intval', (array) $value );
				printf( '<input type="hidden" name="%s[]" value="0">', esc_attr( $name ) );
				printf( '<select id="%s" name="%s[]" multiple="multiple" class="wc-enhanced-select ultq-term-select" data-placeholder="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( '+ 點選加入業務…' ) );
				foreach ( ULTQ_Sales::users() as $user_id => $user_name ) {
					printf(
						'<option value="%d" %s>%s</option>',
						(int) $user_id,
						selected( in_array( (int) $user_id, $selected, true ), true, false ),
						esc_html( $user_name )
					);
				}
				echo '</select>';
				break;

			case 'media':
				$url = $value ? wp_get_attachment_image_url( (int) $value, 'medium' ) : '';
				printf(
					'<div class="ultq-media-field"><input type="hidden" id="%1$s" name="%2$s" value="%3$s"><div class="ultq-media-preview" style="margin-bottom:6px;">%4$s</div><button type="button" class="button ultq-media-select">選擇圖片</button> <button type="button" class="button-link ultq-media-remove" %5$s>移除</button></div>',
					esc_attr( $id ),
					esc_attr( $name ),
					esc_attr( $value ),
					$url ? '<img src="' . esc_url( $url ) . '" style="max-height:80px;width:auto;">' : '',
					$url ? '' : 'style="display:none"'
				);
				break;

			case 'layout':
				$layout = ULTQ_PDF::layout();
				echo '<div class="ultq-layout">';
				foreach ( ULTQ_PDF::layout_groups() as $group => $def ) {
					printf( '<table class="widefat striped ultq-layout-table"><thead><tr><th colspan="2">%s</th></tr></thead><tbody class="ultq-layout-sortable">', esc_html( $def['label'] ) );
					foreach ( $layout[ $group ] as $item_key => $on ) {
						$item   = $def['items'][ $item_key ];
						$field  = $name . '[' . $group . '][' . $item_key . ']';
						printf(
							'<tr><td class="ultq-layout__handle" title="拖曳排序"><span class="dashicons dashicons-menu" aria-hidden="true"></span></td><td><label><input type="hidden" name="%1$s" value="%2$s"><input type="checkbox" name="%1$s" value="1" %3$s %4$s> %5$s</label>%6$s</td></tr>',
							esc_attr( $field ),
							$item['locked'] ? '1' : '0',
							checked( $on, 1, false ),
							disabled( $item['locked'], true, false ),
							esc_html( $item['label'] ),
							$item['locked'] ? '<span class="ultq-layout__tag">必要</span>' : ( $item['custom'] ? '<span class="ultq-layout__tag">自訂</span>' : '' )
						);
					}
					echo '</tbody></table>';
				}
				echo '</div>';
				break;

			case 'custom_fields':
				$value = is_array( $value ) ? $value : array();
				echo '<table class="widefat ultq-cf-table"><thead><tr><th>欄位名稱</th><th>位置</th><th>預設值</th><th></th></tr></thead><tbody>';
				foreach ( $value as $i => $row ) {
					self::render_custom_field_row( $name, $i, $row );
				}
				echo '</tbody><tfoot><tr><td colspan="4"><button type="button" class="button ultq-cf-add">新增欄位</button></td></tr></tfoot></table>';
				echo '<script type="text/html" id="tmpl-ultq-cf-row">';
				self::render_custom_field_row( $name, '{{i}}', array() );
				echo '</script>';
				// 全部刪光時也要送出這個 key，否則 sanitize 拿不到值
				printf( '<input type="hidden" name="%s[_]" value="">', esc_attr( $name ) );
				break;

			default:
				printf( '<input type="text" class="regular-text" id="%s" name="%s" value="%s">', esc_attr( $id ), esc_attr( $name ), esc_attr( $value ) );
		}
	}

	private static function render_custom_field_row( $name, $i, array $row ) {
		$row  = array_merge( array( 'id' => '', 'label' => '', 'position' => 'header', 'default' => '' ), $row );
		$base = $name . '[' . $i . ']';
		?>
		<tr class="ultq-cf-row">
			<td>
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[id]" value="<?php echo esc_attr( $row['id'] ); ?>">
				<input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( $row['label'] ); ?>" placeholder="例如：交貨期">
			</td>
			<td>
				<select name="<?php echo esc_attr( $base ); ?>[position]">
					<?php foreach ( self::custom_field_positions() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $row['position'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="regular-text" name="<?php echo esc_attr( $base ); ?>[default]" value="<?php echo esc_attr( $row['default'] ); ?>" placeholder="例如：收到訂單後 14 個工作天"></td>
			<td><button type="button" class="button-link ultq-cf-remove" aria-label="移除欄位">&times;</button></td>
		</tr>
		<?php
	}
}
