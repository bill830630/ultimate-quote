<?php
/**
 * 後台：選單（獨立的「詢價單」頂層選單，設定頁在「快捷鍵」）、詢價單列表欄位、編輯頁報價 metabox、寄出報價、後台直接開單
 *
 * 後台開單：post-new.php 先產生 auto-draft，第一次儲存時才配單號（apply_status() 判斷沒有 _ultq_number），
 * 商品用 WooCommerce 的商品搜尋選單挑選，選完由 ultq_admin_item 回傳一列品項 HTML。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Admin {

	const LIST_SLUG = 'edit.php?post_type=ultq_quote';

	private static $saving = false;

	/** 後台新開的單在 apply_status() 配好的單號，save() 寫進 meta */
	private static $new_number = '';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 99 );
		add_filter( 'parent_file', array( __CLASS__, 'parent_file' ) );
		add_filter( 'submenu_file', array( __CLASS__, 'submenu_file' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'save_notice' ) );

		$type = ULTQ_Quote_Post::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column_value' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_filter( "bulk_actions-edit-{$type}", array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'display_post_states', array( __CLASS__, 'post_states' ), 10, 2 );

		add_action( "add_meta_boxes_{$type}", array( __CLASS__, 'meta_boxes' ) );
		add_filter( 'wp_insert_post_data', array( __CLASS__, 'apply_status' ), 10, 2 );
		add_action( "save_post_{$type}", array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'redirect_post_location', array( __CLASS__, 'redirect_location' ), 10, 2 );
		add_action( 'post_edit_form_tag', array( __CLASS__, 'form_enctype' ) );
		add_action( 'wp_ajax_ultq_admin_item', array( __CLASS__, 'ajax_item_row' ) );
		add_action( 'wp_ajax_ultq_admin_customer', array( __CLASS__, 'ajax_customer' ) );
	}

	/** 附件方塊有檔案上傳欄位 */
	public static function form_enctype( $post ) {
		if ( ULTQ_Quote_Post::POST_TYPE === $post->post_type ) {
			echo ' enctype="multipart/form-data"';
		}
	}

	// ─── 選單 ──────────────────────────────────────────────────────────────

	const NEW_SLUG = 'post-new.php?post_type=ultq_quote';

	/**
	 * 「快捷鍵」父選單（終極電商 wc-general-settings 或終極登入 wclon-settings 建立的），沒有時回傳空字串。
	 * admin_menu priority 99，確保在另外兩個外掛之後判斷
	 */
	public static function shortcut_parent() {
		global $admin_page_hooks;
		foreach ( array( 'wc-general-settings', 'wclon-settings' ) as $slug ) {
			if ( isset( $admin_page_hooks[ $slug ] ) ) {
				return $slug;
			}
		}
		return '';
	}

	/** 設定頁掛在哪個選單底下：有「快捷鍵」就放那裡，沒有就放「詢價單」底下 */
	public static function settings_parent() {
		return self::shortcut_parent() ?: self::LIST_SLUG;
	}

	/**
	 * 詢價單是獨立的頂層選單（跟「商品」「文章」一樣）：所有詢價單／新增報價單；
	 * B2B 會員申請在「使用者」列表上方的 B2B 篩選（ULTQ_B2B_Member），不另佔選單；
	 * 設定頁維持在「快捷鍵 → 終極詢價」（沒裝另外兩個外掛時改放在詢價單底下）
	 */
	public static function add_menu() {
		// 待處理：新詢價＋顧客已接受（要聯繫出貨／付款）
		$counts  = wp_count_posts( ULTQ_Quote_Post::POST_TYPE );
		$new     = (int) ( $counts->{'ultq-new'} ?? 0 ) + (int) ( $counts->{'ultq-accepted'} ?? 0 );
		$bubble  = $new ? ' <span class="awaiting-mod">' . $new . '</span>' : '';

		add_menu_page( '詢價單', '詢價單' . $bubble, 'manage_woocommerce', self::LIST_SLUG, '', 'dashicons-clipboard', '55.9' );
		add_submenu_page( self::LIST_SLUG, '所有詢價單', '所有詢價單', 'manage_woocommerce', self::LIST_SLUG );
		add_submenu_page( self::LIST_SLUG, '新增報價單', '新增報價單', 'manage_woocommerce', self::NEW_SLUG );

		$parent = self::settings_parent();
		add_submenu_page( $parent, '終極詢價設定', self::LIST_SLUG === $parent ? '設定' : '終極詢價', 'manage_woocommerce', ULTQ_Settings::PAGE_SLUG, array( 'ULTQ_Settings', 'render_page' ) );
	}

	public static function parent_file( $parent_file ) {
		if ( isset( $_GET['page'] ) && ULTQ_Settings::PAGE_SLUG === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return self::settings_parent();
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && ULTQ_Quote_Post::POST_TYPE === $screen->post_type ? self::LIST_SLUG : $parent_file;
	}

	public static function submenu_file( $submenu_file ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && ULTQ_Quote_Post::POST_TYPE === $screen->post_type ) {
			return 'add' === $screen->action ? self::NEW_SLUG : self::LIST_SLUG;
		}
		return $submenu_file;
	}

	public static function enqueue( $hook ) {
		$screen = get_current_screen();
		$is_quote_screen    = $screen && ULTQ_Quote_Post::POST_TYPE === $screen->post_type;
		$is_settings_screen = isset( $_GET['page'] ) && ULTQ_Settings::PAGE_SLUG === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $is_quote_screen && ! $is_settings_screen ) {
			return;
		}
		$deps = array( 'jquery' );
		if ( $is_settings_screen ) {
			wp_enqueue_media();
			$deps[] = 'jquery-ui-sortable';
			// 分類多選沿用 WooCommerce 的 selectWoo（同終極電商）
			wp_enqueue_style( 'woocommerce_admin_styles' );
			$deps[] = 'wc-enhanced-select';
		} elseif ( $screen && 'post' === $screen->base ) {
			// 商品／會員搜尋選單沿用 WooCommerce 的 selectWoo
			wp_enqueue_style( 'woocommerce_admin_styles' );
			$deps[] = 'wc-enhanced-select';
		}
		wp_enqueue_style( 'ultq-admin', ULTQ_PLUGIN_URL . 'assets/css/ultq-admin.css', array(), ULTQ_VERSION );
		wp_enqueue_script( 'ultq-admin', ULTQ_PLUGIN_URL . 'assets/js/ultq-admin.js', $deps, ULTQ_VERSION, true );
		wp_localize_script( 'ultq-admin', 'ultqAdmin', array(
			'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( 'ultq_admin' ),
			'taxLookup'    => ULTQ_Tax_Lookup::enabled(),
			'decimals'     => wc_get_price_decimals(),
			'currency'     => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'confirmSend'  => '確定要寄出報價單給顧客嗎？',
			'mediaTitle'   => '選擇圖片',
			'mediaButton'  => '使用這張圖片',
			'loadFailed'   => '載入失敗，請重新整理後再試一次。',
		) );
	}

	// ─── 列表 ──────────────────────────────────────────────────────────────

	public static function columns( $columns ) {
		return array(
			'cb'            => $columns['cb'] ?? '<input type="checkbox" />',
			'title'         => '單號',
			'ultq_customer' => '顧客',
			'ultq_items'    => '品項',
			'ultq_total'    => '報價金額',
			'ultq_status'   => '狀態',
			'date'          => '詢價時間',
		);
	}

	public static function column_value( $column, $post_id ) {
		switch ( $column ) {
			case 'ultq_customer':
				$c = ULTQ_Quote_Post::get_customer( $post_id );
				echo esc_html( $c['name'] );
				if ( $c['company'] ) {
					echo '<br><small>' . esc_html( $c['company'] ) . '</small>';
				}
				$user_id = ULTQ_Quote_Post::get_user_id( $post_id );
				if ( $user_id && ULTQ_B2B_Member::is_approved( $user_id ) ) {
					echo ' <span class="ultq-tag">B2B</span>';
				}
				break;
			case 'ultq_items':
				$items = ULTQ_Quote_Post::get_items( $post_id );
				$names = wp_list_pluck( array_slice( $items, 0, 2 ), 'name' );
				echo esc_html( count( $items ) . ' 項' );
				if ( $names ) {
					echo '<br><small>' . esc_html( implode( '、', $names ) . ( count( $items ) > 2 ? '…' : '' ) ) . '</small>';
				}
				break;
			case 'ultq_total':
				if ( ULTQ_Quote_Post::is_fully_priced( $post_id ) ) {
					echo wp_kses_post( wc_price( ULTQ_Quote_Post::totals( $post_id )['total'] ) );
				} else {
					echo '—';
				}
				break;
			case 'ultq_status':
				$status = get_post_status( $post_id );
				printf( '<span class="ultq-status ultq-status--%s">%s</span>', esc_attr( $status ), esc_html( ULTQ_Quote_Post::status_label( $status ) ) );
				if ( 'ultq-quoted' === $status ) {
					$days  = ULTQ_Cron::days_since_sent( $post_id );
					$valid = ULTQ_Quote_Post::get_quote( $post_id )['valid_until'];
					$note  = array( $days ? $days . ' 天未回覆' : '今天寄出' );
					if ( $valid ) {
						$note[] = $valid . ' 到期';
					}
					echo '<br><small class="ultq-status-note">' . esc_html( implode( '・', $note ) ) . '</small>';
				}
				break;
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( ULTQ_Quote_Post::POST_TYPE === $post->post_type ) {
			unset( $actions['inline hide-if-no-js'], $actions['view'] );
		}
		return $actions;
	}

	public static function bulk_actions( $actions ) {
		unset( $actions['edit'] );
		return $actions;
	}

	/** 列表標題旁不要出現 WordPress 預設的「— 私密／待審」這類狀態字 */
	public static function post_states( $states, $post ) {
		return ULTQ_Quote_Post::POST_TYPE === $post->post_type ? array() : $states;
	}

	// ─── 編輯頁 ─────────────────────────────────────────────────────────────

	public static function meta_boxes( $post ) {
		remove_meta_box( 'submitdiv', ULTQ_Quote_Post::POST_TYPE, 'side' );
		remove_meta_box( 'slugdiv', ULTQ_Quote_Post::POST_TYPE, 'normal' );
		add_meta_box( 'ultq_items', '報價內容', array( __CLASS__, 'box_items' ), null, 'normal', 'high' );
		add_meta_box( 'ultq_customer', '顧客資料', array( __CLASS__, 'box_customer' ), null, 'normal', 'default' );
		add_meta_box( 'ultq_files', '附件', array( 'ULTQ_Files', 'render_box' ), null, 'normal', 'default' );
		add_meta_box( 'ultq_actions', self::is_new( $post ) ? '新增報價單' : '詢價單 ' . ULTQ_Quote_Post::get_number( $post->ID ), array( __CLASS__, 'box_actions' ), null, 'side', 'high' );
		if ( ULTQ_Quote_Post::get_versions( $post->ID ) ) {
			add_meta_box( 'ultq_versions', '報價版本', array( __CLASS__, 'box_versions' ), null, 'side', 'default' );
		}
		add_meta_box( 'ultq_log', '處理紀錄', array( __CLASS__, 'box_log' ), null, 'side', 'default' );
	}

	/** 後台剛按「新增報價單」、還沒儲存過（沒有單號） */
	private static function is_new( $post ) {
		return ! get_post_meta( $post->ID, '_ultq_number', true );
	}

	public static function box_actions( $post ) {
		wp_nonce_field( 'ultq_save_quote', '_ultq_quote_nonce' );
		$is_new   = self::is_new( $post );
		$sent_at  = (int) get_post_meta( $post->ID, '_ultq_sent_at', true );
		$customer = ULTQ_Quote_Post::get_customer( $post->ID );
		$pdf_url  = wp_nonce_url( admin_url( 'admin-post.php?action=ultq_pdf&post=' . $post->ID ), 'ultq_pdf_' . $post->ID );
		$status   = isset( ULTQ_Quote_Post::statuses()[ $post->post_status ] ) ? $post->post_status : 'ultq-processing';
		// 新開的單預設指派給開單的人（他是業務的話）
		$owner    = $is_new && ULTQ_Sales::is_sales( get_current_user_id() ) ? get_current_user_id() : ULTQ_Sales::get_owner( $post->ID );
		?>
		<div class="ultq-actions">
			<p>
				<label for="ultq_status"><strong>狀態</strong></label><br>
				<select name="ultq_status" id="ultq_status" class="widefat">
					<?php foreach ( ULTQ_Quote_Post::statuses() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="ultq_owner"><strong>負責業務</strong></label><br>
				<select name="ultq_owner" id="ultq_owner" class="widefat">
					<option value="0">— 未指派 —</option>
					<?php
					$sales_users = ULTQ_Sales::users();
					// 目前的負責人已不在業務名單（例如權限被拿掉）時仍列出來，否則一儲存就會被改成「未指派」
					if ( $owner && ! isset( $sales_users[ $owner ] ) ) {
						$former = get_userdata( $owner );
						$sales_users[ $owner ] = ( $former ? $former->display_name : '#' . $owner ) . '（已不在業務名單）';
					}
					foreach ( $sales_users as $id => $name ) :
						?>
						<option value="<?php echo (int) $id; ?>" <?php selected( $owner, $id ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php if ( $is_new ) : ?>
				<p class="ultq-actions__meta">電話、LINE、Email 來的詢價可以在這裡直接開單，儲存後產生報價單號。</p>
			<?php else : ?>
			<p class="ultq-actions__meta">
				詢價時間：<?php echo esc_html( get_the_date( 'Y-m-d H:i', $post ) ); ?><br>
				<?php if ( $sent_at ) : ?>
					最近寄出：<?php echo esc_html( wp_date( 'Y-m-d H:i', $sent_at ) ); ?>
				<?php else : ?>
					尚未寄出報價
				<?php endif; ?>
			</p>
			<?php endif; ?>
			<?php
			$response = ULTQ_Quote_Post::get_response( $post->ID );
			if ( $response['action'] ) :
				?>
				<div class="ultq-response ultq-response--<?php echo esc_attr( $response['action'] ); ?>">
					<strong><?php echo 'accept' === $response['action'] ? '顧客已線上接受報價' : '顧客已婉拒報價'; ?></strong><br>
					<?php echo esc_html( wp_date( 'Y-m-d H:i', (int) $response['time'] ) ); ?>
					<?php echo $response['name'] ? ' · ' . esc_html( $response['name'] ) : ''; ?>
					<?php if ( $response['note'] ) : ?>
						<br><?php echo nl2br( esc_html( $response['note'] ) ); ?>
					<?php endif; ?>
				</div>
			<?php elseif ( $sent_at && 'ultq-quoted' === $post->post_status && ULTQ_Quote_Post::is_expired( $post->ID ) ) : ?>
				<div class="ultq-response">報價已過有效期限，顧客無法再線上回覆。</div>
			<?php endif; ?>
			<?php if ( ULTQ_Quote_Post::has_unsent_changes( $post->ID ) ) : ?>
				<div class="ultq-response"><strong>有未寄出的修改</strong><br><?php echo esc_html( sprintf( '顧客目前看到的仍是第 %d 版（含有效期限），按「儲存並重新寄出」後才會更新。', ULTQ_Quote_Post::revision( $post->ID ) ) ); ?></div>
			<?php endif; ?>
			<?php if ( ! $is_new ) : ?>
				<p class="ultq-actions__files">
					<a href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener" class="button ultq-btn-preview">預覽 PDF</a>
					<a href="<?php echo esc_url( ULTQ_Excel::admin_url( $post->ID ) ); ?>" class="button ultq-btn-preview">下載 Excel</a>
				</p>
			<?php endif; ?>
			<p class="description">預覽的是已儲存的內容，修改後請先儲存。<?php echo $sent_at ? esc_html( sprintf( '再寄出就會是第 %d 版。', ULTQ_Quote_Post::revision( $post->ID ) + 1 ) ) : ''; ?></p>
			<div class="ultq-actions__buttons">
				<button type="submit" name="save" value="1" class="button button-large">儲存</button>
				<button type="submit" name="ultq_send_quote" value="1" class="button button-primary button-large ultq-btn-send" <?php disabled( ! is_email( $customer['email'] ) ); ?> data-email-field="#ultq_c_email">
					<?php echo $sent_at ? '儲存並重新寄出' : '儲存並寄出報價'; ?>
				</button>
			</div>
			<p class="description">寄出時會把報價單 PDF 附在 Email 寄給 <?php echo esc_html( $customer['email'] ?: '（未填 Email）' ); ?>，並把狀態改為「已報價」。</p>
		</div>
		<?php
	}

	/** 每次寄出的快照：版本、時間、總計（與上一版差額）、當時的 PDF */
	public static function box_versions( $post ) {
		$versions = array_reverse( ULTQ_Quote_Post::get_versions( $post->ID ) );
		echo '<ul class="ultq-versions">';
		foreach ( $versions as $i => $version ) {
			$prev  = $versions[ $i + 1 ] ?? null;
			$total = (float) $version['totals']['total'];
			$diff  = $prev ? $total - (float) $prev['totals']['total'] : 0;
			$url   = wp_nonce_url( admin_url( 'admin-post.php?action=ultq_pdf&post=' . $post->ID . '&rev=' . (int) $version['rev'] ), 'ultq_pdf_' . $post->ID );
			printf(
				'<li><strong>第 %1$d 版</strong>%2$s<br><span class="ultq-versions__meta">%3$s%4$s</span><br>總計 %5$s%6$s　<a href="%7$s" target="_blank" rel="noopener">PDF</a>｜<a href="%8$s">Excel</a></li>',
				(int) $version['rev'],
				0 === $i ? ' <span class="ultq-tag">目前</span>' : '',
				esc_html( wp_date( 'Y-m-d H:i', (int) $version['time'] ) ),
				$version['user'] ? ' · ' . esc_html( $version['user'] ) : '',
				esc_html( ULTQ_Helper::money( $total ) ),
				$prev && abs( $diff ) >= 0.005 ? ' <span class="ultq-versions__diff">（' . esc_html( ( $diff > 0 ? '+' : '−' ) . ULTQ_Helper::money( abs( $diff ) ) ) . '）</span>' : '',
				esc_url( $url ),
				esc_url( ULTQ_Excel::admin_url( $post->ID, (int) $version['rev'] ) )
			);
		}
		echo '</ul>';
	}

	public static function box_log( $post ) {
		$log = array_reverse( ULTQ_Quote_Post::get_log( $post->ID ) );
		if ( ! $log ) {
			echo '<p>尚無紀錄</p>';
			return;
		}
		echo '<ul class="ultq-log">';
		foreach ( $log as $entry ) {
			printf(
				'<li><span class="ultq-log__time">%s%s</span>%s</li>',
				esc_html( wp_date( 'Y-m-d H:i', (int) $entry['time'] ) ),
				$entry['user'] ? ' · ' . esc_html( $entry['user'] ) : '',
				esc_html( $entry['text'] )
			);
		}
		echo '</ul>';
	}

	public static function box_customer( $post ) {
		$c       = ULTQ_Quote_Post::get_customer( $post->ID );
		$user_id = ULTQ_Quote_Post::get_user_id( $post->ID );
		$fields  = array(
			'name'    => '聯絡人',
			'company' => '公司名稱',
			'tax_id'  => '統一編號',
			'email'   => 'Email',
			'phone'   => '電話',
			'address' => '地址',
		);
		$user    = $user_id ? get_userdata( $user_id ) : null;
		?>
		<p class="ultq-customer-member">
			<label for="ultq_user_id"><strong>會員</strong></label>
			<select id="ultq_user_id" name="ultq_user_id" class="wc-customer-search" data-placeholder="訪客（不連結會員）" data-allow_clear="true" style="width:320px;">
				<?php if ( $user ) : ?>
					<option value="<?php echo (int) $user_id; ?>" selected><?php echo esc_html( sprintf( '%s（#%d – %s）', $user->display_name, $user_id, $user->user_email ) ); ?></option>
				<?php endif; ?>
			</select>
			<input type="hidden" name="ultq_user_field" value="1">
			<?php if ( $user ) : ?>
				<a href="<?php echo esc_url( get_edit_user_link( $user_id ) ); ?>">會員資料</a>
				<?php
				$b2b = ULTQ_B2B_Member::get_status( $user_id );
				if ( $b2b ) {
					echo ' <span class="ultq-tag">B2B ' . esc_html( ULTQ_B2B_Member::status_labels()[ $b2b ] ?? $b2b ) . '</span>';
				}
				?>
			<?php endif; ?>
			<br><span class="description">連結會員後，顧客可以在「我的帳戶 → 我的詢價」看到這張報價。選擇會員時會自動帶入空白的聯絡資料。</span>
		</p>
		<div class="ultq-customer-grid">
			<?php foreach ( $fields as $key => $label ) : ?>
				<p>
					<label for="ultq_c_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
					<?php if ( 'tax_id' === $key ) : ?>
						<input type="text" class="widefat" id="ultq_c_tax_id" name="ultq_customer[tax_id]" value="<?php echo esc_attr( $c['tax_id'] ); ?>" maxlength="8" inputmode="numeric" data-ultq-tax-lookup data-company="#ultq_c_company" data-address="#ultq_c_address">
					<?php else : ?>
						<input type="<?php echo 'email' === $key ? 'email' : 'text'; ?>" class="widefat" id="ultq_c_<?php echo esc_attr( $key ); ?>" name="ultq_customer[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $c[ $key ] ); ?>">
					<?php endif; ?>
				</p>
			<?php endforeach; ?>
		</div>
		<?php if ( '' !== $c['message'] ) : ?>
			<p><strong>顧客需求說明</strong></p>
			<div class="ultq-customer-message"><?php echo nl2br( esc_html( $c['message'] ) ); ?></div>
		<?php endif; ?>
		<?php
	}

	public static function box_items( $post ) {
		$items    = ULTQ_Quote_Post::get_items( $post->ID );
		$quote    = ULTQ_Quote_Post::get_quote( $post->ID );
		$totals   = ULTQ_Quote_Post::totals( $post->ID, $items, $quote );
		$user_id  = ULTQ_Quote_Post::get_user_id( $post->ID );
		$is_b2b   = ULTQ_Rules::is_b2b( $user_id );
		$has_unit = in_array( 'col_unit', ULTQ_PDF::enabled( 'items' ), true );
		$colspan  = $has_unit ? 8 : 7;
		?>
		<table class="widefat ultq-items" data-tax-mode="<?php echo esc_attr( $quote['tax_mode'] ); ?>">
			<thead>
				<tr>
					<th class="ultq-items__product">商品</th>
					<th class="ultq-items__qty">數量</th>
					<?php if ( $has_unit ) : ?>
						<th class="ultq-items__unit">單位</th>
					<?php endif; ?>
					<th class="ultq-items__price">單價</th>
					<th class="ultq-items__discount" title="這個品項打幾 %，例如 10 代表 9 折">折扣 %</th>
					<th class="ultq-items__line">小計</th>
					<th class="ultq-items__note">品項備註（顯示在報價單）</th>
					<th class="ultq-items__remove"></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $i => $item ) : ?>
					<?php self::render_item_row( $i, $item, $is_b2b, $has_unit ); ?>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="<?php echo (int) $colspan; ?>" class="ultq-items__add">
						<select class="wc-product-search ultq-product-search" data-action="woocommerce_json_search_products_and_variations" data-placeholder="搜尋商品加入報價（名稱或貨號）" data-allow_clear="true" style="width:360px;"></select>
						<button type="button" class="button ultq-add-line">新增自訂品項</button>
					</td>
				</tr>
			</tfoot>
		</table>
		<script type="text/html" id="tmpl-ultq-line">
			<?php self::render_item_row( '{{i}}', ULTQ_Quote_Post::normalize_item( array( 'qty' => 1, 'unit' => ULTQ_Settings::get( 'default_unit', '' ) ) ), false, $has_unit ); ?>
		</script>

		<div class="ultq-quote-fields">
			<div class="ultq-quote-fields__left">
				<p>
					<label for="ultq_q_valid">報價有效期限</label>
					<input type="date" id="ultq_q_valid" name="ultq_quote[valid_until]" value="<?php echo esc_attr( $quote['valid_until'] ); ?>">
				</p>
				<p>
					<label for="ultq_q_payment">付款條件</label>
					<input type="text" class="regular-text" id="ultq_q_payment" name="ultq_quote[payment_terms]" value="<?php echo esc_attr( $quote['payment_terms'] ); ?>" list="ultq-payment-options">
					<datalist id="ultq-payment-options">
						<?php foreach ( ULTQ_Settings::payment_terms_options() as $option ) : ?>
							<option value="<?php echo esc_attr( $option ); ?>"></option>
						<?php endforeach; ?>
					</datalist>
				</p>
				<?php foreach ( ULTQ_Settings::custom_fields() as $field ) : ?>
					<p>
						<label for="ultq_q_<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
						<input type="text" class="regular-text" id="ultq_q_<?php echo esc_attr( $field['id'] ); ?>" name="ultq_quote[fields][<?php echo esc_attr( $field['id'] ); ?>]" value="<?php echo esc_attr( $quote['fields'][ $field['id'] ] ?? $field['default'] ); ?>">
					</p>
				<?php endforeach; ?>
				<p>
					<label for="ultq_q_note">報價說明／條款（顯示在報價單）</label>
					<textarea class="widefat" rows="5" id="ultq_q_note" name="ultq_quote[note]"><?php echo esc_textarea( $quote['note'] ); ?></textarea>
				</p>
				<p>
					<label for="ultq_q_admin_note">內部備註（顧客看不到）</label>
					<textarea class="widefat" rows="3" id="ultq_q_admin_note" name="ultq_quote[admin_note]"><?php echo esc_textarea( $quote['admin_note'] ); ?></textarea>
				</p>
			</div>
			<table class="ultq-totals">
				<tr>
					<th>小計</th>
					<td class="ultq-totals__subtotal"><?php echo wp_kses_post( wc_price( $totals['subtotal'] ) ); ?></td>
				</tr>
				<?php foreach ( ULTQ_Quote_Post::normalize_discounts( $quote['discounts'] ) as $i => $row ) : ?>
					<?php self::render_discount_row( $i, $row ); ?>
				<?php endforeach; ?>
				<tr class="ultq-totals__add-discount">
					<th colspan="2"><button type="button" class="button-link ultq-add-discount">＋ 新增折讓（專案折讓、尾數折讓…）</button></th>
				</tr>
				<tr>
					<th><label for="ultq_q_shipping">運費</label></th>
					<td><input type="text" class="wc_input_price ultq-shipping" id="ultq_q_shipping" name="ultq_quote[shipping]" value="<?php echo esc_attr( wc_format_localized_price( $quote['shipping'] ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="ultq_q_tax_mode">稅別</label></th>
					<td>
						<select id="ultq_q_tax_mode" name="ultq_quote[tax_mode]" class="ultq-tax-mode">
							<?php foreach ( ULTQ_Quote_Post::tax_modes() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $quote['tax_mode'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<input type="number" class="small-text ultq-tax-rate" step="0.1" min="0" max="100" name="ultq_quote[tax_rate]" value="<?php echo esc_attr( $quote['tax_rate'] ); ?>"> %
					</td>
				</tr>
				<tr>
					<th class="ultq-totals__tax-label"><?php echo 'incl' === $quote['tax_mode'] ? '內含營業稅' : '營業稅'; ?></th>
					<td class="ultq-totals__tax"><?php echo wp_kses_post( wc_price( $totals['tax'] ) ); ?></td>
				</tr>
				<tr class="ultq-totals__grand">
					<th>總計</th>
					<td class="ultq-totals__total"><?php echo wp_kses_post( wc_price( $totals['total'] ) ); ?></td>
				</tr>
			</table>
		</div>
		<script type="text/html" id="tmpl-ultq-discount">
			<?php self::render_discount_row( '{{i}}', array( 'label' => '專案折讓', 'type' => 'fixed', 'value' => '' ) ); ?>
		</script>
		<?php
	}

	/** 整單折讓一列：名稱、金額或 %、即時算出的折抵金額 */
	private static function render_discount_row( $i, array $row ) {
		$name = 'ultq_quote[discounts][' . $i . ']';
		?>
		<tr class="ultq-discount">
			<th><input type="text" class="ultq-discount__label" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $row['label'] ); ?>" placeholder="折讓名稱"></th>
			<td>
				<input type="text" class="wc_input_price ultq-discount__value" name="<?php echo esc_attr( $name ); ?>[value]" value="<?php echo esc_attr( '' === $row['value'] ? '' : wc_format_localized_decimal( $row['value'] ) ); ?>" placeholder="0">
				<select class="ultq-discount__type" name="<?php echo esc_attr( $name ); ?>[type]">
					<option value="fixed" <?php selected( $row['type'], 'fixed' ); ?>>元</option>
					<option value="percent" <?php selected( $row['type'], 'percent' ); ?>>%</option>
				</select>
				<button type="button" class="button-link ultq-remove-discount" aria-label="移除折讓">&times;</button>
				<div class="ultq-discount__amount"></div>
			</td>
		</tr>
		<?php
	}

	private static function render_item_row( $i, array $item, $is_b2b, $has_unit ) {
		$name    = 'ultq_items[' . $i . ']';
		$product = $item['product_id'] ? wc_get_product( $item['variation_id'] ?: $item['product_id'] ) : null;
		$custom  = ! $item['product_id'];
		?>
		<tr class="ultq-item">
			<td class="ultq-items__product">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[product_id]" value="<?php echo esc_attr( $item['product_id'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[variation_id]" value="<?php echo esc_attr( $item['variation_id'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[sku]" value="<?php echo esc_attr( $item['sku'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[attributes]" value="<?php echo esc_attr( wp_json_encode( $item['attributes'] ) ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[request_qty]" value="<?php echo esc_attr( $item['request_qty'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[customer_note]" value="<?php echo esc_attr( $item['customer_note'] ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $name ); ?>[variation]" value="<?php echo esc_attr( wp_json_encode( $item['variation'] ) ); ?>">
				<?php if ( ! $has_unit ) : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[unit]" value="<?php echo esc_attr( $item['unit'] ); ?>">
				<?php endif; ?>
				<?php if ( $custom ) : ?>
					<input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $item['name'] ); ?>" placeholder="品項名稱">
				<?php else : ?>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[name]" value="<?php echo esc_attr( $item['name'] ); ?>">
					<?php if ( $product ) : ?>
						<a href="<?php echo esc_url( get_edit_post_link( $item['product_id'] ) ); ?>" target="_blank"><strong><?php echo esc_html( $item['name'] ); ?></strong></a>
					<?php else : ?>
						<strong><?php echo esc_html( $item['name'] ); ?></strong> <em>（商品已刪除）</em>
					<?php endif; ?>
					<div class="ultq-item__meta">
						<?php
						$meta = array();
						if ( $item['sku'] ) {
							$meta[] = '貨號 ' . $item['sku'];
						}
						foreach ( $item['attributes'] as $label => $value ) {
							$meta[] = $label . '：' . $value;
						}
						if ( $item['request_qty'] ) {
							$meta[] = '顧客詢問數量 ' . $item['request_qty'];
						}
						echo esc_html( implode( '｜', $meta ) );
						?>
					</div>
					<?php if ( $item['customer_note'] ) : ?>
						<div class="ultq-item__meta">顧客備註：<?php echo esc_html( $item['customer_note'] ); ?></div>
					<?php endif; ?>
					<?php if ( $product ) : ?>
						<div class="ultq-item__meta ultq-item__ref">
							<?php
							$ref = array();
							if ( '' !== $product->get_regular_price() ) {
								$ref[] = '售價 ' . ULTQ_Helper::money( $product->get_regular_price() );
							}
							$b2b = ULTQ_B2B_Pricing::get_b2b_price( $product, max( 1, (int) $item['qty'] ) );
							if ( null !== $b2b ) {
								$ref[] = 'B2B 參考價 ' . ULTQ_Helper::money( $b2b ) . ( $is_b2b ? '' : '（顧客非 B2B 會員）' );
							}
							if ( ULTQ_Quantity::has_rules( $product ) ) {
								$ref[] = ULTQ_Quantity::describe( $product );
							}
							echo esc_html( implode( '｜', $ref ) );
							?>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</td>
			<td class="ultq-items__qty"><input type="number" min="0" step="any" class="ultq-qty" name="<?php echo esc_attr( $name ); ?>[qty]" value="<?php echo esc_attr( $item['qty'] ); ?>"></td>
			<?php if ( $has_unit ) : ?>
				<td class="ultq-items__unit"><input type="text" class="ultq-unit" name="<?php echo esc_attr( $name ); ?>[unit]" value="<?php echo esc_attr( $item['unit'] ); ?>"></td>
			<?php endif; ?>
			<td class="ultq-items__price"><input type="text" class="wc_input_price ultq-unit-price" name="<?php echo esc_attr( $name ); ?>[unit_price]" value="<?php echo null === $item['unit_price'] ? '' : esc_attr( wc_format_localized_price( $item['unit_price'] ) ); ?>" placeholder="未報價"></td>
			<td class="ultq-items__discount"><input type="number" min="0" max="100" step="any" class="ultq-discount-pct" name="<?php echo esc_attr( $name ); ?>[discount]" value="<?php echo $item['discount'] > 0 ? esc_attr( $item['discount'] ) : ''; ?>" placeholder="0"></td>
			<td class="ultq-items__line ultq-line-total">—</td>
			<td class="ultq-items__note"><input type="text" class="widefat" name="<?php echo esc_attr( $name ); ?>[line_note]" value="<?php echo esc_attr( $item['line_note'] ); ?>"></td>
			<td class="ultq-items__remove"><button type="button" class="button-link ultq-remove-line" aria-label="移除品項">&times;</button></td>
		</tr>
		<?php
	}

	// ─── 儲存 ──────────────────────────────────────────────────────────────

	private static function verified_request( $post_id = 0 ) {
		if ( ! isset( $_POST['_ultq_quote_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_ultq_quote_nonce'] ), 'ultq_save_quote' ) ) {
			return false;
		}
		return current_user_can( 'manage_woocommerce' );
	}

	/** 狀態下拉選單取代 WordPress 的發佈方塊，狀態在寫入資料庫前套用 */
	public static function apply_status( $data, $postarr ) {
		if ( ULTQ_Quote_Post::POST_TYPE !== $data['post_type'] || self::$saving || ! self::verified_request() ) {
			return $data;
		}
		$status = sanitize_key( wp_unslash( $_POST['ultq_status'] ?? '' ) );
		if ( isset( ULTQ_Quote_Post::statuses()[ $status ] ) ) {
			$data['post_status'] = $status;
		}
		// 後台新開的單：第一次儲存時配單號（auto-draft 階段不配，免得按了「新增」又離開也吃掉流水號）
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( $post_id && ! get_post_meta( $post_id, '_ultq_number', true ) ) {
			self::$new_number   = ULTQ_Quote_Post::next_number();
			$data['post_title'] = self::$new_number;
		}
		$user_id = self::posted_user_id();
		if ( null !== $user_id ) {
			$data['post_author'] = $user_id;
		}
		return $data;
	}

	/**
	 * 表單上的會員欄位：清空後 select 沒有選項，ultq_user_id 根本不會送出，所以靠隱藏欄位 ultq_user_field
	 * 判斷「表單上有這個欄位」→ 沒送出就是取消連結（0）。null＝這次請求不是編輯頁表單
	 */
	private static function posted_user_id() {
		if ( empty( $_POST['ultq_user_field'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return null;
		}
		$user_id = absint( $_POST['ultq_user_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		return $user_id && get_userdata( $user_id ) ? $user_id : 0;
	}

	public static function save( $post_id, $post ) {
		if ( self::$saving || wp_is_post_revision( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( ! self::verified_request() ) {
			return;
		}
		self::$saving = true;

		if ( self::$new_number ) {
			update_post_meta( $post_id, '_ultq_number', self::$new_number );
			ULTQ_Quote_Post::add_log( $post_id, '後台建立報價單' );
			self::$new_number = '';
		}

		$old_status = isset( $_POST['original_post_status'] ) ? sanitize_key( wp_unslash( $_POST['original_post_status'] ) ) : '';
		if ( $old_status && 'auto-draft' !== $old_status && $old_status !== $post->post_status ) {
			ULTQ_Quote_Post::add_log( $post_id, sprintf( '狀態：%s → %s', ULTQ_Quote_Post::status_label( $old_status ), ULTQ_Quote_Post::status_label( $post->post_status ) ) );
		}

		// 顧客資料
		$raw_customer = isset( $_POST['ultq_customer'] ) && is_array( $_POST['ultq_customer'] ) ? wp_unslash( $_POST['ultq_customer'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$customer     = ULTQ_Quote_Post::get_customer( $post_id );
		$old_email    = $customer['email'];
		$old_user_id  = ULTQ_Quote_Post::get_user_id( $post_id );
		foreach ( array( 'name', 'company', 'tax_id', 'phone', 'address' ) as $key ) {
			if ( isset( $raw_customer[ $key ] ) ) {
				$customer[ $key ] = sanitize_text_field( $raw_customer[ $key ] );
			}
		}
		if ( isset( $raw_customer['email'] ) ) {
			$customer['email'] = sanitize_email( $raw_customer['email'] );
		}
		$customer['tax_id'] = preg_replace( '/\D/', '', $customer['tax_id'] );
		update_post_meta( $post_id, '_ultq_customer', $customer );

		$user_id = self::posted_user_id();
		if ( null !== $user_id ) {
			if ( ULTQ_Quote_Post::get_user_id( $post_id ) !== $user_id ) {
				update_post_meta( $post_id, '_ultq_user_id', $user_id );
				ULTQ_Quote_Post::add_log( $post_id, $user_id ? '連結會員：' . get_userdata( $user_id )->display_name : '取消連結會員' );
			}
		}
		// 收件人換了（寄錯對象後更正）：舊的免登入連結（檢視、PDF、Excel、回覆）全部作廢，下次寄出時產生新的
		if ( ( strtolower( $old_email ) !== strtolower( $customer['email'] ) || ULTQ_Quote_Post::get_user_id( $post_id ) !== $old_user_id )
			&& ULTQ_Quote_Post::get_token( $post_id ) ) {
			ULTQ_Quote_Post::reset_token( $post_id );
			ULTQ_Quote_Post::add_log( $post_id, '顧客 Email／會員已變更，先前寄出的報價連結已失效' );
		}
		if ( isset( $_POST['ultq_owner'] ) ) {
			ULTQ_Sales::set_owner( $post_id, absint( $_POST['ultq_owner'] ) );
		}

		// 品項
		$raw_items = isset( $_POST['ultq_items'] ) && is_array( $_POST['ultq_items'] ) ? wp_unslash( $_POST['ultq_items'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$items     = array();
		foreach ( $raw_items as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$attributes = json_decode( (string) ( $raw['attributes'] ?? '' ), true );
			$clean_attr = array();
			if ( is_array( $attributes ) ) {
				foreach ( $attributes as $label => $value ) {
					$clean_attr[ sanitize_text_field( $label ) ] = sanitize_text_field( $value );
				}
			}
			$variation = json_decode( (string) ( $raw['variation'] ?? '' ), true );
			$clean_var = array();
			if ( is_array( $variation ) ) {
				foreach ( $variation as $key => $value ) {
					$clean_var[ sanitize_title( $key ) ] = wc_clean( $value );
				}
			}
			$price = trim( (string) ( $raw['unit_price'] ?? '' ) );
			$item  = ULTQ_Quote_Post::normalize_item( array(
				'product_id'    => absint( $raw['product_id'] ?? 0 ),
				'variation_id'  => absint( $raw['variation_id'] ?? 0 ),
				'name'          => sanitize_text_field( $raw['name'] ?? '' ),
				'sku'           => sanitize_text_field( $raw['sku'] ?? '' ),
				'attributes'    => $clean_attr,
				'variation'     => $clean_var,
				'request_qty'   => absint( $raw['request_qty'] ?? 0 ),
				'qty'           => wc_format_decimal( $raw['qty'] ?? 0 ),
				'customer_note' => sanitize_text_field( $raw['customer_note'] ?? '' ),
				'unit_price'    => '' === $price ? null : wc_format_decimal( $price ),
				'line_note'     => sanitize_text_field( $raw['line_note'] ?? '' ),
				'unit'          => sanitize_text_field( $raw['unit'] ?? '' ),
				'discount'      => (float) wc_format_decimal( $raw['discount'] ?? 0 ),
			) );
			if ( ! $item['product_id'] && '' === $item['name'] ) {
				continue; // 空白的自訂品項
			}
			$items[] = $item;
		}
		update_post_meta( $post_id, '_ultq_items', $items );

		// 報價欄位
		$raw_quote = isset( $_POST['ultq_quote'] ) && is_array( $_POST['ultq_quote'] ) ? wp_unslash( $_POST['ultq_quote'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$quote     = ULTQ_Quote_Post::get_quote( $post_id );
		$tax_mode  = sanitize_key( $raw_quote['tax_mode'] ?? $quote['tax_mode'] );
		$valid     = sanitize_text_field( $raw_quote['valid_until'] ?? '' );
		$fields    = array();
		foreach ( ULTQ_Settings::custom_fields() as $field ) {
			$fields[ $field['id'] ] = sanitize_text_field( $raw_quote['fields'][ $field['id'] ] ?? '' );
		}
		$quote     = array_merge( $quote, array(
			'shipping'    => (float) wc_format_decimal( $raw_quote['shipping'] ?? 0 ),
			'tax_mode'    => isset( ULTQ_Quote_Post::tax_modes()[ $tax_mode ] ) ? $tax_mode : 'excl',
			// 免稅時稅率欄是唯讀（舊版是 disabled 不會送出），沒送出就保留原本的稅率
			'tax_rate'    => max( 0, min( 100, (float) ( $raw_quote['tax_rate'] ?? $quote['tax_rate'] ) ) ),
			'valid_until' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valid ) ? $valid : '',
			'note'        => sanitize_textarea_field( $raw_quote['note'] ?? '' ),
			'admin_note'  => sanitize_textarea_field( $raw_quote['admin_note'] ?? '' ),
			'payment_terms' => sanitize_text_field( $raw_quote['payment_terms'] ?? '' ),
			'discounts'     => ULTQ_Quote_Post::normalize_discounts( array_map( function ( $row ) {
				return is_array( $row ) ? array(
					'label' => sanitize_text_field( $row['label'] ?? '' ),
					'type'  => sanitize_key( $row['type'] ?? '' ),
					'value' => wc_format_decimal( $row['value'] ?? 0 ),
				) : null;
			}, is_array( $raw_quote['discounts'] ?? null ) ? $raw_quote['discounts'] : array() ) ),
			// 已從設定移除的自訂欄位保留舊值，之後加回同一個欄位時不會遺失
			'fields'        => array_merge( is_array( $quote['fields'] ) ? $quote['fields'] : array(), $fields ),
		) );
		update_post_meta( $post_id, '_ultq_quote', $quote );

		// 附件（上傳失敗不影響其他內容，只在提示裡說明）
		$file_error = '';
		$files      = ULTQ_Files::from_request( 'ultq_admin_files' );
		if ( $files ) {
			$valid = ULTQ_Files::validate( $files, count( ULTQ_Files::get( $post_id ) ) );
			if ( is_wp_error( $valid ) ) {
				$file_error = '附件沒有上傳：' . $valid->get_error_message();
			} else {
				ULTQ_Files::store( $post_id, $files, 'admin' );
			}
		}

		if ( ! empty( $_POST['ultq_send_quote'] ) ) {
			$result = ULTQ_Notifications::send_quote( $post_id );
			self::flash( is_wp_error( $result ) ? array( 'error', $result->get_error_message() ) : array( 'success', '報價單已寄出給 ' . $customer['email'] . '。' ) );
		} else {
			self::flash( array( 'success', '詢價單已儲存。' ) );
		}
		if ( $file_error ) {
			self::flash( array( 'error', $file_error ) );
		}

		self::$saving = false;
	}

	private static function flash( array $notice ) {
		$key       = 'ultq_notice_' . get_current_user_id();
		$notices   = get_transient( $key );
		$notices   = is_array( $notices ) && isset( $notices[0] ) && is_array( $notices[0] ) ? $notices : array();
		$notices[] = $notice;
		set_transient( $key, $notices, 60 );
	}

	/** 有我們自己的提示時，不要再出現 WordPress 預設的「文章已更新」 */
	public static function redirect_location( $location, $post_id ) {
		if ( ULTQ_Quote_Post::POST_TYPE === get_post_type( $post_id ) ) {
			$location = remove_query_arg( 'message', $location );
		}
		return $location;
	}

	public static function save_notice() {
		$screen = get_current_screen();
		if ( ! $screen || ULTQ_Quote_Post::POST_TYPE !== $screen->post_type ) {
			return;
		}
		$key     = 'ultq_notice_' . get_current_user_id();
		$notices = get_transient( $key );
		if ( ! is_array( $notices ) ) {
			return;
		}
		delete_transient( $key );
		foreach ( $notices as $notice ) {
			if ( ! is_array( $notice ) ) {
				continue;
			}
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				'error' === $notice[0] ? 'error' : 'success',
				esc_html( $notice[1] )
			);
		}
	}

	// ─── 後台開單 AJAX ─────────────────────────────────────────────────────

	private static function verify_ajax() {
		if ( ! check_ajax_referer( 'ultq_admin', 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => '權限不足或頁面已過期。' ), 403 );
		}
	}

	/** 商品搜尋選單選了商品 → 回傳一列品項（單價預填：B2B 會員用 B2B 參考價，否則用售價；數量預填最低訂購量） */
	public static function ajax_item_row() {
		self::verify_ajax();
		$product = wc_get_product( absint( $_POST['product_id'] ?? 0 ) );
		if ( ! $product || $product->is_type( 'variable' ) ) {
			wp_send_json_error( array( 'message' => $product ? '請選擇可變商品底下的規格。' : '找不到這個商品。' ) );
		}
		$user_id = absint( $_POST['user_id'] ?? 0 );
		$is_b2b  = ULTQ_Rules::is_b2b( $user_id );
		$qty     = ULTQ_Quantity::rules( $product )['min'];
		$price   = $is_b2b ? ULTQ_B2B_Pricing::get_b2b_price( $product, $qty ) : null;
		if ( null === $price && '' !== $product->get_price() ) {
			$price = (float) $product->get_price();
		}
		$item = ULTQ_Quote_Post::normalize_item( array(
			'product_id'   => $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id(),
			'variation_id' => $product->is_type( 'variation' ) ? $product->get_id() : 0,
			'name'         => $product->get_name(),
			'sku'          => $product->get_sku(),
			'attributes'   => ULTQ_List::attribute_labels( $product, array() ),
			'variation'    => $product->is_type( 'variation' ) ? $product->get_variation_attributes() : array(),
			'qty'          => $qty,
			'unit_price'   => $price,
			'unit'         => (string) ULTQ_Settings::get( 'default_unit', '' ),
		) );
		ob_start();
		self::render_item_row( sanitize_key( wp_unslash( $_POST['index'] ?? '0' ) ), $item, $is_b2b, in_array( 'col_unit', ULTQ_PDF::enabled( 'items' ), true ) );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/** 會員搜尋選單選了會員 → 回傳預填的聯絡資料 */
	public static function ajax_customer() {
		self::verify_ajax();
		$user_id = absint( $_POST['user_id'] ?? 0 );
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			wp_send_json_error( array( 'message' => '找不到這位會員。' ) );
		}
		wp_send_json_success( ULTQ_List::prefill_customer( $user_id ) );
	}
}
