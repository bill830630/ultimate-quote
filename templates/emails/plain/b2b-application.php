<?php
/**
 * 新的 B2B 會員申請（純文字）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
echo ( $auto_approve ? '有會員申請成為 B2B 會員（已依設定自動核准）：' : '有會員申請成為 B2B 會員，請審核：' ) . "\n\n";
echo '會員帳號：' . $user->user_login . '（' . $user->user_email . "）\n";
echo '採購身分：' . ULTQ_Form::types()[ ULTQ_Form::customer_type( $data ) ] . "\n";
foreach ( array( '公司名稱' => 'company', '統一編號' => 'tax_id', '聯絡人' => 'contact', '電話' => 'phone', '地址' => 'address', '備註' => 'note' ) as $label => $key ) {
	if ( '' !== $data[ $key ] ) {
		echo $label . '：' . $data[ $key ] . "\n";
	}
}
echo "\n前往後台審核：" . $review_url . "\n";

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
echo "\n" . wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n";
