<?php
/**
 * B2B 會員審核結果（顧客，HTML）
 *
 * @var string                 $email_heading
 * @var string                 $additional_content
 * @var WP_User                $user
 * @var bool                   $approved
 * @var string                 $reason
 * @var array                  $data
 * @var string                 $shop_url
 * @var string                 $apply_url
 * @var ULTQ_Email_B2B_Result  $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( '%s 您好，', $data['contact'] ?: $user->display_name ) ); ?></p>

<?php if ( $approved ) : ?>
	<p>您的 B2B 會員申請（<?php echo esc_html( $data['company'] ); ?>）已核准！</p>
	<p>登入後瀏覽商品即可看到 B2B 參考價，把需要的商品加入詢價清單送出，我們會提供正式報價。</p>
	<p><a href="<?php echo esc_url( $shop_url ); ?>">開始選購</a></p>
<?php else : ?>
	<p>很抱歉，您的 B2B 會員申請這次未通過審核。</p>
	<?php if ( $reason ) : ?>
		<p>原因：<?php echo esc_html( $reason ); ?></p>
	<?php endif; ?>
	<p>您可以<a href="<?php echo esc_url( $apply_url ); ?>">修正資料後重新申請</a>，或直接與我們聯繫。</p>
<?php endif; ?>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
