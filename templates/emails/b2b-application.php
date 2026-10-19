<?php
/**
 * 新的 B2B 會員申請（管理員，HTML）
 *
 * @var string                       $email_heading
 * @var string                       $additional_content
 * @var WP_User                      $user
 * @var array                        $data
 * @var bool                         $auto_approve
 * @var string                       $review_url
 * @var ULTQ_Email_B2B_Application   $email
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$cell = 'text-align:left;padding:10px;border:1px solid #e5e5e5;vertical-align:top;';
$rows = array(
	'會員帳號' => $user->user_login . '（' . $user->user_email . '）',
	'採購身分'     => ULTQ_Form::types()[ ULTQ_Form::customer_type( $data ) ],
	'公司名稱' => $data['company'],
	'統一編號' => $data['tax_id'],
	'聯絡人'   => $data['contact'],
	'電話'     => $data['phone'],
	'地址'     => $data['address'],
	'備註'     => $data['note'],
);
do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p><?php echo $auto_approve ? '有會員申請成為 B2B 會員（已依設定自動核准）：' : '有會員申請成為 B2B 會員，請審核：'; ?></p>

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

<p><a href="<?php echo esc_url( $review_url ); ?>">前往後台審核</a></p>

<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}
do_action( 'woocommerce_email_footer', $email );
