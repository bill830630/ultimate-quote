<?php
/**
 * 非 B2B 會員看詢價商品（商品頁加入表單的位置、詢價清單頁）
 *
 * 可被主題覆寫：yourtheme/ultimate-quote/single-product/b2b-only.php
 *
 * @var string     $text   設定頁「非 B2B 會員看到的說明」
 * @var string     $note   狀態說明（審核中）
 * @var array|null $button array( 文字, 網址 )
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="ultq-box ultq-b2b-only">
	<div class="ultq-box__title">B2B 會員專屬</div>
	<?php if ( '' !== trim( $text ) ) : ?>
		<p class="ultq-box__text"><?php echo nl2br( esc_html( $text ) ); ?></p>
	<?php endif; ?>
	<?php if ( $note ) : ?>
		<p class="ultq-box__text"><?php echo esc_html( $note ); ?></p>
	<?php endif; ?>
	<?php if ( $button ) : ?>
		<p><a class="button wp-element-button" href="<?php echo esc_url( $button[1] ); ?>"><?php echo esc_html( $button[0] ); ?></a></p>
	<?php endif; ?>
</div>
