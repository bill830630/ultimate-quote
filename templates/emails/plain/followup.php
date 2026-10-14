<?php
/**
 * 報價待追蹤清單（純文字）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
echo "以下報價已寄出一段時間，顧客還沒有接受或婉拒，建議主動聯繫：\n\n";
foreach ( $quote_ids as $id ) {
	$customer = ULTQ_Quote_Post::get_customer( $id );
	$owner    = ULTQ_Sales::get_owner( $id );
	printf(
		"- %s｜%s｜%s｜%d 天前寄出，%s 到期%s\n  %s\n",
		ULTQ_Quote_Post::get_number( $id ),
		$customer['company'] ?: $customer['name'],
		ULTQ_Helper::money( ULTQ_Quote_Post::sent( $id )['totals']['total'] ),
		ULTQ_Cron::days_since_sent( $id ),
		ULTQ_Quote_Post::sent( $id )['quote']['valid_until'],
		$owner ? '｜業務 ' . ( ULTQ_Sales::info( $owner )['name'] ?? '' ) : '',
		ULTQ_Quote_Post::admin_edit_url( $id )
	);
}

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
echo "\n" . wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n";
