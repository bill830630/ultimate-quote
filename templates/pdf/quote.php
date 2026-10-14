<?php
/**
 * 報價單 PDF 版面（dompdf 渲染，只支援 CSS 2.1 + 部分 CSS3，排版一律用 table）
 *
 * 欄位開關、排序與自訂欄位都在「設定 → 報價單版面」。
 *
 * 可被主題覆寫：yourtheme/ultimate-quote/pdf/quote.php
 *
 * @var int    $post_id
 * @var array  $labels       欄位名稱（見 ULTQ_PDF::default_labels()）
 * @var array  $layout       各區塊要印的欄位 key（已排序）：header|customer|items|footer => array( key )
 * @var string $number
 * @var string $date         報價日期（已依民國年設定格式化）
 * @var string $valid_until  有效期限（同上）
 * @var array  $customer
 * @var array  $items
 * @var array  $quote
 * @var array  $totals
 * @var array  $company      name/tax_id/address/phone/email/logo/stamp（logo/stamp 為本機檔案路徑）
 * @var array  $options      sku_inline（料號印在品名下方）/sign
 * @var array  $custom       有填值的自訂欄位 id => array( 名稱, 值 )
 * @var array  $bank         匯款資訊 array( array( 名稱, 值 ) )，空陣列表示不顯示
 * @var array  $amount_words 大寫金額
 * @var string $footer       頁尾文字
 * @var array|null $accepted 顧客線上接受的紀錄（name/time），沒有則 null
 * @var array  $sales        負責業務 name/phone/email（沒有指派時都是空字串）
 * @var string $accent       主題主色
 * @var string $font
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$money = function ( $amount ) {
	return esc_html( ULTQ_Helper::money( $amount ) );
};

// 依排序把欄位 key 換成 array( 名稱, 值 )；$values 沒有的 key 視為自訂欄位（沒填值的不印）
$rows_of = function ( array $keys, array $values, $skip_empty = false ) use ( $labels, $custom ) {
	$rows = array();
	foreach ( $keys as $key ) {
		if ( array_key_exists( $key, $values ) ) {
			if ( ! $skip_empty || '' !== trim( (string) $values[ $key ] ) ) {
				$rows[] = array( $labels[ $key ], $values[ $key ] );
			}
		} elseif ( isset( $custom[ $key ] ) ) {
			$rows[] = $custom[ $key ];
		}
	}
	return $rows;
};

$meta = $rows_of( $layout['header'], array(
	'quote_no'    => $number,
	'quote_date'  => $date,
	'valid_until' => $valid_until,
	'sales_name'  => $sales['name'],
	'sales_phone' => $sales['phone'],
	'sales_email' => $sales['email'],
), true );

// 客戶資料：兩欄一列
$info = $rows_of( $layout['customer'], array(
	'customer_name' => $customer['company'] ?: $customer['name'],
	'tax_id'        => $customer['tax_id'],
	'contact'       => $customer['name'],
	'phone'         => $customer['phone'],
	'email'         => $customer['email'],
	'address'       => $customer['address'],
) );

// 品項表欄位的 class
$col_class = array(
	'col_no'     => 'idx',
	'col_qty'    => 'num',
	'col_unit'   => 'unit',
	'col_price'  => 'num',
	'col_discount' => 'num',
	'col_amount' => 'num',
);

// 總計左側：連續的一般欄位併成同一張表，匯款資訊自成一塊
$left = array();
foreach ( $layout['footer'] as $key ) {
	if ( 'bank' === $key ) {
		if ( $bank ) {
			$left[] = array( 'bank', $bank );
		}
		continue;
	}
	$row = $rows_of( array( $key ), array( 'payment_terms' => $quote['payment_terms'] ), true );
	if ( ! $row ) {
		continue;
	}
	$last = count( $left ) - 1;
	if ( $last >= 0 && 'kv' === $left[ $last ][0] ) {
		$left[ $last ][1][] = $row[0];
	} else {
		$left[] = array( 'kv', $row );
	}
}
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
<meta charset="UTF-8">
<title><?php echo esc_html( $labels['doc_title'] . ' ' . $number ); ?></title>
<style>
	@page { margin: 16mm 14mm 20mm; }
	* { font-family: "<?php echo esc_attr( $font ); ?>", sans-serif; }
	body { font-size: 10pt; color: #222; line-height: 1.5; }
	table { border-collapse: collapse; width: 100%; }
	td, th { vertical-align: top; }
	.header td { padding: 0; }
	.logo { max-height: 56px; max-width: 200px; margin-bottom: 6px; }
	.company-name { font-size: 14pt; font-weight: bold; }
	.muted { color: #666; font-size: 9pt; }
	.doc-title { font-size: 22pt; font-weight: bold; color: <?php echo esc_attr( $accent ); ?>; text-align: right; letter-spacing: 4pt; }
	.doc-meta { width: auto; margin-left: auto; }
	.doc-meta td { padding: 1px 0 1px 10px; text-align: right; font-size: 9.5pt; }
	.doc-meta td.label { color: #666; }
	.rule { border-top: 2px solid <?php echo esc_attr( $accent ); ?>; margin: 8px 0 10px; }
	.section-title { font-weight: bold; color: <?php echo esc_attr( $accent ); ?>; margin-bottom: 4px; }
	.info td { padding: 1px 8px 1px 0; font-size: 9.5pt; }
	.info td.label { color: #666; width: 64px; white-space: nowrap; }
	.items { margin-top: 12px; border-spacing: 0; }
	.items th { border: 0; background: <?php echo esc_attr( $accent ); ?>; color: #fff; font-weight: bold; padding: 6px 6px; font-size: 9pt; text-align: left; }
	.items td { padding: 5px 6px; border-bottom: 1px solid #ddd; font-size: 9.5pt; }
	/* 分頁不切開：品項列、總計區、報價說明、簽章各自整塊換頁（超過一頁高的內容 dompdf 仍會照常切） */
	.items tr { page-break-inside: avoid; }
	.items .num { text-align: right; white-space: nowrap; }
	.items .idx { text-align: center; width: 28px; }
	.items .unit { text-align: center; white-space: nowrap; }
	.items .sub { color: #666; font-size: 8.5pt; }
	.summary { margin-top: 10px; page-break-inside: avoid; }
	.summary tr { page-break-inside: avoid; }
	.summary > tbody > tr > td { padding: 0; }
	.totals td { padding: 3px 6px; text-align: right; }
	.totals td.label { color: #555; }
	.totals tr.grand td { border-top: 2px solid <?php echo esc_attr( $accent ); ?>; font-size: 12pt; font-weight: bold; padding-top: 6px; }
	.words { margin-top: 4px; padding: 6px 8px; background: #f5f5f5; font-size: 9pt; text-align: right; }
	.kv td { padding: 2px 8px 2px 0; font-size: 9.5pt; }
	.kv td.label { color: #666; white-space: nowrap; width: 1%; }
	.block { margin-top: 14px; }
	.note { margin-top: 12px; padding: 8px 12px; border: 1px solid #ddd; font-size: 9pt; page-break-inside: avoid; }
	.sign { margin-top: 18px; page-break-inside: avoid; }
	.sign td { font-size: 9.5pt; }
	.stamp { max-height: 110px; max-width: 110px; }
	.accepted { margin-top: 6px; color: <?php echo esc_attr( $accent ); ?>; font-size: 9pt; }
	/* 頁尾固定在下邊界內、每頁都印（dompdf 的 fixed 元素要放在內容之前才會出現在每一頁） */
	.footer { position: fixed; left: 0; right: 0; bottom: -13mm; padding-top: 4px; border-top: 1px solid #ddd; color: #666; font-size: 8pt; line-height: 1.4; text-align: center; }
</style>
</head>
<body>

<?php if ( '' !== trim( $footer ) ) : ?>
	<div class="footer"><?php echo nl2br( esc_html( $footer ) ); ?></div>
<?php endif; ?>

<table class="header">
	<tr>
		<td style="width:56%;">
			<?php if ( $company['logo'] ) : ?>
				<img class="logo" src="<?php echo esc_attr( $company['logo'] ); ?>" alt=""><br>
			<?php endif; ?>
			<div class="company-name"><?php echo esc_html( $company['name'] ); ?></div>
			<div class="muted">
				<?php
				$lines = array_filter( array(
					$company['tax_id'] ? $labels['tax_id'] . '：' . $company['tax_id'] : '',
					$company['address'],
					implode( '　', array_filter( array(
						$company['phone'] ? $labels['phone'] . '：' . $company['phone'] : '',
						$company['email'],
					) ) ),
				) );
				echo implode( '<br>', array_map( 'esc_html', $lines ) ); // phpcs:ignore WordPress.Security.EscapeOutput
				?>
			</div>
		</td>
		<td style="width:44%;">
			<div class="doc-title"><?php echo esc_html( $labels['doc_title'] ); ?></div>
			<table class="doc-meta">
				<?php foreach ( $meta as $row ) : ?>
					<tr><td class="label"><?php echo esc_html( $row[0] ); ?></td><td><?php echo esc_html( $row[1] ); ?></td></tr>
				<?php endforeach; ?>
			</table>
		</td>
	</tr>
</table>

<div class="rule"></div>

<?php if ( $info ) : ?>
<div class="section-title"><?php echo esc_html( $labels['customer'] ); ?></div>
<table class="info">
	<?php foreach ( array_chunk( $info, 2 ) as $pair ) : ?>
		<tr>
			<?php foreach ( $pair as $i => $cell ) : ?>
				<td class="label"><?php echo esc_html( $cell[0] ); ?></td>
				<td<?php echo 0 === $i ? ' style="width:40%;"' : ''; ?>><?php echo esc_html( $cell[1] ); ?></td>
			<?php endforeach; ?>
			<?php if ( 1 === count( $pair ) ) : ?>
				<td class="label"></td><td></td>
			<?php endif; ?>
		</tr>
	<?php endforeach; ?>
</table>
<?php endif; ?>

<table class="items">
	<thead>
		<tr>
			<?php foreach ( $layout['items'] as $col ) : ?>
				<th<?php echo isset( $col_class[ $col ] ) ? ' class="' . esc_attr( $col_class[ $col ] ) . '"' : ''; ?>><?php echo esc_html( $labels[ $col ] ); ?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $i => $item ) : ?>
			<tr>
				<?php foreach ( $layout['items'] as $col ) : ?>
					<td<?php echo isset( $col_class[ $col ] ) ? ' class="' . esc_attr( $col_class[ $col ] ) . '"' : ''; ?>>
						<?php
						switch ( $col ) {
							case 'col_no':
								echo (int) $i + 1;
								break;
							case 'col_sku':
								echo esc_html( $item['sku'] );
								break;
							case 'col_desc':
								echo esc_html( $item['name'] );
								$sub = array();
								if ( $options['sku_inline'] && $item['sku'] ) {
									$sub[] = $labels['col_sku'] . ' ' . $item['sku'];
								}
								foreach ( $item['attributes'] as $label => $value ) {
									$sub[] = $label . '：' . $value;
								}
								if ( $item['line_note'] ) {
									$sub[] = $item['line_note'];
								}
								if ( $sub ) {
									echo '<div class="sub">' . esc_html( implode( '｜', $sub ) ) . '</div>';
								}
								break;
							case 'col_qty':
								echo esc_html( ULTQ_Helper::qty( $item['qty'] ) );
								break;
							case 'col_unit':
								echo esc_html( $item['unit'] );
								break;
							case 'col_price':
								echo null === $item['unit_price'] ? '—' : $money( $item['unit_price'] ); // phpcs:ignore WordPress.Security.EscapeOutput
								break;
							case 'col_discount':
								echo $item['discount'] > 0 ? esc_html( ULTQ_Quote_Post::discount_label( $item['discount'] ) ) : '';
								break;
							case 'col_amount':
								$line = ULTQ_Quote_Post::line_total( $item );
								echo null === $line ? '—' : $money( $line ); // phpcs:ignore WordPress.Security.EscapeOutput
								break;
						}
						?>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<?php // 左：付款條件／匯款資訊／自訂欄位（依排序）；右：金額 ?>
<table class="summary">
	<tr>
		<td style="width:52%;padding-right:16px;">
			<?php foreach ( $left as $n => $block ) : ?>
				<?php if ( 'bank' === $block[0] ) : ?>
					<div class="section-title" style="margin-top:<?php echo $n ? '10px' : '0'; ?>;"><?php echo esc_html( $labels['bank'] ); ?></div>
				<?php endif; ?>
				<table class="kv"<?php echo $n && 'kv' === $block[0] ? ' style="margin-top:10px;"' : ''; ?>>
					<?php foreach ( $block[1] as $row ) : ?>
						<tr><td class="label"><?php echo esc_html( $row[0] ); ?></td><td><?php echo esc_html( $row[1] ); ?></td></tr>
					<?php endforeach; ?>
				</table>
			<?php endforeach; ?>
		</td>
		<td style="width:48%;">
			<table class="totals">
				<tr><td class="label"><?php echo esc_html( $labels['subtotal'] ); ?></td><td><?php echo $money( $totals['subtotal'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php // 減號用半形「-」：內建的 Big5 字型沒有「−」（U+2212） ?>
				<?php foreach ( $totals['discounts'] as $row ) : ?>
					<tr><td class="label"><?php echo esc_html( $row['label'] ); ?></td><td>-<?php echo $money( $row['amount'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php endforeach; ?>
				<?php if ( $totals['shipping'] ) : ?>
					<tr><td class="label"><?php echo esc_html( $labels['shipping'] ); ?></td><td><?php echo $money( $totals['shipping'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php endif; ?>
				<?php if ( 'excl' === $quote['tax_mode'] ) : ?>
					<tr><td class="label"><?php echo esc_html( $labels['tax'] ); ?>（<?php echo esc_html( wc_format_localized_decimal( $quote['tax_rate'] ) ); ?>%）</td><td><?php echo $money( $totals['tax'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php elseif ( 'incl' === $quote['tax_mode'] ) : ?>
					<tr><td class="label"><?php echo esc_html( $labels['tax_incl'] ); ?>（<?php echo esc_html( wc_format_localized_decimal( $quote['tax_rate'] ) ); ?>%）</td><td><?php echo $money( $totals['tax'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
				<?php else : ?>
					<tr><td class="label"><?php echo esc_html( $labels['tax_none'] ); ?></td><td><?php echo esc_html( '免稅' ); ?></td></tr>
				<?php endif; ?>
				<tr class="grand"><td class="label"><?php echo esc_html( $labels['total'] ); ?></td><td><?php echo $money( $totals['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td></tr>
			</table>
			<?php if ( $amount_words ) : ?>
				<div class="words">
					<span class="muted"><?php echo esc_html( $labels['amount_words'] ); ?></span><br>
					<?php echo implode( '<br>', array_map( 'esc_html', $amount_words ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				</div>
			<?php endif; ?>
		</td>
	</tr>
</table>

<?php if ( $quote['note'] ) : ?>
	<div class="note">
		<div class="section-title"><?php echo esc_html( $labels['note'] ); ?></div>
		<?php echo nl2br( esc_html( $quote['note'] ) ); ?>
	</div>
<?php endif; ?>

<?php if ( $options['sign'] || $company['stamp'] || $accepted ) : ?>
	<table class="sign">
		<tr>
			<td style="width:60%;">
				<?php if ( $options['sign'] ) : ?>
					<div class="muted"><?php echo esc_html( $labels['sign'] ); ?>：</div>
					<div style="margin-top:40px;border-top:1px solid #999;width:70%;"></div>
				<?php endif; ?>
				<?php if ( $accepted ) : ?>
					<div class="accepted">
						<?php
						echo esc_html( sprintf(
							'已於 %1$s 由 %2$s 線上確認接受',
							wp_date( 'Y-m-d H:i', (int) $accepted['time'] ),
							$accepted['name']
						) );
						?>
					</div>
				<?php endif; ?>
			</td>
			<td style="width:40%;text-align:right;">
				<?php if ( $company['stamp'] ) : ?>
					<img class="stamp" src="<?php echo esc_attr( $company['stamp'] ); ?>" alt="">
				<?php endif; ?>
			</td>
		</tr>
	</table>
<?php endif; ?>

</body>
</html>
