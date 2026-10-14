<?php
/**
 * 報價單（顧客，HTML，附 PDF）
 *
 * @var string                 $email_heading
 * @var string                 $additional_content
 * @var int                    $quote_id
 * @var string                 $pdf_url
 * @var string                 $view_url  免登入檢視頁（可線上接受／婉拒）
 * @var ULTQ_Email_Quote_Sent  $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$customer = ULTQ_Quote_Post::get_customer( $quote_id );
$quote    = ULTQ_Quote_Post::get_quote( $quote_id );
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo esc_html( sprintf( '%s 您好，', $customer['name'] ) ); ?></p>
<?php $rev = ULTQ_Quote_Post::revision( $quote_id ) + 1; ?>
<?php if ( $rev > 1 ) : ?>
	<p>詢價單 <strong><?php echo esc_html( ULTQ_Quote_Post::get_number( $quote_id ) ); ?></strong> 的報價已更新為<strong>第 <?php echo (int) $rev; ?> 版</strong>，內容如下，請以這一版為準。正式報價單 PDF 已附在這封信中。</p>
<?php else : ?>
	<p>感謝您的詢價，詢價單 <strong><?php echo esc_html( ULTQ_Quote_Post::get_number( $quote_id ) ); ?></strong> 的報價如下，正式報價單 PDF 已附在這封信中。</p>
<?php endif; ?>

<?php ULTQ_Helper::email_partial( 'emails/quote-items.php', array( 'quote_id' => $quote_id, 'show_prices' => true ) ); ?>

<?php if ( $quote['valid_until'] ) : ?>
	<p>報價有效期限：<strong><?php echo esc_html( $quote['valid_until'] ); ?></strong></p>
<?php endif; ?>

<?php if ( $quote['payment_terms'] ) : ?>
	<p>付款條件：<?php echo esc_html( $quote['payment_terms'] ); ?></p>
<?php endif; ?>

<?php if ( $quote['note'] ) : ?>
	<p><?php echo nl2br( esc_html( $quote['note'] ) ); ?></p>
<?php endif; ?>

<?php if ( ULTQ_Settings::get( 'allow_respond' ) ) : ?>
	<p style="margin:24px 0;">
		<a href="<?php echo esc_url( $view_url ); ?>" style="display:inline-block;padding:10px 20px;background:<?php echo esc_attr( get_option( 'woocommerce_email_base_color', '#7f54b3' ) ); ?>;color:#ffffff;text-decoration:none;border-radius:4px;font-weight:bold;">查看並確認報價</a>
	</p>
	<p>內容沒有問題的話，可以直接在網頁上按「接受報價」，我們收到後會與您聯繫出貨與付款。</p>
<?php endif; ?>

<p>如果沒有看到附件，也可以<a href="<?php echo esc_url( $pdf_url ); ?>">點這裡下載報價單 PDF</a><?php if ( ! empty( $xlsx_url ) ) : ?>，需要 Excel 版的話請<a href="<?php echo esc_url( $xlsx_url ); ?>">點這裡下載 Excel</a><?php endif; ?>。</p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
