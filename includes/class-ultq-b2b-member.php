<?php
/**
 * B2B 會員：我的帳戶申請頁籤、審核狀態、後台使用者列表審核
 *
 * 判斷依據是 user meta ultq_b2b_status（pending/approved/rejected），角色 ultq_b2b 只是
 * 跟著狀態加／移除的標記（方便後台依角色篩選），不靠角色判斷權限。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_B2B_Member {

	const ROLE         = 'ultq_b2b';
	const ENDPOINT     = 'b2b-member';
	const META_STATUS  = 'ultq_b2b_status';
	const META_DATA    = 'ultq_b2b_data';
	const META_APPLIED = 'ultq_b2b_applied_at';
	const META_REASON  = 'ultq_b2b_reject_reason';

	public static function init() {
		// 後台審核（使用者列表、個人資料頁）不受「開放申請」開關影響，已核准的會員要能繼續管理
		add_filter( 'manage_users_columns', array( __CLASS__, 'users_column' ) );
		add_filter( 'manage_users_custom_column', array( __CLASS__, 'users_column_value' ), 10, 3 );
		add_filter( 'user_row_actions', array( __CLASS__, 'user_row_actions' ), 10, 2 );
		add_filter( 'views_users', array( __CLASS__, 'users_views' ) );
		add_action( 'pre_get_users', array( __CLASS__, 'filter_users_query' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_admin_action' ) );
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile_section' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile_section' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile_section' ) );
		add_action( 'set_user_role', array( __CLASS__, 'keep_role' ) );

		if ( ! ULTQ_Settings::get( 'b2b_enabled', 1 ) ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'endpoint_title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render_endpoint' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_application' ) );
	}

	// ─── 狀態 ──────────────────────────────────────────────────────────────

	public static function status_labels() {
		return array(
			'pending'  => '審核中',
			'approved' => '已核准',
			'rejected' => '未通過',
		);
	}

	public static function get_status( $user_id ) {
		return $user_id ? (string) get_user_meta( $user_id, self::META_STATUS, true ) : '';
	}

	private static $approved_cache = array();

	public static function is_approved( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! $user_id ) {
			return false;
		}
		if ( ! isset( self::$approved_cache[ $user_id ] ) ) {
			self::$approved_cache[ $user_id ] = 'approved' === self::get_status( $user_id );
		}
		return self::$approved_cache[ $user_id ];
	}

	/**
	 * 後台個人資料頁存檔時 WordPress 會用「角色」下拉選單 set_role()，把其他角色（含 ultq_b2b）
	 * 全部洗掉；已核准的會員在這裡補回標記角色
	 */
	public static function keep_role( $user_id ) {
		if ( 'approved' !== self::get_status( $user_id ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( $user && ! in_array( self::ROLE, (array) $user->roles, true ) ) {
			$user->add_role( self::ROLE );
		}
	}

	public static function get_data( $user_id ) {
		$data = get_user_meta( $user_id, self::META_DATA, true );
		return array_merge(
			array( 'company' => '', 'tax_id' => '', 'contact' => '', 'phone' => '', 'address' => '', 'note' => '' ),
			is_array( $data ) ? $data : array()
		);
	}

	public static function approve( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		update_user_meta( $user_id, self::META_STATUS, 'approved' );
		unset( self::$approved_cache[ $user_id ] );
		delete_user_meta( $user_id, self::META_REASON );
		$user->add_role( self::ROLE );
		do_action( 'ultq_b2b_status_changed', $user_id, 'approved' );
	}

	public static function reject( $user_id, $reason = '' ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		update_user_meta( $user_id, self::META_STATUS, 'rejected' );
		unset( self::$approved_cache[ $user_id ] );
		update_user_meta( $user_id, self::META_REASON, $reason );
		$user->remove_role( self::ROLE );
		do_action( 'ultq_b2b_status_changed', $user_id, 'rejected' );
	}

	/** 撤銷資格（回到未申請狀態，不通知） */
	public static function revoke( $user_id ) {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return;
		}
		delete_user_meta( $user_id, self::META_STATUS );
		unset( self::$approved_cache[ $user_id ] );
		$user->remove_role( self::ROLE );
	}

	public static function count_pending() {
		$query = new WP_User_Query( array(
			'meta_key'    => self::META_STATUS, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'  => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery
			'fields'      => 'ID',
			'count_total' => true,
			'number'      => 1,
		) );
		return (int) $query->get_total();
	}

	// ─── 我的帳戶頁籤 ───────────────────────────────────────────────────────

	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public static function add_query_var( $vars ) {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public static function add_menu_item( $items ) {
		$new = array();
		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$new[ self::ENDPOINT ] = 'B2B 會員';
			}
			$new[ $key ] = $label;
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = 'B2B 會員';
		}
		return $new;
	}

	public static function endpoint_title() {
		return 'B2B 會員';
	}

	public static function render_endpoint() {
		$user_id = get_current_user_id();
		$status  = self::get_status( $user_id );
		$data    = self::get_data( $user_id );

		// 尚未申請時，用帳單資料預填
		if ( ! $status && '' === $data['company'] ) {
			$customer = new WC_Customer( $user_id );
			$data['company'] = $customer->get_billing_company();
			$data['contact'] = trim( $customer->get_billing_last_name() . $customer->get_billing_first_name() ) ?: wp_get_current_user()->display_name;
			$data['phone']   = $customer->get_billing_phone();
			$data['address'] = trim( $customer->get_billing_state() . $customer->get_billing_city() . $customer->get_billing_address_1() );
		}

		// 申請表單的統編自動帶公司名稱要用前台 JS
		ULTQ_Frontend::enqueue_assets();
		echo ULTQ_Helper::get_template_html( 'myaccount/b2b-member.php', array( // phpcs:ignore WordPress.Security.EscapeOutput
			'status'  => $status,
			'data'    => $data,
			'reason'  => (string) get_user_meta( $user_id, self::META_REASON, true ),
			'intro'   => (string) ULTQ_Settings::get( 'b2b_intro', '' ),
			'labels'  => self::status_labels(),
		) );
	}

	public static function handle_application() {
		if ( empty( $_POST['ultq_b2b_apply'] ) || ! is_user_logged_in() ) {
			return;
		}
		if ( ! isset( $_POST['_ultq_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_ultq_nonce'] ), 'ultq_b2b_apply' ) ) {
			wc_add_notice( '表單已過期，請重新整理後再送出。', 'error' );
			return;
		}

		$user_id = get_current_user_id();
		if ( in_array( self::get_status( $user_id ), array( 'pending', 'approved' ), true ) ) {
			return;
		}

		$data = array(
			'company' => sanitize_text_field( wp_unslash( $_POST['company'] ?? '' ) ),
			'tax_id'  => preg_replace( '/\D/', '', sanitize_text_field( wp_unslash( $_POST['tax_id'] ?? '' ) ) ),
			'contact' => sanitize_text_field( wp_unslash( $_POST['contact'] ?? '' ) ),
			'phone'   => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'address' => sanitize_text_field( wp_unslash( $_POST['address'] ?? '' ) ),
			'note'    => sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ),
		);

		$errors = array();
		if ( '' === $data['company'] ) {
			$errors[] = '請填寫公司名稱。';
		}
		if ( ! ULTQ_Helper::is_valid_tax_id( $data['tax_id'] ) ) {
			$errors[] = '統一編號格式不正確。';
		}
		if ( '' === $data['contact'] ) {
			$errors[] = '請填寫聯絡人。';
		}
		if ( '' === $data['phone'] ) {
			$errors[] = '請填寫聯絡電話。';
		}
		if ( $errors ) {
			foreach ( $errors as $error ) {
				wc_add_notice( $error, 'error' );
			}
			// 讓表單保留剛填的內容
			update_user_meta( $user_id, self::META_DATA, $data );
			return;
		}

		update_user_meta( $user_id, self::META_DATA, $data );
		update_user_meta( $user_id, self::META_APPLIED, time() );
		update_user_meta( $user_id, self::META_STATUS, 'pending' );
		delete_user_meta( $user_id, self::META_REASON );

		do_action( 'ultq_b2b_applied', $user_id );

		if ( ULTQ_Settings::get( 'b2b_auto_approve' ) ) {
			self::approve( $user_id );
			wc_add_notice( '您已成為 B2B 會員！' );
		} else {
			wc_add_notice( '申請已送出，審核完成後會通知您。' );
		}

		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	// ─── 後台使用者列表 ─────────────────────────────────────────────────────

	public static function users_column( $columns ) {
		$columns['ultq_b2b'] = 'B2B';
		return $columns;
	}

	public static function users_column_value( $value, $column, $user_id ) {
		if ( 'ultq_b2b' !== $column ) {
			return $value;
		}
		$status = self::get_status( $user_id );
		if ( ! $status ) {
			return '—';
		}
		$labels = self::status_labels();
		$data   = self::get_data( $user_id );
		$html   = '<strong>' . esc_html( $labels[ $status ] ?? $status ) . '</strong>';
		if ( $data['company'] ) {
			$html .= '<br>' . esc_html( $data['company'] );
		}
		if ( $data['tax_id'] ) {
			$html .= '<br><small>統編 ' . esc_html( $data['tax_id'] ) . '</small>';
		}
		return $html;
	}

	private static function action_url( $user_id, $action ) {
		return wp_nonce_url(
			add_query_arg( array( 'ultq_b2b_action' => $action, 'user_id' => $user_id ), admin_url( 'users.php' ) ),
			'ultq_b2b_' . $action . '_' . $user_id
		);
	}

	public static function user_row_actions( $actions, $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return $actions;
		}
		$status = self::get_status( $user->ID );
		if ( in_array( $status, array( 'pending', 'rejected' ), true ) ) {
			$actions['ultq_approve'] = '<a href="' . esc_url( self::action_url( $user->ID, 'approve' ) ) . '">核准 B2B</a>';
		}
		if ( 'pending' === $status ) {
			$actions['ultq_reject'] = '<a href="' . esc_url( get_edit_user_link( $user->ID ) . '#ultq-b2b' ) . '">拒絕 B2B</a>';
		}
		return $actions;
	}

	public static function users_views( $views ) {
		$pending = self::count_pending();
		$current = isset( $_GET['ultq_b2b'] ) ? sanitize_key( $_GET['ultq_b2b'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$views['ultq_b2b_pending'] = sprintf(
			'<a href="%s" class="%s">B2B 待審核 <span class="count">(%d)</span></a>',
			esc_url( admin_url( 'users.php?ultq_b2b=pending' ) ),
			'pending' === $current ? 'current' : '',
			$pending
		);
		$views['ultq_b2b_approved'] = sprintf(
			'<a href="%s" class="%s">B2B 會員</a>',
			esc_url( admin_url( 'users.php?ultq_b2b=approved' ) ),
			'approved' === $current ? 'current' : ''
		);
		return $views;
	}

	public static function filter_users_query( $query ) {
		if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'users' !== $screen->id || empty( $_GET['ultq_b2b'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$status = sanitize_key( $_GET['ultq_b2b'] ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( isset( self::status_labels()[ $status ] ) ) {
			$query->set( 'meta_key', self::META_STATUS );
			$query->set( 'meta_value', $status );
		}
	}

	public static function handle_admin_action() {
		if ( empty( $_GET['ultq_b2b_action'] ) || empty( $_GET['user_id'] ) ) {
			return;
		}
		$action  = sanitize_key( $_GET['ultq_b2b_action'] );
		$user_id = absint( $_GET['user_id'] );
		check_admin_referer( 'ultq_b2b_' . $action . '_' . $user_id );
		if ( ! current_user_can( 'edit_users' ) ) {
			wp_die( '權限不足。' );
		}
		if ( 'approve' === $action ) {
			self::approve( $user_id );
		}
		wp_safe_redirect( add_query_arg( 'ultq_b2b_done', $action, wp_get_referer() ?: admin_url( 'users.php' ) ) );
		exit;
	}

	public static function admin_notice() {
		if ( empty( $_GET['ultq_b2b_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		echo '<div class="notice notice-success is-dismissible"><p>B2B 會員狀態已更新，並已通知顧客。</p></div>';
	}

	// ─── 個人資料頁 ─────────────────────────────────────────────────────────

	public static function render_profile_section( $user ) {
		if ( ! current_user_can( 'edit_users' ) ) {
			return;
		}
		$status = self::get_status( $user->ID );
		$data   = self::get_data( $user->ID );
		$reason = (string) get_user_meta( $user->ID, self::META_REASON, true );
		$labels = array( '' => '一般會員（未申請）' ) + self::status_labels();
		$fields = array(
			'company' => '公司名稱',
			'tax_id'  => '統一編號',
			'contact' => '聯絡人',
			'phone'   => '電話',
			'address' => '地址',
			'note'    => '申請備註',
		);
		$applied = (int) get_user_meta( $user->ID, self::META_APPLIED, true );
		?>
		<h2 id="ultq-b2b">B2B 會員（終極詢價）</h2>
		<?php wp_nonce_field( 'ultq_b2b_profile', '_ultq_b2b_profile_nonce' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="ultq_b2b_status">狀態</label></th>
				<td>
					<select name="ultq_b2b_status" id="ultq_b2b_status">
						<?php foreach ( $labels as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $status, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( $applied ) : ?>
						<p class="description">申請時間：<?php echo esc_html( wp_date( 'Y-m-d H:i', $applied ) ); ?></p>
					<?php endif; ?>
					<p class="description">改成「已核准」或「未通過」會寄送審核結果通知給顧客。</p>
				</td>
			</tr>
			<tr>
				<th><label for="ultq_b2b_reason">未通過原因</label></th>
				<td><input type="text" class="regular-text" name="ultq_b2b_reason" id="ultq_b2b_reason" value="<?php echo esc_attr( $reason ); ?>"><p class="description">狀態選「未通過」時會一併告知顧客。</p></td>
			</tr>
			<?php foreach ( $fields as $key => $label ) : ?>
				<tr>
					<th><label for="ultq_b2b_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
					<td>
						<?php if ( 'note' === $key ) : ?>
							<textarea class="large-text" rows="3" name="ultq_b2b_data[<?php echo esc_attr( $key ); ?>]" id="ultq_b2b_<?php echo esc_attr( $key ); ?>"><?php echo esc_textarea( $data[ $key ] ); ?></textarea>
						<?php else : ?>
							<input type="text" class="regular-text" name="ultq_b2b_data[<?php echo esc_attr( $key ); ?>]" id="ultq_b2b_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $data[ $key ] ); ?>">
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php
	}

	public static function save_profile_section( $user_id ) {
		if ( ! current_user_can( 'edit_users' ) || ! isset( $_POST['_ultq_b2b_profile_nonce'] ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( $_POST['_ultq_b2b_profile_nonce'] ), 'ultq_b2b_profile' ) ) {
			return;
		}

		$raw  = isset( $_POST['ultq_b2b_data'] ) && is_array( $_POST['ultq_b2b_data'] ) ? wp_unslash( $_POST['ultq_b2b_data'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$data = self::get_data( $user_id );
		foreach ( array_keys( $data ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$data[ $key ] = 'note' === $key ? sanitize_textarea_field( $raw[ $key ] ) : sanitize_text_field( $raw[ $key ] );
			}
		}
		update_user_meta( $user_id, self::META_DATA, $data );

		$new_status = sanitize_key( wp_unslash( $_POST['ultq_b2b_status'] ?? '' ) );
		$old_status = self::get_status( $user_id );
		$reason     = sanitize_text_field( wp_unslash( $_POST['ultq_b2b_reason'] ?? '' ) );

		if ( $new_status === $old_status ) {
			if ( 'rejected' === $new_status ) {
				update_user_meta( $user_id, self::META_REASON, $reason );
			}
			return;
		}

		switch ( $new_status ) {
			case 'approved':
				self::approve( $user_id );
				break;
			case 'rejected':
				self::reject( $user_id, $reason );
				break;
			case 'pending':
				update_user_meta( $user_id, self::META_STATUS, 'pending' );
				unset( self::$approved_cache[ $user_id ] );
				get_userdata( $user_id )->remove_role( self::ROLE );
				break;
			default:
				self::revoke( $user_id );
		}
	}
}
