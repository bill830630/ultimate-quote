<?php
/**
 * Email 共用：詢價品項表
 *
 * @var int  $quote_id
 * @var bool $show_prices 是否顯示報價金額（報價單信才顯示）
 * @var bool $sent        用最後寄出的版本（到期提醒等寄出之後的信）；寄出報價當下的信用目前內容（快照在寄完才存）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! empty( $sent ) ) {
	$data   = ULTQ_Quote_Post::sent( $quote_id );
	$items  = $data['items'];
	$quote  = $data['quote'];
	$totals = $data['totals'];
} else {
	$items  = ULTQ_Quote_Post::get_items( $quote_id );
	$quote  = ULTQ_Quote_Post::get_quote( $quote_id );
	$totals = ULTQ_Quote_Post::totals( $quote_id, $items, $quote );
}
$cell   = 'text-align:left;padding:10px;border:1px solid #e5e5e5;vertical-align:top;';
?>
<table cellspacing="0" cellpadding="6" border="1" style="border-collapse:collapse;border:1px solid #e5e5e5;width:100%;margin-bottom:24px;">
	<thead>
		<tr>
			<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">商品</th>
			<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">數量</th>
			<?php if ( $show_prices ) : ?>
				<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">單價</th>
				<th style="<?php echo esc_attr( $cell ); ?>background:#f8f8f8;">小計</th>
			<?php endif; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $item ) : ?>
			<tr>
				<td style="<?php echo esc_attr( $cell ); ?>">
					<?php echo esc_html( $item['name'] ); ?>
					<?php
					$meta = array();
					if ( $item['sku'] ) {
						$meta[] = '貨號 ' . $item['sku'];
					}
					foreach ( $item['attributes'] as $label => $value ) {
						$meta[] = $label . '：' . $value;
					}
					$note = $show_prices ? $item['line_note'] : $item['customer_note'];
					if ( $note ) {
						$meta[] = $note;
					}
					if ( $show_prices && $item['discount'] > 0 ) {
						$meta[] = ULTQ_Quote_Post::discount_label( $item['discount'] );
					}
					if ( $meta ) {
						echo '<br><small style="color:#777;">' . esc_html( implode( '｜', $meta ) ) . '</small>';
					}
					?>
				</td>
				<td style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( ULTQ_Helper::qty( $show_prices ? $item['qty'] : $item['request_qty'] ) ); ?></td>
				<?php if ( $show_prices ) : ?>
					<td style="<?php echo esc_attr( $cell ); ?>"><?php echo wp_kses_post( wc_price( (float) $item['unit_price'] ) ); ?></td>
					<td style="<?php echo esc_attr( $cell ); ?>"><?php echo wp_kses_post( wc_price( (float) ULTQ_Quote_Post::line_total( $item ) ) ); ?></td>
				<?php endif; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
	<?php if ( $show_prices ) : ?>
		<tfoot>
			<tr><th colspan="3" style="<?php echo esc_attr( $cell ); ?>">小計</th><td style="<?php echo esc_attr( $cell ); ?>"><?php echo wp_kses_post( wc_price( $totals['subtotal'] ) ); ?></td></tr>
			<?php foreach ( $totals['discounts'] as $row ) : ?>
				<tr><th colspan="3" style="<?php echo esc_attr( $cell ); ?>"><?php echo esc_html( $row['label'] ); ?></th><td style="<?php echo esc_attr( $cell ); ?>">−<?php echo wp_kses_post( wc_price( $row['amount'] ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php if ( $totals['shipping'] ) : ?>
				<tr><th colspan="3" style="<?php echo esc_attr( $cell ); ?>">運費</th><td style="<?php echo esc_attr( $cell ); ?>"><?php echo wp_kses_post( wc_price( $totals['shipping'] ) ); ?></td></tr>
			<?php endif; ?>
			<?php if ( 'none' !== $quote['tax_mode'] ) : ?>
				<tr><th colspan="3" style="<?php echo esc_attr( $cell ); ?>"><?php echo 'incl' === $quote['tax_mode'] ? '內含營業稅' : '營業稅'; ?></th><td style="<?php echo esc_attr( $cell ); ?>"><?php echo wp_kses_post( wc_price( $totals['tax'] ) ); ?></td></tr>
			<?php endif; ?>
			<tr><th colspan="3" style="<?php echo esc_attr( $cell ); ?>">總計</th><td style="<?php echo esc_attr( $cell ); ?>"><strong><?php echo wp_kses_post( wc_price( $totals['total'] ) ); ?></strong></td></tr>
		</tfoot>
	<?php endif; ?>
</table>
