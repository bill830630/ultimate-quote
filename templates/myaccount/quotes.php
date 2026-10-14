<?php
/**
 * 我的帳戶 → 我的詢價（列表）
 *
 * @var array  $rows
 * @var int    $paged
 * @var int    $max_page
 * @var string $list_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( ! $rows ) : ?>
	<div class="woocommerce-info">
		目前沒有詢價紀錄。
		<a class="woocommerce-Button button wp-element-button" href="<?php echo esc_url( $list_url ); ?>">前往詢價清單</a>
	</div>
	<?php return; ?>
<?php endif; ?>

<table class="woocommerce-orders-table shop_table shop_table_responsive my_account_orders ultq-my-quotes">
	<thead>
		<tr>
			<th>單號</th>
			<th>日期</th>
			<th>狀態</th>
			<th>品項</th>
			<th>報價總計</th>
			<th><span class="screen-reader-text">動作</span></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<tr>
				<td data-title="單號"><a href="<?php echo esc_url( $row['url'] ); ?>"><?php echo esc_html( $row['number'] ); ?></a></td>
				<td data-title="日期"><?php echo esc_html( $row['date'] ); ?></td>
				<td data-title="狀態"><?php echo esc_html( $row['status'] ); ?></td>
				<td data-title="品項"><?php echo esc_html( $row['count'] . ' 項' ); ?></td>
				<td data-title="報價總計"><?php echo null === $row['total'] ? '—' : wp_kses_post( wc_price( $row['total'] ) ); ?></td>
				<td>
					<a class="woocommerce-button button view wp-element-button" href="<?php echo esc_url( $row['url'] ); ?>">查看</a>
					<a class="woocommerce-button button ultq-requote wp-element-button" href="<?php echo esc_url( $row['requote'] ); ?>">再次詢價</a>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php if ( $max_page > 1 ) : ?>
	<div class="woocommerce-pagination woocommerce-pagination--without-numbers woocommerce-Pagination">
		<?php if ( $paged > 1 ) : ?>
			<a class="woocommerce-button woocommerce-button--previous woocommerce-Button woocommerce-Button--previous button wp-element-button" href="<?php echo esc_url( add_query_arg( 'quote-page', $paged - 1 ) ); ?>">上一頁</a>
		<?php endif; ?>
		<?php if ( $paged < $max_page ) : ?>
			<a class="woocommerce-button woocommerce-button--next woocommerce-Button woocommerce-Button--next button wp-element-button" href="<?php echo esc_url( add_query_arg( 'quote-page', $paged + 1 ) ); ?>">下一頁</a>
		<?php endif; ?>
	</div>
<?php endif; ?>
