<?php
/**
 * 我的帳戶 → B2B 會員
 *
 * @var string $status  ''／pending／approved／rejected
 * @var array  $data    公司資料
 * @var string $reason  未通過原因
 * @var string $intro   申請說明文字
 * @var array  $labels  狀態文字
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( 'approved' === $status ) : ?>
	<div class="ultq-box">
		<div class="ultq-box__title">您是 B2B 會員</div>
		<p class="ultq-box__text">瀏覽商品時可以看到 B2B 參考價，把需要的商品加入詢價清單送出即可取得正式報價。</p>
	</div>
	<?php $show_summary = true; ?>
<?php elseif ( 'pending' === $status ) : ?>
	<div class="ultq-box ultq-box--muted">
		<div class="ultq-box__title">申請審核中</div>
		<p class="ultq-box__text">我們已收到您的 B2B 會員申請，審核完成後會以 Email 通知您。</p>
	</div>
	<?php $show_summary = true; ?>
<?php else : ?>
	<?php $show_summary = false; ?>
	<?php if ( 'rejected' === $status ) : ?>
		<div class="ultq-box ultq-box--muted">
			<div class="ultq-box__title">先前的申請未通過</div>
			<?php if ( $reason ) : ?>
				<p class="ultq-box__text">原因：<?php echo esc_html( $reason ); ?></p>
			<?php endif; ?>
			<p class="ultq-box__text">您可以修正資料後重新送出申請。</p>
		</div>
	<?php elseif ( $intro ) : ?>
		<p><?php echo nl2br( esc_html( $intro ) ); ?></p>
	<?php endif; ?>

	<form method="post" class="ultq-b2b-form">
		<?php wp_nonce_field( 'ultq_b2b_apply', '_ultq_nonce' ); ?>
		<input type="hidden" name="ultq_b2b_apply" value="1">
		<?php echo ULTQ_Helper::get_template_html( 'customer-fields.php', array( 'scope' => 'b2b', 'data' => $data ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<p>
			<button type="submit" class="woocommerce-Button button wp-element-button">送出申請</button>
		</p>
	</form>
<?php endif; ?>

<?php if ( $show_summary ) : ?>
	<table class="shop_table shop_table_responsive ultq-b2b-summary">
		<tbody>
			<tr><th>狀態</th><td><?php echo esc_html( $labels[ $status ] ?? $status ); ?></td></tr>
			<tr><th>採購身分</th><td><?php echo esc_html( ULTQ_Form::types()[ ULTQ_Form::customer_type( $data ) ] ); ?></td></tr>
			<?php if ( $data['company'] ) : ?>
			<tr><th>公司名稱</th><td><?php echo esc_html( $data['company'] ); ?></td></tr>
			<?php endif; ?>
			<?php if ( $data['tax_id'] ) : ?>
			<tr><th>統一編號</th><td><?php echo esc_html( $data['tax_id'] ); ?></td></tr>
			<?php endif; ?>
			<tr><th>聯絡人</th><td><?php echo esc_html( $data['contact'] ); ?></td></tr>
			<tr><th>聯絡電話</th><td><?php echo esc_html( $data['phone'] ); ?></td></tr>
			<?php if ( $data['address'] ) : ?>
				<tr><th>地址</th><td><?php echo esc_html( $data['address'] ); ?></td></tr>
			<?php endif; ?>
		</tbody>
	</table>
	<p class="ultq-muted">資料如需修改，請與我們聯繫。</p>
<?php endif; ?>
