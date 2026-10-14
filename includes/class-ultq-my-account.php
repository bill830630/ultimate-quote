<?php
/**
 * 「我的詢價」WooCommerce My Account 分頁：列表＋明細（已報價者可下載報價單 PDF）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_My_Account {

	const ENDPOINT = 'my-quotes';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'add_menu_item' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'endpoint_title' ), 10, 2 );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

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
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = '我的詢價';
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = '我的詢價';
		}
		return $new;
	}

	public static function endpoint_title( $title, $endpoint = '' ) {
		global $wp;
		$value = $wp->query_vars[ self::ENDPOINT ] ?? '';
		if ( $value && is_numeric( $value ) ) {
			return '詢價單 ' . ULTQ_Quote_Post::get_number( (int) $value );
		}
		return '我的詢價';
	}

	public static function view_url( $post_id ) {
		return wc_get_endpoint_url( self::ENDPOINT, (string) (int) $post_id, wc_get_page_permalink( 'myaccount' ) );
	}

	public static function enqueue() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_style( 'ultq-frontend', ULTQ_PLUGIN_URL . 'assets/css/ultq-frontend.css', array(), ULTQ_VERSION );
		}
	}

	/** 顧客看得到報價金額的條件，見 ULTQ_Quote_Post::is_customer_visible() */
	public static function is_quote_visible( $post_id ) {
		return ULTQ_Quote_Post::is_customer_visible( $post_id );
	}

	public static function render( $value = '' ) {
		$user_id = get_current_user_id();

		if ( $value && is_numeric( $value ) ) {
			$post_id = (int) $value;
			if ( ULTQ_Quote_Post::POST_TYPE !== get_post_type( $post_id ) || ULTQ_Quote_Post::get_user_id( $post_id ) !== $user_id ) {
				echo '<div class="woocommerce-error">找不到這張詢價單。</div>';
				return;
			}
			echo ULTQ_Helper::get_template_html( 'myaccount/quote-view.php', ULTQ_Quote_Response::view_args( $post_id, '', wc_get_account_endpoint_url( self::ENDPOINT ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput
			return;
		}

		$paged = max( 1, absint( $_GET['quote-page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$query = ULTQ_Quote_Post::query_by_user( $user_id, $paged );
		$rows  = array();
		foreach ( $query->posts as $post ) {
			$visible = self::is_quote_visible( $post->ID );
			$rows[]  = array(
				'number' => ULTQ_Quote_Post::get_number( $post->ID ),
				'date'   => get_the_date( 'Y-m-d', $post ),
				'status' => ULTQ_Quote_Post::status_label( $post->post_status ),
				'count'  => count( ULTQ_Quote_Post::get_items( $post->ID ) ),
				'total'  => $visible ? ULTQ_Quote_Post::sent( $post->ID )['totals']['total'] : null,
				'url'    => self::view_url( $post->ID ),
				'requote' => ULTQ_Quote_Response::requote_url( $post->ID ),
			);
		}
		echo ULTQ_Helper::get_template_html( 'myaccount/quotes.php', array( // phpcs:ignore WordPress.Security.EscapeOutput
			'rows'     => $rows,
			'paged'    => $paged,
			'max_page' => (int) $query->max_num_pages,
			'list_url' => ULTQ_List::page_url(),
		) );
	}
}
