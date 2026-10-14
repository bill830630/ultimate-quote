<?php
/**
 * 送出詢價成功
 *
 * @var string $number
 * @var string $email
 * @var string $shop_url
 * @var string $my_url   會員才有
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ultq-box ultq-submitted">
	<h3 class="ultq-box__title">已收到您的詢價</h3>
	<p class="ultq-box__text">詢價單號：<strong><?php echo esc_html( $number ); ?></strong></p>
	<p class="ultq-box__text">確認信已寄到 <?php echo esc_html( $email ); ?>，我們會盡快為您報價。</p>
	<p class="ultq-submitted__actions">
		<a class="button wp-element-button" href="<?php echo esc_url( $shop_url ); ?>">繼續逛逛</a>
		<?php if ( $my_url ) : ?>
			<a class="button wp-element-button" href="<?php echo esc_url( $my_url ); ?>">查看我的詢價</a>
		<?php endif; ?>
	</p>
</div>
