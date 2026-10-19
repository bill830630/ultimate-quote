<?php
/**
 * 詢價清單頁（[ultq_quote_list]）
 *
 * 可被主題覆寫：yourtheme/ultimate-quote/quote-list.php
 *
 * @var array  $items       ULTQ_List::get_display_items()
 * @var array  $customer    預填的聯絡資料
 * @var bool   $can_request 目前的人可否送出詢價（只有 B2B 會員）
 * @var string $gate        非 B2B 會員看到的說明＋登入／申請按鈕
 * @var string $login_url
 * @var string $shop_url
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( ! $can_request ) : ?>
	<div class="ultq-list ultq-list--gate"><?php echo $gate; // phpcs:ignore WordPress.Security.EscapeOutput -- ULTQ_Frontend::b2b_gate_html() 已跳脫 ?></div>
	<?php return; ?>
<?php endif; ?>
<?php if ( ! $items ) : ?>
	<div class="ultq-list ultq-list--empty">
		<p class="woocommerce-info">您的詢價清單目前是空的。</p>
		<p class="return-to-shop"><a class="button wc-backward wp-element-button" href="<?php echo esc_url( $shop_url ); ?>">回到商店</a></p>
	</div>
	<?php return; ?>
<?php endif; ?>

<div class="ultq-list">
	<div class="ultq-notices" role="alert" aria-live="polite"></div>

	<table class="shop_table shop_table_responsive ultq-list__table">
		<thead>
			<tr>
				<th class="product-remove"><span class="screen-reader-text">移除</span></th>
				<th class="product-thumbnail"><span class="screen-reader-text">縮圖</span></th>
				<th class="product-name">商品</th>
				<th class="product-quantity">數量</th>
				<th class="ultq-col-note">備註</th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $items as $key => $item ) : ?>
				<?php $product = $item['product']; ?>
				<tr class="ultq-list__item" data-key="<?php echo esc_attr( $key ); ?>">
					<td class="product-remove">
						<button type="button" class="remove ultq-remove" aria-label="<?php echo esc_attr( '移除 ' . $product->get_name() ); ?>">&times;</button>
					</td>
					<td class="product-thumbnail">
						<a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo $product->get_image( 'woocommerce_gallery_thumbnail' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></a>
					</td>
					<td class="product-name" data-title="商品">
						<a href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $product->get_name() ); ?></a>
						<?php if ( $item['attributes'] ) : ?>
							<dl class="variation">
								<?php foreach ( $item['attributes'] as $label => $value ) : ?>
									<dt><?php echo esc_html( $label ); ?>:</dt>
									<dd><?php echo esc_html( $value ); ?></dd>
								<?php endforeach; ?>
							</dl>
						<?php endif; ?>
						<?php if ( $product->get_sku() ) : ?>
							<div class="ultq-list__sku">貨號：<?php echo esc_html( $product->get_sku() ); ?></div>
						<?php endif; ?>
						<?php if ( ! empty( $item['qty_note'] ) ) : ?>
							<div class="ultq-list__moq"><?php echo esc_html( $item['qty_note'] ); ?></div>
						<?php endif; ?>
					</td>
					<td class="product-quantity" data-title="數量">
						<?php
						// 用 WooCommerce 原生的數量元件，主題（例如 Blocksy 的＋／－按鈕）樣式才會套用
						woocommerce_quantity_input( array(
							'input_id'     => 'ultq-qty-' . $key,
							'input_name'   => 'ultq_qty[' . $key . ']',
							'input_value'  => $item['qty'],
							'min_value'    => (int) ( $item['qty_rules']['min'] ?? 1 ),
							'max_value'    => '',
							'step'         => (int) ( $item['qty_rules']['step'] ?? 1 ),
							'classes'      => array( 'input-text', 'qty', 'text', 'ultq-qty' ),
							'product_name' => $product->get_name(),
						), $product );
						?>
					</td>
					<td class="ultq-col-note" data-title="備註">
						<input type="text" class="input-text ultq-note" value="<?php echo esc_attr( $item['note'] ?? '' ); ?>" placeholder="規格、交期等需求（選填）">
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $can_request ) : ?>
		<form class="ultq-form" novalidate>
			<h3 class="ultq-form__title">聯絡資料</h3>
			<?php echo ULTQ_Helper::get_template_html( 'customer-fields.php', array( 'scope' => 'quote', 'data' => $customer ) ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<?php if ( ULTQ_Files::enabled() ) : ?>
				<?php $types = ULTQ_Files::allowed_types(); ?>
				<div class="form-row woocommerce-form-row ultq-form__files ultq-upload">
					<label for="ultq-files">附件（選填）</label>
					<input type="file" class="ultq-upload__input" id="ultq-files" name="ultq_files[]" multiple accept="<?php echo esc_attr( implode( ',', array_map( function ( $ext ) { return '.' . $ext; }, $types ) ) ); ?>" aria-describedby="ultq-files-hint ultq-files-status ultq-files-error" data-max-files="<?php echo (int) ULTQ_Files::max_files(); ?>" data-max-bytes="<?php echo (int) ULTQ_Files::max_bytes(); ?>">
					<label class="ultq-upload__dropzone ct-pseudo-input" for="ultq-files">
						<svg class="ultq-upload__icon" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 16V3m-5 5 5-5 5 5M4 16v4a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-4"/></svg>
						<strong>加入圖面、規格書或照片</strong>
						<span class="ultq-upload__prompt">點選或拖曳檔案至此</span>
						<small id="ultq-files-hint"><?php echo esc_html( sprintf( '最多 %d 個檔案，單檔 %s 以內；附件將隨詢價一併送出。', ULTQ_Files::max_files(), size_format( ULTQ_Files::max_bytes() ) ) ); ?></small>
					</label>
					<div class="ultq-upload__meta">
						<span id="ultq-files-status" class="ultq-upload__status" role="status">尚未選擇附件</span>
						<details class="ultq-upload__formats"><summary>查看支援格式</summary><p><?php echo esc_html( strtoupper( implode( '、', $types ) ) ); ?></p></details>
					</div>
					<p id="ultq-files-error" class="ultq-upload__error" role="alert" hidden></p>
					<ul class="ultq-upload__list" aria-label="已選附件" hidden></ul>
				</div>
			<?php endif; ?>
			<div class="ultq-hp" aria-hidden="true">
				<label for="ultq-website">請勿填寫</label>
				<input type="text" id="ultq-website" name="ultq_website" tabindex="-1" autocomplete="off">
			</div>
			<p class="ultq-form__actions">
				<button type="submit" class="button alt wp-element-button ultq-submit">送出詢價</button>
			</p>
		</form>
	<?php endif; ?>
</div>
