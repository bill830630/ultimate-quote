<?php
/**
 * 顧客線上回覆報價（接受／婉拒），顯示在報價檢視頁（我的帳戶明細、免登入檢視頁）
 *
 * 可被主題覆寫：yourtheme/ultimate-quote/quote-respond.php
 *
 * @var int    $post_id
 * @var string $token        免登入檢視頁的 token（我的帳戶為空字串）
 * @var bool   $can_respond
 * @var bool   $enabled      設定是否開放線上回覆
 * @var bool   $expired
 * @var string $status
 * @var array  $response     action/name/note/time
 * @var string $notice       剛送出：accept|reject
 * @var string $error        required|closed|expired
 * @var string $prefill      簽署人姓名預填（詢價聯絡人）
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$errors = array(
	'required' => '接受報價需要填寫簽署人姓名，並勾選同意報價內容。',
	'closed'   => '這份報價目前無法線上回覆（可能已過期或已經回覆過）。',
	'expired'  => '頁面停留太久，請重新整理後再送出一次。',
);
?>
<div class="ultq-respond">
	<?php if ( 'accept' === $notice ) : ?>
		<div class="woocommerce-message" role="status">已收到您的確認，我們會盡快與您聯繫後續出貨與付款事宜。</div>
	<?php elseif ( 'reject' === $notice ) : ?>
		<div class="woocommerce-info" role="status">已收到您的回覆，謝謝。如需重新報價，歡迎隨時與我們聯繫。</div>
	<?php endif; ?>
	<?php if ( isset( $errors[ $error ] ) ) : ?>
		<div class="woocommerce-error" role="alert"><?php echo esc_html( $errors[ $error ] ); ?></div>
	<?php endif; ?>

	<?php if ( 'ultq-accepted' === $status ) : ?>
		<?php if ( ! $notice ) : ?>
			<div class="ultq-box">
				<div class="ultq-box__title">您已接受這份報價</div>
				<p class="ultq-box__text">
					<?php echo esc_html( sprintf( '於 %s 由 %s 線上確認。我們會盡快與您聯繫後續事宜。', wp_date( 'Y-m-d H:i', (int) $response['time'] ), $response['name'] ) ); ?>
				</p>
			</div>
		<?php endif; ?>

	<?php elseif ( 'ultq-rejected' === $status ) : ?>
		<?php if ( ! $notice ) : ?>
			<div class="ultq-box ultq-box--muted">
				<div class="ultq-box__title">您已婉拒這份報價</div>
				<p class="ultq-box__text">如需重新報價，歡迎隨時與我們聯繫。</p>
			</div>
		<?php endif; ?>

	<?php elseif ( $can_respond ) : ?>
		<div class="ultq-box ultq-respond__box">
			<div class="ultq-box__title">確認報價</div>
			<p class="ultq-box__text">內容沒有問題的話，可以直接在這裡接受報價，我們收到後會與您聯繫出貨與付款。</p>

			<form method="post" class="ultq-respond__form">
				<?php wp_nonce_field( 'ultq_respond_' . $post_id, '_ultq_respond_nonce' ); ?>
				<input type="hidden" name="ultq_quote_id" value="<?php echo (int) $post_id; ?>">
				<input type="hidden" name="ultq_token" value="<?php echo esc_attr( $token ); ?>">
				<p class="form-row form-row-wide">
					<label for="ultq-sign-name">簽署人姓名&nbsp;<abbr class="required" title="必填">*</abbr></label>
					<input type="text" class="input-text" id="ultq-sign-name" name="ultq_sign_name" value="<?php echo esc_attr( $prefill ); ?>" required>
				</p>
				<p class="form-row form-row-wide">
					<label for="ultq-accept-note">備註（選填）</label>
					<textarea class="input-text" id="ultq-accept-note" name="ultq_respond_note" rows="2" placeholder="例如：希望的交貨日期、收貨地址"></textarea>
				</p>
				<p class="form-row form-row-wide">
					<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">
						<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="ultq_agree" value="1" required>
						<span>我已確認報價內容與條款，同意接受這份報價</span>
					</label>
				</p>
				<p><button type="submit" name="ultq_respond" value="accept" class="button alt wp-element-button">接受報價</button></p>
			</form>

			<details class="ultq-respond__reject">
				<summary>不需要這份報價？</summary>
				<form method="post" class="ultq-respond__form">
					<?php wp_nonce_field( 'ultq_respond_' . $post_id, '_ultq_respond_nonce' ); ?>
					<input type="hidden" name="ultq_quote_id" value="<?php echo (int) $post_id; ?>">
					<input type="hidden" name="ultq_token" value="<?php echo esc_attr( $token ); ?>">
					<p class="form-row form-row-wide">
						<label for="ultq-reject-note">原因（選填，幫助我們調整報價）</label>
						<textarea class="input-text" id="ultq-reject-note" name="ultq_respond_note" rows="2" placeholder="例如：價格超出預算、交期太長"></textarea>
					</p>
					<p><button type="submit" name="ultq_respond" value="reject" class="button wp-element-button">婉拒報價</button></p>
				</form>
			</details>
		</div>

	<?php elseif ( $enabled && $expired && 'ultq-quoted' === $status ) : ?>
		<div class="ultq-box ultq-box--muted">
			<div class="ultq-box__title">報價已過期</div>
			<p class="ultq-box__text">這份報價已超過有效期限，如需下單請與我們聯繫，我們會重新為您報價。</p>
		</div>
	<?php endif; ?>
</div>
