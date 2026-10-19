<?php
/** 共用戶別與聯絡資料，$scope = quote|b2b，$data = 預填資料。 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$rules = ULTQ_Form::rules( $scope );
$type  = ULTQ_Form::customer_type( $data );
$prefix = 'ultq-' . $scope . '-';
?>
<div class="ultq-customer-fields" data-rules="<?php echo esc_attr( wp_json_encode( $rules ) ); ?>">
	<fieldset class="ultq-customer-type">
		<legend>採購身分&nbsp;<abbr class="required" title="必填">*</abbr></legend>
		<div class="ultq-customer-type__options">
			<?php foreach ( ULTQ_Form::types() as $value => $label ) : ?>
				<label class="ultq-customer-type__option">
					<input type="radio" name="customer_type" value="<?php echo esc_attr( $value ); ?>" <?php checked( $type, $value ); ?> required>
					<span class="ultq-customer-type__content">
						<strong><?php echo esc_html( $label ); ?></strong>
						<small><?php echo 'personal' === $value ? '以個人名義詢價與採購' : '代表公司或行號詢價與採購'; ?></small>
					</span>
				</label>
			<?php endforeach; ?>
		</div>
	</fieldset>
	<div class="ultq-form__grid">
		<?php foreach ( ULTQ_Form::fields( $scope ) as $key => $field ) : ?>
			<?php
			$rule = $rules[ $type ][ $key ];
			$required = 'required' === $rule;
			$hidden = 'hidden' === $rule;
			$id = $prefix . str_replace( '_', '-', $key );
			?>
			<p class="form-row woocommerce-form-row<?php echo 'textarea' === $field['type'] ? ' ultq-field-wide' : ''; ?>" data-customer-field="<?php echo esc_attr( $key ); ?>" <?php echo $hidden ? 'hidden' : ''; ?>>
				<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?><abbr class="required" title="必填" <?php echo $required ? '' : 'hidden'; ?>> *</abbr></label>
				<?php if ( 'textarea' === $field['type'] ) : ?>
					<textarea class="input-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" rows="4" <?php echo $required ? 'required aria-required="true"' : ''; ?> <?php disabled( $hidden ); ?>><?php echo esc_textarea( $data[ $key ] ?? '' ); ?></textarea>
				<?php else : ?>
					<input type="<?php echo esc_attr( $field['type'] ); ?>" class="input-text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $data[ $key ] ?? '' ); ?>" <?php echo $required ? 'required aria-required="true"' : ''; ?> <?php disabled( $hidden ); ?>
						<?php if ( ! empty( $field['autocomplete'] ) ) : ?>autocomplete="<?php echo esc_attr( $field['autocomplete'] ); ?>"<?php endif; ?>
						<?php if ( 'tax_id' === $key ) : ?>inputmode="numeric" maxlength="8" data-ultq-tax-lookup data-company="#<?php echo esc_attr( $prefix ); ?>company" data-address="#<?php echo esc_attr( $prefix ); ?>address"<?php endif; ?>>
				<?php endif; ?>
			</p>
		<?php endforeach; ?>
	</div>
</div>
