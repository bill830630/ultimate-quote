<?php
/**
 * 新詢價通知（管理員，HTML）
 *
 * @var string                $email_heading
 * @var string                $additional_content
 * @var int                   $quote_id
 * @var ULTQ_Email_New_Quote  $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>收到一筆新的詢價，單號 <strong><?php echo esc_html( ULTQ_Quote_Post::get_number( $quote_id ) ); ?></strong>。</p>

<h2>詢價品項</h2>
<?php ULTQ_Helper::email_partial( 'emails/quote-items.php', array( 'quote_id' => $quote_id, 'show_prices' => false ) ); ?>

<h2>顧客資料</h2>
<?php ULTQ_Helper::email_partial( 'emails/customer-details.php', array( 'quote_id' => $quote_id ) ); ?>

<?php $files = ULTQ_Files::get( $quote_id ); ?>
<?php if ( $files ) : ?>
	<p>顧客上傳了 <?php echo (int) count( $files ); ?> 個附件：<?php echo esc_html( implode( '、', wp_list_pluck( $files, 'name' ) ) ); ?>（請到後台詢價單下載）</p>
<?php endif; ?>
<?php $owner = ULTQ_Sales::get_owner( $quote_id ); ?>
<?php if ( $owner ) : ?>
	<p>負責業務：<?php echo esc_html( ULTQ_Sales::info( $owner )['name'] ?? '' ); ?></p>
<?php endif; ?>

<p><a href="<?php echo esc_url( ULTQ_Quote_Post::admin_edit_url( $quote_id ) ); ?>">前往後台填寫報價</a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
