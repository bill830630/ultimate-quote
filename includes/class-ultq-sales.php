<?php
/**
 * 業務窗口：每張詢價單指派一位負責業務（_ultq_owner），報價單印業務姓名／電話，通知只找負責的人
 *
 * - 業務名單 = 有 ultq_manage_quotes 或 manage_woocommerce 權限的使用者（filter ultq_sales_users 可改）
 * - 業務自己的「報價單上顯示的姓名／電話」存在 user meta ultq_sales_name／ultq_sales_phone，Email 用帳號 Email
 * - 顧客可以設定專屬業務（user meta ultq_sales_rep，B2B 經銷商常見），新詢價優先指派給他
 * - 其餘依設定 sales_assign：不自動指派／輪流分配（sales_team 名單，輪到誰記在 option ultq_sales_rr）／固定一位
 * - 後台開單時預設指派給開單的人
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Sales {

	const ROLE       = 'ultq_sales';
	const CAPABILITY = 'ultq_manage_quotes';

	const META_OWNER = '_ultq_owner';
	const META_REP   = 'ultq_sales_rep';
	const META_NAME  = 'ultq_sales_name';
	const META_PHONE = 'ultq_sales_phone';

	/** 批次指派結果參數，只用來顯示一次通知。 */
	const BULK_ARGS = array( 'ultq_assigned', 'ultq_unchanged', 'ultq_skipped', 'ultq_assign_error' );

	public static function init() {
		$type = ULTQ_Quote_Post::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'column' ), 20 );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'map_quote_capabilities' ), 20, 4 );
		add_filter( 'wp_count_posts', array( __CLASS__, 'filter_counts' ), 10, 3 );
		add_filter( 'views_edit-' . $type, array( __CLASS__, 'filter_views' ) );
		add_filter( 'bulk_actions-edit-' . $type, array( __CLASS__, 'bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . $type, array( __CLASS__, 'bulk_assign' ), 10, 3 );
		add_action( 'manage_posts_extra_tablenav', array( __CLASS__, 'bulk_owner_dropdown' ) );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_notice' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_args' ) );
		add_action( 'load-edit.php', array( __CLASS__, 'clean_bulk_referer' ) );
		add_action( 'wp_after_insert_post', array( __CLASS__, 'assign_new_draft' ), 10, 3 );
		add_filter( 'woocommerce_login_redirect', array( __CLASS__, 'login_redirect' ), 99, 2 );
		add_filter( 'login_redirect', array( __CLASS__, 'wp_login_redirect' ), 99, 3 );
		add_filter( 'woocommerce_prevent_admin_access', array( __CLASS__, 'prevent_admin_access' ), 99 );
		add_filter( 'show_admin_bar', array( __CLASS__, 'show_admin_bar' ), 99 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_account_dashboard' ), 20 );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'account_menu' ), 30 );
		add_filter( 'woocommerce_get_endpoint_url', array( __CLASS__, 'account_endpoint_url' ), 10, 2 );

		add_action( 'show_user_profile', array( __CLASS__, 'render_profile' ), 20 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile' ), 20 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	// ─── 名單 ──────────────────────────────────────────────────────────────
	public static function dashboard_url() {
		return admin_url( 'edit.php?post_type=' . ULTQ_Quote_Post::POST_TYPE );
	}

	public static function login_redirect( $redirect, $user ) {
		return $user instanceof WP_User && self::is_restricted( $user->ID ) ? self::dashboard_url() : $redirect;
	}

	public static function wp_login_redirect( $redirect, $requested, $user ) {
		return self::login_redirect( $redirect, $user );
	}

	public static function prevent_admin_access( $prevent ) {
		return current_user_can( self::CAPABILITY ) ? false : $prevent;
	}

	public static function show_admin_bar( $show ) {
		return self::is_restricted() ? 'false' !== get_user_option( 'show_admin_bar_front' ) : $show;
	}

	/** 社群登入直接導回會員首頁時，也要能到達詢價後台；帳號設定等端點仍可使用。 */
	public static function redirect_account_dashboard() {
		if ( self::is_restricted() && function_exists( 'is_account_page' ) && is_account_page() && ! is_wc_endpoint_url() ) {
			wp_safe_redirect( self::dashboard_url() );
			exit;
		}
	}

	public static function account_menu( $items ) {
		if ( self::is_restricted() ) {
			$items = array( 'ultq-staff-quotes' => '詢價管理後台' ) + $items;
		}
		return $items;
	}

	public static function account_endpoint_url( $url, $endpoint ) {
		return 'ultq-staff-quotes' === $endpoint && self::is_restricted() ? self::dashboard_url() : $url;
	}

	/** 管理員／商店管理員可管理全部，其餘具詢價權限的帳號限本人負責。 */
	public static function can_manage_all( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'manage_options' );
	}

	public static function is_restricted( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return user_can( $user_id, self::CAPABILITY ) && ! self::can_manage_all( $user_id );
	}

	public static function can_manage_quote( $post_id, $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return ULTQ_Quote_Post::POST_TYPE === get_post_type( $post_id )
			&& user_can( $user_id, self::CAPABILITY )
			&& ( self::can_manage_all( $user_id ) || self::get_owner( $post_id ) === $user_id );
	}

	/** 同時封鎖直接編輯網址、核心刪除／還原與帶單號的原始 capability 檢查。 */
	public static function map_quote_capabilities( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post', self::CAPABILITY ), true ) || empty( $args[0] ) ) {
			return $caps;
		}
		$post = get_post( (int) $args[0] );
		if ( $post && 'revision' === $post->post_type ) {
			$post = get_post( $post->post_parent );
		}
		if ( $post && ULTQ_Quote_Post::POST_TYPE === $post->post_type && ! self::can_manage_quote( $post->ID, $user_id ) ) {
			return array( 'do_not_allow' );
		}
		return $caps;
	}

	/** 新增報價頁建立的暫存單立即歸本人，後續存檔才能通過物件權限。 */
	public static function assign_new_draft( $post_id, $post, $update ) {
		if ( ! $update && ULTQ_Quote_Post::POST_TYPE === $post->post_type && 'auto-draft' === $post->post_status
			&& self::is_restricted() && (int) $post->post_author === get_current_user_id() ) {
			self::set_owner( $post_id, get_current_user_id(), false );
		}
	}

	/** 避免狀態頁籤與選單徽章洩漏其他業務的詢價數量。 */
	public static function filter_counts( $counts, $type, $perm ) {
		if ( ULTQ_Quote_Post::POST_TYPE !== $type || ! self::is_restricted() ) {
			return $counts;
		}
		global $wpdb;
		$own = new stdClass();
		foreach ( $counts as $status => $count ) {
			$own->$status = 0;
		}
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.post_status, COUNT(DISTINCT p.ID) AS num FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id=p.ID WHERE p.post_type=%s AND m.meta_key=%s AND m.meta_value=%s GROUP BY p.post_status",
			$type, self::META_OWNER, (string) get_current_user_id()
		) );
		foreach ( $rows as $row ) {
			$own->{$row->post_status} = (int) $row->num;
		}
		return $own;
	}

	public static function filter_views( $views ) {
		if ( self::is_restricted() ) {
			// 核心「我的」依文章作者統計，不等於目前負責人，避免顯示已改派的單。
			unset( $views['mine'] );
		}
		return $views;
	}

	/** 業務名單只收「業務」與「商店經理」角色，管理員不列入。@return array user ID => 暱稱 */
	public static function users() {
		static $users = null;
		if ( null === $users ) {
			$users = array();
			foreach ( get_users( array( 'role__in' => array( self::ROLE, 'shop_manager' ) ) ) as $user ) {
				$users[ (int) $user->ID ] = self::nickname( $user );
			}
			asort( $users, SORT_NATURAL | SORT_FLAG_CASE );
			$users = (array) apply_filters( 'ultq_sales_users', $users );
		}
		return $users;
	}

	/** 選單上顯示的帳號暱稱，沒填時退回顯示名稱 */
	public static function nickname( $user ) {
		return (string) $user->nickname ?: $user->display_name;
	}

	public static function is_sales( $user_id ) {
		return $user_id && isset( self::users()[ (int) $user_id ] );
	}

	/** 報價單／Email 上的業務資料：name/phone/email，沒有指派時回傳空陣列 */
	public static function info( $user_id ) {
		$user = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user ) {
			return array();
		}
		return array(
			'name'  => (string) get_user_meta( $user->ID, self::META_NAME, true ) ?: $user->display_name,
			'phone' => (string) get_user_meta( $user->ID, self::META_PHONE, true ),
			'email' => $user->user_email,
		);
	}

	// ─── 指派 ──────────────────────────────────────────────────────────────

	public static function get_owner( $post_id ) {
		return (int) get_post_meta( $post_id, self::META_OWNER, true );
	}

	/**
	 * @param bool $notify 由別人指派時通知被指派的業務（LINE＋Email）
	 */
	public static function set_owner( $post_id, $user_id, $notify = true ) {
		$user_id = (int) $user_id;
		$old     = self::get_owner( $post_id );
		if ( $old === $user_id || ( $user_id && ! self::is_sales( $user_id ) ) ) {
			return;
		}
		if ( $user_id ) {
			update_post_meta( $post_id, self::META_OWNER, $user_id );
		} else {
			delete_post_meta( $post_id, self::META_OWNER );
		}
		ULTQ_Quote_Post::add_log( $post_id, $user_id ? '負責業務：' . ( self::info( $user_id )['name'] ?? '' ) : '取消指派業務' );
		if ( $notify && $user_id && get_current_user_id() !== $user_id ) {
			self::notify_assigned( $post_id, $user_id );
		}
	}

	/** 前台新詢價：顧客的專屬業務 → 設定的分配方式 */
	public static function auto_assign( $post_id, $customer_id = 0 ) {
		$owner = $customer_id ? (int) get_user_meta( $customer_id, self::META_REP, true ) : 0;
		if ( ! self::is_sales( $owner ) ) {
			$owner = self::pick_by_setting();
		}
		if ( $owner ) {
			// 新詢價通知本身就會發給負責業務，這裡不另外發「已指派」
			self::set_owner( $post_id, $owner, false );
		}
	}

	private static function pick_by_setting() {
		$mode = (string) ULTQ_Settings::get( 'sales_assign', '' );
		if ( 'round' !== $mode ) {
			return self::is_sales( (int) $mode ) ? (int) $mode : 0;
		}
		$team = array_values( array_filter( array_map( 'intval', (array) ULTQ_Settings::get( 'sales_team', array() ) ), array( __CLASS__, 'is_sales' ) ) );
		if ( ! $team ) {
			return 0;
		}
		$next = (int) get_option( 'ultq_sales_rr', 0 ) % count( $team );
		update_option( 'ultq_sales_rr', $next + 1, false );
		return $team[ $next ];
	}

	// ─── 通知 ──────────────────────────────────────────────────────────────

	/** 要不要另外通知負責業務（設定頁「通知負責業務」） */
	public static function should_notify( $post_id ) {
		return ULTQ_Settings::get( 'notify_owner', 1 ) && self::get_owner( $post_id );
	}

	/** 負責業務的 Email（給管理員信加副本用），沒有或沒開通知回傳空字串 */
	public static function owner_email( $post_id ) {
		if ( ! self::should_notify( $post_id ) ) {
			return '';
		}
		$email = self::info( self::get_owner( $post_id ) )['email'] ?? '';
		return is_email( $email ) ? $email : '';
	}

	/** LINE 推播給負責業務本人（業務要先用終極登入綁定 LINE） */
	public static function push_line_to_owner( $post_id, $title, array $rows, $btn_label, $btn_url ) {
		if ( ! self::should_notify( $post_id ) ) {
			return false;
		}
		return ULTQ_Helper::push_line_to_staff( self::get_owner( $post_id ), $title, $rows, $btn_label, $btn_url );
	}

	private static function notify_assigned( $post_id, $user_id ) {
		if ( ! ULTQ_Settings::get( 'notify_owner', 1 ) ) {
			return;
		}
		$number   = ULTQ_Quote_Post::get_number( $post_id );
		$customer = ULTQ_Quote_Post::get_customer( $post_id );
		$url      = ULTQ_Quote_Post::admin_edit_url( $post_id );
		$by       = wp_get_current_user();
		$by       = $by && $by->exists() ? $by->display_name : '系統';

		ULTQ_Helper::push_line_to_staff( $user_id, '詢價單指派給你 ' . $number, array(
			array( '公司', $customer['company'] ),
			array( '聯絡人', $customer['name'] ),
			array( '電話', $customer['phone'] ),
			array( '指派人', $by ),
		), '前往後台', $url );

		$email = self::info( $user_id )['email'] ?? '';
		if ( is_email( $email ) && function_exists( 'WC' ) ) {
			$mailer  = WC()->mailer();
			$heading = '詢價單指派給你';
			$body    = sprintf(
				'<p>%1$s 把詢價單 <strong>%2$s</strong>（%3$s）指派給你處理。</p><p><a href="%4$s">前往後台查看</a></p>',
				esc_html( $by ),
				esc_html( $number ),
				esc_html( trim( $customer['company'] . ' ' . $customer['name'] ) ),
				esc_url( $url )
			);
			$mailer->send( $email, sprintf( '【%s】詢價單 %s 指派給你', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $number ), $mailer->wrap_message( $heading, $body ) );
		}
	}

	/** 批次指派：同一位業務只收一則彙總通知（LINE＋Email），只有一張時沿用單張通知。 */
	private static function notify_assigned_bulk( array $post_ids, $user_id ) {
		if ( 1 === count( $post_ids ) ) {
			self::notify_assigned( reset( $post_ids ), $user_id );
			return;
		}
		if ( ! ULTQ_Settings::get( 'notify_owner', 1 ) ) {
			return;
		}
		$numbers = array_map( array( 'ULTQ_Quote_Post', 'get_number' ), $post_ids );
		$count   = count( $numbers );
		$shown   = implode( '、', array_slice( $numbers, 0, 10 ) ) . ( $count > 10 ? sprintf( ' 等 %d 張', $count ) : '' );
		$url     = admin_url( 'edit.php?post_type=' . ULTQ_Quote_Post::POST_TYPE . '&ultq_owner=mine' );
		$by      = wp_get_current_user();
		$by      = $by && $by->exists() ? $by->display_name : '系統';

		ULTQ_Helper::push_line_to_staff( $user_id, sprintf( '%d 張詢價單指派給你', $count ), array(
			array( '單號', $shown ),
			array( '指派人', $by ),
		), '前往後台', $url );

		$email = self::info( $user_id )['email'] ?? '';
		if ( is_email( $email ) && function_exists( 'WC' ) ) {
			$mailer = WC()->mailer();
			$items  = '';
			foreach ( $post_ids as $post_id ) {
				$customer = ULTQ_Quote_Post::get_customer( $post_id );
				$items   .= sprintf(
					'<li><a href="%1$s">%2$s</a>（%3$s）</li>',
					esc_url( ULTQ_Quote_Post::admin_edit_url( $post_id ) ),
					esc_html( ULTQ_Quote_Post::get_number( $post_id ) ),
					esc_html( trim( $customer['company'] . ' ' . $customer['name'] ) )
				);
			}
			$body = sprintf(
				'<p>%1$s 把 %2$d 張詢價單指派給你處理：</p><ul>%3$s</ul><p><a href="%4$s">前往後台查看</a></p>',
				esc_html( $by ),
				$count,
				$items,
				esc_url( $url )
			);
			$mailer->send( $email, sprintf( '【%s】%d 張詢價單指派給你', wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $count ), $mailer->wrap_message( '詢價單指派給你', $body ) );
		}
	}

	// ─── 後台列表 ──────────────────────────────────────────────────────────

	/** 回收桶裡的詢價一律略過，不提供指派。 */
	private static function is_trash_view() {
		return isset( $_REQUEST['post_status'] ) && 'trash' === $_REQUEST['post_status']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function bulk_actions( $actions ) {
		if ( self::can_manage_all() && current_user_can( self::CAPABILITY ) && ! self::is_trash_view() ) {
			$actions['ultq_assign'] = '批次指派業務';
		}
		return $actions;
	}

	/** 上下兩組各有獨立欄位，沒有 JavaScript 時也能使用。 */
	public static function bulk_owner_dropdown( $which ) {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . ULTQ_Quote_Post::POST_TYPE !== $screen->id || ! self::can_manage_all() || ! current_user_can( self::CAPABILITY ) || self::is_trash_view() ) {
			return;
		}
		?>
		<div class="alignleft actions ultq-bulk-owner" data-position="<?php echo esc_attr( $which ); ?>">
			<label class="screen-reader-text" for="ultq-bulk-owner-<?php echo esc_attr( $which ); ?>">指派給業務</label>
			<select id="ultq-bulk-owner-<?php echo esc_attr( $which ); ?>" name="ultq_bulk_owner[<?php echo esc_attr( $which ); ?>]">
				<option value="">選擇負責業務</option>
				<option value="0">取消指派（未指派）</option>
				<?php foreach ( self::users() as $id => $name ) : ?>
					<option value="<?php echo (int) $id; ?>"><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	public static function bulk_assign( $redirect, $action, $post_ids ) {
		if ( 'ultq_assign' !== $action ) {
			return $redirect;
		}
		if ( ! self::can_manage_all() || ! current_user_can( self::CAPABILITY ) ) {
			wp_die( '你沒有指派業務的權限。', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bulk-posts' );
		$redirect = remove_query_arg( self::BULK_ARGS, $redirect );
		$position = isset( $_REQUEST['action'] ) && 'ultq_assign' === $_REQUEST['action'] ? 'top' : 'bottom';
		$owners   = isset( $_REQUEST['ultq_bulk_owner'] ) && is_array( $_REQUEST['ultq_bulk_owner'] ) ? wp_unslash( $_REQUEST['ultq_bulk_owner'] ) : array();
		$raw      = $owners[ $position ] ?? '';
		if ( ! is_scalar( $raw ) || ! ctype_digit( (string) $raw ) || ( (int) $raw && ! self::is_sales( (int) $raw ) ) ) {
			return add_query_arg( 'ultq_assign_error', 1, $redirect );
		}
		$owner = (int) $raw;
		$changed   = array();
		$unchanged = $skipped = 0;
		foreach ( array_unique( array_map( 'absint', (array) $post_ids ) ) as $post_id ) {
			if ( ! self::can_manage_quote( $post_id ) || ! current_user_can( 'edit_post', $post_id ) || 'trash' === get_post_status( $post_id ) ) {
				++$skipped;
				continue;
			}
			if ( self::get_owner( $post_id ) === $owner ) {
				++$unchanged;
				continue;
			}
			// 逐張寫處理紀錄，通知等全部完成後合併成一則，避免勾很多張時連發 LINE／Email。
			self::set_owner( $post_id, $owner, false );
			$changed[] = $post_id;
		}
		if ( $changed && $owner && get_current_user_id() !== $owner ) {
			self::notify_assigned_bulk( $changed, $owner );
		}
		return add_query_arg( array( 'ultq_assigned' => count( $changed ), 'ultq_unchanged' => $unchanged, 'ultq_skipped' => $skipped ), $redirect );
	}

	public static function bulk_notice() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . ULTQ_Quote_Post::POST_TYPE !== $screen->id || ! self::can_manage_all() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		// 僅顯示列表操作結果，實際寫入已檢查權限及 bulk-posts nonce。
		if ( isset( $_GET['ultq_assign_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>請選擇有效的負責業務，或選擇取消指派。</p></div>';
		} elseif ( isset( $_GET['ultq_assigned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html( sprintf(
				'已更新 %1$d 張詢價單的負責業務；%2$d 張指派未變更，%3$d 張略過。',
				absint( $_GET['ultq_assigned'] ), absint( $_GET['ultq_unchanged'] ?? 0 ), absint( $_GET['ultq_skipped'] ?? 0 )
			) ) );
		}
	}

	public static function removable_args( $args ) {
		return array_merge( $args, self::BULK_ARGS );
	}

	/** 列表表單的 _wp_http_referer 會帶著上次的結果參數，執行其他批次操作時不要再顯示舊通知。 */
	public static function clean_bulk_referer() {
		if ( empty( $_REQUEST['_wp_http_referer'] ) || ULTQ_Quote_Post::POST_TYPE !== ( $GLOBALS['typenow'] ?? '' ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$_REQUEST['_wp_http_referer'] = wp_slash( remove_query_arg( self::BULK_ARGS, wp_unslash( $_REQUEST['_wp_http_referer'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
	}

	public static function column( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'ultq_customer' === $key ) {
				$out['ultq_owner'] = '業務';
			}
		}
		return $out;
	}

	public static function column_value( $column, $post_id ) {
		if ( 'ultq_owner' !== $column ) {
			return;
		}
		$owner = self::get_owner( $post_id );
		echo $owner ? esc_html( self::info( $owner )['name'] ?? '' ) : '<span aria-hidden="true">—</span>';
	}

	public static function filter_dropdown( $post_type ) {
		if ( ULTQ_Quote_Post::POST_TYPE !== $post_type ) {
			return;
		}
		if ( self::is_restricted() ) {
			echo '<span>指派給我的詢價單</span>';
			return;
		}
		$current = isset( $_GET['ultq_owner'] ) ? sanitize_key( wp_unslash( $_GET['ultq_owner'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		echo '<label class="screen-reader-text" for="ultq-owner-filter">依業務篩選</label><select name="ultq_owner" id="ultq-owner-filter">';
		printf( '<option value="">%s</option>', esc_html( '所有業務' ) );
		printf( '<option value="mine" %s>%s</option>', selected( $current, 'mine', false ), esc_html( '指派給我的' ) );
		printf( '<option value="none" %s>%s</option>', selected( $current, 'none', false ), esc_html( '尚未指派' ) );
		foreach ( self::users() as $id => $name ) {
			printf( '<option value="%d" %s>%s</option>', (int) $id, selected( $current, (string) $id, false ), esc_html( $name ) );
		}
		echo '</select>';
	}

	public static function filter_query( $query ) {
		if ( ! in_array( ULTQ_Quote_Post::POST_TYPE, (array) $query->get( 'post_type' ), true ) ) {
			return;
		}
		if ( self::is_restricted() ) {
			$meta = array( 'key' => self::META_OWNER, 'value' => get_current_user_id(), 'compare' => '=' );
			if ( is_admin() && $query->is_main_query() ) {
				$query->set( 'author', '' );
				unset( $_GET['author'], $_REQUEST['author'] );
			}
		} elseif ( is_admin() && $query->is_main_query() && ! empty( $_GET['ultq_owner'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$value = sanitize_key( wp_unslash( $_GET['ultq_owner'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			$meta = 'none' === $value ? array( 'key' => self::META_OWNER, 'compare' => 'NOT EXISTS' )
				: array( 'key' => self::META_OWNER, 'value' => 'mine' === $value ? get_current_user_id() : absint( $value ) );
		} else {
			return;
		}
		$existing = $query->get( 'meta_query' );
		$query->set( 'meta_query', $existing ? array( 'relation' => 'AND', $existing, $meta ) : array( $meta ) );
	}

	// ─── 個人資料頁 ─────────────────────────────────────────────────────────

	/** 業務本人：報價單上的姓名／電話；顧客：專屬業務 */
	public static function render_profile( $user ) {
		if ( ! current_user_can( self::CAPABILITY ) || ( ! current_user_can( 'manage_woocommerce' ) && get_current_user_id() !== (int) $user->ID ) ) {
			return;
		}
		wp_nonce_field( 'ultq_sales_profile', '_ultq_sales_nonce' );
		if ( user_can( $user, self::CAPABILITY ) ) {
			?>
			<h2 id="ultq-sales">業務資料（終極詢價）</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th><label for="ultq_sales_name">報價單上的姓名</label></th>
					<td><input type="text" class="regular-text" name="ultq_sales_name" id="ultq_sales_name" value="<?php echo esc_attr( get_user_meta( $user->ID, self::META_NAME, true ) ); ?>" placeholder="<?php echo esc_attr( $user->display_name ); ?>"><p class="description">留空時用顯示名稱。被指派為負責業務時，會印在報價單上。</p></td>
				</tr>
				<tr>
					<th><label for="ultq_sales_phone">聯絡電話</label></th>
					<td><input type="text" class="regular-text" name="ultq_sales_phone" id="ultq_sales_phone" value="<?php echo esc_attr( get_user_meta( $user->ID, self::META_PHONE, true ) ); ?>" placeholder="例如：02-2345-6789 #12"></td>
				</tr>
			</table>
			<?php
			return;
		}
		$rep = (int) get_user_meta( $user->ID, self::META_REP, true );
		?>
		<h2 id="ultq-sales">專屬業務（終極詢價）</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th><label for="ultq_sales_rep">專屬業務</label></th>
				<td>
					<select name="ultq_sales_rep" id="ultq_sales_rep">
						<option value="0">— 依設定自動分配 —</option>
						<?php foreach ( self::users() as $id => $name ) : ?>
							<option value="<?php echo (int) $id; ?>" <?php selected( $rep, $id ); ?>><?php echo esc_html( $name ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">這位顧客之後送出的詢價都會直接指派給這位業務。</p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function save_profile( $user_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) && get_current_user_id() !== (int) $user_id ) {
			return;
		}
		if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_POST['_ultq_sales_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['_ultq_sales_nonce'] ), 'ultq_sales_profile' ) ) {
			return;
		}
		if ( user_can( $user_id, self::CAPABILITY ) ) {
			foreach ( array( 'ultq_sales_name' => self::META_NAME, 'ultq_sales_phone' => self::META_PHONE ) as $field => $meta ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $field ] ?? '' ) );
				if ( '' === $value ) {
					delete_user_meta( $user_id, $meta );
				} else {
					update_user_meta( $user_id, $meta, $value );
				}
			}
			return;
		}
		$rep = absint( $_POST['ultq_sales_rep'] ?? 0 );
		if ( self::is_sales( $rep ) ) {
			update_user_meta( $user_id, self::META_REP, $rep );
		} else {
			delete_user_meta( $user_id, self::META_REP );
		}
	}
}
