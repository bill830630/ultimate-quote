<?php
/** 個人戶／公司戶的表單欄位、顯示規則與送出驗證。 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ULTQ_Form {
	public static function types() {
		return array( 'personal' => '個人採購', 'company' => '公司行號' );
	}

	public static function fields( $scope ) {
		$fields = array(
			'name'    => array( 'label' => '聯絡人', 'type' => 'text', 'autocomplete' => 'name' ),
			'company' => array( 'label' => '公司名稱', 'type' => 'text', 'autocomplete' => 'organization' ),
			'email'   => array( 'label' => 'Email', 'type' => 'email', 'autocomplete' => 'email' ),
			'phone'   => array( 'label' => '聯絡電話', 'type' => 'tel', 'autocomplete' => 'tel' ),
			'tax_id'  => array( 'label' => '統一編號', 'type' => 'text' ),
			'address' => array( 'label' => '地址', 'type' => 'text', 'autocomplete' => 'street-address' ),
			'message' => array( 'label' => '需求說明', 'type' => 'textarea' ),
		);
		if ( 'b2b' === $scope ) {
			$fields['contact'] = $fields['name'];
			$fields['note']    = array( 'label' => '備註', 'type' => 'textarea' );
			unset( $fields['name'], $fields['email'], $fields['message'] );
			$fields = array_merge( array( 'contact' => $fields['contact'] ), $fields );
		}
		return $fields;
	}

	public static function defaults( $scope ) {
		$rules = array();
		foreach ( self::types() as $type => $label ) {
			foreach ( self::fields( $scope ) as $key => $field ) {
				$required = in_array( $key, array( 'name', 'contact', 'email', 'phone' ), true );
				$business = in_array( $key, array( 'company', 'tax_id' ), true );
				$rules[ $type ][ $key ] = $business ? ( 'company' === $type ? 'required' : 'hidden' ) : ( $required ? 'required' : 'optional' );
			}
		}
		return $rules;
	}

	/** 欄位固定依採購身分套用；舊版逐欄設定不再使用。 */
	public static function rules( $scope ) {
		return self::defaults( $scope );
	}

	public static function enabled_types() {
		$saved = ULTQ_Settings::get( 'customer_types', array( 'personal', 'company' ) );
		$keys = is_array( $saved ) ? array_filter( $saved, 'is_string' ) : array();
		return array_intersect_key( self::types(), array_flip( $keys ) );
	}

	/** 舊資料沒有戶別時，依已填公司名稱／統編推定，不改寫舊資料。 */
	public static function customer_type( $data ) {
		$type = is_string( $data['customer_type'] ?? null ) ? $data['customer_type'] : '';
		if ( isset( self::types()[ $type ] ) ) {
			return $type;
		}
		return ! empty( $data['company'] ) || ! empty( $data['tax_id'] ) ? 'company' : 'personal';
	}

	/** $raw 已經 wp_unslash；隱藏欄位一律忽略，即使被手動送出也不儲存。 */
	public static function validate( $scope, array $raw ) {
		$type   = is_string( $raw['customer_type'] ?? null ) ? $raw['customer_type'] : '';
		$errors = array();
		if ( ! isset( self::enabled_types()[ $type ] ) ) {
			$errors[] = self::enabled_types() ? '請選擇目前開放的採購身分。' : '目前暫停受理新的會員申請與詢價。';
		}
		$rules = self::rules( $scope )[ $type ] ?? array();
		$data  = array( 'customer_type' => $type );
		foreach ( self::fields( $scope ) as $key => $field ) {
			$value = is_scalar( $raw[ $key ] ?? null ) ? trim( (string) $raw[ $key ] ) : '';
			$rule  = $rules[ $key ] ?? 'hidden';
			if ( 'hidden' === $rule ) {
				$data[ $key ] = '';
				continue;
			}
			$data[ $key ] = 'textarea' === $field['type'] ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
			if ( 'required' === $rule && '' === trim( $data[ $key ] ) ) {
				$errors[] = '請填寫' . $field['label'] . '。';
			}
			if ( 'email' === $key ) {
				if ( '' !== $value && ! is_email( $value ) ) {
					$errors[] = '請填寫正確的 Email。';
				}
				$data[ $key ] = sanitize_email( $value );
			}
			if ( 'tax_id' === $key ) {
				$data[ $key ] = preg_replace( '/\D/', '', $value );
				if ( '' !== $value && ! ULTQ_Helper::is_valid_tax_id( $data[ $key ] ) ) {
					$errors[] = '統一編號格式不正確。';
				}
			}
		}
		return array( 'data' => $data, 'errors' => $errors );
	}
}
