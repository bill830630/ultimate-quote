<?php
/**
 * B2B 會員審核結果（純文字）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

echo "= " . wp_strip_all_tags( $email_heading ) . " =\n\n";
printf( "%s 您好，\n\n", $data['contact'] ?: $user->display_name );
if ( $approved ) {
	printf( "您的 B2B 會員申請（%s）已核准！\n", $data['company'] );
	echo "登入後瀏覽商品即可看到 B2B 參考價，把需要的商品加入詢價清單送出，我們會提供正式報價。\n\n";
	echo '開始選購：' . $shop_url . "\n";
} else {
	echo "很抱歉，您的 B2B 會員申請這次未通過審核。\n";
	if ( $reason ) {
		echo '原因：' . $reason . "\n";
	}
	echo "\n修正資料後重新申請：" . $apply_url . "\n";
}

if ( $additional_content ) {
	echo "\n" . wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n";
}
echo "\n" . wp_strip_all_tags( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) . "\n";
