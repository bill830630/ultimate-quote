<?php
/**
 * 新詢價通知（純文字）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
$customer = ULTQ_Quote_Post::get_customer( $quote_id );
printf( "收到一筆新的詢價，單號 %s。\n\n", ULTQ_Quote_Post::get_number( $quote_id ) );
echo "【詢價品項】\n" . ULTQ_Helper::plain_items( $quote_id, false ) . "\n";
echo "【顧客資料】\n";
foreach ( array( '聯絡人' => 'name', '公司名稱' => 'company', '統一編號' => 'tax_id', 'Email' => 'email', '電話' => 'phone', '地址' => 'address', '需求說明' => 'message' ) as $label => $key ) {
	if ( '' !== $customer[ $key ] ) {
		echo $label . '：' . $customer[ $key ] . "\n";
	}
}
$files = ULTQ_Files::get( $quote_id );
if ( $files ) {
	echo "\n附件（請到後台下載）：" . implode( '、', wp_list_pluck( $files, 'name' ) ) . "\n";
}
$owner = ULTQ_Sales::get_owner( $quote_id );
if ( $owner ) {
	echo '負責業務：' . ( ULTQ_Sales::info( $owner )['name'] ?? '' ) . "\n";
}
echo "\n前往後台填寫報價：" . ULTQ_Quote_Post::admin_edit_url( $quote_id ) . "\n";

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
echo "\n" . wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n";
