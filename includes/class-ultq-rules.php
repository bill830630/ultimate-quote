<?php
/**
 * 「這個商品對這個人是不是走詢價」的單一判斷入口，前台所有 hook 都只問這裡
 *
 * - 詢價商品 = 全站開關 ｜ 商品勾選 _ultq_quote_only ｜ 屬於設定頁指定的分類；對所有人都不能加入購物車、隱藏一般售價
 * - 只有已核准的 B2B 會員能把詢價商品加入詢價清單、送出詢價（看得到 B2B 參考價）；
 *   訪客與一般會員看得到商品，但加入按鈕換成「B2B 會員專屬」說明（ULTQ_Frontend::b2b_gate_html()）
 * - 一般商品對 B2B 會員和其他人都一樣，照售價直接購買（1.3.8 起；之前 B2B 會員是全站商品都走詢價）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Rules {

	private static $cache = array();

	public static function supported_types() {
		return apply_filters( 'ultq_supported_product_types', array( 'simple', 'variable', 'variation' ) );
	}

	/** 規格一律以主商品判斷 */
	private static function base_product( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}
		if ( $product && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			return $parent ?: $product;
		}
		return $product;
	}

	/** 商品本身是否被設成「僅限詢價」（不看使用者） */
	public static function is_flagged( $product ) {
		$product = self::base_product( $product );
		if ( ! $product || ! in_array( $product->get_type(), self::supported_types(), true ) ) {
			return false;
		}
		$id = $product->get_id();
		if ( isset( self::$cache[ $id ] ) ) {
			return self::$cache[ $id ];
		}

		$flagged = (bool) ULTQ_Settings::get( 'quote_all' )
			|| 'yes' === $product->get_meta( '_ultq_quote_only' );

		if ( ! $flagged ) {
			$cats = array_filter( array_map( 'intval', (array) ULTQ_Settings::get( 'quote_categories', array() ) ) );
			if ( $cats ) {
				$flagged = (bool) array_intersect( $cats, $product->get_category_ids() );
			}
		}

		self::$cache[ $id ] = (bool) apply_filters( 'ultq_product_is_flagged', $flagged, $product );
		return self::$cache[ $id ];
	}

	public static function is_b2b( $user_id = null ) {
		$user_id = null === $user_id ? get_current_user_id() : (int) $user_id;
		return ULTQ_B2B_Member::is_approved( $user_id );
	}

	/** 這個商品是不是詢價商品（不能加入購物車、隱藏一般售價）；能不能詢價另外看 can_request_quote() */
	public static function is_quote_only( $product, $user_id = null ) {
		$base = self::base_product( $product );
		if ( ! $base || ! in_array( $base->get_type(), self::supported_types(), true ) ) {
			return false;
		}
		return (bool) apply_filters( 'ultq_is_quote_only', self::is_flagged( $base ), $base, $user_id );
	}

	/** 目前的人可不可以詢價：只有已核准的 B2B 會員 */
	public static function can_request_quote( $user_id = null ) {
		return (bool) apply_filters( 'ultq_can_request_quote', self::is_b2b( $user_id ), $user_id );
	}
}
