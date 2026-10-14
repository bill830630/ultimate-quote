<?php
/**
 * B2B 階梯價表（B2B 會員才會看到）
 *
 * @var array $rows array( array( 'from' => 1, 'to' => 9, 'price' => 100 ), ... )，to 為 0 表示以上
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ultq-box ultq-tiers">
	<div class="ultq-box__title">B2B 數量優惠</div>
	<table class="ultq-tiers__table">
		<thead>
			<tr><th>數量</th><th>參考單價</th></tr>
		</thead>
		<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( $row['to'] ? $row['from'] . '–' . $row['to'] : $row['from'] . ' 以上' ); ?></td>
					<td><?php echo wp_kses_post( wc_price( $row['price'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="ultq-box__text ultq-tiers__note">實際價格以報價單為準。</p>
</div>
