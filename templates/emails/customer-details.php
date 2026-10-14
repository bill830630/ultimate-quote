<?php
/**
 * Email 共用：詢價顧客資料
 *
 * @var int $quote_id
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$customer = ULTQ_Quote_Post::get_customer( $quote_id );
$rows     = array(
	'聯絡人'   => $customer['name'],
	'公司名稱' => $customer['company'],
	'統一編號' => $customer['tax_id'],
	'Email'    => $customer['email'],
	'電話'     => $customer['phone'],
	'地址'     => $customer['address'],
	'需求說明' => $customer['message'],
);
$cell = 'text-align:left;padding:10px;border:1px solid #e5e5e5;vertical-align:top;';
?>
<table cellspacing="0" cellpadding="6" border="1" style="border-collapse:collapse;border:1px solid #e5e5e5;width:100%;margin-bottom:24px;">
	<?php foreach ( $rows as $label => $value ) : ?>
		<?php if ( '' !== (string) $value ) : ?>
			<tr>
				<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;width:30%;"><?php echo esc_html( $label ); ?></th>
				<td style="<?php echo esc_attr( $cell ); ?>"><?php echo nl2br( esc_html( $value ) ); ?></td>
			</tr>
		<?php endif; ?>
	<?php endforeach; ?>
</table>
