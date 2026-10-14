<?php
/**
 * B2B 專屬價／階梯價（只當參考價：前台顯示給 B2B 會員看、後台報價預填，不會用來結帳）
 *
 * meta（簡單商品存在商品上，可變商品存在各規格上）：
 * - _ultq_b2b_price  B2B 單價
 * - _ultq_b2b_tiers  階梯價 array( array( 'min' => 10, 'price' => 90 ), ... )，依 min 由小到大
 * 商品層另有 _ultq_quote_only（yes/no）「僅限詢價」勾選，在商品類型選項列（虛擬／可下載旁）。
 * 後台欄位全部在商品資料的「詢價／B2B」頁籤（可變商品的各規格也列在這裡，不放在規格面板），只有詢價商品才顯示。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_B2B_Pricing {

	public static function init() {
		add_filter( 'product_type_options', array( __CLASS__, 'product_type_option' ) );
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function product_type_option( $options ) {
		$options['ultq_quote_only'] = array(
			'id'            => '_ultq_quote_only',
			'wrapper_class' => 'show_if_simple show_if_variable',
			'label'         => '僅限詢價',
			'description'   => '勾選後對一般顧客隱藏價格，改為「加入詢價清單」。',
			'default'       => 'no',
		);
		return $options;
	}

	public static function enqueue( $hook ) {
		global $post;
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) || ! $post || 'product' !== $post->post_type ) {
			return;
		}
		wp_enqueue_script( 'ultq-admin-product', ULTQ_PLUGIN_URL . 'assets/js/ultq-admin-product.js', array( 'jquery' ), ULTQ_VERSION, true );
		// 「詢價／B2B」頁籤只在詢價商品顯示：判斷條件同 ULTQ_Rules::is_flagged()，交給 JS 依畫面上的勾選即時切換
		wp_localize_script( 'ultq-admin-product', 'ultqProduct', array(
			'quoteAll'   => (bool) ULTQ_Settings::get( 'quote_all' ),
			'categories' => array_values( array_filter( array_map( 'intval', (array) ULTQ_Settings::get( 'quote_categories', array() ) ) ) ),
		) );
		wp_enqueue_style( 'ultq-admin', ULTQ_PLUGIN_URL . 'assets/css/ultq-admin.css', array(), ULTQ_VERSION );
	}

	// ─── 後台「詢價／B2B」頁籤 ──────────────────────────────────────────────

	/**
	 * 不加 show_if_* class：顯示與否完全由 ultq-admin-product.js 決定（商品類型＋是否為詢價商品），
	 * 免得 WooCommerce 切換商品類型時又把它打開
	 */
	public static function add_tab( $tabs ) {
		$tabs['ultq'] = array(
			'label'    => '詢價／B2B',
			'target'   => 'ultq_product_data',
			'class'    => array(),
			'priority' => 65,
		);
		return $tabs;
	}

	public static function render_panel() {
		global $product_object;
		if ( ! $product_object ) {
			return;
		}
		echo '<div id="ultq_product_data" class="panel woocommerce_options_panel hidden">';

		echo '<div class="options_group">';
		ULTQ_Quantity::render_fields( $product_object );
		echo '</div>';

		// 簡單商品：B2B 價＋階梯價
		echo '<div class="options_group show_if_simple">';
		woocommerce_wp_text_input( array(
			'id'          => '_ultq_b2b_price',
			'label'       => 'B2B 價 (' . get_woocommerce_currency_symbol() . ')',
			'data_type'   => 'price',
			'value'       => $product_object->get_meta( '_ultq_b2b_price' ),
			'desc_tip'    => true,
			'description' => 'B2B 會員看到的參考單價，報價時預設帶入。B2B 會員一律走詢價，不會用這個價格結帳。',
		) );
		self::render_tiers_field( '_ultq_b2b_tiers', self::get_tiers( $product_object ) );
		echo '</div>';

		// 可變商品：每個規格各自的 B2B 價＋階梯價
		echo '<div class="show_if_variable">';
		$children = $product_object->is_type( 'variable' ) ? $product_object->get_children() : array();
		if ( ! $children ) {
			echo '<div class="options_group"><p class="ultq-panel-note">先在「變化類型」建立規格並儲存商品，這裡就會列出每個規格的 B2B 價與階梯價。</p></div>';
		}
		foreach ( $children as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			$name = 'ultq_vars[' . $child_id . ']';
			echo '<div class="options_group ultq-variation-group">';
			printf(
				'<p class="ultq-variation-group__title"><strong>%s</strong>%s</p>',
				esc_html( wc_get_formatted_variation( $child, true, false, false ) ?: '#' . $child_id ),
				$child->get_sku() ? ' <span class="description">' . esc_html( $child->get_sku() ) . '</span>' : ''
			);
			woocommerce_wp_text_input( array(
				'id'        => 'ultq_vars_' . $child_id . '_price',
				'name'      => $name . '[price]',
				'label'     => 'B2B 價 (' . get_woocommerce_currency_symbol() . ')',
				'data_type' => 'price',
				'value'     => $child->get_meta( '_ultq_b2b_price' ),
			) );
			self::render_tiers_field( $name . '[tiers]', self::get_tiers( $child ) );
			echo '</div>';
		}
		if ( $children ) {
			echo '<p class="ultq-panel-note">新增的規格要先儲存商品才會出現在這裡。</p>';
		}
		echo '</div>';

		echo '</div>';
	}

	/**
	 * fieldset.form-field：WooCommerce 只給 p／fieldset 的 .form-field 留 162px 左側空間放浮動的欄位名稱。
	 * 名稱要用 <legend>：WooCommerce 的 .options_group fieldset label 會取消 label 的浮動（給單選鈕文字用），名稱會掉到下面
	 */
	private static function render_tiers_field( $name, array $tiers ) {
		?>
		<fieldset class="form-field ultq-tiers" data-name="<?php echo esc_attr( $name ); ?>">
			<legend>B2B 階梯價</legend>
			<div class="ultq-tiers__body">
				<table class="ultq-tiers__table">
					<thead><tr><th>最低數量</th><th>單價</th><th></th></tr></thead>
					<tbody>
						<?php foreach ( $tiers as $tier ) : ?>
							<tr>
								<td><input type="number" min="1" step="1" name="<?php echo esc_attr( $name ); ?>[min][]" value="<?php echo esc_attr( $tier['min'] ); ?>"></td>
								<td><input type="text" class="wc_input_price" name="<?php echo esc_attr( $name ); ?>[price][]" value="<?php echo esc_attr( wc_format_localized_price( $tier['price'] ) ); ?>"></td>
								<td><button type="button" class="button-link ultq-tiers__remove" aria-label="移除">&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<button type="button" class="button ultq-tiers__add">新增階梯</button>
				<p class="description">購買數量達到「最低數量」時改用該單價，例如 10 件以上每件 90 元。</p>
			</div>
		</fieldset>
		<?php
	}

	public static function save_product( $product ) {
		$product->update_meta_data( '_ultq_quote_only', isset( $_POST['_ultq_quote_only'] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification

		if ( $product->is_type( 'simple' ) ) {
			// 頁籤沒輸出時（例如從別的類型切過來）不要把既有資料洗掉
			if ( isset( $_POST['_ultq_b2b_price'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
				$price = wc_format_decimal( wp_unslash( $_POST['_ultq_b2b_price'] ) ); // phpcs:ignore
				self::store( $product, $price, wp_unslash( $_POST['_ultq_b2b_tiers'] ?? array() ) ); // phpcs:ignore
			}
			return;
		}

		if ( $product->is_type( 'variable' ) && isset( $_POST['ultq_vars'] ) && is_array( $_POST['ultq_vars'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$children = array_map( 'intval', $product->get_children() );
			foreach ( wp_unslash( $_POST['ultq_vars'] ) as $variation_id => $raw ) { // phpcs:ignore
				$variation_id = (int) $variation_id;
				$variation    = in_array( $variation_id, $children, true ) ? wc_get_product( $variation_id ) : null;
				if ( ! $variation || ! is_array( $raw ) ) {
					continue;
				}
				self::store( $variation, wc_format_decimal( $raw['price'] ?? '' ), $raw['tiers'] ?? array() );
				$variation->save();
			}
		}
	}

	private static function store( $product, $price, $raw_tiers ) {
		if ( '' === $price || null === $price ) {
			$product->delete_meta_data( '_ultq_b2b_price' );
		} else {
			$product->update_meta_data( '_ultq_b2b_price', $price );
		}

		$tiers = array();
		if ( is_array( $raw_tiers ) && isset( $raw_tiers['min'], $raw_tiers['price'] ) ) {
			$mins   = (array) $raw_tiers['min'];
			$prices = (array) $raw_tiers['price'];
			foreach ( $mins as $idx => $min ) {
				$min = absint( $min );
				$p   = wc_format_decimal( $prices[ $idx ] ?? '' );
				if ( $min < 1 || '' === $p ) {
					continue;
				}
				$tiers[ $min ] = array( 'min' => $min, 'price' => (float) $p );
			}
		}
		ksort( $tiers );
		if ( $tiers ) {
			$product->update_meta_data( '_ultq_b2b_tiers', array_values( $tiers ) );
		} else {
			$product->delete_meta_data( '_ultq_b2b_tiers' );
		}
	}

	// ─── 取價 ──────────────────────────────────────────────────────────────

	public static function get_tiers( $product ) {
		$tiers = $product->get_meta( '_ultq_b2b_tiers' );
		return is_array( $tiers ) ? $tiers : array();
	}

	/**
	 * B2B 參考單價：階梯價（數量達標的最高門檻）→ B2B 價 → 一般售價套全站折扣 → null
	 *
	 * @param WC_Product $product 簡單商品或規格（可變商品主體請用 get_b2b_price_range()）
	 */
	public static function get_b2b_price( $product, $qty = 1 ) {
		$price = null;

		$base = $product->get_meta( '_ultq_b2b_price' );
		if ( '' !== $base && null !== $base ) {
			$price = (float) $base;
		}

		foreach ( self::get_tiers( $product ) as $tier ) {
			if ( $qty >= (int) $tier['min'] ) {
				$price = (float) $tier['price'];
			}
		}

		if ( null === $price ) {
			$discount = (float) ULTQ_Settings::get( 'b2b_discount', 0 );
			$regular  = $product->get_regular_price();
			if ( $discount > 0 && '' !== $regular ) {
				$price = round( (float) $regular * ( 100 - $discount ) / 100, wc_get_price_decimals() );
			}
		}

		return apply_filters( 'ultq_b2b_price', $price, $product, $qty );
	}

	/**
	 * 可變商品各規格 B2B 參考價的最低／最高（數量 1 起算）。沒有任何規格有 B2B 價時回傳 null
	 */
	public static function get_b2b_price_range( $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			$price = self::get_b2b_price( $product );
			return null === $price ? null : array( $price, $price );
		}
		$prices = array();
		foreach ( $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( ! $child ) {
				continue;
			}
			// 取「最低可能價格」：B2B 價與階梯價中最便宜的那個，顯示成「起」
			$price = self::get_b2b_price( $child );
			foreach ( self::get_tiers( $child ) as $tier ) {
				$price = null === $price ? (float) $tier['price'] : min( $price, (float) $tier['price'] );
			}
			if ( null !== $price ) {
				$prices[] = $price;
			}
		}
		return $prices ? array( min( $prices ), max( $prices ) ) : null;
	}

	/**
	 * 給前台階梯價表用：基準價（1 件）＋各門檻
	 */
	public static function get_tier_rows( $product ) {
		$tiers = self::get_tiers( $product );
		if ( ! $tiers ) {
			return array();
		}
		$rows = array();
		$base = self::get_b2b_price( $product, 1 );
		$first_min = (int) $tiers[0]['min'];
		if ( null !== $base && $first_min > 1 ) {
			$rows[] = array( 'from' => 1, 'to' => $first_min - 1, 'price' => $base );
		}
		foreach ( $tiers as $idx => $tier ) {
			$next   = $tiers[ $idx + 1 ] ?? null;
			$rows[] = array(
				'from'  => (int) $tier['min'],
				'to'    => $next ? (int) $next['min'] - 1 : 0,
				'price' => (float) $tier['price'],
			);
		}
		return $rows;
	}
}
