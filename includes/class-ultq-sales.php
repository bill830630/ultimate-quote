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

	public static function init() {
		$type = ULTQ_Quote_Post::POST_TYPE;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'column' ), 20 );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column_value' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'filter_query' ) );

		add_action( 'show_user_profile', array( __CLASS__, 'render_profile' ), 20 );
		add_action( 'edit_user_profile', array( __CLASS__, 'render_profile' ), 20 );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	// ─── 名單 ──────────────────────────────────────────────────────────────

	/** @return array user ID => 顯示名稱 */
	public static function users() {
		static $users = null;
		if ( null === $users ) {
			$users = array();
			foreach ( get_users( array( 'capability__in' => array( 'manage_woocommerce', self::CAPABILITY ), 'orderby' => 'display_name', 'fields' => array( 'ID', 'display_name' ) ) ) as $user ) {
				$users[ (int) $user->ID ] = $user->display_name;
			}
			$users = (array) apply_filters( 'ultq_sales_users', $users );
		}
		return $users;
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

	// ─── 後台列表 ──────────────────────────────────────────────────────────

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
		if ( ! is_admin() || ! $query->is_main_query() || ULTQ_Quote_Post::POST_TYPE !== $query->get( 'post_type' ) || empty( $_GET['ultq_owner'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$value = sanitize_key( wp_unslash( $_GET['ultq_owner'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'none' === $value ) {
			$meta = array( 'key' => self::META_OWNER, 'compare' => 'NOT EXISTS' );
		} else {
			$meta = array( 'key' => self::META_OWNER, 'value' => 'mine' === $value ? get_current_user_id() : absint( $value ) );
		}
		$query->set( 'meta_query', array( $meta ) );
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
