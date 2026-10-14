<?php
/**
 * 最低訂購量／包裝倍數（只管詢價流程，不影響一般購買）
 *
 * 商品（可變商品存在主商品上，各規格共用）：
 * - _ultq_min_qty  最低訂購量
 * - _ultq_qty_step 包裝倍數（每次以幾個為單位，例如一箱 24 入）
 * 兩者同時設定時，實際最低量會往上取到倍數（最低 30、倍數 24 → 48）。
 *
 * 加入／修改詢價清單時自動把數量調到合規（往上取），並告訴顧客調成多少；後台報價不受限制。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Quantity {

	public static function init() {
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_fields' ) );

		add_filter( 'woocommerce_quantity_input_args', array( __CLASS__, 'input_args' ), 20, 2 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_data' ), 100, 3 );
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render_notice' ), 12 );
	}

	private static function base( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}
		if ( $product && $product->is_type( 'variation' ) ) {
			return wc_get_product( $product->get_parent_id() ) ?: $product;
		}
		return $product;
	}

	/**
	 * @return array( 'min' => 實際最低量, 'step' => 倍數 )；都沒設定時 min=1 step=1
	 */
	public static function rules( $product ) {
		$product = self::base( $product );
		if ( ! $product ) {
			return array( 'min' => 1, 'step' => 1 );
		}
		$step = max( 1, (int) $product->get_meta( '_ultq_qty_step' ) );
		$min  = max( 1, (int) $product->get_meta( '_ultq_min_qty' ), $step );
		$min  = (int) ( ceil( $min / $step ) * $step );
		return (array) apply_filters( 'ultq_qty_rules', array( 'min' => $min, 'step' => $step ), $product );
	}

	public static function has_rules( $product ) {
		$rules = self::rules( $product );
		return $rules['min'] > 1 || $rules['step'] > 1;
	}

	/** 往上取到合規的數量 */
	public static function fix( $product, $qty ) {
		$rules = self::rules( $product );
		$qty   = max( (int) $qty, $rules['min'] );
		return (int) ( ceil( $qty / $rules['step'] ) * $rules['step'] );
	}

	/** 「最低訂購量 48，以 24 為一單位」 */
	public static function describe( $product ) {
		$rules = self::rules( $product );
		$parts = array();
		if ( $rules['min'] > 1 ) {
			$parts[] = '最低訂購量 ' . $rules['min'];
		}
		if ( $rules['step'] > 1 ) {
			$parts[] = '以 ' . $rules['step'] . ' 為一單位';
		}
		return implode( '，', $parts );
	}

	// ─── 後台商品欄位 ───────────────────────────────────────────────────────

	/** 商品資料「詢價／B2B」頁籤裡的欄位（ULTQ_B2B_Pricing::render_panel() 呼叫） */
	public static function render_fields( $product_object ) {
		woocommerce_wp_text_input( array(
			'id'                => '_ultq_min_qty',
			'label'             => '詢價最低訂購量',
			'type'              => 'number',
			'value'             => $product_object->get_meta( '_ultq_min_qty' ),
			'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			'desc_tip'          => true,
			'description'       => '顧客加入詢價清單的數量至少要多少，不足會自動調高。只影響詢價，不影響一般購買。',
		) );
		woocommerce_wp_text_input( array(
			'id'                => '_ultq_qty_step',
			'label'             => '詢價包裝倍數',
			'type'              => 'number',
			'value'             => $product_object->get_meta( '_ultq_qty_step' ),
			'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
			'desc_tip'          => true,
			'description'       => '數量必須是這個數字的倍數，例如一箱 24 入就填 24。可變商品的各規格共用這個設定。',
		) );
	}

	public static function save_fields( $product ) {
		foreach ( array( '_ultq_min_qty', '_ultq_qty_step' ) as $key ) {
			$value = absint( wp_unslash( $_POST[ $key ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $value > 1 ) {
				$product->update_meta_data( $key, $value );
			} else {
				$product->delete_meta_data( $key );
			}
		}
	}

	// ─── 前台 ──────────────────────────────────────────────────────────────

	public static function input_args( $args, $product ) {
		if ( ! $product || ! ULTQ_Rules::is_quote_only( $product ) || ! self::has_rules( $product ) ) {
			return $args;
		}
		$rules             = self::rules( $product );
		$args['min_value'] = $rules['min'];
		$args['step']      = $rules['step'];
		if ( (int) $args['input_value'] < $rules['min'] ) {
			$args['input_value'] = $rules['min'];
		}
		return $args;
	}

	public static function variation_data( $data, $product, $variation ) {
		if ( ULTQ_Rules::is_quote_only( $variation ) && self::has_rules( $product ) ) {
			$data['min_qty'] = self::rules( $product )['min'];
		}
		return $data;
	}

	public static function render_notice() {
		global $product;
		if ( ! $product || ! ULTQ_Rules::is_quote_only( $product ) || ! self::has_rules( $product ) ) {
			return;
		}
		echo '<p class="ultq-moq">' . esc_html( self::describe( $product ) ) . '</p>';
	}
}
