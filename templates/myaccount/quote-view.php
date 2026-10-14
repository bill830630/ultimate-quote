<?php
/**
 * 報價檢視：我的帳戶 → 我的詢價 → 明細，也用在免登入檢視頁（詢價清單頁 ?ultq_view=ID&token=）
 *
 * @var int    $post_id
 * @var string $number
 * @var string $status
 * @var string $date
 * @var array  $customer
 * @var array  $items
 * @var array  $quote
 * @var array  $totals
 * @var bool   $visible   是否已報價（顯示金額）
 * @var string $pdf_url
 * @var string $back_url  空字串時不顯示「回到我的詢價」（免登入檢視頁）
 * @var string $respond   線上回覆區塊 HTML（templates/quote-respond.php）
 * @var int    $revision  目前報價版本（第 2 版起標示）
 * @var string $requote   「再次詢價」連結
 * @var string $xlsx_url  Excel 報價單下載連結（空字串＝不提供）
 * @var array  $sales     負責業務 name/phone/email（沒有指派時是空陣列）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$respond  = $respond ?? '';
$revision = (int) ( $revision ?? 0 );
$requote  = $requote ?? '';
$xlsx_url = $xlsx_url ?? '';
$sales    = $sales ?? array();
?>
<p>
	詢價單 <mark class="order-number"><?php echo esc_html( $number ); ?></mark>
	於 <mark class="order-date"><?php echo esc_html( $date ); ?></mark> 送出，目前狀態為 <mark class="order-status"><?php echo esc_html( $status ); ?></mark>。
</p>

<?php if ( $visible ) : ?>
	<div class="ultq-box">
		<div class="ultq-box__title">報價已送達<?php echo $revision > 1 ? esc_html( '（第 ' . $revision . ' 版）' ) : ''; ?></div>
		<p class="ultq-box__text">
			<?php if ( $quote['valid_until'] ) : ?>
				報價有效期限至 <?php echo esc_html( $quote['valid_until'] ); ?>。
			<?php endif; ?>
			<?php if ( ! $respond || ! trim( wp_strip_all_tags( $respond ) ) ) : ?>
				如需下單請與我們聯繫。
			<?php endif; ?>
		</p>
		<p class="ultq-box__actions">
			<a class="button wp-element-button" href="<?php echo esc_url( $pdf_url ); ?>" target="_blank" rel="noopener">下載報價單 PDF</a>
			<?php if ( $xlsx_url ) : ?>
				<a class="button wp-element-button" href="<?php echo esc_url( $xlsx_url ); ?>">下載 Excel</a>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $sales['name'] ) ) : ?>
			<p class="ultq-box__text">業務窗口：<?php echo esc_html( implode( '　', array_filter( array( $sales['name'], $sales['phone'], $sales['email'] ) ) ) ); ?></p>
		<?php endif; ?>
	</div>
<?php else : ?>
	<div class="ultq-box ultq-box--muted">
		<div class="ultq-box__title">報價準備中</div>
		<p class="ultq-box__text">報價完成後會以 Email 通知您。</p>
	</div>
<?php endif; ?>

<table class="woocommerce-table shop_table ultq-quote-view">
	<thead>
		<tr>
			<th>商品</th>
			<th>數量</th>
			<?php if ( $visible ) : ?>
				<th>單價</th>
				<th>小計</th>
			<?php endif; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $item ) : ?>
			<tr>
				<td>
					<?php echo esc_html( $item['name'] ); ?>
					<?php if ( $item['attributes'] ) : ?>
						<div class="ultq-muted"><?php echo esc_html( implode( '、', array_map( function ( $label, $value ) { return $label . '：' . $value; }, array_keys( $item['attributes'] ), $item['attributes'] ) ) ); ?></div>
					<?php endif; ?>
					<?php if ( $visible && $item['discount'] > 0 ) : ?>
						<div class="ultq-muted"><?php echo esc_html( ULTQ_Quote_Post::discount_label( $item['discount'] ) ); ?></div>
					<?php endif; ?>
					<?php if ( $visible && $item['line_note'] ) : ?>
						<div class="ultq-muted"><?php echo esc_html( $item['line_note'] ); ?></div>
					<?php elseif ( ! $visible && $item['customer_note'] ) : ?>
						<div class="ultq-muted"><?php echo esc_html( $item['customer_note'] ); ?></div>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( ULTQ_Helper::qty( $visible ? $item['qty'] : $item['request_qty'] ) . ( $visible && $item['unit'] && in_array( 'col_unit', ULTQ_PDF::enabled( 'items' ), true ) ? ' ' . $item['unit'] : '' ) ); ?></td>
				<?php if ( $visible ) : ?>
					<td><?php echo wp_kses_post( wc_price( (float) $item['unit_price'] ) ); ?></td>
					<td><?php echo wp_kses_post( wc_price( (float) ULTQ_Quote_Post::line_total( $item ) ) ); ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
	<?php if ( $visible ) : ?>
		<tfoot>
			<tr><th colspan="3">小計</th><td><?php echo wp_kses_post( wc_price( $totals['subtotal'] ) ); ?></td></tr>
			<?php foreach ( $totals['discounts'] as $row ) : ?>
				<tr><th colspan="3"><?php echo esc_html( $row['label'] ); ?></th><td>−<?php echo wp_kses_post( wc_price( $row['amount'] ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( $totals['shipping'] ) : ?>
				<tr><th colspan="3">運費</th><td><?php echo wp_kses_post( wc_price( $totals['shipping'] ) ); ?></td></tr>
			<?php endif; ?>
			<?php if ( 'none' !== $quote['tax_mode'] ) : ?>
				<tr><th colspan="3"><?php echo 'incl' === $quote['tax_mode'] ? '內含營業稅' : '營業稅'; ?></th><td><?php echo wp_kses_post( wc_price( $totals['tax'] ) ); ?></td></tr>
			<?php endif; ?>
			<tr><th colspan="3">總計</th><td><strong><?php echo wp_kses_post( wc_price( $totals['total'] ) ); ?></strong></td></tr>
		</tfoot>
	<?php endif; ?>
</table>

<?php if ( $visible ) : ?>
	<?php
	$terms = array();
	if ( $quote['payment_terms'] ) {
		$terms[] = array( '付款條件', $quote['payment_terms'] );
	}
	foreach ( ULTQ_Settings::custom_fields() as $field ) {
		$value = trim( (string) ( $quote['fields'][ $field['id'] ] ?? $field['default'] ) );
		if ( '' !== $value ) {
			$terms[] = array( $field['label'], $value );
		}
	}
	?>
	<?php if ( $terms ) : ?>
		<table class="woocommerce-table shop_table ultq-quote-terms">
			<tbody>
				<?php foreach ( $terms as $row ) : ?>
					<tr><th><?php echo esc_html( $row[0] ); ?></th><td><?php echo esc_html( $row[1] ); ?></td></tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
<?php endif; ?>

<?php if ( $visible && $quote['note'] ) : ?>
	<h3>報價說明</h3>
	<p><?php echo nl2br( esc_html( $quote['note'] ) ); ?></p>
<?php endif; ?>

<?php echo $respond; // phpcs:ignore WordPress.Security.EscapeOutput -- 已在 quote-respond.php 逐項跳脫 ?>

<?php if ( $customer['message'] ) : ?>
	<h3>您的需求說明</h3>
	<p><?php echo nl2br( esc_html( $customer['message'] ) ); ?></p>
<?php endif; ?>

<p class="ultq-view-actions">
	<?php if ( $requote ) : ?>
		<a class="button wp-element-button" href="<?php echo esc_url( $requote ); ?>">再次詢價</a>
	<?php endif; ?>
	<?php if ( $back_url ) : ?>
		<a class="button wp-element-button" href="<?php echo esc_url( $back_url ); ?>">回到我的詢價</a>
	<?php endif; ?>
</p>
